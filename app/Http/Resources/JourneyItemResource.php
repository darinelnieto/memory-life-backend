<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JourneyItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'journey_id' => $this->journey_id,
            'type'       => $this->type,
            'content'    => $this->content,
            'file_url'   => $this->file_url,
            'caption'    => $this->caption,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
        ];
    }
}
