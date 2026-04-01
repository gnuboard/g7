<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;

/**
 * 시퀀스가 최대값에 도달했을 때 발생하는 예외
 */
class SequenceOverflowException extends Exception
{
    /**
     * 생성자
     *
     * @param SequenceType $type 시퀀스 타입
     * @param int $maxValue 최대값
     */
    public function __construct(SequenceType $type, int $maxValue)
    {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.sequence_overflow', [
                'type' => $type->value,
                'max_value' => $maxValue,
            ])
        );
    }
}
