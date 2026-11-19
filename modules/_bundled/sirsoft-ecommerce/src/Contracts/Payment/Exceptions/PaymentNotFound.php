<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions;

use RuntimeException;

/**
 * 결제 계약 쓰기의 대상 주문(또는 결제)이 없을 때 던지는 공개 예외
 *
 * @since 1.4.0
 */
class PaymentNotFound extends RuntimeException
{
    /**
     * 주문번호로 찾지 못한 경우의 예외를 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return self 예외
     */
    public static function forOrder(string $orderNumber): self
    {
        return new self(__('sirsoft-ecommerce::exceptions.payment_contract.order_not_found', ['order_number' => $orderNumber]));
    }
}
