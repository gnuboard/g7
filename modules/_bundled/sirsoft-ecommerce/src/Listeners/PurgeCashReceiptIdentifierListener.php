<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;

/**
 * 구매확정 시 재발급용 식별번호 암호문 폐기 리스너 (D15)
 *
 * 구매확정 이후에는 환불·재발급이 사실상 발생하지 않으므로, 개인정보 최소보유 원칙에 따라
 * 재발급용으로만 보관하던 식별번호 암호문(cash_receipt_identifier_encrypted)을 즉시 지운다.
 *
 * 이력 테이블·영수증 URL·마스킹 식별번호는 유지된다 (국세청 신고 근거).
 * 폐기 후 재발급이 필요해지면 관리자가 식별번호를 재입력해 발급한다.
 *
 * 서비스 간 직접 결합을 피하기 위해 훅(order.after_purchase_confirmed)을 경유한다.
 */
class PurgeCashReceiptIdentifierListener implements HookListenerInterface
{
    /**
     * @param  CashReceiptService  $cashReceiptService  현금영수증 서비스
     */
    public function __construct(
        protected CashReceiptService $cashReceiptService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 구독 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order.after_purchase_confirmed' => [
                'method' => 'purgeIdentifier',
                'priority' => 50,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 구매확정된 주문의 식별번호 암호문을 폐기합니다.
     *
     * 이미 폐기되었거나 애초에 저장된 적 없는 경우에도 안전하다 (멱등).
     *
     * @param  Order  $order  구매확정된 주문
     */
    public function purgeIdentifier(Order $order): void
    {
        try {
            $order->loadMissing('payment');

            $this->cashReceiptService->purgeIdentifier($order);
        } catch (\Throwable $e) {
            Log::error('PurgeCashReceiptIdentifierListener: 식별번호 폐기 실패', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
