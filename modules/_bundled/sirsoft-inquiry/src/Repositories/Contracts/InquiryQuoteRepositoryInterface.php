<?php

namespace Modules\Sirsoft\Inquiry\Repositories\Contracts;

use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;

interface InquiryQuoteRepositoryInterface
{
    public function issue(Inquiry $inquiry, array $payload, array $items): InquiryQuote;

    public function expireActiveQuotes(Inquiry $inquiry): int;

    public function markAccepted(InquiryQuote $quote): void;

    public function markRejected(InquiryQuote $quote): void;

    public function findActiveForInquiry(Inquiry $inquiry): ?InquiryQuote;

    public function findOrFail(int $id): InquiryQuote;
}
