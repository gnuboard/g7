<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

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
    public function __construct(
        private readonly OrderProcessingService $orderService
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
        $order = $this->orderService->findByOrderNumber($orderNumber);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => __('sirsoft-pay_nicepayments::messages.errors.order_not_found'),
                'data' => ['notifications' => [], 'summary' => null],
            ], 404);
        }

        $payment = $order->payment;
        if (! $payment || ! $payment->isVirtualAccount()) {
            return response()->json([
                'success' => true,
                'data' => ['notifications' => [], 'summary' => null],
            ]);
        }

        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $notifications = is_array($meta['vbank_notifications'] ?? null) ? $meta['vbank_notifications'] : [];
        $summary = is_array($meta['vbank_notification_summary'] ?? null) ? $meta['vbank_notification_summary'] : null;

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
                'summary' => $summary,
            ],
        ]);
    }
}
