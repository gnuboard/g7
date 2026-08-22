<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'nhnkcp';

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
     * KCP 결제 건 (pg_provider=nhnkcp) 만 처리. before_cancel/after_cancel
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

        $tno = $payment->transaction_id;
        if (! $tno) {
            return [
                'success' => false,
                'error_code' => 'MISSING_TNO',
                'error_message' => __('sirsoft-pay_nhnkcp::messages.refund.missing_tid'),
                'transaction_id' => null,
            ];
        }

        try {
            $lock = Cache::lock('nhnkcp:refund:' . $payment->id, 30);
            if (! $lock->get()) {
                return [
                    'success' => false,
                    'error_code' => 'REFUND_IN_PROGRESS',
                    'error_message' => __('sirsoft-pay_nhnkcp::messages.refund.in_progress'),
                    'transaction_id' => null,
                ];
            }

            try {
                $apiService = app(NhnKcpApiService::class);
                $this->restorePaymentCredentials($apiService, $payment);

                $cancelMsg = $reason ?? __('sirsoft-pay_nhnkcp::messages.refund.default_reason');
                $cancelAmt = (int) $refundAmount;
                $ordrIdxx = (string) $order->order_number;

                // $refundAmount 는 코어가 결제 통화(order_currency)로 환산해 전달한 실환불액이다.
                // 누적 취소액도 결제 통화 기준(mc_cancelled_amount[order_currency])으로 맞춰야
                // base≠결제통화 주문에서 부분취소 판정·총액이 어긋나 PG 실환불이 잘못 전송되지 않는다.
                $paidAmount = (int) $payment->paid_amount_local;
                $cumulativeCancelled = (int) round((float) $this->cancelledLocalAmount($payment));
                $previousCancelled = max(0, $cumulativeCancelled - $cancelAmt);
                $totalAmt = max($cancelAmt, $paidAmount - $previousCancelled);
                $isPartial = $previousCancelled > 0 || $cancelAmt < $paidAmount;
                $response = $apiService->cancelPayment($tno, $ordrIdxx, $cancelAmt, $cancelMsg, $isPartial, $totalAmt);

                Log::info('KCP: refund success', [
                    'order_id' => $order->id,
                    'tno' => $tno,
                    'cancel_amt' => $cancelAmt,
                ]);

                return [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'transaction_id' => $response['tno'] ?? $tno,
                ];
            } finally {
                $lock->release();
            }
        } catch (\Exception $e) {
            Log::error('KCP: refund failed', [
                'order_id' => $order->id,
                'tno' => $tno,
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

    private function restorePaymentCredentials(NhnKcpApiService $apiService, OrderPayment $payment): void
    {
        $meta = $payment->payment_meta ?? [];
        if (! array_key_exists('is_test_mode', $meta) || empty($meta['site_cd'])) {
            return;
        }

        $apiService->useStoredCredentials((bool) $meta['is_test_mode'], (string) $meta['site_cd']);
    }

    /**
     * 결제 통화(order_currency) 기준 누적 취소액을 반환합니다.
     *
     * 코어가 결제 통화로 누적한 mc_cancelled_amount[order_currency] 를 우선 사용하고,
     * 없으면(레거시 결제) base 누적 cancelled_amount 로 폴백합니다.
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @return float 결제 통화 기준 누적 취소액
     */
    private function cancelledLocalAmount(OrderPayment $payment): float
    {
        $currency = $payment->currency;
        $mc = $payment->mc_cancelled_amount ?? [];

        if ($currency !== null && isset($mc[$currency])) {
            return (float) $mc[$currency];
        }

        return (float) $payment->cancelled_amount;
    }
}
