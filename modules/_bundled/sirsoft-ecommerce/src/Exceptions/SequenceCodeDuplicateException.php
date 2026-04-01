<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;

/**
 * 시퀀스 코드 중복 예외
 *
 * 이미 발급된 코드를 재발급하려고 할 때 발생합니다.
 */
class SequenceCodeDuplicateException extends Exception
{
    /**
     * 시퀀스 코드 중복 예외 생성
     *
     * @param SequenceType $type 시퀀스 타입
     * @param string $code 중복된 코드
     */
    public function __construct(SequenceType $type, string $code)
    {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.sequence_code_duplicate', [
                'type' => $type->value,
                'code' => $code,
            ])
        );
    }
}
