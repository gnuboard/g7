<?php

namespace Modules\Sirsoft\Inquiry\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;

class Inquiry extends Model
{
    protected $table = 'inquiries';

    protected $fillable = [
        'uuid',
        'user_id',
        'title',
        'content',
        'category',
        'budget_range',
        'desired_due_at',
        'status',
        'accepted_quote_id',
        'payment_id',
        'extra_data',
        'received_at',
        'quoted_at',
        'started_at',
        'completed_at',
        'canceled_at',
    ];

    protected $casts = [
        'status' => InquiryStatus::class,
        'extra_data' => 'array',
        'desired_due_at' => 'date',
        'received_at' => 'datetime',
        'quoted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(InquiryQuote::class)->orderBy('version');
    }

    public function acceptedQuote(): BelongsTo
    {
        return $this->belongsTo(InquiryQuote::class, 'accepted_quote_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InquiryMessage::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(InquiryAttachment::class);
    }
}
