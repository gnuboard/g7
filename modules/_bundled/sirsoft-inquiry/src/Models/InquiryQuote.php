<?php

namespace Modules\Sirsoft\Inquiry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;

class InquiryQuote extends Model
{
    protected $table = 'inquiry_quotes';

    protected $fillable = [
        'inquiry_id',
        'version',
        'total_amount',
        'tax_amount',
        'currency',
        'valid_until',
        'note',
        'status',
        'issued_at',
        'accepted_at',
        'rejected_at',
    ];

    protected $casts = [
        'status' => QuoteStatus::class,
        'valid_until' => 'date',
        'issued_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'total_amount' => 'decimal:0',
        'tax_amount' => 'decimal:0',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InquiryQuoteItem::class, 'quote_id')->orderBy('position');
    }
}
