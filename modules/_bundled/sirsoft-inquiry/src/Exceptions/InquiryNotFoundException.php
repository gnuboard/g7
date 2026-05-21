<?php

namespace Modules\Sirsoft\Inquiry\Exceptions;

use RuntimeException;

class InquiryNotFoundException extends RuntimeException
{
    public function __construct(string $uuid)
    {
        parent::__construct("Inquiry [{$uuid}] not found.", 404);
    }
}
