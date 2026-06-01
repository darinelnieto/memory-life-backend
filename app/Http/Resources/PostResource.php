<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $likesCount = $this->whenCounted('likes') ?? (int) ($this->likes_count ?? 0);
        $commentsCount = $this->whenCounted('comments') ?? (int) ($this->comments_count ?? 0);
        $repostsCount = $this->whenCounted('reposts') ?? (int) ($this->reposts_count ?? 0);

        $isLikedByMe = $this->relationLoaded('likes')
            ? $this->likes->contains('user_id', $request->user()?->id)
            : false;

        $isRepostedByMe = $this->relationLoaded('reposts')
            ? $this->reposts->contains('user_id', $request->user()?->id)
            : false;

        $latestRepost = $this->relationLoaded('latestRepost') && $this->latestRepost
            ? [
                'at' => $this->latestRepost->created_at?->toISOString(),
                'by' => [
                    'id' => $this->latestRepost->user->id,
                    'name' => $this->latestRepost->user->name,
                    'avatar_url' => $this->latestRepost->user->avatar_url,
                ],
            ]
            : null;

        return [
            'id'         => $this->id,
            'content'    => $this->content,
            'type'       => $this->type,
            'media_url'  => $this->media_url,
            'media_urls' => $this->media_urls,
            'allow_comments' => (bool) $this->allow_comments,
            'allow_likes' => (bool) $this->allow_likes,
            'allow_reposts' => (bool) $this->allow_reposts,
            'likes_count' => $likesCount,
            'comments_count' => $commentsCount,
            'reposts_count' => $repostsCount,
            'is_liked_by_me' => $isLikedByMe,
            'is_reposted_by_me' => $isRepostedByMe,
            'latest_repost' => $latestRepost,
            'created_at' => $this->created_at->toISOString(),
            'author'     => [
                'id'         => $this->user->id,
                'name'       => $this->user->name,
                'avatar_url' => $this->user->avatar_url,
            ],
        ];
    }
}
