<?php

namespace App\Http\Controllers;

use App\Http\Resources\PostResource;
use App\Models\Family;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function index(Request $request, Family $family): AnonymousResourceCollection
    {
        $this->assertMember($family, $request);

        $posts = $family->posts()
            ->with('user')
            ->latest()
            ->paginate(20);

        return PostResource::collection($posts);
    }

    public function store(Request $request, Family $family): PostResource
    {
        $this->assertMember($family, $request);

        $data = $request->validate([
            'content'   => 'nullable|string|max:2000',
            'type'      => 'required|in:text,photo,video',
            'media'     => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov|max:51200',
        ]);

        $mediaPath = null;
        if ($request->hasFile('media')) {
            $mediaPath = $request->file('media')->store("posts/{$family->id}", 'public');
        }

        $post = $family->posts()->create([
            'user_id'    => $request->user()->id,
            'content'    => $data['content'] ?? null,
            'type'       => $data['type'],
            'media_path' => $mediaPath,
        ]);

        return new PostResource($post->load('user'));
    }

    public function destroy(Request $request, Family $family, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id || $family->owner_id === $request->user()->id, 403);
        if ($post->media_path) Storage::disk('public')->delete($post->media_path);
        $post->delete();

        return response()->json(['message' => 'Post eliminado']);
    }

    private function assertMember(Family $family, Request $request): void
    {
        abort_unless(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            403,
            'No tienes acceso a esta familia'
        );
    }
}
