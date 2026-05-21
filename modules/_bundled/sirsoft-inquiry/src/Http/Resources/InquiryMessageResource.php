<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;

/**
 * @mixin InquiryMessage
 */
class InquiryMessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'inquiry_id' => $this->inquiry_id,
            'sender_user_id' => $this->sender_user_id,
            'sender_role' => $this->sender_role?->value,
            'body' => $this->body,
            'meta' => $this->meta,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => InquiryAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
