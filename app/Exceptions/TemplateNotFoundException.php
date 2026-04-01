<?php

namespace App\Exceptions;

use Exception;

class TemplateNotFoundException extends Exception
{
    /**
     * 활성화된 템플릿을 찾을 수 없을 때 예외를 생성합니다.
     */
    public function __construct(string $type)
    {
        parent::__construct("Active template not found for type: {$type}");
    }
}
