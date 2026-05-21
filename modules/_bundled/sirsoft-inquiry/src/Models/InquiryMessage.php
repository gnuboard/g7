<?php

namespace Modules\Sirsoft\Inquiry\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;

class InquiryMessage extends Model
{
    protected $table = 'inquiry_messages';

    protected $fillable = [
        'inquiry_id',
        'sender_user_id',
        'sender_role',
        'body',
        'meta',
        'read_at',
    ];

    protected $casts = [
        'sender_role' => SenderRole::class,
        'meta' => 'array',
        'read_at' => 'datetime',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(InquiryAttachment::class, 'message_id');
    }

    public function isSystem(): bool
    {
        return $this->sender_role === SenderRole::System;
    }
}
