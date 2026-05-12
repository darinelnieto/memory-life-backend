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
        'parent_id',
        'spouse_id',
        'created_by',
        'first_name',
        'last_name',
        'relationship',
        'gender',
        'avatar',
        'birth_date',
        'death_date',
        'bio',
        'is_deceased',
    ];

    protected $casts = [
        'birth_date'  => 'date',
        'death_date'  => 'date',
        'is_deceased' => 'boolean',
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

    public function spouse(): BelongsTo
    {
        return $this->belongsTo(TreeMember::class, 'spouse_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TreeMember::class, 'parent_id')->with('children');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) {
            return null;
        }
        return str_starts_with($this->avatar, 'http')
            ? $this->avatar
            : asset('storage/' . $this->avatar);
    }
}
