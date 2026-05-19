<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FamilyInvitationController extends Controller
{
    /** List all invitations for a family (owner/admin only). */
    public function index(Request $request, Family $family): JsonResponse
    {
        $this->authorizeManager($family, $request);

        $invitations = FamilyInvitation::where('family_id', $family->id)
            ->with('inviter:id,name,email')
            ->latest()
            ->get();

        return response()->json(['data' => $invitations]);
    }

    /** Send an invitation to an existing user by email. */
    public function invite(Request $request, Family $family): JsonResponse
    {
        $this->authorizeManager($family, $request);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($validated['email']));

        $invitee = User::where('email', $email)->first();
        abort_unless($invitee, 422, 'No existe un usuario registrado con ese correo.');
        abort_if($invitee->id === $request->user()->id, 422, 'No puedes invitarte a ti mismo.');
        abort_if(
            $family->familyMembers()->where('user_id', $invitee->id)->exists(),
            422,
            'Ese usuario ya pertenece a esta familia.'
        );
        abort_if(
            FamilyInvitation::where('family_id', $family->id)
                ->where('email', $email)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->exists(),
            422,
            'Ya existe una invitación pendiente para ese correo.'
        );

        $invitation = FamilyInvitation::create([
            'family_id'  => $family->id,
            'invited_by' => $request->user()->id,
            'email'      => $email,
            'token'      => Str::random(64),
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'message' => 'Invitación enviada.',
            'data'    => $invitation->load('inviter:id,name,email'),
        ], 201);
    }

    /** Cancel an invitation (owner/admin). */
    public function cancel(Request $request, Family $family, FamilyInvitation $invitation): JsonResponse
    {
        $this->authorizeManager($family, $request);
        abort_if($invitation->family_id !== $family->id, 403);

        $invitation->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Invitación cancelada.']);
    }

    /** List pending invitations for the authenticated user. */
    public function myInvitations(Request $request): JsonResponse
    {
        $invitations = FamilyInvitation::where('email', strtolower($request->user()->email))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with(['family:id,name,surname', 'inviter:id,name,email'])
            ->get();

        return response()->json(['data' => $invitations]);
    }

    /** Accept an invitation. */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->findValidInvitation($token, $request->user()->email);

        $family = $invitation->family;

        abort_if(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            422,
            'Ya eres miembro de esta familia.'
        );

        $family->members()->attach($request->user()->id, [
            'role'      => 'member',
            'joined_at' => now(),
        ]);

        $invitation->update(['status' => 'accepted']);

        return response()->json([
            'message' => 'Te has unido a la familia.',
            'family'  => [
                'id'      => $family->id,
                'name'    => $family->name,
                'surname' => $family->surname,
            ],
        ]);
    }

    /** Reject an invitation. */
    public function reject(Request $request, string $token): JsonResponse
    {
        $invitation = $this->findValidInvitation($token, $request->user()->email);
        $invitation->update(['status' => 'rejected']);

        return response()->json(['message' => 'Invitación rechazada.']);
    }

    private function findValidInvitation(string $token, string $email): FamilyInvitation
    {
        $invitation = FamilyInvitation::with('family')->where('token', $token)->first();

        abort_unless($invitation, 404, 'Invitación no encontrada.');
        abort_unless(
            strtolower($invitation->email) === strtolower($email),
            403,
            'Esta invitación no te corresponde.'
        );
        abort_unless($invitation->status === 'pending', 422, 'Esta invitación ya fue procesada.');
        abort_unless($invitation->expires_at->isFuture(), 422, 'Esta invitación ha expirado.');

        return $invitation;
    }

    private function authorizeManager(Family $family, Request $request): void
    {
        abort_unless(
            $family->familyMembers()
                ->where('user_id', $request->user()->id)
                ->whereIn('role', ['owner', 'admin'])
                ->exists(),
            403,
            'Solo un administrador de la familia puede realizar esta acción.'
        );
    }
}
