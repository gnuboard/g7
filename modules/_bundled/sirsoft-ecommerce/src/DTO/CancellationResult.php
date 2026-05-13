<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 취소 실행 결과 DTO
 *
 * 취소 처리 후 결과 정보를 담습니다.
 */
class CancellationResult
{
    /**
     * @param  Order  $order  취소된 주문
     * @param  OrderCancel  $orderCancel  생성된 취소 레코드
     * @param  OrderRefund|null  $orderRefund  생성된 환불 레코드 (미결제 취소 시 null)
     * @param  AdjustmentResult  $adjustmentResult  재계산 결과
     * @param  CancelTypeEnum  $cancellationType  취소 유형 (full/partial)
     * @param  array  $cancelledOptionIds  취소된 옵션 ID 배열
     * @param  float  $refundAmount  PG 환불금액
     * @param  float  $refundPointsAmount  마일리지 환불액
     * @param  bool  $pgRefundSuccess  PG 환불 성공 여부
     */
    public function __construct(
        public Order $order,
        public OrderCancel $orderCancel,
        public ?OrderRefund $orderRefund,
        public AdjustmentResult $adjustmentResult,
        public CancelTypeEnum $cancellationType,
        public array $cancelledOptionIds = [],
        public float $refundAmount = 0,
        public float $refundPointsAmount = 0,
        public bool $pgRefundSuccess = true,
    ) {}

    /**
     * 전체취소 여부를 반환합니다.
     *
     * @return bool
     */
    public function isFullCancel(): bool
    {
        return $this->cancellationType === CancelTypeEnum::FULL;
    }

    /**
     * 부분취소 여부를 반환합니다.
     *
     * @return bool
     */
    public function isPartialCancel(): bool
    {
        return $this->cancellationType === CancelTypeEnum::PARTIAL;
    }
}
