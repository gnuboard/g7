<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 결제 상태 쓰기의 결과 (공개 계약 DTO)
 *
 * `applied` 가 false 면 `reason` 에 사유가 있다 — 판정 클로저가 돌려준 문자열이거나 이커머스 사유 코드
 * (`ignored`, `order_not_payable`, `payment_not_found`).
 *
 * @since 1.4.0
 */
final readonly class PaymentOutcome
{
    /**
     * PaymentOutcome 생성자
     *
     * @param  bool  $applied  실제로 기록했는지
     * @param  string|null  $reason  기록하지 않은 사유
     * @param  OrderRecord|null  $order  처리 뒤 주문
     */
    public function __construct(
        public bool $applied,
        public ?string $reason,
        public ?OrderRecord $order,
    ) {}
}
