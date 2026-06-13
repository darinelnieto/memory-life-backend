<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamilyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $avatarPath = $this->avatar ?: $this->cover_photo;

        return [
            'id'          => $this->id,
            'name'        => '',
            'surname'     => $this->surname,
            'avatar_url'  => $avatarPath ? asset('storage/' . $avatarPath) : null,
            'cover_url'   => $this->cover_photo ? asset('storage/' . $this->cover_photo) : null,
            'member_count' => $this->family_members_count ?? $this->familyMembers()->count(),
            'my_role'     => $this->whenPivotLoaded('family_members', fn () => $this->pivot->role),
        ];
    }
}
