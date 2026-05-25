<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatGroupMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chat_group_id' => $this->chat_group_id,
            'message' => $this->message,
            'created_at' => $this->created_at?->toISOString(),
            'sender' => [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
                'username' => $this->sender->username,
                'avatar_url' => $this->sender->avatar_url,
            ],
            'is_mine' => $request->user()?->id === $this->sender_id,
        ];
    }
}
