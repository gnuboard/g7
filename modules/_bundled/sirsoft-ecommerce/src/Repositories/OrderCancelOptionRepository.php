<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelOptionRepositoryInterface;

/**
 * 주문 취소 옵션 리포지토리 구현체
 */
class OrderCancelOptionRepository implements OrderCancelOptionRepositoryInterface
{
    /**
     * @param  OrderCancelOption  $model  주문 취소 옵션 모델
     */
    public function __construct(
        protected OrderCancelOption $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderCancelOption
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(int $cancelId, string $status, ?int $processedBy = null, ?Carbon $completedAt = null): int
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
            ->where('order_cancel_id', $cancelId)
            ->update($updates);
    }

    /**
     * {@inheritDoc}
     */
    public function listReactivatableForRows(int $orderId, array $orderOptionIds): Collection
    {
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->whereIn('order_option_id', $orderOptionIds)
            ->whereColumn('reactivated_quantity', '<', 'cancel_quantity')
            ->whereHas('orderCancel', fn ($q) => $q
                ->where('cancel_status', CancelStatusEnum::COMPLETED->value)
                ->whereDoesntHave('refund', fn ($r) => $r->whereIn('refund_source_type', [
                    RefundSourceTypeEnum::RETURN->value,
                    RefundSourceTypeEnum::EXCHANGE->value,
                ])))
            ->orderByDesc('order_cancel_id')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function addReactivatedQuantity(OrderCancelOption $row, int $quantity): void
    {
        $row->increment('reactivated_quantity', $quantity);
    }

    /**
     * {@inheritDoc}
     */
    public function listByCancelId(int $cancelId): Collection
    {
        return $this->model->newQuery()
            ->where('order_cancel_id', $cancelId)
            ->get();
    }
}
