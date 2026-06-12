<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TreeMember extends Model
{
    protected $fillable = [
        'family_id',
        'user_id',
        'is_pet',
        'owner_tree_member_id',
        'app_user_email',
        'invite_status',
        'parent_id',
        'spouse_id',
        'created_by',
        'first_name',
        'last_name',
        'relationship',
        'gender',
        'avatar',
        'cover',
        'media_photos',
        'media_video',
        'birth_date',
        'death_date',
        'bio',
        'is_deceased',
    ];

    protected $casts = [
        'birth_date'  => 'date',
        'death_date'  => 'date',
        'is_pet' => 'boolean',
        'is_deceased' => 'boolean',
        'media_photos' => 'array',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TreeMember::class, 'parent_id');
    }

    public function petOwner(): BelongsTo
    {
        return $this->belongsTo(TreeMember::class, 'owner_tree_member_id');
    }

    public function pets(): HasMany
    {
        return $this->hasMany(TreeMember::class, 'owner_tree_member_id')
            ->where('is_pet', true)
            ->whereNotIn('invite_status', ['pending', 'rejected', 'cancelled']);
    }

    public function spouse(): BelongsTo
    {
        return $this->belongsTo(TreeMember::class, 'spouse_id')
            ->whereNotIn('invite_status', ['pending', 'rejected']);
    }

    public function spouses(): HasMany
    {
        return $this->hasMany(TreeMember::class, 'spouse_id')
            ->whereNotIn('invite_status', ['pending', 'rejected']);
    }

    public function children(): HasMany
    {
        return $this->hasMany(TreeMember::class, 'parent_id')
            ->whereNotIn('invite_status', ['pending', 'rejected'])
            ->with('children');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) {
            return $this->user?->avatar_url;
        }
        return str_starts_with($this->avatar, 'http')
            ? $this->avatar
            : asset('storage/' . $this->avatar);
    }

    public function getCoverUrlAttribute(): ?string
    {
        if (!$this->cover) {
            return null;
        }

        return str_starts_with($this->cover, 'http')
            ? $this->cover
            : asset('storage/' . $this->cover);
    }

    public function getMediaVideoUrlAttribute(): ?string
    {
        if (!$this->media_video) {
            return null;
        }

        return str_starts_with($this->media_video, 'http')
            ? $this->media_video
            : asset('storage/' . $this->media_video);
    }

    public function getMediaPhotosUrlsAttribute(): array
    {
        $photos = $this->media_photos ?? [];
        if (!is_array($photos)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $path): string => str_starts_with($path, 'http') ? $path : asset('storage/' . $path),
            $photos,
        ));
    }
}
