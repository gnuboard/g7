<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * KG 이니시스 PG 결제 취소 활동 로그 리스너.
 *
 * 코어의 sirsoft-ecommerce.payment.refund 필터 훅을 priority 20 으로 구독해
 * PaymentRefundListener (priority 10) 가 KG 이니시스 cancel API 호출에 성공한
 * 직후 실행. 성공한 PG 응답을 관찰만 하고 통과시킨다 (mutation 없음).
 *
 * 핵심 의도: 코어가 이미 기록하는 order.cancel (주문 단위 취소 의도) 와 별도로,
 * "PG 가 실제로 취소를 확인했다" 는 별도 이벤트를 활동 로그에 보존한다.
 * 운영자가 PG 측 응답 시각 / TID 등 사후 추적이 필요할 때 사용.
 */
class CancelActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    private const PG_PROVIDER_ID = 'kginicis';

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.refund' => [
                'method' => 'logCancelConfirmed',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * PG 가 결제 취소를 확인한 직후 활동 로그를 기록한다.
     *
     * @param  array  $result  이전 필터 누적 결과 (PaymentRefundListener 반환)
     * @param  Order  $order  대상 주문
     * @param  OrderPayment  $payment  대상 결제 정보
     * @param  float  $refundAmount  환불 금액
     * @param  string|null  $reason  취소 사유
     * @return array 결과 통과 (mutation 없음)
     */
    public function logCancelConfirmed(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
    ): array {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }
        if (empty($result['success'])) {
            return $result;
        }

        $this->logActivity('payment.cancel', [
            'loggable' => $order,
            'description_key' => 'sirsoft-pay_kginicis::activity_log.description.payment_cancel',
            'description_params' => [
                'order_number' => $order->order_number,
                'refund_amount' => number_format((int) $refundAmount),
            ],
            'properties' => [
                'tid' => $payment->transaction_id,
                'cancel_tid' => $result['transaction_id'] ?? null,
                'refund_amount' => $refundAmount,
                'reason' => $reason,
            ],
        ]);

        return $result;
    }
}
