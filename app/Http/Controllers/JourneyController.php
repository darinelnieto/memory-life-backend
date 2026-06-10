<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\Journey;
use App\Http\Resources\JourneyResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JourneyController extends Controller
{
    public function index(Family $family)
    {
        $journeys = $family->journeys()
            ->with('user')
            ->withCount('items')
            ->latest()
            ->get();

        return JourneyResource::collection($journeys);
    }

    public function store(Request $request, Family $family)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'cover'       => 'nullable|image|max:5120',
            'tree_member_id' => 'nullable|integer|exists:tree_members,id',
        ]);

        if (!empty($data['tree_member_id'])) {
            $belongsToFamily = $family->treeMembers()
                ->where('id', $data['tree_member_id'])
                ->exists();

            abort_unless($belongsToFamily, 422, 'El miembro no pertenece a esta familia.');
        }

        $coverPath = null;
        if ($request->hasFile('cover')) {
            $coverPath = $request->file('cover')->store(
                "journeys/{$family->id}/covers",
                'public'
            );
        }

        $journey = $family->journeys()->create([
            'user_id'     => $request->user()->id,
            'tree_member_id' => $data['tree_member_id'] ?? null,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'cover_path'  => $coverPath,
        ]);

        $journey->load('user');

        return new JourneyResource($journey);
    }

    public function show(Family $family, Journey $journey)
    {
        $journey->load([
            'user',
            'items' => fn ($q) => $q->latest()->with([
                'sourcePost' => fn ($pq) => $pq->with('user')->withCount(['likes', 'comments', 'reposts']),
            ]),
        ]);
        return new JourneyResource($journey);
    }

    public function destroy(Family $family, Journey $journey)
    {
        abort_unless($journey->family_id === $family->id, 404);
        abort_unless($journey->user_id === request()->user()->id, 403, 'Solo quien creo el journey puede eliminarlo.');

        if ($journey->cover_path) {
            Storage::disk('public')->delete($journey->cover_path);
        }
        $journey->delete();
        return response()->noContent();
    }
}
