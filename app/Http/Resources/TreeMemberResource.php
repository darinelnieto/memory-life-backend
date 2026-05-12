<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TreeMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'family_id'    => $this->family_id,
            'user_id'      => $this->user_id,
            'parent_id'    => $this->parent_id,
            'spouse_id'    => $this->spouse_id,
            'first_name'   => $this->first_name,
            'last_name'    => $this->last_name,
            'full_name'    => $this->first_name . ' ' . $this->last_name,
            'relationship' => $this->relationship,
            'gender'       => $this->gender,
            'avatar_url'   => $this->avatar_url,
            'birth_date'   => $this->birth_date?->toDateString(),
            'death_date'   => $this->death_date?->toDateString(),
            'bio'          => $this->bio,
            'is_deceased'  => $this->is_deceased,
            // Spouse inline (without further nesting to avoid circular)
            'spouse'       => $this->whenLoaded('spouse', fn () => [
                'id'           => $this->spouse->id,
                'first_name'   => $this->spouse->first_name,
                'last_name'    => $this->spouse->last_name,
                'full_name'    => $this->spouse->first_name . ' ' . $this->spouse->last_name,
                'relationship' => $this->spouse->relationship,
                'gender'       => $this->spouse->gender,
                'avatar_url'   => $this->spouse->avatar_url,
                'birth_date'   => $this->spouse->birth_date?->toDateString(),
                'death_date'   => $this->spouse->death_date?->toDateString(),
                'bio'          => $this->spouse->bio,
                'is_deceased'  => $this->spouse->is_deceased,
                'spouse_id'    => $this->spouse->spouse_id,
                'parent_id'    => $this->spouse->parent_id,
            ]),
            'children'     => TreeMemberResource::collection($this->whenLoaded('children')),
        ];
    }
}
