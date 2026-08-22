<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

/**
 * 쿠폰 선점 실패 예외
 *
 * 주문 확정 시점에 쿠폰 발급 레코드를 사용 상태로 차감하려 했으나 이미 다른 주문이
 * 선점했거나 사용 가능 상태가 아닌 경우에 사용합니다. 두 주문 모두 할인 금액이 확정된
 * 상태이므로, 경쟁에서 밀린 주문은 이 예외로 트랜잭션을 롤백해야 합니다.
 */
class CouponAlreadyUsedException extends CouponOperationException
{
    /**
     * CouponAlreadyUsedException 생성자
     *
     * @param  int  $couponIssueId  선점 실패한 쿠폰 발급 ID
     */
    public function __construct(private int $couponIssueId)
    {
        parent::__construct('sirsoft-ecommerce::exceptions.coupon_already_used');
    }

    /**
     * 선점 실패한 쿠폰 발급 ID를 반환합니다.
     *
     * @return int 쿠폰 발급 ID
     */
    public function getCouponIssueId(): int
    {
        return $this->couponIssueId;
    }
}
