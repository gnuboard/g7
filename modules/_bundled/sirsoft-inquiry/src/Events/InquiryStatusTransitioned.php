<?php

namespace Modules\Sirsoft\Inquiry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class InquiryStatusTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly InquiryStatus $from,
        public readonly InquiryStatus $to,
        public readonly TransitionEvent $event,
        public readonly ?int $actorUserId,
    ) {}
}
