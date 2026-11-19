<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;

/**
 * 결제 행 조회 필터 (공개 계약 DTO)
 *
 * null 필드는 조건을 걸지 않는다.
 *
 * @since 1.4.0
 */
final readonly class PaymentFilters
{
    /**
     * PaymentFilters 생성자
     *
     * @param  bool|null  $escrow  에스크로 여부
     * @param  bool|null  $hasTransactionId  true 면 거래번호가 null 이 아닌 행만
     * @param  bool  $nonEmptyTransactionId  true 면 거래번호가 빈 문자열인 행도 뺀다
     * @param  PaymentStatusEnum|null  $status  결제 상태
     * @param  int|null  $ownerUserId  주문 회원 id
     */
    public function __construct(
        public ?bool $escrow = null,
        public ?bool $hasTransactionId = null,
        public bool $nonEmptyTransactionId = false,
        public ?PaymentStatusEnum $status = null,
        public ?int $ownerUserId = null,
    ) {}

    /**
     * 거래번호 조건이 걸렸는지 확인합니다(이 경우 결제 전 행 인수 규칙을 적용하지 않는다).
     *
     * @return bool 거래번호 조건 여부
     */
    public function requiresTransactionId(): bool
    {
        return $this->hasTransactionId === true || $this->nonEmptyTransactionId;
    }
}
