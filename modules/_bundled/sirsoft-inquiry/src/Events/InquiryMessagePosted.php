<?php

namespace Modules\Sirsoft\Inquiry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;

class InquiryMessagePosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly InquiryMessage $message,
    ) {}
}
