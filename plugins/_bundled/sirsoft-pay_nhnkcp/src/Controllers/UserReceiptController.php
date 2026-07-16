<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNhnkcp\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

class UserReceiptController
{
    use IssuesReceiptCookie;
    use ResolvesEasyPayDisplay;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    // 카드/계좌이체 영수증 URL (cmd= 로 끝나므로 cmd 값을 바로 붙임)
    private const BILL_URL_TEST = 'https://testadmin8.kcp.co.kr/assist/bill.BillActionNew.do?cmd=';

    private const BILL_URL_LIVE = 'https://admin8.kcp.co.kr/assist/bill.BillActionNew.do?cmd=';

    // 현금영수증 URL (term_id=PGNW 로 끝나므로 site_cd 값을 바로 붙임)
    private const CASH_URL_TEST = 'https://testadmin8.kcp.co.kr/Modules/Service/Cash/Cash_Bill_Common_View.jsp?term_id=PGNW';

    private const CASH_URL_LIVE = 'https://admin.kcp.co.kr/Modules/Service/Cash/Cash_Bill_Common_View.jsp?term_id=PGNW';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NhnKcpApiService $apiService,
        private readonly GuestOrderAuthService $guestOrderAuthService,
    ) {}

    /**
     * GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/receipt
     */
    /**
     * 사용자 마이페이지 KCP 영수증 정보 조회
     *
     * 결제 영수증 URL(신용카드)과 현금영수증 URL(현금/계좌이체)을 반환한다.
     * receipt_url 이 비어있으면 transaction_id (tno) 로 KCP 영수증 URL 을 동적 생성.
     *
     * @param  Request  $request  회원 또는 비회원 영수증 요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse receipt_url / cash_receipt_url 또는 404
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'nhnkcp')
            ->whereNotNull('p.transaction_id');

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
                'p.payment_meta',
                'p.payment_method',
                'p.embedded_pg_provider',
                'p.payment_device',
                'p.paid_amount_local',
                'o.order_number',
            ])
            ->first();

        if (! $payment || ! $payment->transaction_id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTest = (bool) ($settings['is_test_mode'] ?? true);

        $billBaseUrl = $isTest ? self::BILL_URL_TEST : self::BILL_URL_LIVE;
        $cashBaseUrl = $isTest ? self::CASH_URL_TEST : self::CASH_URL_LIVE;

        $tno = $payment->transaction_id;
        $orderNo = $payment->order_number;
        $tradeMony = (int) round((float) ($payment->paid_amount_local ?? 0));
        $method = $payment->payment_method ?? '';
        $display = $this->resolvePaymentDisplay($payment);

        // 결제수단 분류 — KCP 영수증 cmd 는 결제수단(휴대폰결제 vs 그 외) 으로만 분기.
        // 디바이스(PC/모바일) 와 무관 — 모바일 디바이스에서 카드결제해도 card_bill 로 가야 함.
        // 그누보드5 settle_kcp_common.php 패턴과 일치.
        $isPhonePayment = $method === 'phone';
        $isCashMethod = in_array($method, ['bank', 'vbank'], true);

        // 휴대폰결제 → mcash_bill, 그 외 (card / bank / vbank) → card_bill
        $billCmd = $isPhonePayment ? 'mcash_bill' : 'card_bill';
        $receiptUrl = $billBaseUrl . $billCmd
            . '&tno=' . urlencode($tno)
            . '&order_no=' . urlencode($orderNo)
            . '&trade_mony=' . $tradeMony;

        // 현금영수증 URL (계좌이체 · 가상계좌만 해당)
        $cashReceiptUrl = null;
        if ($isCashMethod) {
            $meta = $this->decodePaymentMeta($payment->payment_meta ?? null);
            $pgRaw = $meta['pg_raw_response'] ?? $meta;
            $authNo = $pgRaw['app_no'] ?? $pgRaw['receipt_no'] ?? $tno;
            $siteCd = $this->apiService->getSiteCd();

            $cashReceiptUrl = $cashBaseUrl . $siteCd
                . '&orderid=' . urlencode($orderNo)
                . '&bill_yn=Y'
                . '&authno=' . urlencode((string) $authNo);
        }

        return response()->json([
            'receipt_url'      => $receiptUrl,
            'cash_receipt_url' => $cashReceiptUrl,
            'is_test_mode'     => $isTest,
            'payment_method_label' => $display['payment_method_label'],
            'payment_method_display_label' => $display['payment_method_display_label'],
            'selected_payment_method' => $display['selected_payment_method'],
            'embedded_pg_provider' => $display['embedded_pg_provider'],
            'embedded_pg_provider_label' => $display['embedded_pg_provider_label'],
        ]);
    }
}
