<?php

namespace App\Http\Controllers;

use App\Http\Resources\TreeMemberResource;
use App\Models\Family;
use App\Models\TreeMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TreeMemberController extends Controller
{
    /** Return the full nested tree for a family */
    public function tree(Family $family): AnonymousResourceCollection
    {
        $this->authorizeFamily($family);

        // Of each couple (A ↔ B), exclude the one with the HIGHER id (the "secondary").
        // This avoids both members excluding each other when the link is bidirectional.
        $spouseIds = TreeMember::where('family_id', $family->id)
            ->whereNotNull('spouse_id')
            ->whereColumn('id', '<', 'spouse_id')   // only the primary (lower id) emits the exclusion
            ->pluck('spouse_id');

        $roots = TreeMember::with(['children.spouse', 'children.children', 'spouse'])
            ->where('family_id', $family->id)
            ->whereNull('parent_id')
            ->whereNotIn('id', $spouseIds)
            ->get();

        return TreeMemberResource::collection($roots);
    }

    /** Return all members flat (for selects / lookups) */
    public function index(Family $family): AnonymousResourceCollection
    {
        $this->authorizeFamily($family);

        $members = TreeMember::where('family_id', $family->id)->get();

        return TreeMemberResource::collection($members);
    }

    public function store(Request $request, Family $family): TreeMemberResource
    {
        $this->authorizeFamily($family);

        $data = $request->validate([
            'first_name'   => 'required|string|max:100',
            'last_name'    => 'required|string|max:100',
            'relationship' => 'nullable|string|max:80',
            'gender'       => 'nullable|in:male,female,other',
            'parent_id'    => 'nullable|integer|exists:tree_members,id',
            'spouse_id'    => 'nullable|integer|exists:tree_members,id',
            'user_id'      => 'nullable|integer|exists:users,id',
            'birth_date'   => 'nullable|date',
            'death_date'   => 'nullable|date|after_or_equal:birth_date',
            'bio'          => 'nullable|string|max:1000',
            'is_deceased'  => 'boolean',
        ]);

        $member = TreeMember::create([
            ...$data,
            'family_id'  => $family->id,
            'created_by' => $request->user()->id,
        ]);

        // If assigned as spouse, link back bidirectionally
        if (!empty($data['spouse_id'])) {
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

        $data = $request->validate([
            'first_name'   => 'sometimes|string|max:100',
            'last_name'    => 'sometimes|string|max:100',
            'relationship' => 'nullable|string|max:80',
            'gender'       => 'nullable|in:male,female,other',
            'parent_id'    => 'nullable|integer|exists:tree_members,id',
            'spouse_id'    => 'nullable|integer|exists:tree_members,id',
            'user_id'      => 'nullable|integer|exists:users,id',
            'birth_date'   => 'nullable|date',
            'death_date'   => 'nullable|date',
            'bio'          => 'nullable|string|max:1000',
            'is_deceased'  => 'boolean',
        ]);

        $oldSpouseId = $member->spouse_id;
        $member->update($data);

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

        // Clear spouse link
        if ($member->spouse_id) {
            TreeMember::where('id', $member->spouse_id)->update(['spouse_id' => null]);
        }

        $member->delete();

        return response()->json(['message' => 'Member removed.']);
    }

    /** Link the authenticated user to a TreeMember ("This is me"). */
    public function claimAsMe(Request $request, Family $family, TreeMember $member): TreeMemberResource
    {
        $this->authorizeFamily($family);
        abort_if($member->family_id !== $family->id, 403);
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
}
