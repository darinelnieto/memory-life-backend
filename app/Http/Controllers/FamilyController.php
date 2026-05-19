<?php

namespace App\Http\Controllers;

use App\Http\Resources\FamilyResource;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FamilyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $families = $request->user()
            ->families()
            ->withCount('familyMembers')
            ->get();

        return FamilyResource::collection($families);
    }

    public function store(Request $request): FamilyResource
    {
        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'surname' => 'required|string|max:100',
        ]);

        $family = Family::create([
            ...$data,
            'owner_id' => $request->user()->id,
        ]);

        $family->members()->attach($request->user()->id, [
            'role'      => 'owner',
            'joined_at' => now(),
        ]);

        return new FamilyResource($family->load('familyMembers'));
    }

    public function show(Family $family): FamilyResource
    {
        $this->authorizeFamily($family);

        return new FamilyResource($family->load('familyMembers'));
    }

    public function update(Request $request, Family $family): FamilyResource
    {
        $this->authorizeOwner($family, $request);

        $data = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'surname' => 'sometimes|string|max:100',
        ]);

        $family->update($data);

        return new FamilyResource($family->load('familyMembers'));
    }

    public function addMember(Request $request, Family $family): JsonResponse
    {
        $this->authorizeManager($family, $request);

        $validated = $request->validate([
            'identifier' => 'required|string|max:255',
        ]);

        $member = User::query()
            ->where('email', $validated['identifier'])
            ->orWhere('username', $validated['identifier'])
            ->first();

        abort_unless($member, 404, 'No existe un usuario con ese correo o nombre de usuario.');
        abort_if($member->id === $request->user()->id, 422, 'Tu usuario ya pertenece a esta familia.');
        abort_if(
            $family->familyMembers()->where('user_id', $member->id)->exists(),
            422,
            'Ese usuario ya pertenece a esta familia.'
        );

        $family->members()->attach($member->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        return response()->json([
            'message' => 'Miembro agregado a la familia.',
            'data' => new FamilyResource($family->loadCount('familyMembers')->load('familyMembers')),
        ], 201);
    }

    public function destroy(Request $request, Family $family): JsonResponse
    {
        $this->authorizeOwner($family, $request);
        $family->delete();

        return response()->json(['message' => 'Familia eliminada']);
    }

    private function authorizeFamily(Family $family): void
    {
        $userId = auth()->id();
        abort_unless(
            $family->familyMembers()->where('user_id', $userId)->exists(),
            403,
            'No tienes acceso a esta familia'
        );
    }

    private function authorizeOwner(Family $family, Request $request): void
    {
        abort_unless(
            $family->owner_id === $request->user()->id,
            403,
            'Solo el propietario puede realizar esta acción'
        );
    }

    private function authorizeManager(Family $family, Request $request): void
    {
        abort_unless(
            $family->familyMembers()
                ->where('user_id', $request->user()->id)
                ->whereIn('role', ['owner', 'admin'])
                ->exists(),
            403,
            'Solo un administrador de la familia puede realizar esta acción'
        );
    }
}
