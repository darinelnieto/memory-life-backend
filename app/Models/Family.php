<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Family extends Model
{
    protected $fillable = ['owner_id', 'surname', 'name', 'avatar', 'cover_photo'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'family_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function memoryLeaves(): HasMany
    {
        return $this->hasMany(MemoryLeaf::class);
    }

    public function getMemberCountAttribute(): int
    {
        return $this->familyMembers()->count();
    }
}
