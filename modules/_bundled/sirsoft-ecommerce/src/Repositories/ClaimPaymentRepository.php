<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
        // 실수납 판정(netPaidAmount)이 행 단위 규칙이라 DB 집계로 내리지 않는다.
        return (float) $this->model->newQuery()
            ->where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->where('payment_status', ClaimPaymentStatusEnum::PAID->value)
            ->get() // audit:allow query-unbounded-get reason: 한 신청서의 결제 건 목록 — 건수가 그 신청서의 청구 횟수(운영자 금액 수정·재청구)에 묶인다
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
    public function listPendingOlderThan(Carbon $cutoff, int $limit): Collection
    {
        return $this->model->newQuery()
            ->where('payment_status', ClaimPaymentStatusEnum::PENDING->value)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();
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
        // 다통화 안분(부분 환급 비율 × 결제 시점 동결 mc 맵)이 행 단위 계산이라 DB 집계로 내리지 않는다.
        $payments = $this->model->newQuery()
            ->where('order_id', $orderId)
            ->where('payment_status', ClaimPaymentStatusEnum::PAID->value)
            ->get(); // audit:allow query-unbounded-get reason: 한 주문의 결제 완료 건 목록 — 건수가 그 주문의 클레임 신청·재청구 횟수에 묶인다

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

    /**
     * {@inheritDoc}
     */
    public function updateIfStatus(ClaimPayment $payment, ClaimPaymentStatusEnum $expected, array $data): ?ClaimPayment
    {
        return DB::transaction(function () use ($payment, $expected, $data) {
            $locked = $this->model->newQuery()->lockForUpdate()->find($payment->id);

            if ($locked === null || $locked->payment_status !== $expected) {
                return null;
            }

            $locked->update($data);

            return $locked->fresh();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function hasRecordedPaymentMethod(string $method): bool
    {
        return $this->model->newQuery()->where('payment_method', $method)->exists();
    }
}
