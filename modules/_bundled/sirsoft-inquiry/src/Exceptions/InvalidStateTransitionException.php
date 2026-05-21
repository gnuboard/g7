<?php

namespace Modules\Sirsoft\Inquiry\Exceptions;

use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use RuntimeException;

class InvalidStateTransitionException extends RuntimeException
{
    public function __construct(InquiryStatus $from, TransitionEvent $event)
    {
        parent::__construct(
            "Invalid transition: cannot apply event '{$event->value}' to inquiry in status '{$from->value}'.",
            422
        );
    }
}
