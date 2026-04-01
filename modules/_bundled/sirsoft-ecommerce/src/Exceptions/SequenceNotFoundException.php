<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;

/**
 * 시퀀스를 찾을 수 없을 때 발생하는 예외
 */
class SequenceNotFoundException extends Exception
{
    /**
     * 생성자
     *
     * @param SequenceType $type 시퀀스 타입
     */
    public function __construct(SequenceType $type)
    {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.sequence_not_found', ['type' => $type->value])
        );
    }
}
