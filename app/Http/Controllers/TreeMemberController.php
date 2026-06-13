<?php

namespace App\Http\Controllers;

use App\Http\Resources\TreeMemberResource;
use App\Http\Resources\JourneyResource;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\Journey;
use App\Models\TreeMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Notifications\MemberAccountInvitationNotification;

class TreeMemberController extends Controller
{
    /** Return the full nested tree for a family */
    public function tree(Family $family): AnonymousResourceCollection
    {
        $this->authorizeFamily($family);
        $supportsPets = $this->supportsPetProfiles();

        // Of each couple (A ↔ B), exclude the one with the HIGHER id (the "secondary").
        // This avoids both members excluding each other when the link is bidirectional.
        $spouseIdsQuery = TreeMember::where('family_id', $family->id)
            ->whereNotNull('spouse_id')
            ->whereColumn('id', '<', 'spouse_id'); // only the primary (lower id) emits the exclusion

        if ($supportsPets) {
            $spouseIdsQuery->where('is_pet', false);
        }

        $spouseIds = $spouseIdsQuery->pluck('spouse_id');

        $rootsQuery = TreeMember::with([
            'spouse',
            'spouses',
            'children.spouse',
            'children.spouses',
            'children.children',
            'children.children.spouse',
            'children.children.spouses',
        ])
            ->where('family_id', $family->id)
            ->whereNull('parent_id')
            ->whereNotIn('invite_status', ['pending', 'rejected', 'cancelled'])
            ->whereNotIn('id', $spouseIds);

        if ($supportsPets) {
            $rootsQuery->where('is_pet', false);
        }

        $roots = $rootsQuery->get();

        return TreeMemberResource::collection($roots);
    }

    /** Return all members flat (for selects / lookups) */
    public function index(Request $request, Family $family): AnonymousResourceCollection
    {
        $this->authorizeFamily($family);
        $supportsPets = $this->supportsPetProfiles();
        $includeAssignablePets = $request->boolean('include_assignable_pets', false);

        $membersQuery = TreeMember::where('family_id', $family->id)
            ->whereNotIn('invite_status', ['pending', 'rejected', 'cancelled']);

        if ($supportsPets) {
            if ($includeAssignablePets) {
                $authUserId = $request->user()->id;

                $membersQuery->where(function ($query) use ($authUserId) {
                    $query->where('is_pet', false)
                        ->orWhere(function ($petQuery) use ($authUserId) {
                            $petQuery->where('is_pet', true)
                                ->where(function ($ownedPetQuery) use ($authUserId) {
                                    $ownedPetQuery->where('created_by', $authUserId)
                                        ->orWhere('user_id', $authUserId);
                                });
                        });
                });
            } else {
                $membersQuery->where('is_pet', false);
            }
        }

        $members = $membersQuery->get();

        return TreeMemberResource::collection($members);
    }

