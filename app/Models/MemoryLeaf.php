<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemoryLeaf extends Model
{
    protected $fillable = [
        'family_id', 'first_name', 'last_name', 'surname',
        'avatar', 'bio', 'birth_date', 'death_date', 'managed_by',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class);
    }

    public function getAvatarUrlAttribute(): string|null
    {
        if (!$this->avatar) return null;
        return str_starts_with($this->avatar, 'http')
            ? $this->avatar
            : asset('storage/' . $this->avatar);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'death_date' => 'date',
        ];
    }
}
