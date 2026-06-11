<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isMine = $request->user()?->id === $this->sender_id;
        $isExpired = $this->is_temporary && $this->expires_at?->isPast();
        $canViewMedia = !$this->media_path
            || (!$isExpired && (!$this->is_view_once || $isMine || !$this->viewed_at));

        return [
            'id' => $this->id,
            'family_id' => $this->family_id,
            'message' => $this->message,
            'reply_to_message_id' => $this->reply_to_message_id,
            'reply_to' => $this->replyTo ? [
                'id' => $this->replyTo->id,
                'message' => $this->replyTo->message,
                'sender_name' => $this->replyTo->sender?->name,
            ] : null,
            'edited_at' => $this->edited_at?->toISOString(),
            'media_type' => $this->media_type,
            'media_url' => $this->media_path ? Storage::disk('public')->url($this->media_path) : null,
            'is_temporary' => (bool) $this->is_temporary,
            'is_view_once' => (bool) $this->is_view_once,
            'expires_at' => $this->expires_at?->toISOString(),
            'is_expired' => $isExpired,
            'can_view_media' => $canViewMedia,
            'read_at' => $this->read_at?->toISOString(),
            'viewed_at' => $this->viewed_at?->toISOString(),
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
            'is_mine' => $isMine,
        ];
    }
}
