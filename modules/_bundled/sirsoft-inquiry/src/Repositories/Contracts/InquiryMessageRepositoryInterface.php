<?php

namespace Modules\Sirsoft\Inquiry\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;

interface InquiryMessageRepositoryInterface
{
    public function append(Inquiry $inquiry, int $senderUserId, SenderRole $role, string $body): InquiryMessage;

    public function appendSystem(Inquiry $inquiry, string $key, array $params = []): InquiryMessage;

    public function listForInquiry(Inquiry $inquiry, int $perPage = 50): LengthAwarePaginator;

    public function markReadFor(Inquiry $inquiry, SenderRole $oppositeRole): int;
}
