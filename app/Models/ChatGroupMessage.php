<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatGroupMessage extends Model
{
    protected $fillable = [
        'chat_group_id',
        'sender_id',
        'reply_to_message_id',
        'message',
        'media_path',
        'media_type',
        'is_temporary',
        'is_view_once',
        'expires_at',
        'edited_at',
    ];

    protected $casts = [
        'is_temporary' => 'boolean',
        'is_view_once' => 'boolean',
        'expires_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChatGroup::class, 'chat_group_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(ChatGroupMessageView::class, 'chat_group_message_id');
    }

    public function hiddenForUsers(): HasMany
    {
        return $this->hasMany(ChatGroupMessageUserHidden::class, 'chat_group_message_id');
    }
}
