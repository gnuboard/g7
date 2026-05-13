<?php

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * PG 결제 환불 리스너
 *
 * 이커머스 모듈의 결제 환불 훅을 구독하여
 * 토스페이먼츠 결제 취소 API를 호출합니다.
 */
class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'tosspayments';

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array 훅 구독 설정
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
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  인수
     * @return void
     */
    public function handle(...$args): void
    {
        // processRefund에서 처리
    }

    /**
     * PG 결제 환불을 처리합니다.
     *
     * @param  array  $result  환불 결과 (기본값)
     * @param  Order  $order  주문
     * @param  OrderPayment  $payment  결제 정보
     * @param  float  $refundAmount  환불 금액
     * @param  string|null  $reason  환불 사유
     * @return array 환불 결과 {success, error_code, error_message, transaction_id}
     */
    public function processRefund(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
    ): array {
        // 토스페이먼츠 결제가 아닌 경우 스킵
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $paymentKey = $payment->transaction_id;
        if (! $paymentKey) {
            return [
                'success' => false,
                'error_code' => 'MISSING_PAYMENT_KEY',
                'error_message' => __('sirsoft-tosspayments::messages.refund.missing_payment_key'),
                'transaction_id' => null,
            ];
        }

        try {
            $apiService = $this->getApiService();

            $cancelReason = $reason ?? __('sirsoft-tosspayments::messages.refund.default_reason');
            $cancelAmount = (int) $refundAmount;

            $response = $apiService->cancelPayment($paymentKey, $cancelReason, $cancelAmount);

            Log::info('토스페이먼츠 결제 취소 성공', [
                'order_id' => $order->id,
                'payment_key' => $paymentKey,
                'cancel_amount' => $cancelAmount,
            ]);

            // 토스 응답에서 취소 트랜잭션 ID 추출
            $cancels = $response['cancels'] ?? [];
            $lastCancel = end($cancels);
            $transactionId = $lastCancel['transactionKey'] ?? $paymentKey;

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'transaction_id' => $transactionId,
            ];
        } catch (\Exception $e) {
            Log::error('토스페이먼츠 결제 취소 실패', [
                'order_id' => $order->id,
                'payment_key' => $paymentKey,
                'cancel_amount' => (int) $refundAmount,
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

    /**
     * API 서비스 인스턴스를 가져옵니다.
     *
     * @return TossPaymentsApiService
     */
    protected function getApiService(): TossPaymentsApiService
    {
        return app(TossPaymentsApiService::class);
    }
}
