<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemoryLeafResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'first_name'       => $this->first_name,
            'last_name'        => $this->last_name,
            'full_name'        => $this->full_name,
            'surname'          => $this->surname,
            'avatar_url'       => $this->avatar_url,
            'bio'              => $this->bio,
            'birth_date'       => $this->birth_date?->toDateString(),
            'death_date'       => $this->death_date?->toDateString(),
            'memories_count'   => $this->whenCounted('memories'),
            'featured_memory'  => new MemoryResource($this->whenLoaded('featuredMemory')),
            'memories'         => MemoryResource::collection($this->whenLoaded('memories')),
        ];
    }
}
