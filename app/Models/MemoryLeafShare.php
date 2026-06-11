<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryLeafShare extends Model
{
    protected $fillable = [
        'memory_leaf_id',
        'sender_id',
        'recipient_id',
        'copied_memory_leaf_id',
        'status',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function memoryLeaf(): BelongsTo
    {
        return $this->belongsTo(MemoryLeaf::class, 'memory_leaf_id');
    }

    public function copiedMemoryLeaf(): BelongsTo
    {
        return $this->belongsTo(MemoryLeaf::class, 'copied_memory_leaf_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
