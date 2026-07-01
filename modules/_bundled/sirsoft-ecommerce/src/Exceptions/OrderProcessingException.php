<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 주문 처리 예외
 *
 * 주문 금액 계산 검증 실패 등 주문 생성·처리 과정에서 발생하는 비즈니스 예외입니다.
 */
class OrderProcessingException extends Exception
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
