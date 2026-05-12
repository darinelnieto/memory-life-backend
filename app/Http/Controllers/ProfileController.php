<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new ProfileResource($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'username'   => ['sometimes', 'string', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'bio'        => 'sometimes|nullable|string|max:1000',
            'birth_date' => 'sometimes|nullable|date|before:today',
            'phone'      => 'sometimes|nullable|string|max:30',
            'location'   => 'sometimes|nullable|string|max:120',
            'gender'     => ['sometimes', 'nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
        ]);

        $user->update($validated);

        return response()->json(['data' => new ProfileResource($user->fresh())]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
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
            'cover' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $user = $request->user();

        if ($user->cover_photo) {
            Storage::disk('public')->delete($user->cover_photo);
        }

        $path = $request->file('cover')->store("covers/{$user->id}", 'public');
        $user->update(['cover_photo' => $path]);

        return response()->json(['data' => new ProfileResource($user->fresh())]);
    }
}
