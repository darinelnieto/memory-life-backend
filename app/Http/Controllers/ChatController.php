<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChatController extends Controller
{
    public function contacts(Request $request, Family $family): JsonResponse
    {
        $user = $request->user();
        $this->authorizeFamilyMember($family, $user->id);

        $unreadBySender = ChatMessage::query()
            ->where('family_id', $family->id)
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->selectRaw('sender_id, COUNT(*) as total')
            ->groupBy('sender_id')
            ->pluck('total', 'sender_id');

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
                'is_typing' => Cache::has($this->typingKey($family->id, $member->id, $user->id)),
                'unread_count' => (int) ($unreadBySender[$member->id] ?? 0),
            ])
            ->values();

        return response()->json([
            'data' => $contacts,
            'meta' => [
                'total_unread' => (int) $unreadBySender->sum(),
            ],
        ]);
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

        $isTyping = Cache::has($this->typingKey($family->id, $member->id, $user->id));

        return response()->json([
            'data' => ChatMessageResource::collection($messages),
            'meta' => [
                'contact_is_typing' => $isTyping,
            ],
        ]);
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

        Cache::forget($this->typingKey($family->id, $user->id, $member->id));

        return response()->json(['data' => new ChatMessageResource($message)], 201);
    }

    public function typing(Request $request, Family $family, User $member): JsonResponse
    {
        $user = $request->user();
        $this->authorizeConversation($family, $user->id, $member->id);

        abort_if($user->id === $member->id, 422, 'No puedes enviarte estado de escritura a ti mismo.');

        $validated = $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        $key = $this->typingKey($family->id, $user->id, $member->id);

        if ($validated['is_typing']) {
            Cache::put($key, true, now()->addSeconds(6));
        } else {
            Cache::forget($key);
        }

        return response()->json(['ok' => true]);
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

    private function typingKey(int $familyId, int $fromUserId, int $toUserId): string
    {
        return "chat_typing:{$familyId}:{$fromUserId}:{$toUserId}";
    }
}
