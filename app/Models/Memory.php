<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Memory extends Model
{
    protected $fillable = [
        'memory_leaf_id',
        'contributed_by',
        'type',
        'title',
        'content',
        'file_path',
        'media_paths',
        'caption',
    ];

    protected $casts = [
        'media_paths' => 'array',
    ];

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

    public function getMediaUrlsAttribute(): array
    {
        $paths = is_array($this->media_paths) ? $this->media_paths : [];
        if (count($paths) === 0 && $this->file_path) {
            $paths = [$this->file_path];
        }

        return array_values(array_map(
            fn (string $path): string => asset('storage/' . $path),
            array_filter($paths)
        ));
    }
}
