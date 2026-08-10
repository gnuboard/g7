<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 쿠폰 발급 불가 예외
 *
 * 발급 관문(assertIssuable / assertWithinUserLimit)이 거절한 상태에서 발급을 시도할 때
 * 발생합니다. 사유 식별자는 `messages.coupon.*` 의 키와 같은 이름을 쓰며, 그 키로 사용자
 * 안내 문구를 만듭니다.
 *
 * 직접 발급 배치는 회원별로 이 예외를 잡아 skipped 목록에 사유를 담고, 컨트롤러는
 * `catch (\Exception)` 으로 400 응답을 만듭니다. RuntimeException 상속이라 두 흐름 모두
 * 종전과 동일하게 동작합니다.
 */
class CouponNotIssuableException extends RuntimeException
{
    /**
     * @param  string  $reason  발급 불가 사유 식별자 (messages.coupon.* 키와 동일)
     */
    public function __construct(
        private string $reason
    ) {
        parent::__construct(
            __("sirsoft-ecommerce::messages.coupon.{$reason}"),
            400
        );
    }

    /**
     * 발급 불가 사유 식별자 반환
     *
     * @return string 사유 식별자
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
