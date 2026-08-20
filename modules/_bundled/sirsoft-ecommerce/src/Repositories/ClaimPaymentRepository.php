<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;

/**
 * 클레임 추가결제 리포지토리 구현체
 */
class ClaimPaymentRepository implements ClaimPaymentRepositoryInterface
{
    /**
     * @param  ClaimPayment  $model  클레임 추가결제 모델
     */
    public function __construct(
        protected ClaimPayment $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ClaimPayment
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(ClaimPayment $payment, array $data): ClaimPayment
    {
        $payment->update($data);

        return $payment->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ClaimPayment
    {
        return $this->model->newQuery()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByPaymentNumber(string $paymentNumber): ?ClaimPayment
    {
        return $this->model->newQuery()
            ->where('payment_number', $paymentNumber)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByTransactionId(string $transactionId): ?ClaimPayment
    {
        return $this->model->newQuery()
            ->where('transaction_id', $transactionId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function listByPayable(string $payableType, int $payableId): Collection
    {
        return $this->model->newQuery()
            ->where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function sumPaidAmount(string $payableType, int $payableId): float
    {
        $row = $this->model->newQuery()
            ->where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->where('payment_status', ClaimPaymentStatusEnum::PAID->value)
            ->selectRaw('COALESCE(SUM(amount), 0) as pg_total, COALESCE(SUM(points_amount), 0) as points_total')
            ->first();

        return (float) ($row?->pg_total ?? 0) + (float) ($row?->points_total ?? 0);
    }

    /**
     * {@inheritDoc}
     */
    public function findForUpdate(int $id): ?ClaimPayment
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->find($id);
    }
}
