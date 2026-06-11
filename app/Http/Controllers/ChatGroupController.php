<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatGroupMessageResource;
use App\Models\ChatGroup;
use App\Models\ChatGroupMember;
use App\Models\ChatGroupMessage;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
                    ->where('sender_id', '!=', $user->id);

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
            ->with('sender:id,name,username,email,avatar')
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
            'message' => 'required|string|max:2000',
        ]);

        $message = ChatGroupMessage::create([
            'chat_group_id' => $group->id,
            'sender_id' => $user->id,
            'message' => $validated['message'],
        ]);

        $message->load('sender:id,name,username,email,avatar');

        Cache::forget($this->groupTypingKey($family->id, $group->id, $user->id));

        return response()->json([
            'data' => new ChatGroupMessageResource($message),
        ], 201);
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
}