    /** Return pets for an owner profile in family context */
    public function pets(Request $request, Family $family): AnonymousResourceCollection
    {
        $this->authorizeFamily($family);
        if (!$this->supportsPetProfiles()) {
            return TreeMemberResource::collection(collect());
        }

        $data = $request->validate([
            'owner_tree_member_id' => 'nullable|integer|exists:tree_members,id',
            'owner_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $ownerMemberId = $data['owner_tree_member_id'] ?? null;

        if (!$ownerMemberId && !empty($data['owner_user_id'])) {
            $ownerMemberId = TreeMember::query()
                ->where('family_id', $family->id)
                ->where('is_pet', false)
                ->where('user_id', $data['owner_user_id'])
                ->value('id');
        }

        if (!$ownerMemberId) {
            return TreeMemberResource::collection(collect());
        }

        $owner = TreeMember::query()
            ->where('family_id', $family->id)
            ->where('is_pet', false)
            ->find($ownerMemberId);

        abort_if(!$owner, 422, 'El perfil dueño no es válido para mascotas.');

        $pets = TreeMember::query()
            ->where('family_id', $family->id)
            ->where('is_pet', true)
            ->where('owner_tree_member_id', $owner->id)
            ->whereNotIn('invite_status', ['pending', 'rejected', 'cancelled'])
            ->latest()
            ->get();

        return TreeMemberResource::collection($pets);
    }

    public function outgoingRequests(Request $request): JsonResponse
    {
        $requests = TreeMember::query()
            ->with('family:id,surname')
            ->where('created_by', $request->user()->id)
            ->whereNotNull('app_user_email')
            ->whereIn('invite_status', ['pending', 'accepted', 'rejected', 'cancelled'])
            ->latest()
            ->take(50)
            ->get();

        return response()->json([
            'data' => $requests->map(fn (TreeMember $member) => [
                'id' => $member->id,
                'family_id' => $member->family_id,
                'family_name' => '',
                'family_surname' => $member->family?->surname,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->app_user_email,
                'relationship' => $member->relationship,
                'invite_status' => $member->invite_status,
                'updated_at' => $member->updated_at?->toISOString(),
                'created_at' => $member->created_at?->toISOString(),
            ]),
        ]);
    }

    public function cancelOutgoingRequest(Request $request, Family $family, TreeMember $member): JsonResponse
    {
        $this->authorizeFamily($family);
        abort_if($member->family_id !== $family->id, 403);
        abort_if($member->created_by !== $request->user()->id, 403, 'Solo el solicitante puede cancelar.');
        abort_if($member->invite_status !== 'pending', 422, 'La solicitud ya no esta pendiente.');

        $member->update(['invite_status' => 'cancelled']);

        if ($member->app_user_email) {
            FamilyInvitation::query()
                ->where('family_id', $family->id)
                ->where('email', strtolower($member->app_user_email))
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->update(['status' => 'cancelled']);
        }

        return response()->json(['message' => 'Solicitud cancelada.']);
    }

    public function profile(Request $request, Family $family, TreeMember $member): JsonResponse
    {
        $this->authorizeFamily($family);
        abort_if($member->family_id !== $family->id, 403);
        $authUserId = $request->user()->id;
        $isOwnHumanProfile = !$member->is_pet && $member->user_id === $authUserId;

        $journeys = Journey::query()
            ->where('family_id', $family->id)
            ->where(function ($query) use ($member, $isOwnHumanProfile, $authUserId) {
                $query->where('tree_member_id', $member->id);

                if ($isOwnHumanProfile) {
                    $query->orWhere(function ($ownQuery) use ($authUserId) {
                        $ownQuery->whereNull('tree_member_id')
                            ->where('user_id', $authUserId);
                    });
                }
            })
            ->with('user')
            ->withCount('items')
            ->latest()
            ->get();

        return response()->json([
            'data' => [
                'member' => new TreeMemberResource($member->load('spouse')),
                'journeys' => JourneyResource::collection($journeys),
            ],
        ]);
    }

    public function sendAccountInvitation(Request $request, Family $family, TreeMember $member): JsonResponse
    {
        $this->authorizeFamily($family);
        abort_if($member->family_id !== $family->id, 403);
        abort_if($member->is_pet, 422, 'Las mascotas no tienen invitación de cuenta.');
        abort_if($member->user_id !== null, 422, 'Este miembro ya tiene una cuenta vinculada.');

        $data = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($data['email']));
        abort_if($email === '', 422, 'Debes ingresar un correo valido.');
        abort_if(User::query()->where('email', $email)->exists(), 422, 'Ese correo ya tiene cuenta. Usa la invitacion para cuenta existente.');

        $invitation = $this->createFamilyInvitationByEmail($family, $request->user()->id, $email);

        $member->update([
            'app_user_email' => $email,
            'invite_status' => 'pending',
        ]);

        Notification::route('mail', $email)
            ->notify(new MemberAccountInvitationNotification($invitation->token, $family, $member));

        return response()->json(['message' => 'Invitacion enviada al correo para crear la cuenta.']);
    }

    public function store(Request $request, Family $family): TreeMemberResource
    {
        $this->authorizeFamily($family);
        $supportsPets = $this->supportsPetProfiles();
        $isPet = $request->boolean('is_pet', false);

        $data = $request->validate([
            'first_name'   => $isPet
                ? 'required|string|max:100'
                : 'nullable|required_without_all:use_my_profile,has_app_user|string|max:100',
            'last_name'    => $isPet
                ? 'nullable|string|max:100'
                : 'nullable|required_without_all:use_my_profile,has_app_user|string|max:100',
            'relationship' => 'nullable|string|max:80',
            'gender'       => 'nullable|in:male,female,other',
            'is_pet'       => 'boolean',
            'owner_tree_member_id' => 'nullable|integer|exists:tree_members,id',
            'avatar'       => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'cover'        => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'parent_id'    => 'nullable|integer|exists:tree_members,id',
            'spouse_id'    => 'nullable|integer|exists:tree_members,id',
            'user_id'      => 'nullable|integer|exists:users,id',
            'birth_date'   => 'nullable|date',
            'death_date'   => 'nullable|date|after_or_equal:birth_date',
            'bio'          => 'nullable|string|max:1000',
            'is_deceased'  => 'boolean',
            'photos'       => 'nullable|array|max:8',
            'photos.*'     => 'file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'video'        => 'nullable|file|mimes:mp4,mov,webm|max:51200',
            'has_app_user' => 'boolean',
            'app_user_email' => 'nullable|email|max:255|required_if:has_app_user,1',
            'use_my_profile' => 'boolean',
            'create_account' => 'boolean',
            'account_email' => 'nullable|email|max:255|required_if:create_account,1',
            'account_password' => 'nullable|string|min:8|required_if:create_account,1',
            'send_invitation' => 'boolean',
        ]);

        $isPetFinal = $supportsPets ? (bool) ($data['is_pet'] ?? false) : false;
        $ownerMemberId = $data['owner_tree_member_id'] ?? null;

        if ($isPetFinal) {
            abort_if(!$ownerMemberId, 422, 'Debes seleccionar el perfil dueño de la mascota.');

            $owner = TreeMember::query()
                ->where('family_id', $family->id)
                ->where('is_pet', false)
                ->find($ownerMemberId);

            abort_if(!$owner, 422, 'El perfil dueño no es válido para mascotas.');
        }

        $useMyProfile = $isPetFinal ? false : (bool) ($data['use_my_profile'] ?? false);
        $selfUser = $useMyProfile ? $request->user() : null;

        $createdAccountUser = ($useMyProfile || $isPetFinal) ? null : $this->createAppAccountForMemberIfRequested($data);
        $invitee = ($isPetFinal ? null : ($selfUser ?? $createdAccountUser ?? $this->resolveInvitee($family, $data)));
        $this->ensureSingleLinkedMemberPerUser($family->id, $invitee?->id, null);

        [$selfFirstName, $selfLastName] = $selfUser ? $this->splitUserName($selfUser) : ['', ''];
        [$inviteeFirstName, $inviteeLastName] = $invitee ? $this->splitUserName($invitee) : ['', ''];
        $shouldUseInviteeProfile = !$useMyProfile && $invitee && !$createdAccountUser;
        $shouldLinkUserNow = $useMyProfile;
        $firstName = $useMyProfile ? $selfFirstName : (string) ($data['first_name'] ?? '');
        $lastName = $useMyProfile ? $selfLastName : (string) ($data['last_name'] ?? '');
        if ($shouldUseInviteeProfile) {
            $firstName = $inviteeFirstName;
            $lastName = $inviteeLastName;
        }

        $avatarPath = $this->storeMemberAvatar($request, $family);
        $coverPath = $this->storeMemberCover($request, $family);
        $avatarValue = $avatarPath
            ?? ($useMyProfile ? $selfUser?->avatar : null)
            ?? ($shouldUseInviteeProfile ? $invitee?->avatar : null);
        $photoPaths = $this->storeMemberPhotos($request, $family);
        $videoPath = $this->storeMemberVideo($request, $family);

        $memberData = [
            ...collect($data)->except([
                'first_name',
                'last_name',
                'avatar',
                'cover',
                'photos',
                'video',
                'has_app_user',
                'use_my_profile',
                'create_account',
                'account_email',
                'account_password',
                'send_invitation',
            ])->all(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'user_id'    => $isPetFinal ? $request->user()->id : ($shouldLinkUserNow ? ($invitee?->id ?? ($data['user_id'] ?? null)) : null),
            'spouse_id' => $isPetFinal ? null : ($data['spouse_id'] ?? null),
            'parent_id' => $isPetFinal ? null : ($data['parent_id'] ?? null),
            'app_user_email' => $isPetFinal ? null : ($invitee?->email ?? ($data['account_email'] ?? null) ?? ($data['app_user_email'] ?? null)),
            'avatar'     => $avatarValue,
            'cover'      => $coverPath,
            'media_photos' => $photoPaths,
            'media_video' => $videoPath,
            'family_id'  => $family->id,
            'created_by' => $request->user()->id,
        ];

        if ($supportsPets) {
            $memberData['is_pet'] = $isPetFinal;
            $memberData['owner_tree_member_id'] = $isPetFinal ? $ownerMemberId : null;
        }

        $member = TreeMember::create($memberData);

        if ($createdAccountUser && $avatarPath && !$createdAccountUser->avatar) {
            $createdAccountUser->update(['avatar' => $avatarPath]);
        }

        $shouldRequireAcceptance = !$isPetFinal && !$useMyProfile && (bool) $invitee;
        $inviteStatus = $shouldRequireAcceptance ? 'pending' : 'none';

        $member->update(['invite_status' => $inviteStatus]);

        if ($invitee && !$useMyProfile && !$isPetFinal) {
            $this->createFamilyInvitationIfNeeded($family, $request->user()->id, $invitee);
        }

        // If assigned as spouse, link back bidirectionally
        if (!$isPetFinal && !empty($data['spouse_id'])) {
            TreeMember::where('id', $data['spouse_id'])
                ->whereNull('spouse_id')
                ->update(['spouse_id' => $member->id]);
        }

        return new TreeMemberResource($member->load(['children', 'spouse']));
    }

    public function update(Request $request, Family $family, TreeMember $member): TreeMemberResource
    {
        $this->authorizeFamily($family);
        abort_if($member->family_id !== $family->id, 403);
        $supportsPets = $this->supportsPetProfiles();

        $data = $request->validate([
            'first_name'   => 'sometimes|nullable|string|max:100',
            'last_name'    => 'sometimes|nullable|string|max:100',
            'relationship' => 'nullable|string|max:80',
            'gender'       => 'nullable|in:male,female,other',
            'is_pet'       => 'boolean',
            'owner_tree_member_id' => 'nullable|integer|exists:tree_members,id',
            'avatar'       => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'cover'        => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'parent_id'    => 'nullable|integer|exists:tree_members,id',
            'spouse_id'    => 'nullable|integer|exists:tree_members,id',
            'user_id'      => 'nullable|integer|exists:users,id',
            'birth_date'   => 'nullable|date',
            'death_date'   => 'nullable|date',
            'bio'          => 'nullable|string|max:1000',
            'is_deceased'  => 'boolean',
            'photos'       => 'nullable|array|max:8',
            'photos.*'     => 'file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'video'        => 'nullable|file|mimes:mp4,mov,webm|max:51200',
            'clear_video'  => 'boolean',
            'has_app_user' => 'boolean',
            'app_user_email' => 'nullable|email|max:255',
            'use_my_profile' => 'boolean',
            'create_account' => 'boolean',
            'account_email' => 'nullable|email|max:255|required_if:create_account,1',
            'account_password' => 'nullable|string|min:8|required_if:create_account,1',
            'send_invitation' => 'boolean',
        ]);

        $isPet = $supportsPets ? (bool) ($data['is_pet'] ?? $member->is_pet) : false;
        $ownerMemberId = $data['owner_tree_member_id'] ?? $member->owner_tree_member_id;

        if ($isPet) {
            abort_if(!$ownerMemberId, 422, 'Debes seleccionar el perfil dueño de la mascota.');

            $owner = TreeMember::query()
                ->where('family_id', $family->id)
                ->where('is_pet', false)
                ->find($ownerMemberId);

            abort_if(!$owner, 422, 'El perfil dueño no es válido para mascotas.');
        }

        $useMyProfile = $isPet ? false : (bool) ($data['use_my_profile'] ?? false);
        $selfUser = $useMyProfile ? $request->user() : null;
        $createdAccountUser = ($useMyProfile || $isPet) ? null : $this->createAppAccountForMemberIfRequested($data);
        $invitee = ($isPet ? null : ($selfUser ?? $createdAccountUser ?? $this->resolveInvitee($family, $data)));
        $this->ensureSingleLinkedMemberPerUser($family->id, $invitee?->id, $member->id);
        $shouldUseInviteeProfile = !$useMyProfile && $invitee && !$createdAccountUser;
        $shouldLinkUserNow = $useMyProfile;

        $payload = collect($data)
            ->except([
                'avatar',
                'cover',
                'photos',
                'video',
                'clear_video',
                'has_app_user',
                'use_my_profile',
                'create_account',
                'account_email',
                'account_password',
                'send_invitation',
            ])
            ->all();

        if ($invitee) {
            $payload['user_id'] = $isPet ? null : ($shouldLinkUserNow ? $invitee->id : null);
            $payload['app_user_email'] = $isPet ? null : $invitee->email;
            $payload['invite_status'] = $isPet ? 'none' : ($shouldLinkUserNow ? 'accepted' : 'pending');

            if (!$useMyProfile && !$isPet) {
                $this->createFamilyInvitationIfNeeded($family, $request->user()->id, $invitee);
            }
        }

        if ($supportsPets && $isPet) {
            $payload['owner_tree_member_id'] = $ownerMemberId;
            $payload['user_id'] = null;
            $payload['app_user_email'] = null;
            $payload['invite_status'] = 'none';
            $payload['parent_id'] = null;
            $payload['spouse_id'] = null;
        } elseif ($supportsPets && array_key_exists('owner_tree_member_id', $data)) {
            $payload['owner_tree_member_id'] = null;
        }

        if ($useMyProfile && $selfUser) {
            [$firstName, $lastName] = $this->splitUserName($selfUser);
            $payload['first_name'] = $firstName;
            $payload['last_name'] = $lastName;
            $payload['invite_status'] = 'accepted';

            if (!$request->hasFile('avatar')) {
                $payload['avatar'] = $selfUser->avatar;
            }
        } elseif ($shouldUseInviteeProfile && $invitee) {
            [$firstName, $lastName] = $this->splitUserName($invitee);
            $payload['first_name'] = $firstName;
            $payload['last_name'] = $lastName;

            if (!$request->hasFile('avatar')) {
                $payload['avatar'] = $invitee->avatar;
            }
        }

        if ($request->hasFile('avatar')) {
            $this->deleteAvatar($member->avatar);
            $payload['avatar'] = $this->storeMemberAvatar($request, $family);
        }

        if ($request->hasFile('cover')) {
            $this->deleteCover($member->cover);
            $payload['cover'] = $this->storeMemberCover($request, $family);
        }

        if ($request->hasFile('photos')) {
            $this->deleteMediaPhotos($member->media_photos ?? []);
            $payload['media_photos'] = $this->storeMemberPhotos($request, $family);
        }

        if ($request->hasFile('video')) {
            $this->deleteMediaVideo($member->media_video);
            $payload['media_video'] = $this->storeMemberVideo($request, $family);
        } elseif (!empty($data['clear_video'])) {
            $this->deleteMediaVideo($member->media_video);
            $payload['media_video'] = null;
        }

        $oldSpouseId = $member->spouse_id;
        $member->update($payload);

        // Clear old back-link if spouse changed
        if ($oldSpouseId && $oldSpouseId !== ($data['spouse_id'] ?? null)) {
            TreeMember::where('id', $oldSpouseId)->update(['spouse_id' => null]);
        }

        // Set new back-link
        if (!empty($data['spouse_id'])) {
            TreeMember::where('id', $data['spouse_id'])
                ->update(['spouse_id' => $member->id]);
        }

        return new TreeMemberResource($member->load(['children', 'spouse']));
    }

    public function destroy(Family $family, TreeMember $member): JsonResponse
    {
        $this->authorizeFamily($family);
        abort_if($member->family_id !== $family->id, 403);

        if ($member->invite_status === 'pending' && $member->app_user_email) {
            FamilyInvitation::query()
                ->where('family_id', $family->id)
                ->where('email', strtolower($member->app_user_email))
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->update(['status' => 'cancelled']);
        }

        // Clear spouse link
        if ($member->spouse_id) {
            TreeMember::where('id', $member->spouse_id)->update(['spouse_id' => null]);
        }

        $this->deleteMediaPhotos($member->media_photos ?? []);
        $this->deleteMediaVideo($member->media_video);
        $this->deleteCover($member->cover);

        $member->delete();

        return response()->json(['message' => 'Member removed.']);
    }

    /** Link the authenticated user to a TreeMember ("This is me"). */
    public function claimAsMe(Request $request, Family $family, TreeMember $member): TreeMemberResource
    {
        $this->authorizeFamily($family);
        abort_if($member->family_id !== $family->id, 403);
        abort_if($this->supportsPetProfiles() && $member->is_pet, 422, 'Las mascotas no se vinculan a cuentas de usuario.');
        abort_if(
            $member->user_id !== null && $member->user_id !== $request->user()->id,
            422,
            'Este perfil ya está vinculado a otro usuario.'
        );

        $alreadyClaimed = TreeMember::where('family_id', $family->id)
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $member->id)
            ->exists();

        abort_if($alreadyClaimed, 422, 'Ya tienes un perfil vinculado en este árbol familiar.');

        $member->update(['user_id' => $request->user()->id]);

        return new TreeMemberResource($member->fresh()->load(['children', 'spouse']));
    }

    /** Unlink the authenticated user from a TreeMember. */
    public function unclaimMe(Request $request, Family $family, TreeMember $member): TreeMemberResource
    {
        $this->authorizeFamily($family);
        abort_if($member->family_id !== $family->id, 403);
        abort_if($this->supportsPetProfiles() && $member->is_pet, 422, 'Las mascotas no se vinculan a cuentas de usuario.');
        abort_if(
            $member->user_id !== $request->user()->id,
            403,
            'No puedes desvincular un perfil que no te corresponde.'
        );

        $member->update(['user_id' => null]);

        return new TreeMemberResource($member->fresh()->load(['children', 'spouse']));
    }

    private function authorizeFamily(Family $family): void
    {
        abort_unless(
            $family->familyMembers()->where('user_id', auth()->id())->exists(),
            403,
            'No tienes acceso a esta familia'
        );
    }

    private function supportsPetProfiles(): bool
    {
        return Schema::hasColumn('tree_members', 'is_pet')
            && Schema::hasColumn('tree_members', 'owner_tree_member_id');
    }

    private function storeMemberPhotos(Request $request, Family $family): array
    {
        if (!$request->hasFile('photos')) {
            return [];
        }

        $paths = [];
        foreach ($request->file('photos') as $photoFile) {
            $paths[] = $photoFile->store("tree-members/{$family->id}/photos", 'public');
        }

        return $paths;
    }

    private function storeMemberAvatar(Request $request, Family $family): ?string
    {
        if (!$request->hasFile('avatar')) {
            return null;
        }

        return $request->file('avatar')->store("tree-members/{$family->id}/avatars", 'public');
    }

    private function storeMemberCover(Request $request, Family $family): ?string
    {
        if (!$request->hasFile('cover')) {
            return null;
        }

        return $request->file('cover')->store("tree-members/{$family->id}/covers", 'public');
    }

    private function storeMemberVideo(Request $request, Family $family): ?string
    {
        if (!$request->hasFile('video')) {
            return null;
        }

        return $request->file('video')->store("tree-members/{$family->id}/videos", 'public');
    }

    private function deleteMediaPhotos(array $paths): void
    {
        if (empty($paths)) {
            return;
        }

        Storage::disk('public')->delete($paths);
    }

    private function deleteMediaVideo(?string $path): void
    {
        if (!$path) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function deleteAvatar(?string $path): void
    {
        if (!$path || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function deleteCover(?string $path): void
    {
        if (!$path || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function createAppAccountForMemberIfRequested(array $data): ?User
    {
        $createAccount = (bool) ($data['create_account'] ?? false);
        $email = strtolower(trim((string) ($data['account_email'] ?? '')));

        if (!$createAccount) {
            return null;
        }

        abort_if($email === '', 422, 'Debes indicar un correo para crear la cuenta.');
        abort_if(
            User::where('email', $email)->exists(),
            422,
            'Ya existe un usuario registrado con ese correo. Usa la opción de cuenta existente.'
        );

        $password = (string) ($data['account_password'] ?? '');
        abort_if($password === '', 422, 'Debes indicar una contraseña para crear la cuenta.');

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $name = trim($firstName . ' ' . $lastName);

        return User::create([
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }

    private function splitUserName(User $user): array
    {
        $clean = trim((string) $user->name);
        if ($clean === '') {
            return ['Mi', 'Perfil'];
        }

        $parts = preg_split('/\s+/', $clean) ?: [];
        $firstName = array_shift($parts) ?? 'Mi';
        $lastName = count($parts) > 0 ? implode(' ', $parts) : 'Perfil';

        return [$firstName, $lastName];
    }

    private function ensureSingleLinkedMemberPerUser(int $familyId, ?int $userId, ?int $exceptMemberId): void
    {
        if (!$userId) {
            return;
        }

        $query = TreeMember::query()
            ->where('family_id', $familyId)
            ->where('user_id', $userId);

        if ($exceptMemberId) {
            $query->where('id', '!=', $exceptMemberId);
        }

        abort_if(
            $query->exists(),
            422,
            'Ese usuario ya está vinculado a otro miembro del árbol en esta familia.'
        );
    }

    private function resolveInvitee(Family $family, array $data): ?User
    {
        $hasAppUser = (bool) ($data['has_app_user'] ?? false);
        $email = strtolower(trim((string) ($data['app_user_email'] ?? '')));

        if (!$hasAppUser || $email === '') {
            return null;
        }

        $invitee = User::where('email', $email)->first();
        abort_unless($invitee, 422, 'No existe un usuario registrado con ese correo.');

        return $invitee;
    }

    private function createFamilyInvitationIfNeeded(Family $family, int $inviterId, User $invitee): void
    {
        $email = strtolower($invitee->email);

        $pendingExists = FamilyInvitation::where('family_id', $family->id)
            ->where('email', $email)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->exists();

        if ($pendingExists) {
            return;
        }

        FamilyInvitation::create([
            'family_id'  => $family->id,
            'invited_by' => $inviterId,
            'email'      => $email,
            'token'      => Str::random(64),
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function createFamilyInvitationByEmail(Family $family, int $inviterId, string $email): FamilyInvitation
    {
        $pending = FamilyInvitation::query()
            ->where('family_id', $family->id)
            ->where('email', $email)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($pending) {
            return $pending;
        }

        return FamilyInvitation::create([
            'family_id' => $family->id,
            'invited_by' => $inviterId,
            'email' => $email,
            'token' => Str::random(64),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
    }
}
