<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JourneyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'family_id'   => $this->family_id,
            'title'       => $this->title,
            'description' => $this->description,
            'cover_url'   => $this->cover_url,
            'items_count' => $this->whenCounted('items'),
            'items'       => JourneyItemResource::collection($this->whenLoaded('items')),
            'author'      => [
                'id'     => $this->user->id,
                'name'   => $this->user->name,
                'avatar' => $this->user->avatar_url ?? null,
            ],
            'created_at'  => $this->created_at,
        ];
    }
}
