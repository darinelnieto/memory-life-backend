<?php

namespace App\Http\Controllers;

use App\Http\Resources\PostCommentResource;
use App\Http\Resources\PostResource;
use App\Models\Family;
use App\Models\Post;
use App\Models\PostComment;
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
            ->with([
                'user',
                'likes' => fn ($q) => $q->where('user_id', $request->user()->id),
                'reposts' => fn ($q) => $q->where('user_id', $request->user()->id),
                'latestRepost.user',
            ])
            ->withCount(['likes', 'comments', 'reposts'])
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
            'media'     => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov|max:51200|required_if:type,photo,video',
            'allow_comments' => 'sometimes|boolean',
            'allow_likes' => 'sometimes|boolean',
            'allow_reposts' => 'sometimes|boolean',
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
            'allow_comments' => $data['allow_comments'] ?? true,
            'allow_likes' => $data['allow_likes'] ?? true,
            'allow_reposts' => $data['allow_reposts'] ?? true,
        ]);

        return new PostResource($post->load('user'));
    }

    public function toggleLike(Request $request, Family $family, Post $post): JsonResponse
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);

        if (!$post->allow_likes) {
            return response()->json(['message' => 'El autor desactivó los likes para esta publicación.'], 422);
        }

        $existing = $post->likes()->where('user_id', $request->user()->id)->first();
        if ($existing) {
            $existing->delete();
        } else {
            $post->likes()->create(['user_id' => $request->user()->id]);
        }

        return response()->json([
            'liked' => !$existing,
            'likes_count' => $post->likes()->count(),
        ]);
    }

    public function likes(Request $request, Family $family, Post $post): JsonResponse
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);

        $users = $post->likes()
            ->with('user')
            ->latest()
            ->get()
            ->map(fn ($like) => [
                'id' => $like->user->id,
                'name' => $like->user->name,
                'avatar_url' => $like->user->avatar_url,
            ])
            ->values();

        return response()->json(['data' => $users]);
    }

    public function comments(Request $request, Family $family, Post $post): AnonymousResourceCollection
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);

        $comments = $post->comments()
            ->with('user')
            ->latest()
            ->paginate(10);

        return PostCommentResource::collection($comments);
    }

    public function addComment(Request $request, Family $family, Post $post): PostCommentResource|JsonResponse
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);

        if (!$post->allow_comments) {
            return response()->json(['message' => 'El autor desactivó los comentarios para esta publicación.'], 422);
        }

        $data = $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'content' => $data['content'],
        ]);

        return new PostCommentResource($comment->load('user'));
    }

    public function updateComment(Request $request, Family $family, Post $post, PostComment $comment): PostCommentResource|JsonResponse
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);
        $this->assertCommentBelongsToPost($comment, $post);

        abort_unless($comment->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $comment->update(['content' => $data['content']]);

        return new PostCommentResource($comment->load('user'));
    }

    public function deleteComment(Request $request, Family $family, Post $post, PostComment $comment): JsonResponse
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);
        $this->assertCommentBelongsToPost($comment, $post);

        abort_unless(
            $comment->user_id === $request->user()->id || $post->user_id === $request->user()->id,
            403
        );

        $comment->delete();

        return response()->json(['message' => 'Comentario eliminado']);
    }

    public function toggleRepost(Request $request, Family $family, Post $post): JsonResponse
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);

        if (!$post->allow_reposts) {
            return response()->json(['message' => 'El autor desactivó los reposts para esta publicación.'], 422);
        }

        $existing = $post->reposts()->where('user_id', $request->user()->id)->first();
        if ($existing) {
            $existing->delete();
        } else {
            $post->reposts()->create(['user_id' => $request->user()->id]);
        }

        return response()->json([
            'reposted' => !$existing,
            'reposts_count' => $post->reposts()->count(),
        ]);
    }

    public function reposts(Request $request, Family $family, Post $post): JsonResponse
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);

        $users = $post->reposts()
            ->with('user')
            ->latest()
            ->get()
            ->map(fn ($repost) => [
                'id' => $repost->user->id,
                'name' => $repost->user->name,
                'avatar_url' => $repost->user->avatar_url,
            ])
            ->values();

        return response()->json(['data' => $users]);
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

    private function assertPostBelongsToFamily(Post $post, Family $family): void
    {
        abort_unless($post->family_id === $family->id, 404);
    }

    private function assertCommentBelongsToPost(PostComment $comment, Post $post): void
    {
        abort_unless($comment->post_id === $post->id, 404);
    }
}
