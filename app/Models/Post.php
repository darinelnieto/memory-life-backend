<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Post extends Model
{
    protected $fillable = [
        'family_id',
        'user_id',
        'repost_of_post_id',
        'content',
        'type',
        'media_path',
        'media_paths',
        'allow_comments',
        'allow_likes',
        'allow_reposts',
        'show_on_profile',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function repostOf(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'repost_of_post_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function reposts(): HasMany
    {
        return $this->hasMany(PostRepost::class);
    }

    public function latestRepost(): HasOne
    {
        return $this->hasOne(PostRepost::class)->latestOfMany();
    }

    public function repostedPosts(): HasMany
    {
        return $this->hasMany(Post::class, 'repost_of_post_id');
    }

    public function getMediaUrlAttribute(): string|null
    {
        if (!$this->media_path) return null;
        return asset('storage/' . $this->media_path);
    }

    public function getMediaUrlsAttribute(): array
    {
        if (is_array($this->media_paths) && count($this->media_paths) > 0) {
            return array_map(fn ($path) => asset('storage/' . $path), $this->media_paths);
        }

        return $this->media_path ? [asset('storage/' . $this->media_path)] : [];
    }

    protected function casts(): array
    {
        return [
            'allow_comments' => 'boolean',
            'allow_likes' => 'boolean',
            'allow_reposts' => 'boolean',
            'show_on_profile' => 'boolean',
            'media_paths' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
