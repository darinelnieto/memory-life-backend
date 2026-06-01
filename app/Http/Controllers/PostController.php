<?php

namespace App\Http\Controllers;

use App\Http\Resources\PostCommentResource;
use App\Http\Resources\PostResource;
use App\Models\Family;
use App\Models\Post;
use App\Models\PostComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
            'allow_comments' => 'sometimes|boolean',
            'allow_likes' => 'sometimes|boolean',
            'allow_reposts' => 'sometimes|boolean',
        ]);

        $mediaFiles = $this->extractMediaFiles($request);
        if (in_array($data['type'], ['photo', 'video'], true) && count($mediaFiles) === 0) {
            throw ValidationException::withMessages([
                'media' => ['Debes adjuntar un archivo para este tipo de publicacion.'],
            ]);
        }

        if ($data['type'] === 'video' && count($mediaFiles) > 1) {
            throw ValidationException::withMessages([
                'media' => ['Solo se permite un video por publicacion.'],
            ]);
        }

        $mediaPaths = [];
        foreach ($mediaFiles as $file) {
            $mediaPaths[] = $file->store("posts/{$family->id}", 'public');
        }

        $mediaPath = $mediaPaths[0] ?? null;

        $post = $family->posts()->create([
            'user_id'    => $request->user()->id,
            'content'    => $data['content'] ?? null,
            'type'       => $data['type'],
            'media_path' => $mediaPath,
            'media_paths' => count($mediaPaths) > 0 ? $mediaPaths : null,
            'allow_comments' => $data['allow_comments'] ?? true,
            'allow_likes' => $data['allow_likes'] ?? true,
            'allow_reposts' => $data['allow_reposts'] ?? true,
        ]);

        return new PostResource($post->load('user'));
    }

    public function update(Request $request, Family $family, Post $post): PostResource
    {
        $this->assertMember($family, $request);
        $this->assertPostBelongsToFamily($post, $family);
        abort_unless($post->user_id === $request->user()->id || $family->owner_id === $request->user()->id, 403);

        $data = $request->validate([
            'content' => 'nullable|string|max:2000',
            'allow_comments' => 'sometimes|boolean',
            'allow_likes' => 'sometimes|boolean',
            'allow_reposts' => 'sometimes|boolean',
        ]);

        $updateData = [];
        if (array_key_exists('content', $data)) {
            $updateData['content'] = $data['content'];
        }
        if (array_key_exists('allow_comments', $data)) {
            $updateData['allow_comments'] = $data['allow_comments'];
        }
        if (array_key_exists('allow_likes', $data)) {
            $updateData['allow_likes'] = $data['allow_likes'];
        }
        if (array_key_exists('allow_reposts', $data)) {
            $updateData['allow_reposts'] = $data['allow_reposts'];
        }

        if (count($updateData) > 0) {
            $post->update($updateData);
        }

        $post->load([
            'user',
            'likes' => fn ($q) => $q->where('user_id', $request->user()->id),
            'reposts' => fn ($q) => $q->where('user_id', $request->user()->id),
            'latestRepost.user',
        ])->loadCount(['likes', 'comments', 'reposts']);

        return new PostResource($post);
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

        $paths = is_array($post->media_paths) ? $post->media_paths : [];
        if (count($paths) === 0 && $post->media_path) {
            $paths = [$post->media_path];
        }

        if (count($paths) > 0) {
            Storage::disk('public')->delete($paths);
        }

        $post->delete();

        return response()->json(['message' => 'Post eliminado']);
    }

    /**
     * @return UploadedFile[]
     */
    private function extractMediaFiles(Request $request): array
    {
        if (!$request->hasFile('media')) {
            return [];
        }

        $raw = $request->file('media');
        $files = $raw instanceof UploadedFile ? [$raw] : (is_array($raw) ? $raw : []);

        $validated = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $validator = validator(
                ['file' => $file],
                ['file' => 'file|mimes:jpg,jpeg,png,webp,mp4,mov|max:51200']
            );

            if ($validator->fails()) {
                throw ValidationException::withMessages([
                    'media' => $validator->errors()->all(),
                ]);
            }

            $validated[] = $file;
        }

        return $validated;
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
