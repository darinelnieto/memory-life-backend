<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\Journey;
use App\Models\JourneyItem;
use App\Http\Resources\JourneyItemResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JourneyItemController extends Controller
{
    public function store(Request $request, Family $family, Journey $journey)
    {
        $data = $request->validate([
            'type'           => 'required|in:text,photo,video,voice,audio',
            'content'        => 'nullable|string',
            'file'           => 'nullable|file|max:51200',
            'caption'        => 'nullable|string|max:500',
            'sort_order'     => 'nullable|integer',
            'source_post_id' => 'nullable|integer|exists:posts,id',
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store(
                "journeys/{$journey->id}/items",
                'public'
            );
        }

        $item = $journey->items()->create([
            'type'           => $data['type'],
            'content'        => $data['content'] ?? null,
            'file_path'      => $filePath,
            'caption'        => $data['caption'] ?? null,
            'sort_order'     => $data['sort_order'] ?? 0,
            'source_post_id' => $data['source_post_id'] ?? null,
        ]);

        return new JourneyItemResource($item);
    }

    public function destroy(Family $family, Journey $journey, JourneyItem $item)
    {
        abort_unless($journey->family_id === $family->id, 404);
        abort_unless($item->journey_id === $journey->id, 404);
        abort_unless($journey->user_id === request()->user()->id, 403, 'Solo quien creo el journey puede eliminar contenido.');

        if ($item->file_path) {
            Storage::disk('public')->delete($item->file_path);
        }
        $item->delete();
        return response()->noContent();
    }
}
