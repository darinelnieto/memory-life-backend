<?php

namespace App\Http\Controllers;

use App\Http\Resources\MemoryLeafResource;
use App\Models\Family;
use App\Models\MemoryLeaf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class MemoryLeafController extends Controller
{
    public function index(Request $request, Family $family): AnonymousResourceCollection
    {
        $this->assertMember($family, $request);

        $leaves = $family->memoryLeaves()
            ->withCount('memories')
            ->latest()
            ->get();

        return MemoryLeafResource::collection($leaves);
    }

    public function store(Request $request, Family $family): MemoryLeafResource
    {
        $this->assertMember($family, $request);

        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'surname'    => 'required|string|max:100',
            'bio'        => 'nullable|string|max:1000',
            'birth_date' => 'nullable|date',
            'death_date' => 'nullable|date',
            'avatar'     => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
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
        $this->assertManager($memoryLeaf, $request);
        $request->validate(['avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120']);

        if ($memoryLeaf->avatar) Storage::disk('public')->delete($memoryLeaf->avatar);
        $path = $request->file('avatar')->store("memory-leaves/{$family->id}", 'public');
        $memoryLeaf->update(['avatar' => $path]);

        return new MemoryLeafResource($memoryLeaf->fresh());
    }

    public function destroy(Request $request, Family $family, MemoryLeaf $memoryLeaf): JsonResponse
    {
        abort_unless($family->owner_id === $request->user()->id, 403);
        if ($memoryLeaf->avatar) Storage::disk('public')->delete($memoryLeaf->avatar);
        $memoryLeaf->delete();

        return response()->json(['message' => 'Memory Leaf eliminado']);
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
            $leaf->managed_by === $request->user()->id || auth()->user()->hasRole('super_admin'),
            403
        );
    }
}
