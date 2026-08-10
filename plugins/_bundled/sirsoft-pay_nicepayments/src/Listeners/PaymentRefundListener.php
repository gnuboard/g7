<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'nicepayments';
    private const MONEY_EPSILON = 0.0001;

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.refund' => [
                'method' => 'processRefund',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * 환불 처리 훅 핸들러 (sirsoft-ecommerce.payment.refund 필터)
     *
     * NicePay 결제 건 (pg_provider=nicepayments) 만 처리. 가상계좌 입금 완료 건은
     * 환불계좌 정보가 필요해 별도 어드민 API 로 우회. before_cancel/after_cancel
     * 액션 훅을 발행하여 외부 확장 지점 제공.
     *
     * @param  array  $result  이전 필터 누적 결과
     * @param  Order  $order  대상 주문
     * @param  OrderPayment  $payment  대상 결제 정보
     * @param  float  $refundAmount  환불 금액
     * @param  string|null  $reason  환불 사유 (없으면 기본 메시지)
     * @return array success / error_code / error_message / transaction_id
     */
    public function processRefund(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
    ): array {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        // 가상계좌 입금 완료 건은 환불 계좌 정보가 필요하므로 일반 훅으로 처리 불가
        // payment_status 는 훅 호출 전 updatePayment() 에서 이미 CANCELLED 로 변경되므로
        // 입금 여부 판별에는 paid_at (입금 확인 시 설정) 을 사용한다
        if ($payment->vbank_number !== null
            && $payment->paid_at !== null) {
            return [
                'success' => false,
                'error_code' => 'VBANK_REQUIRES_BANK_INFO',
                'error_message' => __('sirsoft-pay_nicepayments::messages.errors.vbank_completed_requires_bank_info'),
                'transaction_id' => null,
            ];
        }

        $tid = $payment->transaction_id;
        if (! $tid) {
            return [
                'success' => false,
                'error_code' => 'MISSING_TID',
                'error_message' => __('sirsoft-pay_nicepayments::messages.refund.missing_tid'),
                'transaction_id' => null,
            ];
        }

        try {
            $apiService = app(NicePaymentsApiService::class);
            $this->useStoredCredentials($apiService, $payment);

            $cancelMsg = $reason ?? __('sirsoft-pay_nicepayments::messages.refund.default_reason');
            $cancelAmt = (int) $refundAmount;

            if ($cancelAmt <= 0) {
                return [
                    'success' => false,
                    'error_code' => 'INVALID_REFUND_AMOUNT',
                    'error_message' => __('sirsoft-pay_nicepayments::messages.errors.invalid_refund_amount', ['amount' => $cancelAmt]),
                    'transaction_id' => null,
                ];
            }

            $moid = (string) $order->order_number;

            $isPartial = $this->shouldUsePartialCancelCode($payment, $cancelAmt);
            $response = $apiService->cancelPayment($tid, $moid, $cancelAmt, $cancelMsg, $isPartial ? 1 : 0);

            Log::info('NicePayments: refund success', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => $cancelAmt,
            ]);

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'transaction_id' => $response['TID'] ?? $tid,
            ];
        } catch (\Exception $e) {
            Log::error('NicePayments: refund failed', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => (int) $refundAmount,
                'error' => $e->getMessage(),
            ]);

            HookManager::doAction('sirsoft-pay_nicepayments.payment.refund_failed', $order, $payment, [
                'tid' => $tid,
                'cancel_amt' => (int) $refundAmount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error_code' => 'PG_API_ERROR',
                'error_message' => $e->getMessage(),
                'transaction_id' => null,
            ];
        }
    }

    private function useStoredCredentials(NicePaymentsApiService $apiService, OrderPayment $payment): void
    {
        $meta = $payment->payment_meta ?? [];
        $mid = trim((string) ($meta['mid'] ?? ''));
        if ($mid === '' || ! array_key_exists('is_test_mode', $meta)) {
            return;
        }

        $apiService->useStoredCredentials((bool) $meta['is_test_mode'], $mid);
    }

    /**
     * 코어는 PG 환불 훅 호출 전에 누적 취소액을 먼저 반영한다.
     * 따라서 남은 잔액 취소 시 getCancellableAmount() 만 보면 전액취소처럼 보일 수 있으므로,
     * 이번 환불 이전에 이미 취소된 결제 통화 금액이 있었는지도 함께 확인한다.
     */
    private function shouldUsePartialCancelCode(OrderPayment $payment, int $cancelAmt): bool
    {
        $cancelledBeforeThisRefund = max(0.0, $payment->cancelledLocalAmount() - $cancelAmt);

        return $cancelledBeforeThisRefund > self::MONEY_EPSILON
            || $payment->getCancellableAmount() > self::MONEY_EPSILON;
    }
}
