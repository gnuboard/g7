<?php

namespace Modules\Sirsoft\Inquiry\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;

class ExpireQuotesCommand extends Command
{
    protected $signature = 'inquiry:expire-quotes';
    protected $description = 'Mark inquiry quotes as expired when valid_until has passed.';

    public function handle(): int
    {
        $affected = InquiryQuote::query()
            ->where('status', QuoteStatus::Issued->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->update(['status' => QuoteStatus::Expired->value]);

        $this->info("Expired {$affected} quote(s).");
        return self::SUCCESS;
    }
}
