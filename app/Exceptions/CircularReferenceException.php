<?php

namespace App\Exceptions;

use Exception;

class CircularReferenceException extends Exception
{
    /**
     * 순환 참조 스택
     */
    private array $stack;

    /**
     * CircularReferenceException 생성자
     */
    public function __construct(array $stack, string $currentLayout)
    {
        $this->stack = $stack;

        $stackTrace = implode(' → ', $stack) . " → {$currentLayout}";
        $message = __('exceptions.circular_reference', ['trace' => $stackTrace]);

        parent::__construct($message);
    }

    /**
     * 순환 참조 스택 반환
     */
    public function getStack(): array
    {
        return $this->stack;
    }
}
