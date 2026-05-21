<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\InquiryQuoteItem;

/**
 * @mixin InquiryQuoteItem
 */
class InquiryQuoteItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'name' => $this->name,
            'description' => $this->description,
            'qty' => (string) $this->qty,
            'unit_price' => (string) $this->unit_price,
            'amount' => (string) $this->amount,
        ];
    }
}
