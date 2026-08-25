<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;

/**
 * 주문 환불 옵션 리포지토리 구현체
 */
class OrderRefundOptionRepository implements OrderRefundOptionRepositoryInterface
{
    /**
     * @param  OrderRefundOption  $model  주문 환불 옵션 모델
     */
    public function __construct(
        protected OrderRefundOption $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderRefundOption
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(int $refundId, string $status, ?int $processedBy = null, ?Carbon $completedAt = null): int
    {
        $updates = [
            'option_status' => $status,
            'updated_at' => now(),
        ];

        // 처리자·완료 시각은 값이 주어졌을 때만 기입한다 — 원값을 무조건 덮어쓰면
        // 처리자 미상 호출이 이미 기록된 상세 행의 처리자를 NULL 로 지운다.
        if ($processedBy !== null) {
            $updates['processed_by'] = $processedBy;
        }

        if ($completedAt !== null) {
            $updates['completed_at'] = $completedAt;
        }

        return $this->model->newQuery()
            ->where('order_refund_id', $refundId)
            ->update($updates);
    }
}
