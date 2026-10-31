<?php

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * 입금 전에 취소된 주문의 가상계좌 발급 취소 리스너 (P-D1)
 *
 * 이커머스가 입금 전 취소를 커밋한 뒤 `sirsoft-ecommerce.payment.close_vbank` 필터로 부른다. 토스는 입금 전 가상계좌를
 * 결제 취소 API(전액 — `cancelAmount` 생략)로 발급 취소한다(https://docs.tosspayments.com/guides/v2/cancel-payment).
 * 환불 리스너를 거치지 않는다 — 그 리스너는 가상계좌면 환불계좌를 요구하는데, 입금 전에는 돌려줄 돈이 없다.
 */
class VbankCloseListener implements HookListenerInterface
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
            'sirsoft-ecommerce.payment.close_vbank' => [
                'method' => 'closeVbank',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 자기 결제의 미입금 가상계좌를 발급 취소합니다.
     *
     * @param  array  $result  결과 {handled, success, error_message}
     * @param  Order  $order  취소된 주문
     * @param  OrderPayment  $payment  결제행
     * @return array 결과 (자기 결제면 handled=true)
     */
    public function closeVbank(array $result, Order $order, OrderPayment $payment): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        try {
            app(TossPaymentsApiService::class)->cancelPayment(
                (string) $payment->transaction_id,
                __('sirsoft-ecommerce::messages.vbank_close_reason'),
            );

            return ['handled' => true, 'success' => true, 'error_message' => null];
        } catch (\Throwable $e) {
            Log::warning('토스페이먼츠 가상계좌 발급 취소 실패', [
                'order_id' => $order->id,
                'payment_key' => $payment->transaction_id,
                'error' => $e->getMessage(),
            ]);

            return ['handled' => true, 'success' => false, 'error_message' => $e->getMessage()];
        }
    }
}
