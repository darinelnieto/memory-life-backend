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
        'content',
        'type',
        'media_path',
        'allow_comments',
        'allow_likes',
        'allow_reposts',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function getMediaUrlAttribute(): string|null
    {
        if (!$this->media_path) return null;
        return asset('storage/' . $this->media_path);
    }

    protected function casts(): array
    {
        return [
            'allow_comments' => 'boolean',
            'allow_likes' => 'boolean',
            'allow_reposts' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
