<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions;

use RuntimeException;

/**
 * 허용되지 않는 결제 상태 전이를 요청했을 때 던지는 공개 예외
 *
 * 재결제 복구에서 결제 완료 이력이나 부분 취소 이력이 있는 주문을 되살리려 할 때 쓴다.
 *
 * @since 1.4.0
 */
class InvalidPaymentTransition extends RuntimeException
{
    /** 결제 완료 이력이 있음 */
    public const PAID_HISTORY = 'paid_history';

    /** 부분 취소 이력이 있음 */
    public const PARTIAL_CANCEL_HISTORY = 'partial_cancel_history';

    /** @var string 사유 코드(PAID_HISTORY, PARTIAL_CANCEL_HISTORY) */
    public string $reason = '';

    /**
     * 사유 코드로 예외를 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $reason  사유 코드
     * @return self 예외
     */
    public static function because(string $orderNumber, string $reason): self
    {
        $exception = new self(__('sirsoft-ecommerce::exceptions.payment_contract.transition_denied', ['order_number' => $orderNumber, 'reason' => $reason]));
        $exception->reason = $reason;

        return $exception;
    }
}
