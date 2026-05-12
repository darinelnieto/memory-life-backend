<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Memory extends Model
{
    protected $fillable = ['memory_leaf_id', 'contributed_by', 'type', 'content', 'file_path', 'caption'];

    public function memoryLeaf(): BelongsTo
    {
        return $this->belongsTo(MemoryLeaf::class);
    }

    public function contributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contributed_by');
    }

    public function getFileUrlAttribute(): string|null
    {
        if (!$this->file_path) return null;
        return asset('storage/' . $this->file_path);
    }
}
