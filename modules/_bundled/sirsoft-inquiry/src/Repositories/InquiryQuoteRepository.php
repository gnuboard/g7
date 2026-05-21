<?php

namespace Modules\Sirsoft\Inquiry\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;
use Modules\Sirsoft\Inquiry\Exceptions\QuoteNotFoundException;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface;

class InquiryQuoteRepository implements InquiryQuoteRepositoryInterface
{
    public function issue(Inquiry $inquiry, array $payload, array $items): InquiryQuote
    {
        return DB::transaction(function () use ($inquiry, $payload, $items) {
            $this->expireActiveQuotes($inquiry);

            $nextVersion = ($inquiry->quotes()->max('version') ?? 0) + 1;
            $quote = $inquiry->quotes()->create(array_merge($payload, [
                'version' => $nextVersion,
                'status' => QuoteStatus::Issued->value,
                'issued_at' => now(),
                'currency' => $payload['currency'] ?? config('inquiry.quote.currency', 'KRW'),
            ]));

            foreach ($items as $i => $item) {
                $quote->items()->create(array_merge($item, [
                    'position' => $item['position'] ?? $i + 1,
                ]));
            }

            return $quote;
        });
    }

    public function expireActiveQuotes(Inquiry $inquiry): int
    {
        return $inquiry->quotes()
            ->where('status', QuoteStatus::Issued->value)
            ->update(['status' => QuoteStatus::Expired->value]);
    }

    public function markAccepted(InquiryQuote $quote): void
    {
        $quote->update([
            'status' => QuoteStatus::Accepted->value,
            'accepted_at' => now(),
        ]);
    }

    public function markRejected(InquiryQuote $quote): void
    {
        $quote->update([
            'status' => QuoteStatus::Rejected->value,
            'rejected_at' => now(),
        ]);
    }

    public function findActiveForInquiry(Inquiry $inquiry): ?InquiryQuote
    {
        return $inquiry->quotes()
            ->where('status', QuoteStatus::Issued->value)
            ->latest('version')
            ->first();
    }

    public function findOrFail(int $id): InquiryQuote
    {
        return InquiryQuote::find($id) ?? throw new QuoteNotFoundException($id);
    }
}
