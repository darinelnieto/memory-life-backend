<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\Post;
use App\Models\User;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    private function transformProfileMediaPost(Post $post): array
    {
        return [
            'id' => $post->id,
            'type' => $post->type,
            'url' => $post->media_url,
            'urls' => $post->media_urls,
            'content' => $post->content,
            'created_at' => $post->created_at?->toISOString(),
            'likes_count' => (int) ($post->likes_count ?? 0),
            'comments_count' => (int) ($post->comments_count ?? 0),
            'reposts_count' => (int) ($post->reposts_count ?? 0),
        ];
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new ProfileResource($request->user())]);
    }

    public function showByUser(Request $request, Family $family, User $user): JsonResponse
    {
        abort_unless(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            403,
            'No tienes acceso a esta familia'
        );

        abort_unless(
            $family->familyMembers()->where('user_id', $user->id)->exists(),
            404,
            'El usuario no pertenece a esta familia'
        );

        return response()->json(['data' => new ProfileResource($user)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'username'   => ['sometimes', 'string', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'bio'        => 'sometimes|nullable|string|max:1000',
            'birth_date' => 'sometimes|nullable|date|before:today',
            'phone'      => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'location'   => 'sometimes|nullable|string|max:120',
            'gender'     => ['sometimes', 'nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
        ]);

        $user->update($validated);

        return response()->json(['data' => new ProfileResource($user->fresh())]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
        ], [
            'avatar.max' => 'La foto de perfil no puede superar los 10 MB.',
        ]);

        $user = $request->user();

        if ($user->avatar && !str_starts_with($user->avatar, 'http')) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store("avatars/{$user->id}", 'public');
        $user->update(['avatar' => $path]);

        return response()->json(['data' => new ProfileResource($user->fresh())]);
    }

    public function uploadCover(Request $request): JsonResponse
    {
        $request->validate([
            'cover' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:20480',
        ]);

        $user = $request->user();

        if ($user->cover_photo) {
            Storage::disk('public')->delete($user->cover_photo);
        }

        $path = $request->file('cover')->store("covers/{$user->id}", 'public');
        $user->update(['cover_photo' => $path]);

        return response()->json(['data' => new ProfileResource($user->fresh())]);
    }

    public function media(Request $request, Family $family): JsonResponse
    {
        abort_unless(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            403,
            'No tienes acceso a esta familia'
        );

        $posts = Post::query()
            ->where('family_id', $family->id)
            ->where('user_id', $request->user()->id)
            ->whereNotNull('media_path')
            ->whereIn('type', ['photo', 'video'])
            ->where('show_on_profile', true)
            ->withCount(['likes', 'comments', 'reposts'])
            ->latest()
            ->get();

        return response()->json([
            'data' => [
                'photos' => $posts->where('type', 'photo')->values()->map(fn (Post $post) => $this->transformProfileMediaPost($post))->all(),
                'videos' => $posts->where('type', 'video')->values()->map(fn (Post $post) => $this->transformProfileMediaPost($post))->all(),
            ],
        ]);
    }

    public function mediaByUser(Request $request, Family $family, User $user): JsonResponse
    {
        abort_unless(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            403,
            'No tienes acceso a esta familia'
        );

        abort_unless(
            $family->familyMembers()->where('user_id', $user->id)->exists(),
            404,
            'El usuario no pertenece a esta familia'
        );

        $posts = Post::query()
            ->where('family_id', $family->id)
            ->where('user_id', $user->id)
            ->whereNotNull('media_path')
            ->whereIn('type', ['photo', 'video'])
            ->where('show_on_profile', true)
            ->withCount(['likes', 'comments', 'reposts'])
            ->latest()
            ->get();

        return response()->json([
            'data' => [
                'photos' => $posts->where('type', 'photo')->values()->map(fn (Post $post) => $this->transformProfileMediaPost($post))->all(),
                'videos' => $posts->where('type', 'video')->values()->map(fn (Post $post) => $this->transformProfileMediaPost($post))->all(),
            ],
        ]);
    }
}
