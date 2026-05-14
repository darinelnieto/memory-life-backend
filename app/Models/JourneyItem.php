<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyItem extends Model
{
    protected $fillable = ['journey_id', 'type', 'content', 'file_path', 'caption', 'sort_order'];

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? asset('storage/' . $this->file_path) : null;
    }
}
