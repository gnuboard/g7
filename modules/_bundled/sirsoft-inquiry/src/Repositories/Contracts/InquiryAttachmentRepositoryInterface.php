<?php

namespace Modules\Sirsoft\Inquiry\Repositories\Contracts;

use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;

interface InquiryAttachmentRepositoryInterface
{
    public function create(array $data): InquiryAttachment;

    public function attachToMessage(InquiryAttachment $attachment, InquiryMessage $message): void;

    public function findOrFail(int $id): InquiryAttachment;

    public function listOrphansOlderThanMinutes(int $minutes): \Illuminate\Support\Collection;

    public function delete(InquiryAttachment $attachment): void;
}
