<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'family_id' => $this->family_id,
            'message' => $this->message,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'sender' => [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
                'username' => $this->sender->username,
                'avatar_url' => $this->sender->avatar_url,
            ],
            'recipient' => [
                'id' => $this->recipient->id,
                'name' => $this->recipient->name,
                'username' => $this->recipient->username,
                'avatar_url' => $this->recipient->avatar_url,
            ],
            'is_mine' => $request->user()?->id === $this->sender_id,
        ];
    }
}
