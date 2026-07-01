<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 주문 취소·환불 처리 예외
 *
 * 취소 불가 상태, 취소 옵션 부재/중복, 취소 수량 오류, 환불 금액 오류,
 * PG 환불 실패 등 주문 취소·환불 라이프사이클에서 발생하는 비즈니스 예외입니다.
 */
class OrderCancellationException extends Exception
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
