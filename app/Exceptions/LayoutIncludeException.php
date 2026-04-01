<?php

namespace App\Exceptions;

use Exception;

class LayoutIncludeException extends Exception
{
    private ?string $includePath = null;

    private ?array $includeStack = null;

    public function __construct(
        string $message,
        ?string $includePath = null,
        ?array $includeStack = null
    ) {
        $this->includePath = $includePath;
        $this->includeStack = $includeStack;
        parent::__construct($message);
    }

    public function getIncludePath(): ?string
    {
        return $this->includePath;
    }

    public function getIncludeStack(): ?array
    {
        return $this->includeStack;
    }
}
