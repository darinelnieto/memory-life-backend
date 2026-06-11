<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    protected $fillable = [
        'family_id',
        'sender_id',
        'recipient_id',
        'reply_to_message_id',
        'message',
        'media_path',
        'media_type',
        'is_temporary',
        'is_view_once',
        'expires_at',
        'read_at',
        'viewed_at',
        'edited_at',
    ];

    protected $casts = [
        'is_temporary' => 'boolean',
        'is_view_once' => 'boolean',
        'expires_at' => 'datetime',
        'read_at' => 'datetime',
        'viewed_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function hiddenForUsers(): HasMany
    {
        return $this->hasMany(ChatMessageUserHidden::class, 'chat_message_id');
    }
}
