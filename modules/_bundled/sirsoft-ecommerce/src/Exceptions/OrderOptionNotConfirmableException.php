<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 주문 옵션 구매확정 불가 예외
 *
 * 이미 구매확정된 옵션을 다시 확정하려 하거나, 구매확정 가능 상태
 * (order_settings.confirmable_statuses) 가 아닌 옵션을 확정하려 할 때 발생합니다.
 * OrderService::confirmOption 이 호출자(회원/비회원)와 무관하게 던지며, 컨트롤러는
 * 사유 식별자로 422 응답을 만듭니다.
 *
 * 사유 식별자는 `exceptions.*` 의 키와 같은 이름을 사용합니다:
 *   - order_option_already_confirmed
 *   - order_option_not_confirmable
 */
class OrderOptionNotConfirmableException extends RuntimeException
{
    /**
     * @param  string  $reason  확정 불가 사유 식별자 (exceptions.* 키와 동일)
     */
    public function __construct(
        private string $reason
    ) {
        parent::__construct(
            __("sirsoft-ecommerce::exceptions.{$reason}")
        );
    }

    /**
     * 확정 불가 사유 식별자 반환
     *
     * @return string 사유 식별자
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
