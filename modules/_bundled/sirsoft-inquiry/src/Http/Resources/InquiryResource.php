<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

/**
 * @mixin Inquiry
 */
class InquiryResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request->user();
        $isOwner = $user && $user->id === $this->user_id;

        return [
            'uuid' => $this->uuid,
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'content' => $this->content,
            'category' => $this->category,
            'budget_range' => $this->budget_range,
            'desired_due_at' => $this->desired_due_at?->toDateString(),
            'status' => $this->status?->value,
            'accepted_quote_id' => $this->accepted_quote_id,
            'payment_id' => $this->payment_id,
            'received_at' => $this->received_at?->toIso8601String(),
            'quoted_at' => $this->quoted_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'is_owner' => $isOwner,
            'abilities' => [
                'update' => $user ? $user->can('update', $this->resource) : false,
                'cancel' => $user ? $user->can('cancel', $this->resource) : false,
                'postMessage' => $user ? $user->can('postMessage', $this->resource) : false,
                'acceptQuote' => $user ? $user->can('acceptQuote', $this->resource) : false,
                'rejectQuote' => $user ? $user->can('rejectQuote', $this->resource) : false,
            ],
            'quotes' => InquiryQuoteResource::collection($this->whenLoaded('quotes')),
            'attachments' => InquiryAttachmentResource::collection(
                $this->whenLoaded('attachments', fn () => $this->attachments->whereNull('message_id'))
            ),
        ];
    }
}
