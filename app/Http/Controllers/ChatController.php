<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\ChatMessageUserHidden;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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
            ->whereDoesntHave('hiddenForUsers', fn ($query) => $query->where('user_id', $user->id))
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
            ->with(['sender', 'recipient', 'replyTo.sender'])
            ->where('family_id', $family->id)
            ->where(function ($query) use ($user, $member) {
                $query->where('sender_id', $user->id)->where('recipient_id', $member->id)
                    ->orWhere(function ($inner) use ($user, $member) {
                        $inner->where('sender_id', $member->id)->where('recipient_id', $user->id);
                    });
            })
            ->whereDoesntHave('hiddenForUsers', fn ($query) => $query->where('user_id', $user->id))
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
            'message' => 'nullable|string|max:2000|required_without:media',
            'media' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm|max:20480|required_without:message',
            'is_temporary' => 'sometimes|boolean',
            'is_view_once' => 'sometimes|boolean',
            'reply_to_message_id' => 'nullable|integer|exists:chat_messages,id',
        ]);

        $replyToId = $validated['reply_to_message_id'] ?? null;
        if ($replyToId) {
            $replyMessage = ChatMessage::query()->find($replyToId);
            $isBetweenUsers = $replyMessage
                && (
                    ((int) $replyMessage->sender_id === (int) $member->id && (int) $replyMessage->recipient_id === (int) $user->id)
                    || ((int) $replyMessage->sender_id === (int) $user->id && (int) $replyMessage->recipient_id === (int) $member->id)
                );

            abort_unless($isBetweenUsers && (int) $replyMessage->family_id === (int) $family->id, 422, 'El mensaje al que respondes no pertenece a esta conversacion.');
        }

        $mediaPath = $request->hasFile('media')
            ? $this->storeDirectMedia($request->file('media'), $family->id, $user->id)
            : null;

        $mediaType = $request->hasFile('media')
            ? $this->resolveMediaType($request->file('media'))
            : null;

        $isTemporary = (bool) ($validated['is_temporary'] ?? false);
        $isViewOnce = (bool) ($validated['is_view_once'] ?? false);

        abort_if(!$mediaPath && !filled($validated['message'] ?? null), 422, 'Debes enviar texto o un archivo.');

        $message = ChatMessage::create([
            'family_id' => $family->id,
            'sender_id' => $user->id,
            'recipient_id' => $member->id,
            'reply_to_message_id' => $replyToId,
            'message' => (string) ($validated['message'] ?? ''),
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'is_temporary' => $isTemporary,
            'is_view_once' => $isViewOnce,
            'expires_at' => $isTemporary ? now()->addDay() : null,
        ]);

        $message->load(['sender', 'recipient', 'replyTo.sender']);

        Cache::forget($this->typingKey($family->id, $user->id, $member->id));

        return response()->json(['data' => new ChatMessageResource($message)], 201);
    }

    public function markViewed(Request $request, Family $family, User $member, ChatMessage $chatMessage): JsonResponse
    {
        $user = $request->user();
        $this->authorizeConversation($family, $user->id, $member->id);

        $isBetweenUsers =
            ((int) $chatMessage->sender_id === (int) $member->id && (int) $chatMessage->recipient_id === (int) $user->id)
            || ((int) $chatMessage->sender_id === (int) $user->id && (int) $chatMessage->recipient_id === (int) $member->id);

        abort_unless($isBetweenUsers && (int) $chatMessage->family_id === (int) $family->id, 404);

        if ($chatMessage->is_view_once && (int) $chatMessage->sender_id !== (int) $user->id && !$chatMessage->viewed_at) {
            $chatMessage->update(['viewed_at' => now()]);
            $chatMessage->refresh();
        }

        return response()->json(['data' => new ChatMessageResource($chatMessage)]);
    }

    public function update(Request $request, Family $family, User $member, ChatMessage $chatMessage): JsonResponse
    {
        $user = $request->user();
        $this->authorizeConversation($family, $user->id, $member->id);

        $isBetweenUsers =
            ((int) $chatMessage->sender_id === (int) $member->id && (int) $chatMessage->recipient_id === (int) $user->id)
            || ((int) $chatMessage->sender_id === (int) $user->id && (int) $chatMessage->recipient_id === (int) $member->id);

        abort_unless($isBetweenUsers && (int) $chatMessage->family_id === (int) $family->id, 404);
        abort_unless((int) $chatMessage->sender_id === (int) $user->id, 403, 'Solo puedes editar mensajes enviados por ti.');

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $chatMessage->update([
            'message' => $validated['message'],
            'edited_at' => now(),
        ]);

        $chatMessage->load(['sender', 'recipient', 'replyTo.sender']);

        return response()->json(['data' => new ChatMessageResource($chatMessage)]);
    }

    public function destroy(Request $request, Family $family, User $member, ChatMessage $chatMessage): JsonResponse
    {
        $user = $request->user();
        $this->authorizeConversation($family, $user->id, $member->id);

        $isBetweenUsers =
            ((int) $chatMessage->sender_id === (int) $member->id && (int) $chatMessage->recipient_id === (int) $user->id)
            || ((int) $chatMessage->sender_id === (int) $user->id && (int) $chatMessage->recipient_id === (int) $member->id);

        abort_unless($isBetweenUsers && (int) $chatMessage->family_id === (int) $family->id, 404);

        if ((int) $chatMessage->sender_id !== (int) $user->id) {
            ChatMessageUserHidden::firstOrCreate(
                [
                    'chat_message_id' => $chatMessage->id,
                    'user_id' => $user->id,
                ],
                [
                    'hidden_at' => now(),
                ]
            );

            return response()->json([
                'message' => 'Mensaje ocultado para ti.',
            ]);
        }

        if (filled($chatMessage->media_path)) {
            Storage::disk('public')->delete($chatMessage->media_path);
        }

        $chatMessage->delete();

        return response()->json([
            'message' => 'Mensaje eliminado correctamente.',
        ]);
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

    private function storeDirectMedia(UploadedFile $file, int $familyId, int $userId): string
    {
        return $file->store("chat/{$familyId}/direct/{$userId}", 'public');
    }

    private function resolveMediaType(UploadedFile $file): string
    {
        return str_starts_with($file->getMimeType() ?? '', 'video/') ? 'video' : 'image';
    }
}
