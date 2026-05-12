<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'username'              => $this->username,
            'email'                 => $this->email,
            'bio'                   => $this->bio,
            'avatar_url'            => $this->avatar_url,
            'cover_url'             => $this->cover_url,
            'birth_date'            => $this->birth_date?->toDateString(),
            'phone'                 => $this->phone,
            'location'              => $this->location,
            'gender'                => $this->gender,
            'completion_percentage' => $this->completion_percentage,
            'roles'                 => $this->getRoleNames(),
        ];
    }
}
