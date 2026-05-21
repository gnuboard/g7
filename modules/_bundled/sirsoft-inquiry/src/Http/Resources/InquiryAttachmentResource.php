<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;

/**
 * @mixin InquiryAttachment
 */
class InquiryAttachmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'inquiry_id' => $this->inquiry_id,
            'message_id' => $this->message_id,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'download_url' => url("/api/modules/sirsoft-inquiry/attachments/{$this->id}"),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
