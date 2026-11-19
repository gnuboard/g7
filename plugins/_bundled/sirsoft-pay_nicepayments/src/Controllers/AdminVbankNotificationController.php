<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage reason: 주문과 결제 조회를 이커머스 공개 결제 계약으로 옮기고 응답을 ResponseHelper 로 만든다 — success, data(notifications, summary) 모양은 같고 성공 응답에 message 가 더해진다 (#916)

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNicepayments\Concerns\GuardsOrderScope;

/**
 * 어드민 — 가상계좌 입금통보 이력 조회 컨트롤러
 *
 * GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/vbank-notifications
 *
 * OrderPaymentResource 는 payment_meta 를 노출하지 않으므로 (PII 보호),
 * 어드민 전용으로 입금통보 이력만 따로 추출해 반환한다.
 *
 * 응답 shape:
 *   {
 *     "success": true,
 *     "data": {
 *       "notifications": [...],   // 시간순 통보 entry 배열
 *       "summary": {...}           // 한 줄 요약 (count/timestamps/depositor)
 *     }
 *   }
 *
 * 미들웨어: auth:sanctum + admin (routes/api.php 그룹에서 적용)
 */
class AdminVbankNotificationController
{
    use GuardsOrderScope;

    /** 이 플러그인 결제 행의 결제사 id(옛 식별자 포함 — 옛 id 로 발급된 가상계좌 이력도 보인다) */
    private const PG_SCOPE = ['nicepayments', 'nicepay'];

    /**
     * 의존성을 주입받습니다.
     *
     * @param  PaymentGatewayBridge  $paymentGateway  이커머스 공개 결제 계약 (주문번호 조회)
     */
    public function __construct(
        private readonly PaymentGatewayBridge $paymentGateway,
    ) {}

    /**
     * 가상계좌 입금통보 이력 조회
     *
     * 주문의 payment_meta 에 누적 저장된 NicePay 입금통보 이벤트 (계좌발급/입금완료/취소/재통보)
     * 를 timeline 형태로 반환한다. 어드민 패널에서 통보 시점/금액/예금주를 추적 가능.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse notifications + summary, 또는 404 (주문 없음) / 빈 배열 (가상계좌 아님)
     */
    public function show(string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.read')) {
            return $denied;
        }

        $order = $this->paymentGateway->findOrderByNumber($orderNumber, self::PG_SCOPE);

        if (! $order) {
            return ResponseHelper::error(
                'messages.errors.order_not_found',
                404,
                null,
                [],
                'sirsoft-pay_nicepayments',
                ['notifications' => [], 'summary' => null],
            );
        }

        // 결제 행은 이 플러그인 행이거나 아직 결제 전인 행일 때만 실린다(다른 결제사가 결제한 행은 null).
        $payment = $order->payment;
        if (! $payment || ! $payment->isVirtualAccount) {
            return ResponseHelper::success('common.success', ['notifications' => [], 'summary' => null]);
        }

        $meta = $payment->paymentMeta;
        $notifications = is_array($meta['vbank_notifications'] ?? null) ? $meta['vbank_notifications'] : [];
        $summary = is_array($meta['vbank_notification_summary'] ?? null) ? $meta['vbank_notification_summary'] : null;

        return ResponseHelper::success('common.success', [
            'notifications' => $notifications,
            'summary' => $summary,
        ]);
    }
}
