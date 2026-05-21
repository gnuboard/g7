<?php

namespace Modules\Sirsoft\Inquiry\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return $this === self::Issued;
    }
}
