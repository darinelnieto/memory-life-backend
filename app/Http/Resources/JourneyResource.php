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
            'tree_member_id' => $this->tree_member_id,
            'title'       => $this->title,
            'description' => $this->description,
            'cover_url'   => $this->cover_url,
            'published_at' => $this->published_at,
            'is_published' => $this->is_published,
            'items_count' => $this->whenCounted('items'),
            'items'       => JourneyItemResource::collection($this->whenLoaded('items')),
            'author'      => [
                'id'     => $this->user->id,
                'name'   => $this->user->name,
                'avatar_url' => $this->user->avatar_url ?? null,
            ],
            'created_at'  => $this->created_at,
        ];
    }
}
