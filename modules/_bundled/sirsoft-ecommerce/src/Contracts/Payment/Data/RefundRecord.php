<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use Carbon\CarbonImmutable;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 주문 환불 요약 (공개 계약 DTO)
 *
 * @since 1.4.0
 */
final readonly class RefundRecord
{
    /**
     * RefundRecord 생성자
     *
     * @param  int  $id  환불 id
     * @param  string  $refundNumber  환불 번호
     * @param  RefundStatusEnum|null  $refundStatus  환불 상태
     * @param  float  $refundAmount  기준 통화 환불액
     * @param  string|null  $pgTransactionId  결제사 취소 거래번호
     * @param  CarbonImmutable|null  $refundedAt  환불 완료 시각
     */
    public function __construct(
        public int $id,
        public string $refundNumber,
        public ?RefundStatusEnum $refundStatus,
        public float $refundAmount,
        public ?string $pgTransactionId,
        public ?CarbonImmutable $refundedAt,
    ) {}

    /**
     * 환불 Model 에서 DTO 를 만듭니다.
     *
     * @internal 이커머스 계약 구현만 쓴다.
     *
     * @param  OrderRefund  $refund  환불 행
     * @return self DTO
     */
    public static function fromModel(OrderRefund $refund): self
    {
        $status = $refund->refund_status;

        return new self(
            id: (int) $refund->id,
            refundNumber: (string) $refund->refund_number,
            refundStatus: $status instanceof RefundStatusEnum ? $status : RefundStatusEnum::tryFrom((string) $status),
            refundAmount: (float) $refund->refund_amount,
            pgTransactionId: $refund->pg_transaction_id === null ? null : (string) $refund->pg_transaction_id,
            refundedAt: $refund->refunded_at === null ? null : CarbonImmutable::instance($refund->refunded_at),
        );
    }
}
