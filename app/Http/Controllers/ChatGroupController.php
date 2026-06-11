<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatGroupMessageResource;
use App\Models\ChatGroup;
use App\Models\ChatGroupMember;
use App\Models\ChatGroupMessage;
use App\Models\ChatGroupMessageView;
use App\Models\ChatGroupMessageUserHidden;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatGroupController extends Controller
{
    public function index(Request $request, Family $family): JsonResponse
    {
        $user = $request->user();
        $this->authorizeFamilyMember($family, $user->id);

        $groups = ChatGroup::query()
            ->where('family_id', $family->id)
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('members')
            ->with([
                'members.user:id,name,username,email,avatar',
                'messages' => fn ($q) => $q->latest()->limit(1)->with('sender:id,name,username,email,avatar'),
            ])
            ->latest()
            ->get()
            ->map(function (ChatGroup $group) use ($family, $user) {
                $lastMessage = $group->messages->first();
                $myMember = $group->members->firstWhere('user_id', $user->id);
                $lastReadAt = $myMember?->last_read_at;

                $unreadQuery = ChatGroupMessage::query()
                    ->where('chat_group_id', $group->id)
                    ->where('sender_id', '!=', $user->id)
                    ->whereDoesntHave('hiddenForUsers', fn ($query) => $query->where('user_id', $user->id));

                if ($lastReadAt) {
                    $unreadQuery->where('created_at', '>', $lastReadAt);
                }

                $unreadCount = $unreadQuery->count();
                $memberIds = $group->members->pluck('user_id')->all();
                $isTyping = $this->groupHasTyping($family->id, $group->id, $user->id, $memberIds);

                return [
                    'id' => $group->id,
                    'family_id' => $group->family_id,
                    'name' => $group->name,
                    'can_manage' => (int) $group->created_by === (int) $user->id,
                    'members_count' => (int) $group->members_count,
                    'unread_count' => (int) $unreadCount,
                    'is_typing' => $isTyping,
                    'members' => $group->members->map(fn ($member) => [
                        'id' => $member->user->id,
                        'name' => $member->user->name,
                        'username' => $member->user->username,
                        'email' => $member->user->email,
                        'avatar_url' => $member->user->avatar_url,
                    ])->values(),
                    'last_message' => $lastMessage ? [
                        'id' => $lastMessage->id,
                        'message' => $lastMessage->message,
                        'created_at' => $lastMessage->created_at?->toISOString(),
                        'sender' => [
                            'id' => $lastMessage->sender->id,
                            'name' => $lastMessage->sender->name,
                            'username' => $lastMessage->sender->username,
                            'avatar_url' => $lastMessage->sender->avatar_url,
                        ],
                    ] : null,
                ];
            })
            ->values();

        return response()->json([
            'data' => $groups,
            'meta' => [
                'total_unread' => (int) $groups->sum('unread_count'),
            ],
        ]);
    }

    public function store(Request $request, Family $family): JsonResponse
    {
        $user = $request->user();
        $this->authorizeFamilyMember($family, $user->id);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'member_ids' => 'sometimes|array',
            'member_ids.*' => 'integer|distinct',
        ]);

        $requestedMemberIds = collect($validated['member_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        $familyMemberIds = $family->familyMembers()->pluck('user_id');
        $invalidIds = $requestedMemberIds->diff($familyMemberIds);

        if ($invalidIds->isNotEmpty()) {
            return response()->json([
                'message' => 'Solo puedes agregar miembros que pertenezcan a la familia.',
                'invalid_member_ids' => $invalidIds->values(),
            ], 422);
        }

        $finalMemberIds = $requestedMemberIds
            ->push($user->id)
            ->unique()
            ->values();

        if ($finalMemberIds->count() < 2) {
            return response()->json([
                'message' => 'Un chat grupal requiere al menos 2 miembros de la familia.',
            ], 422);
        }

        $group = DB::transaction(function () use ($family, $user, $validated, $finalMemberIds) {
            $group = ChatGroup::create([
                'family_id' => $family->id,
                'created_by' => $user->id,
                'name' => $validated['name'],
            ]);

            foreach ($finalMemberIds as $memberId) {
                ChatGroupMember::create([
                    'chat_group_id' => $group->id,
                    'user_id' => $memberId,
                    'added_by' => $user->id,
                    'joined_at' => now(),
                    'last_read_at' => now(),
                ]);
            }

            return $group;
        });

        $group->load(['members.user:id,name,username,email,avatar']);

        return response()->json([
            'data' => $this->serializeGroup($group, $user->id),
        ], 201);
    }

    public function update(Request $request, Family $family, ChatGroup $group): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);
        $this->authorizeGroupManagement($group, $user->id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'member_ids' => 'sometimes|array|min:1',
            'member_ids.*' => 'integer|distinct',
        ]);

        if (!array_key_exists('name', $validated) && !array_key_exists('member_ids', $validated)) {
            return response()->json([
                'message' => 'Debes enviar un nuevo nombre o miembros del grupo.',
            ], 422);
        }

        DB::transaction(function () use ($family, $group, $user, $validated) {
            if (array_key_exists('name', $validated)) {
                $group->name = $validated['name'];
                $group->save();
            }

            if (!array_key_exists('member_ids', $validated)) {
                return;
            }

            $requestedMemberIds = collect($validated['member_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values();

            $familyMemberIds = $family->familyMembers()->pluck('user_id');
            $invalidIds = $requestedMemberIds->diff($familyMemberIds);

            if ($invalidIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'member_ids' => ['Solo puedes agregar miembros que pertenezcan a la familia.'],
                ]);
            }

            $finalMemberIds = $requestedMemberIds
                ->push($user->id)
                ->unique()
                ->values();

            if ($finalMemberIds->count() < 2) {
                throw ValidationException::withMessages([
                    'member_ids' => ['Un chat grupal requiere al menos 2 miembros de la familia.'],
                ]);
            }

            $currentMemberIds = $group->members()->pluck('user_id');
            $memberIdsToRemove = $currentMemberIds->diff($finalMemberIds)->values();
            $memberIdsToAdd = $finalMemberIds->diff($currentMemberIds)->values();

            if ($memberIdsToRemove->isNotEmpty()) {
                $group->members()->whereIn('user_id', $memberIdsToRemove)->delete();
            }

            foreach ($memberIdsToAdd as $memberId) {
                ChatGroupMember::create([
                    'chat_group_id' => $group->id,
                    'user_id' => (int) $memberId,
                    'added_by' => $user->id,
                    'joined_at' => now(),
                    'last_read_at' => now(),
                ]);
            }
        });

        $group->load(['members.user:id,name,username,email,avatar']);

        return response()->json([
            'data' => $this->serializeGroup($group, $user->id),
        ]);
    }

    public function destroy(Request $request, Family $family, ChatGroup $group): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);
        $this->authorizeGroupManagement($group, $user->id);

        DB::transaction(function () use ($group) {
            $group->messages()->delete();
            $group->members()->delete();
            $group->delete();
        });

        return response()->json([
            'message' => 'Grupo eliminado correctamente.',
        ]);
    }

    public function leave(Request $request, Family $family, ChatGroup $group): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);

        if ((int) $group->created_by === (int) $user->id) {
            return response()->json([
                'message' => 'El creador no puede salir del grupo. Puede editar miembros o eliminar el grupo.',
            ], 422);
        }

        $group->members()->where('user_id', $user->id)->delete();
        Cache::forget($this->groupTypingKey($family->id, $group->id, $user->id));

        return response()->json([
            'message' => 'Saliste del grupo correctamente.',
        ]);
    }

    public function messages(Request $request, Family $family, ChatGroup $group): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);

        $messages = ChatGroupMessage::query()
            ->where('chat_group_id', $group->id)
            ->with(['sender:id,name,username,email,avatar', 'replyTo.sender:id,name,username,email,avatar'])
            ->with(['views' => fn ($query) => $query->where('user_id', $user->id)])
            ->whereDoesntHave('hiddenForUsers', fn ($query) => $query->where('user_id', $user->id))
            ->orderBy('created_at')
            ->get();

        $group->members()
            ->where('user_id', $user->id)
            ->update(['last_read_at' => now()]);

        $typingUsers = $this->groupTypingUsers($family->id, $group->id, $user->id, $group->members()->pluck('user_id')->all());

        return response()->json([
            'data' => ChatGroupMessageResource::collection($messages),
            'meta' => [
                'group_is_typing' => !empty($typingUsers),
                'typing_users' => $typingUsers,
            ],
        ]);
    }

    public function send(Request $request, Family $family, ChatGroup $group): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);

        $validated = $request->validate([
            'message' => 'nullable|string|max:2000|required_without:media',
            'media' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm|max:20480|required_without:message',
            'is_temporary' => 'sometimes|boolean',
            'is_view_once' => 'sometimes|boolean',
            'reply_to_message_id' => 'nullable|integer|exists:chat_group_messages,id',
        ]);

        $replyToId = $validated['reply_to_message_id'] ?? null;
        if ($replyToId) {
            $replyMessage = ChatGroupMessage::query()->find($replyToId);
            abort_unless($replyMessage && (int) $replyMessage->chat_group_id === (int) $group->id, 422, 'El mensaje al que respondes no pertenece a este grupo.');
        }

        $mediaPath = $request->hasFile('media')
            ? $this->storeGroupMedia($request->file('media'), $family->id, $group->id, $user->id)
            : null;

        $mediaType = $request->hasFile('media')
            ? $this->resolveMediaType($request->file('media'))
            : null;

        $isTemporary = (bool) ($validated['is_temporary'] ?? false);
        $isViewOnce = (bool) ($validated['is_view_once'] ?? false);

        abort_if(!$mediaPath && !filled($validated['message'] ?? null), 422, 'Debes enviar texto o un archivo.');

        $message = ChatGroupMessage::create([
            'chat_group_id' => $group->id,
            'sender_id' => $user->id,
            'reply_to_message_id' => $replyToId,
            'message' => (string) ($validated['message'] ?? ''),
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'is_temporary' => $isTemporary,
            'is_view_once' => $isViewOnce,
            'expires_at' => $isTemporary ? now()->addDay() : null,
        ]);

        $message->load([
            'sender:id,name,username,email,avatar',
            'replyTo.sender:id,name,username,email,avatar',
            'views' => fn ($query) => $query->where('user_id', $user->id),
        ]);

        Cache::forget($this->groupTypingKey($family->id, $group->id, $user->id));

        return response()->json([
            'data' => new ChatGroupMessageResource($message),
        ], 201);
    }

    public function markViewed(Request $request, Family $family, ChatGroup $group, ChatGroupMessage $message): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);
        abort_unless((int) $message->chat_group_id === (int) $group->id, 404);

        if ($message->is_view_once && (int) $message->sender_id !== (int) $user->id) {
            ChatGroupMessageView::firstOrCreate(
                [
                    'chat_group_message_id' => $message->id,
                    'user_id' => $user->id,
                ],
                [
                    'viewed_at' => now(),
                ]
            );
        }

        $message->load([
            'sender:id,name,username,email,avatar',
            'views' => fn ($query) => $query->where('user_id', $user->id),
        ]);

        return response()->json(['data' => new ChatGroupMessageResource($message)]);
    }

    public function updateMessage(Request $request, Family $family, ChatGroup $group, ChatGroupMessage $message): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);
        abort_unless((int) $message->chat_group_id === (int) $group->id, 404);
        abort_unless((int) $message->sender_id === (int) $user->id, 403, 'Solo puedes editar mensajes enviados por ti.');

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $message->update([
            'message' => $validated['message'],
            'edited_at' => now(),
        ]);

        $message->load([
            'sender:id,name,username,email,avatar',
            'replyTo.sender:id,name,username,email,avatar',
            'views' => fn ($query) => $query->where('user_id', $user->id),
        ]);

        return response()->json(['data' => new ChatGroupMessageResource($message)]);
    }

    public function destroyMessage(Request $request, Family $family, ChatGroup $group, ChatGroupMessage $message): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);
        abort_unless((int) $message->chat_group_id === (int) $group->id, 404);

        if ((int) $message->sender_id !== (int) $user->id) {
            ChatGroupMessageUserHidden::firstOrCreate(
                [
                    'chat_group_message_id' => $message->id,
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

        if (filled($message->media_path)) {
            Storage::disk('public')->delete($message->media_path);
        }

        $message->views()->delete();
        $message->delete();

        return response()->json([
            'message' => 'Mensaje eliminado correctamente.',
        ]);
    }

    public function typing(Request $request, Family $family, ChatGroup $group): JsonResponse
    {
        $user = $request->user();
        $this->authorizeGroupAccess($family, $group, $user->id);

        $validated = $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        $key = $this->groupTypingKey($family->id, $group->id, $user->id);

        if ($validated['is_typing']) {
            Cache::put($key, true, now()->addSeconds(6));
        } else {
            Cache::forget($key);
        }

        return response()->json(['ok' => true]);
    }

    private function groupTypingKey(int $familyId, int $groupId, int $userId): string
    {
        return "chat_group_typing:{$familyId}:{$groupId}:{$userId}";
    }

    private function groupHasTyping(int $familyId, int $groupId, int $viewerId, array $memberIds): bool
    {
        foreach ($memberIds as $memberId) {
            if ($memberId === $viewerId) continue;
            if (Cache::has($this->groupTypingKey($familyId, $groupId, (int) $memberId))) {
                return true;
            }
        }

        return false;
    }

    private function groupTypingUsers(int $familyId, int $groupId, int $viewerId, array $memberIds): array
    {
        $typingIds = [];

        foreach ($memberIds as $memberId) {
            if ((int) $memberId === $viewerId) continue;
            if (Cache::has($this->groupTypingKey($familyId, $groupId, (int) $memberId))) {
                $typingIds[] = (int) $memberId;
            }
        }

        if (empty($typingIds)) {
            return [];
        }

        return User::query()
            ->whereIn('id', $typingIds)
            ->get(['id', 'name', 'username', 'avatar'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatar_url,
            ])
            ->values()
            ->all();
    }

    private function authorizeGroupAccess(Family $family, ChatGroup $group, int $userId): void
    {
        $this->authorizeFamilyMember($family, $userId);

        abort_unless($group->family_id === $family->id, 404);

        abort_unless(
            $group->members()->where('user_id', $userId)->exists(),
            403,
            'No tienes acceso a este chat grupal.'
        );
    }

    private function authorizeGroupManagement(ChatGroup $group, int $userId): void
    {
        abort_unless(
            (int) $group->created_by === $userId,
            403,
            'Solo el creador puede editar o eliminar este chat grupal.'
        );
    }

    private function serializeGroup(ChatGroup $group, ?int $viewerId = null): array
    {
        return [
            'id' => $group->id,
            'family_id' => $group->family_id,
            'name' => $group->name,
            'can_manage' => $viewerId !== null ? ((int) $group->created_by === (int) $viewerId) : false,
            'members_count' => $group->members->count(),
            'members' => $group->members->map(fn ($member) => [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'username' => $member->user->username,
                'email' => $member->user->email,
                'avatar_url' => $member->user->avatar_url,
            ])->values(),
        ];
    }

    private function authorizeFamilyMember(Family $family, int $userId): void
    {
        abort_unless(
            $family->familyMembers()->where('user_id', $userId)->exists(),
            403,
            'No tienes acceso a esta familia'
        );
    }

    private function storeGroupMedia(UploadedFile $file, int $familyId, int $groupId, int $userId): string
    {
        return $file->store("chat/{$familyId}/groups/{$groupId}/{$userId}", 'public');
    }

    private function resolveMediaType(UploadedFile $file): string
    {
        return str_starts_with($file->getMimeType() ?? '', 'video/') ? 'video' : 'image';
    }
}
