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
            'type'       => 'required|in:text,photo,video,audio',
            'content'    => 'nullable|string',
            'file'       => 'nullable|file|max:51200',
            'caption'    => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer',
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store(
                "journeys/{$journey->id}/items",
                'public'
            );
        }

        $item = $journey->items()->create([
            'type'       => $data['type'],
            'content'    => $data['content'] ?? null,
            'file_path'  => $filePath,
            'caption'    => $data['caption'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return new JourneyItemResource($item);
    }

    public function destroy(Family $family, Journey $journey, JourneyItem $item)
    {
        if ($item->file_path) {
            Storage::disk('public')->delete($item->file_path);
        }
        $item->delete();
        return response()->noContent();
    }
}
