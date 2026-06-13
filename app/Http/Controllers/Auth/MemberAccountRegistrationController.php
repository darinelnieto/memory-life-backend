<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\FamilyInvitation;
use App\Models\TreeMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Permission\Models\Role;

class MemberAccountRegistrationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        [$invitation, $member] = $this->resolveInvitationAndMember($token);

        return response()->json([
            'data' => [
                'token' => $invitation->token,
                'email' => $invitation->email,
                'family' => [
                    'id' => $invitation->family_id,
                    'name' => '',
                    'surname' => $invitation->family?->surname,
                ],
                'member' => [
                    'id' => $member->id,
                    'first_name' => $member->first_name,
                    'last_name' => $member->last_name,
                    'full_name' => trim($member->first_name . ' ' . $member->last_name),
                ],
            ],
        ]);
    }

    public function register(Request $request, string $token): JsonResponse
    {
        [$invitation, $member] = $this->resolveInvitationAndMember($token);

        $data = $request->validate([
            'username' => 'nullable|string|max:50|alpha_dash|unique:users,username',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:30|unique:users,phone',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $email = strtolower(trim($data['email']));
        $username = trim((string) ($data['username'] ?? ''));
        $phone = trim((string) $data['phone']);
        abort_if($email !== strtolower($invitation->email), 422, 'El correo debe coincidir con el de la invitacion.');
        abort_if(User::query()->where('email', $email)->exists(), 422, 'Ya existe una cuenta con ese correo.');

        $user = User::query()->create([
            'name' => trim($member->first_name . ' ' . $member->last_name),
            'username' => $username !== '' ? $username : null,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($data['password']),
        ]);

        Role::findOrCreate('free');
        $user->assignRole('free');

        $alreadyInFamily = $invitation->family
            ->familyMembers()
            ->where('user_id', $user->id)
            ->exists();

        if (!$alreadyInFamily) {
            $invitation->family->members()->attach($user->id, [
                'role' => 'member',
                'joined_at' => now(),
            ]);
        }

        TreeMember::query()
            ->where('family_id', $invitation->family_id)
            ->whereNull('user_id')
            ->whereRaw('LOWER(app_user_email) = ?', [strtolower($invitation->email)])
            ->update([
                'user_id' => $user->id,
                'invite_status' => 'accepted',
            ]);

        $invitation->update(['status' => 'accepted']);

        $tokenValue = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'message' => 'Cuenta creada y vinculada correctamente.',
            'token' => $tokenValue,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'roles' => $user->getRoleNames(),
            ],
            'family' => [
                'id' => $invitation->family_id,
                'name' => '',
                'surname' => $invitation->family?->surname,
            ],
        ], 201);
    }

    private function resolveInvitationAndMember(string $token): array
    {
        $invitation = FamilyInvitation::query()
            ->with('family')
            ->where('token', $token)
            ->first();

        abort_unless($invitation, 404, 'Invitacion no encontrada.');
        abort_unless($invitation->status === 'pending', 422, 'Esta invitacion ya fue procesada.');
        abort_unless($invitation->expires_at->isFuture(), 422, 'Esta invitacion ha expirado.');

        $member = TreeMember::query()
            ->where('family_id', $invitation->family_id)
            ->whereNull('user_id')
            ->whereRaw('LOWER(app_user_email) = ?', [strtolower($invitation->email)])
            ->where('invite_status', 'pending')
            ->latest()
            ->first();

        abort_unless($member, 404, 'No hay un miembro pendiente para esta invitacion.');

        return [$invitation, $member];
    }
}
