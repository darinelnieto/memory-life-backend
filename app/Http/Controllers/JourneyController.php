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
        ]);

        $coverPath = null;
        if ($request->hasFile('cover')) {
            $coverPath = $request->file('cover')->store(
                "journeys/{$family->id}/covers",
                'public'
            );
        }

        $journey = $family->journeys()->create([
            'user_id'     => $request->user()->id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'cover_path'  => $coverPath,
        ]);

        $journey->load('user');

        return new JourneyResource($journey);
    }

    public function show(Family $family, Journey $journey)
    {
        $journey->load('user', 'items');
        return new JourneyResource($journey);
    }

    public function destroy(Family $family, Journey $journey)
    {
        if ($journey->cover_path) {
            Storage::disk('public')->delete($journey->cover_path);
        }
        $journey->delete();
        return response()->noContent();
    }
}
