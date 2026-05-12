<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,
            'content'     => $this->content,
            'file_url'    => $this->file_url,
            'caption'     => $this->caption,
            'created_at'  => $this->created_at?->toISOString(),
            'contributor' => $this->whenLoaded('contributor', fn () => [
                'id'         => $this->contributor->id,
                'name'       => $this->contributor->name,
                'avatar_url' => $this->contributor->avatar_url,
            ]),
        ];
    }
}
