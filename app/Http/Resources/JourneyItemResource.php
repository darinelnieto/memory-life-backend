<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JourneyItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sourcePost = null;
        if ($this->source_post_id && $this->relationLoaded('sourcePost') && $this->sourcePost) {
            $post = $this->sourcePost;
            $sourcePost = [
                'id'                => $post->id,
                'content'           => $post->content,
                'type'              => $post->type,
                'media_url'         => $post->media_url,
                'media_urls'        => $post->media_urls,
                'allow_comments'    => (bool) $post->allow_comments,
                'allow_likes'       => (bool) $post->allow_likes,
                'allow_reposts'     => (bool) $post->allow_reposts,
                'is_repost'         => (bool) $post->repost_of_post_id,
                'repost_of'         => null,
                'likes_count'       => (int) ($post->likes_count ?? 0),
                'comments_count'    => (int) ($post->comments_count ?? 0),
                'reposts_count'     => (int) ($post->reposts_count ?? 0),
                'is_liked_by_me'    => false,
                'is_reposted_by_me' => false,
                'latest_repost'     => null,
                'created_at'        => $post->created_at->toISOString(),
                'author'            => [
                    'id'         => $post->user->id,
                    'name'       => $post->user->name,
                    'avatar_url' => $post->user->avatar_url,
                ],
            ];
        }

        return [
            'id'             => $this->id,
            'journey_id'     => $this->journey_id,
            'type'           => $this->type,
            'content'        => $this->content,
            'file_url'       => $this->file_url,
            'caption'        => $this->caption,
            'sort_order'     => $this->sort_order,
            'source_post_id' => $this->source_post_id,
            'source_post'    => $sourcePost,
            'created_at'     => $this->created_at,
        ];
    }
}
