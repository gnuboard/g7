<?php

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

/**
 * 입금 전에 취소된 주문의 가상계좌 발급 취소 리스너 (P-D1)
 *
 * 이커머스가 입금 전 취소를 커밋한 뒤 `sirsoft-ecommerce.payment.close_vbank` 필터로 부른다. 나이스페이는 입금 전
 * 가상계좌를 일반 취소 API(전액, 부분취소 코드 0)로 발급 취소한다. 입금 전 거래번호는 `payment_meta.vbank_tid` 에 있다.
 */
class VbankCloseListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'nicepayments';

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
     * @param  int  $issuedAmount  발급 금액 (결제 통화 최소 단위)
     * @return array 결과 (자기 결제면 handled=true)
     */
    public function closeVbank(array $result, Order $order, OrderPayment $payment, int $issuedAmount = 0): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $meta = $payment->payment_meta ?? [];
        $tid = trim((string) ($payment->transaction_id ?: ($meta['vbank_tid'] ?? '')));

        if ($tid === '' || $issuedAmount <= 0) {
            return ['handled' => true, 'success' => false, 'error_message' => $tid === '' ? 'missing_tid' : 'missing_amount'];
        }

        try {
            $apiService = app(NicePaymentsApiService::class);
            $mid = trim((string) ($meta['mid'] ?? ''));
            if ($mid !== '' && array_key_exists('is_test_mode', $meta)) {
                $apiService->useStoredCredentials((bool) $meta['is_test_mode'], $mid);
            }

            $apiService->cancelPayment($tid, (string) $order->order_number, $issuedAmount, __('sirsoft-ecommerce::messages.vbank_close_reason'), 0);

            return ['handled' => true, 'success' => true, 'error_message' => null];
        } catch (\Throwable $e) {
            Log::warning('NicePayments: 가상계좌 발급 취소 실패', [
                'order_id' => $order->id,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return ['handled' => true, 'success' => false, 'error_message' => $e->getMessage()];
        }
    }
}
