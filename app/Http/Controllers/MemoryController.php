<?php

namespace App\Http\Controllers;

use App\Http\Resources\MemoryResource;
use App\Models\Family;
use App\Models\Memory;
use App\Models\MemoryLeaf;
use Illuminate\Http\JsonResponse;
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

        $data = $request->validate([
            'type'    => 'required|in:text,photo,video,voice',
            'content' => 'nullable|string|max:2000',
            'caption' => 'nullable|string|max:500',
            'file'    => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,m4a,mp3|max:51200',
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store("memories/{$memoryLeaf->id}", 'public');
        }

        $memory = $memoryLeaf->memories()->create([
            'contributed_by' => $request->user()->id,
            'type'           => $data['type'],
            'content'        => $data['content'] ?? null,
            'caption'        => $data['caption'] ?? null,
            'file_path'      => $filePath,
        ]);

        return new MemoryResource($memory->load('contributor'));
    }

    public function destroy(Request $request, Family $family, MemoryLeaf $memoryLeaf, Memory $memory): JsonResponse
    {
        abort_unless($memory->memory_leaf_id === $memoryLeaf->id, 404);
        abort_unless(
            $memory->contributed_by === $request->user()->id || $family->owner_id === $request->user()->id,
            403
        );

        if ($memory->file_path) Storage::disk('public')->delete($memory->file_path);
        $memory->delete();

        return response()->json(['message' => 'Recuerdo eliminado']);
    }
}
