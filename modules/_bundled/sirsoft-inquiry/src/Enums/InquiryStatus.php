<?php

namespace Modules\Sirsoft\Inquiry\Enums;

enum InquiryStatus: string
{
    case Received = 'received';
    case Quoted = 'quoted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return __('inquiry::system.status.' . $this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Canceled], true);
    }
}
