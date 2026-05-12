<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamilyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'surname'     => $this->surname,
            'avatar_url'  => $this->avatar ? asset('storage/' . $this->avatar) : null,
            'cover_url'   => $this->cover_photo ? asset('storage/' . $this->cover_photo) : null,
            'member_count' => $this->family_members_count ?? $this->familyMembers()->count(),
            'my_role'     => $this->whenPivotLoaded('family_members', fn () => $this->pivot->role),
        ];
    }
}
