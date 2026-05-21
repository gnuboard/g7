<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;

/**
 * @mixin InquiryQuote
 */
class InquiryQuoteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'inquiry_id' => $this->inquiry_id,
            'version' => $this->version,
            'total_amount' => (string) $this->total_amount,
            'tax_amount' => (string) $this->tax_amount,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->toDateString(),
            'note' => $this->note,
            'status' => $this->status?->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'items' => InquiryQuoteItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
