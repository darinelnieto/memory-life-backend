<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journey extends Model
{
    protected $fillable = [
        'family_id',
        'user_id',
        'tree_member_id',
        'title',
        'description',
        'cover_path',
        'published_at',
        'copied_from_journey_id',
        'copied_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function treeMember(): BelongsTo
    {
        return $this->belongsTo(TreeMember::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(JourneyItem::class)->orderBy('sort_order');
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_path ? asset('storage/' . $this->cover_path) : null;
    }

    public function getIsPublishedAttribute(): bool
    {
        if (!$this->published_at) {
            return true;
        }

        return $this->published_at->lessThanOrEqualTo(now());
    }
}
