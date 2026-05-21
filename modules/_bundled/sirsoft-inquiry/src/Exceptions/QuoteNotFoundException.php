<?php

namespace Modules\Sirsoft\Inquiry\Exceptions;

use RuntimeException;

class QuoteNotFoundException extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Inquiry quote [{$id}] not found.", 404);
    }
}
