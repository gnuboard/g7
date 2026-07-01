<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 임시 주문(주문서) 예외
 *
 * 장바구니가 비어 있거나 임시 주문을 찾을 수 없는 등
 * 주문서 생성·조회 과정에서 발생하는 비즈니스 예외입니다.
 */
class TempOrderException extends Exception
{
    /**
     * 생성자
     *
     * @param  string  $message  다국어 처리된 예외 메시지
     */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
