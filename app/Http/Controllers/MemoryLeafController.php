<?php

namespace App\Http\Controllers;

use App\Http\Resources\MemoryLeafResource;
use App\Models\Family;
use App\Models\MemoryLeaf;
use App\Models\MemoryLeafShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MemoryLeafController extends Controller
{
    public function index(Request $request, Family $family): AnonymousResourceCollection
    {
        $this->assertMember($family, $request);

        $leaves = $family->memoryLeaves()
            ->where('managed_by', $request->user()->id)
            ->withCount('memories')
            ->latest()
            ->get();

        return MemoryLeafResource::collection($leaves);
    }

    public function store(Request $request, Family $family): MemoryLeafResource
    {
        $this->assertMember($family, $request);

        $data = $request->validate([
            'first_name'   => 'required|string|max:100',
            'last_name'    => 'required|string|max:100',
            'surname'      => 'required|string|max:100',
            'relationship' => 'nullable|string|max:80',
            'bio'          => 'nullable|string|max:1000',
            'birth_date'   => 'nullable|date',
            'death_date'   => 'nullable|date',
            'avatar'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store("memory-leaves/{$family->id}", 'public');
        }

        $leaf = $family->memoryLeaves()->create([
            ...$data,
            'avatar'     => $avatarPath,
            'managed_by' => $request->user()->id,
        ]);

        return new MemoryLeafResource($leaf);
    }

    public function show(Request $request, Family $family, MemoryLeaf $memoryLeaf): MemoryLeafResource
    {
        $this->assertMember($family, $request);
        abort_unless($memoryLeaf->family_id === $family->id, 404);

        $memoryLeaf->load(['memories.contributor']);
        return new MemoryLeafResource($memoryLeaf);
    }

    public function update(Request $request, Family $family, MemoryLeaf $memoryLeaf): MemoryLeafResource
    {
        abort_unless($memoryLeaf->family_id === $family->id, 404);
        $this->assertManager($memoryLeaf, $request);

        $data = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'surname'    => 'sometimes|string|max:100',
            'bio'        => 'sometimes|nullable|string|max:1000',
            'birth_date' => 'sometimes|nullable|date',
            'death_date' => 'sometimes|nullable|date',
        ]);

        $memoryLeaf->update($data);
        return new MemoryLeafResource($memoryLeaf->fresh());
    }

    public function uploadAvatar(Request $request, Family $family, MemoryLeaf $memoryLeaf): MemoryLeafResource
    {
        abort_unless($memoryLeaf->family_id === $family->id, 404);
        $this->assertMember($family, $request);
        $request->validate(['avatar' => 'required|image|max:10240']);

        if ($memoryLeaf->avatar) Storage::disk('public')->delete($memoryLeaf->avatar);
        $path = $request->file('avatar')->store("memory-leaves/{$family->id}", 'public');
        $memoryLeaf->update(['avatar' => $path]);

        return new MemoryLeafResource($memoryLeaf->fresh());
    }

    public function destroy(Request $request, Family $family, MemoryLeaf $memoryLeaf): JsonResponse
    {
        abort_unless($memoryLeaf->family_id === $family->id, 404);
        $this->assertManager($memoryLeaf, $request);
        if ($memoryLeaf->avatar) Storage::disk('public')->delete($memoryLeaf->avatar);
        $memoryLeaf->delete();

        return response()->json(['message' => 'Memory Leaf eliminado']);
    }

    public function shareUsers(Request $request, Family $family): JsonResponse
    {
        $this->assertMember($family, $request);

        $users = $family->familyMembers()
            ->with('user')
            ->where('user_id', '!=', $request->user()->id)
            ->get()
            ->map(fn ($member) => [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'avatar_url' => $member->user->avatar_url,
            ])
            ->values();

        return response()->json(['data' => $users]);
    }

    public function share(Request $request, Family $family, MemoryLeaf $memoryLeaf): JsonResponse
    {
        $this->assertMember($family, $request);
        abort_unless($memoryLeaf->family_id === $family->id, 404);
        $this->assertManager($memoryLeaf, $request);

        $data = $request->validate([
            'recipient_user_id' => 'required|integer|exists:users,id',
        ]);

        abort_if($data['recipient_user_id'] === $request->user()->id, 422, 'No puedes compartir contigo mismo.');

        $isRecipientInFamily = $family->familyMembers()
            ->where('user_id', $data['recipient_user_id'])
            ->exists();
        abort_unless($isRecipientInFamily, 422, 'El usuario destino no pertenece a esta familia.');

        $alreadyPending = MemoryLeafShare::query()
            ->where('memory_leaf_id', $memoryLeaf->id)
            ->where('recipient_id', $data['recipient_user_id'])
            ->where('status', 'pending')
            ->exists();
        abort_if($alreadyPending, 422, 'Ya existe una solicitud pendiente para este usuario.');

        MemoryLeafShare::create([
            'memory_leaf_id' => $memoryLeaf->id,
            'sender_id' => $request->user()->id,
            'recipient_id' => $data['recipient_user_id'],
            'status' => 'pending',
        ]);

        return response()->json(['message' => 'Solicitud de compartido enviada.']);
    }

    public function pendingShares(Request $request, Family $family): JsonResponse
    {
        $this->assertMember($family, $request);

        $shares = MemoryLeafShare::query()
            ->where('recipient_id', $request->user()->id)
            ->where('status', 'pending')
            ->whereHas('memoryLeaf', fn ($q) => $q->where('family_id', $family->id))
            ->with(['sender', 'memoryLeaf'])
            ->latest()
            ->get()
            ->map(fn ($share) => [
                'id' => $share->id,
                'status' => $share->status,
                'created_at' => $share->created_at?->toISOString(),
                'sender' => [
                    'id' => $share->sender->id,
                    'name' => $share->sender->name,
                    'avatar_url' => $share->sender->avatar_url,
                ],
                'memory_leaf' => [
                    'id' => $share->memoryLeaf->id,
                    'full_name' => $share->memoryLeaf->full_name,
                    'surname' => $share->memoryLeaf->surname,
                    'avatar_url' => $share->memoryLeaf->avatar_url,
                ],
            ])
            ->values();

        return response()->json(['data' => $shares]);
    }

    public function acceptShare(Request $request, Family $family, MemoryLeafShare $share): JsonResponse
    {
        $this->assertMember($family, $request);
        abort_unless($share->recipient_id === $request->user()->id, 403);
        abort_unless($share->status === 'pending', 422, 'Esta solicitud ya fue procesada.');
        abort_unless($share->memoryLeaf && $share->memoryLeaf->family_id === $family->id, 404);

        DB::transaction(function () use ($request, $family, $share) {
            $sourceLeaf = $share->memoryLeaf()->with('memories')->firstOrFail();

            $copiedAvatar = $this->copyFileForLeaf($sourceLeaf->avatar, $family->id, 'avatars');
            $copiedLeaf = $family->memoryLeaves()->create([
                'first_name' => $sourceLeaf->first_name,
                'last_name' => $sourceLeaf->last_name,
                'surname' => $sourceLeaf->surname,
                'bio' => $sourceLeaf->bio,
                'birth_date' => $sourceLeaf->birth_date,
                'death_date' => $sourceLeaf->death_date,
                'avatar' => $copiedAvatar,
                'managed_by' => $request->user()->id,
            ]);

            foreach ($sourceLeaf->memories as $memory) {
                $sourcePaths = is_array($memory->media_paths) ? $memory->media_paths : [];
                if (count($sourcePaths) === 0 && $memory->file_path) {
                    $sourcePaths = [$memory->file_path];
                }

                $copiedPaths = [];
                foreach ($sourcePaths as $path) {
                    $copiedPath = $this->copyFileForLeaf($path, $copiedLeaf->id, 'memories');
                    if ($copiedPath) {
                        $copiedPaths[] = $copiedPath;
                    }
                }

                $copiedLeaf->memories()->create([
                    'contributed_by' => $memory->contributed_by,
                    'type' => $memory->type,
                    'title' => $memory->title,
                    'content' => $memory->content,
                    'caption' => $memory->caption,
                    'file_path' => $copiedPaths[0] ?? null,
                    'media_paths' => count($copiedPaths) > 0 ? $copiedPaths : null,
                ]);
            }

            $share->update([
                'status' => 'accepted',
                'responded_at' => now(),
                'copied_memory_leaf_id' => $copiedLeaf->id,
            ]);
        });

        return response()->json(['message' => 'Compartido aceptado correctamente.']);
    }

    public function rejectShare(Request $request, Family $family, MemoryLeafShare $share): JsonResponse
    {
        $this->assertMember($family, $request);
        abort_unless($share->recipient_id === $request->user()->id, 403);
        abort_unless($share->status === 'pending', 422, 'Esta solicitud ya fue procesada.');
        abort_unless($share->memoryLeaf && $share->memoryLeaf->family_id === $family->id, 404);

        $share->update([
            'status' => 'rejected',
            'responded_at' => now(),
        ]);

        return response()->json(['message' => 'Compartido rechazado.']);
    }

    private function assertMember(Family $family, Request $request): void
    {
        abort_unless(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            403
        );
    }

    private function assertManager(MemoryLeaf $leaf, Request $request): void
    {
        abort_unless(
            $leaf->managed_by === $request->user()->id,
            403
        );
    }

    private function copyFileForLeaf(?string $sourcePath, int $scopeId, string $segment): ?string
    {
        if (!$sourcePath) {
            return null;
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($sourcePath)) {
            return null;
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $target = sprintf('%s/%d/%s.%s', $segment, $scopeId, Str::uuid(), $extension ?: 'bin');
        $disk->copy($sourcePath, $target);

        return $target;
    }
}
