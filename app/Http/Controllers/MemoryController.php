<?php

namespace App\Http\Controllers;

use App\Http\Resources\MemoryResource;
use App\Models\Family;
use App\Models\Memory;
use App\Models\MemoryLeaf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class MemoryController extends Controller
{
    public function index(Request $request, Family $family, MemoryLeaf $memoryLeaf): AnonymousResourceCollection
    {
        abort_unless($memoryLeaf->family_id === $family->id, 404);
        abort_unless(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            403
        );
        abort_unless($memoryLeaf->managed_by === $request->user()->id, 403);

        return MemoryResource::collection(
            $memoryLeaf->memories()->with('contributor')->latest()->get()
        );
    }

    public function store(Request $request, Family $family, MemoryLeaf $memoryLeaf): MemoryResource
    {
        abort_unless($memoryLeaf->family_id === $family->id, 404);
        abort_unless(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            403
        );
        abort_unless($memoryLeaf->managed_by === $request->user()->id, 403);

        $data = $request->validate([
            'type'    => 'required|in:text,photo,video,voice',
            'title'   => 'nullable|string|max:255',
            'content' => 'nullable|string|max:2000',
            'caption' => 'nullable|string|max:500',
            'file'    => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,m4a,mp3|max:51200',
            'files'   => 'nullable|array',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp|max:51200',
        ]);

        $mediaPaths = [];
        if ($data['type'] === 'photo') {
            $photoFiles = $request->file('files', []);
            if (!is_array($photoFiles) || count($photoFiles) === 0) {
                abort(422, 'Debes adjuntar al menos una foto.');
            }

            foreach ($photoFiles as $file) {
                if ($file instanceof UploadedFile) {
                    $mediaPaths[] = $file->store("memories/{$memoryLeaf->id}", 'public');
                }
            }
        } elseif (in_array($data['type'], ['video', 'voice'], true)) {
            if (!$request->hasFile('file')) {
                abort(422, 'Debes adjuntar un archivo para este tipo.');
            }

            $singlePath = $request->file('file')->store("memories/{$memoryLeaf->id}", 'public');
            $mediaPaths[] = $singlePath;
        }

        $filePath = $mediaPaths[0] ?? null;

        $memory = $memoryLeaf->memories()->create([
            'contributed_by' => $request->user()->id,
            'type'           => $data['type'],
            'title'          => $data['title'] ?? null,
            'content'        => $data['content'] ?? null,
            'caption'        => $data['caption'] ?? null,
            'file_path'      => $filePath,
            'media_paths'    => count($mediaPaths) > 0 ? $mediaPaths : null,
        ]);

        return new MemoryResource($memory->load('contributor'));
    }

    public function destroy(Request $request, Family $family, MemoryLeaf $memoryLeaf, Memory $memory): JsonResponse
    {
        abort_unless($memoryLeaf->family_id === $family->id, 404);
        abort_unless($memory->memory_leaf_id === $memoryLeaf->id, 404);
        abort_unless(
            $memory->contributed_by === $request->user()->id || $memoryLeaf->managed_by === $request->user()->id,
            403
        );

        $pathsToDelete = is_array($memory->media_paths) ? $memory->media_paths : [];
        if (count($pathsToDelete) === 0 && $memory->file_path) {
            $pathsToDelete = [$memory->file_path];
        }
        foreach ($pathsToDelete as $path) {
            Storage::disk('public')->delete($path);
        }
        $memory->delete();

        return response()->json(['message' => 'Recuerdo eliminado']);
    }
}
