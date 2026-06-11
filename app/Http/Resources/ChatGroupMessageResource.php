<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ChatGroupMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = (int) ($request->user()?->id ?? 0);
        $isMine = $viewerId === (int) $this->sender_id;
        $isExpired = $this->is_temporary && $this->expires_at?->isPast();

        $hasViewed = false;
        if (!$isMine && $this->relationLoaded('views')) {
            $hasViewed = $this->views->contains(fn ($view) => (int) $view->user_id === $viewerId);
        }

        $canViewMedia = !$this->media_path
            || (!$isExpired && (!$this->is_view_once || $isMine || !$hasViewed));

        return [
            'id' => $this->id,
            'chat_group_id' => $this->chat_group_id,
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
            'created_at' => $this->created_at?->toISOString(),
            'sender' => [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
                'username' => $this->sender->username,
                'avatar_url' => $this->sender->avatar_url,
            ],
            'is_mine' => $isMine,
        ];
    }
}
