<?php

namespace Modules\Sirsoft\Inquiry\Repositories;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface;

class InquiryAttachmentRepository implements InquiryAttachmentRepositoryInterface
{
    public function create(array $data): InquiryAttachment
    {
        return InquiryAttachment::create($data);
    }

    public function attachToMessage(InquiryAttachment $attachment, InquiryMessage $message): void
    {
        $attachment->update(['message_id' => $message->id]);
    }

    public function findOrFail(int $id): InquiryAttachment
    {
        return InquiryAttachment::findOrFail($id);
    }

    public function listOrphansOlderThanMinutes(int $minutes): Collection
    {
        return InquiryAttachment::query()
            ->whereNull('message_id')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();
    }

    public function delete(InquiryAttachment $attachment): void
    {
        $attachment->delete();
    }
}
