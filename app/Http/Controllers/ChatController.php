<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function contacts(Request $request, Family $family): JsonResponse
    {
        $user = $request->user();
        $this->authorizeFamilyMember($family, $user->id);

        $contacts = $family->members()
            ->where('users.id', '!=', $user->id)
            ->get(['users.id', 'users.name', 'users.username', 'users.email', 'users.avatar'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'username' => $member->username,
                'email' => $member->email,
                'avatar_url' => $member->avatar_url,
                'role' => $member->pivot?->role,
            ])
            ->values();

        return response()->json(['data' => $contacts]);
    }

    public function conversation(Request $request, Family $family, User $member): JsonResponse
    {
        $user = $request->user();
        $this->authorizeConversation($family, $user->id, $member->id);

        $messages = ChatMessage::query()
            ->with(['sender', 'recipient'])
            ->where('family_id', $family->id)
            ->where(function ($query) use ($user, $member) {
                $query->where('sender_id', $user->id)->where('recipient_id', $member->id)
                    ->orWhere(function ($inner) use ($user, $member) {
                        $inner->where('sender_id', $member->id)->where('recipient_id', $user->id);
                    });
            })
            ->orderBy('created_at')
            ->get();

        ChatMessage::query()
            ->where('family_id', $family->id)
            ->where('sender_id', $member->id)
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ChatMessageResource::collection($messages)]);
    }

    public function store(Request $request, Family $family, User $member): JsonResponse
    {
        $user = $request->user();
        $this->authorizeConversation($family, $user->id, $member->id);

        abort_if($user->id === $member->id, 422, 'No puedes enviarte mensajes a ti mismo.');

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $message = ChatMessage::create([
            'family_id' => $family->id,
            'sender_id' => $user->id,
            'recipient_id' => $member->id,
            'message' => $validated['message'],
        ]);

        $message->load(['sender', 'recipient']);

        return response()->json(['data' => new ChatMessageResource($message)], 201);
    }

    private function authorizeConversation(Family $family, int $userId, int $memberId): void
    {
        $this->authorizeFamilyMember($family, $userId);
        $this->authorizeFamilyMember($family, $memberId);
    }

    private function authorizeFamilyMember(Family $family, int $userId): void
    {
        abort_unless(
            $family->familyMembers()->where('user_id', $userId)->exists(),
            403,
            'No tienes acceso a esta familia'
        );
    }
}
