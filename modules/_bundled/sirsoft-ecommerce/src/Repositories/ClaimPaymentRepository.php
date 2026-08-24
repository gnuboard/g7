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
        // 부분 환급(재발송지 수정 차액)을 뺀 실수납 잔액을 합산한다 — 상태만 보면 환급된 차액이 수납으로 남는다.
        return (float) $this->model->newQuery()
            ->where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->where('payment_status', ClaimPaymentStatusEnum::PAID->value)
            ->get()
            ->sum(fn (ClaimPayment $payment) => $payment->netPaidAmount());
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

    /**
     * {@inheritDoc}
     */
    public function findPendingByPayable(string $payableType, int $payableId): ?ClaimPayment
    {
        return $this->model->newQuery()
            ->where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->where('payment_status', ClaimPaymentStatusEnum::PENDING->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function listPaidByPayable(string $payableType, int $payableId): Collection
    {
        return $this->model->newQuery()
            ->where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->where('payment_status', ClaimPaymentStatusEnum::PAID->value)
            ->orderBy('id')
            ->get()
            ->filter(fn (ClaimPayment $payment) => $payment->netPaidAmount() > 0)
            ->values();
    }

    /**
     * {@inheritDoc}
     */
    public function sumNetPaidByOrder(int $orderId): array
    {
        $payments = $this->model->newQuery()
            ->where('order_id', $orderId)
            ->where('payment_status', ClaimPaymentStatusEnum::PAID->value)
            ->get();

        $total = 0.0;
        $mc = [];

        foreach ($payments as $payment) {
            $net = $payment->netPaidAmount();

            if ($net <= 0) {
                continue;
            }

            $total += $net;

            // 다통화 금액은 결제 시점 동결값이라 부분 환급 비율만큼 줄여 합산한다.
            $ratio = $payment->getTotalAmount() > 0 ? $net / $payment->getTotalAmount() : 0;

            foreach ((array) ($payment->mc_amount ?? []) as $code => $amount) {
                $mc[$code] = (float) ($mc[$code] ?? 0) + ((float) $amount * $ratio);
            }
        }

        return ['amount' => $total, 'mc' => $mc];
    }

    /**
     * {@inheritDoc}
     */
    public function findByPaymentNumberForUpdate(string $paymentNumber): ?ClaimPayment
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->where('payment_number', $paymentNumber)
            ->first();
    }
}
