<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use Closure;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;

/**
 * 결제 실패 기록 (공개 계약 DTO)
 *
 * meta 는 결제사가 자기 모양대로 만든다 — 이커머스는 현재 meta 를 넘겨 주고 돌려받은 값을 상태와 함께 저장한다.
 *
 * @since 1.4.0
 */
final readonly class PaymentFailure
{
    /**
     * PaymentFailure 생성자
     *
     * @param  PaymentStatusEnum  $status  기록할 결제 상태(FAILED 또는 CANCELLED)
     * @param  Closure  $meta  Closure(array $currentMeta): array — 새 결제 meta 전체
     * @param  bool  $takeOverProvider  결제 행의 결제사 id 를 호출 결제사로 기록할지
     */
    public function __construct(
        public PaymentStatusEnum $status,
        public Closure $meta,
        public bool $takeOverProvider = true,
    ) {}
}
