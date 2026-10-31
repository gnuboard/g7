<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;

/**
 * 취소된 주문에 들어온 가상계좌 입금을 기록하고 운영자에게 알립니다. (P-D1 · Q11 — 자동 환불은 하지 않는다)
 *
 * 결제 플러그인의 입금통보 처리가 「주문이 취소됨」 가드에서 부른다. 주문을 되살리지 않고(P4), 결제행
 * `payment_meta.deposit_after_cancel` 에 금액·시각·거래를 남긴 뒤 `sirsoft-ecommerce.order.deposit_after_cancel`
 * 훅을 낸다 — 직원 알림(`deposit_after_cancel_admin`)과 관리자 주문 상세 배너가 이 기록을 읽는다.
 *
 * 같은 입금의 통보가 재전송돼도(KCP 는 응답에 따라 최대 10회) 기록·알림은 한 번이다 — 거래 식별값이 같으면 건너뛴다.
 */
class DepositAfterCancelRecorder
{
    /**
     * @param  OrderPaymentRepositoryInterface  $paymentRepository  결제 Repository
     */
    public function __construct(
        protected OrderPaymentRepositoryInterface $paymentRepository,
    ) {}

    /**
     * 취소된 주문의 입금을 기록합니다.
     *
     * @param  Order  $order  취소된 주문
     * @param  float  $amount  입금액 (결제 통화)
     * @param  string  $provider  통보한 PG 제공자 ID
     * @param  string|null  $transactionId  입금 거래 식별값 (재전송 판별)
     * @return bool 새로 기록했으면 true (재전송이면 false)
     */
    public function record(Order $order, float $amount, string $provider, ?string $transactionId = null): bool
    {
        $recorded = DB::transaction(function () use ($order, $amount, $provider, $transactionId) {
            $payment = $this->paymentRepository->findByOrderIdForUpdate((int) $order->id);

            if ($payment === null) {
                return false;
            }

            $meta = $payment->payment_meta ?? [];
            $existing = $meta['deposit_after_cancel'] ?? null;

            if (is_array($existing) && ($existing['transaction_id'] ?? null) === $transactionId
                && (float) ($existing['amount'] ?? 0) === $amount) {
                return false;
            }

            $meta['deposit_after_cancel'] = [
                'amount' => $amount,
                'currency' => $payment->currency,
                'deposited_at' => Carbon::now()->toIso8601String(),
                'provider' => $provider,
                'transaction_id' => $transactionId,
            ];

            $this->paymentRepository->savePendingChanges($payment->forceFill(['payment_meta' => $meta]));

            return true;
        });

        if (! $recorded) {
            return false;
        }

        Log::warning('취소된 주문에 가상계좌 입금이 확인됐습니다 — 고객에게 환불해야 합니다', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'amount' => $amount,
            'provider' => $provider,
        ]);

        HookManager::doAction('sirsoft-ecommerce.order.deposit_after_cancel', $order->fresh(['payment']), [
            'amount' => $amount,
            'provider' => $provider,
        ]);

        return true;
    }
}
