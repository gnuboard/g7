<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNicepayments\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;

class UserReceiptController
{
    use IssuesReceiptCookie;
    use ResolvesEasyPayDisplay;

    private const RECEIPT_BASE_URL = 'https://npg.nicepay.co.kr/issue/IssueLoader.do';

    public function __construct(
        private readonly GuestOrderAuthService $guestOrderAuthService,
    ) {}

    /**
     * 사용자 마이페이지 영수증 정보 조회
     *
     * 결제 영수증 URL(신용카드)과 현금영수증 URL(현금/계좌이체)을 반환한다.
     * receipt_url 이 비어있으면 transaction_id 로 NicePay IssueLoader URL 을 동적 생성.
     * 테스트 모드 결제는 is_test_mode=true 플래그로 표시되어 UI 에서 안내 가능.
     *
     * @param  Request  $request  회원 또는 비회원 영수증 요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse receipt_url / cash_receipt_url / is_test_mode 또는 404
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'nicepayments');

        if ($user) {
            $query->where('o.user_id', $user->id);
        } else {
            $order = $this->guestOrderAuthService->verifyToken($request->header('X-Guest-Order-Token'), $orderNumber);

            if ($order) {
                $query->whereNull('o.user_id')->where('o.id', $order->id);
            } elseif ($this->verifyReceiptCookie($request->cookie(self::RECEIPT_COOKIE_NAME), $orderNumber)) {
                $query->whereNull('o.user_id')
                    ->where('o.id', function ($sub) use ($orderNumber) {
                        $sub->select('id')->from('ecommerce_orders')->where('order_number', $orderNumber);
                    });
            } else {
                return response()->json(['error' => 'Not found'], 404);
            }
        }

        $payment = $query
            ->select([
                'p.transaction_id',
                'p.receipt_url',
                'p.payment_meta',
                'p.payment_method',
                'p.embedded_pg_provider',
            ])
            ->first();

        if (! $payment) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $receiptUrl = $payment->receipt_url;
        if (! $receiptUrl && $payment->transaction_id) {
            $receiptUrl = self::RECEIPT_BASE_URL . '?type=0&TID=' . rawurlencode($payment->transaction_id);
        }

        $cashReceiptUrl = null;
        $isTestMode = false;
        $display = $this->resolvePaymentDisplay($payment);
        if ($payment->payment_meta) {
            $meta = json_decode($payment->payment_meta, true);
            $rcptTid = $meta['rcpt_tid'] ?? ($meta['pg_raw_response']['RcptTID'] ?? null);
            if ($rcptTid) {
                $cashReceiptUrl = self::RECEIPT_BASE_URL . '?type=1&TID=' . rawurlencode($rcptTid);
            }
            $isTestMode = (bool) ($meta['is_test_mode'] ?? false);
        }

        return response()->json([
            'receipt_url' => $receiptUrl,
            'cash_receipt_url' => $cashReceiptUrl,
            'is_test_mode' => $isTestMode,
            'payment_method_label' => $display['payment_method_label'],
            'payment_method_display_label' => $display['payment_method_display_label'],
            'selected_payment_method' => $display['selected_payment_method'],
            'embedded_pg_provider' => $display['embedded_pg_provider'],
            'embedded_pg_provider_label' => $display['embedded_pg_provider_label'],
        ]);
    }
}
