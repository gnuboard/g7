<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 재결제 복구 결과 (공개 계약 DTO)
 *
 * `restored` 가 false 면 `reason` 이 `payment_not_found`, `not_restorable_state`, `amount_mismatch`, `charge_not_resolvable` 중 하나다.
 *
 * @since 1.4.0
 */
final readonly class RetryRestoreResult
{
    /**
     * RetryRestoreResult 생성자
     *
     * @param  bool  $restored  복구했는지
     * @param  string|null  $reason  복구하지 않은 사유
     * @param  OrderRecord|null  $order  처리 뒤 주문
     * @param  int|null  $retryCount  복구 뒤 주문 meta 재시도 횟수
     */
    public function __construct(
        public bool $restored,
        public ?string $reason = null,
        public ?OrderRecord $order = null,
        public ?int $retryCount = null,
    ) {}
}
