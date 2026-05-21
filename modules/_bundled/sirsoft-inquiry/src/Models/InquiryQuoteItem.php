<?php

namespace Modules\Sirsoft\Inquiry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryQuoteItem extends Model
{
    protected $table = 'inquiry_quote_items';

    protected $fillable = [
        'quote_id',
        'position',
        'name',
        'description',
        'qty',
        'unit_price',
        'amount',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'unit_price' => 'decimal:0',
        'amount' => 'decimal:0',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(InquiryQuote::class, 'quote_id');
    }
}
