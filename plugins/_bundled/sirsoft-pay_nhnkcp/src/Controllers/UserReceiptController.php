<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ReceiptRequester;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNhnkcp\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

class UserReceiptController extends PublicBaseController
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

    /**
     * 의존성을 주입받습니다.
     *
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스(테스트 모드 판정)
     * @param  NhnKcpApiService  $apiService  KCP API 서비스(현금영수증 주소의 사이트 코드)
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약 (요청자 조건을 건 영수증 결제 행 조회, 비회원 토큰 검증)
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NhnKcpApiService $apiService,
        private readonly PaymentGatewayBridge $bridge,
    ) {
        parent::__construct();
    }

    /**
     * 사용자 마이페이지 KCP 영수증 정보 조회 — GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/receipt
     *
     * 결제 영수증 URL(신용카드)과 현금영수증 URL(현금/계좌이체)을 반환한다.
     * receipt_url 이 비어있으면 transaction_id (tno) 로 KCP 영수증 URL 을 동적 생성.
     *
     * 응답은 공통 형식(`ResponseHelper`)이며 영수증 정보는 `data` 에 담는다. 대상이 없으면 404(`ResponseHelper::notFound`).
     *
     * @param  Request  $request  회원 또는 비회원 영수증 요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 영수증 정보(data) 또는 404
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — user()/비회원 영수증 쿠키만 참조 (검증 대상 필드 없음)
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        // 요청자 조건 — 회원은 본인 주문, 비회원은 이 주문의 조회 토큰(계약이 검증) 또는 이 플러그인의 영수증 쿠키(비회원 주문만)
        $user = $request->user();
        if ($user) {
            $requester = ReceiptRequester::member((int) $user->id);
        } else {
            $guestToken = $request->header('X-Guest-Order-Token');

            if ($this->bridge->verifyGuestOrderToken($guestToken, $orderNumber)) {
                $requester = ReceiptRequester::guestToken($guestToken);
            } elseif ($this->verifyReceiptCookie($request->cookie(self::RECEIPT_COOKIE_NAME), $orderNumber)) {
                $requester = ReceiptRequester::guestVerifiedByPg();
            } else {
                return ResponseHelper::notFound();
            }
        }

        $payment = $this->bridge->findReceiptPayment('nhnkcp', $orderNumber, $requester, new PaymentFilters(hasTransactionId: true));

        if (! $payment || ! $payment->transactionId) {
            return ResponseHelper::notFound();
        }

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTest = (bool) ($settings['is_test_mode'] ?? true);

        $billBaseUrl = $isTest ? self::BILL_URL_TEST : self::BILL_URL_LIVE;
        $cashBaseUrl = $isTest ? self::CASH_URL_TEST : self::CASH_URL_LIVE;

        $tno = $payment->transactionId;
        $orderNo = (string) $payment->orderNumber;
        $tradeMony = (int) round($payment->paidAmountLocal);
        $method = $payment->paymentMethod ?? '';
        $display = $this->resolvePaymentDisplay($payment);

        // 결제수단 분류 — KCP 영수증 cmd 는 결제수단(휴대폰결제 vs 그 외) 으로만 분기.
        // 디바이스(PC/모바일) 와 무관 — 모바일 디바이스에서 카드결제해도 card_bill 로 가야 함.
        // 그누보드5 settle_kcp_common.php 패턴과 일치.
        $isPhonePayment = $method === 'phone';
        $isCashMethod = in_array($method, ['bank', 'vbank'], true);

        // 휴대폰결제 → mcash_bill, 그 외 (card / bank / vbank) → card_bill
        $billCmd = $isPhonePayment ? 'mcash_bill' : 'card_bill';
        $receiptUrl = $billBaseUrl.$billCmd
            .'&tno='.urlencode($tno)
            .'&order_no='.urlencode($orderNo)
            .'&trade_mony='.$tradeMony;

        // 현금영수증 URL (계좌이체 · 가상계좌만 해당)
        $cashReceiptUrl = null;
        if ($isCashMethod) {
            $meta = $payment->paymentMeta;
            $pgRaw = $meta['pg_raw_response'] ?? $meta;
            $authNo = $pgRaw['app_no'] ?? $pgRaw['receipt_no'] ?? $tno;
            $siteCd = $this->apiService->getSiteCd();

            $cashReceiptUrl = $cashBaseUrl.$siteCd
                .'&orderid='.urlencode($orderNo)
                .'&bill_yn=Y'
                .'&authno='.urlencode((string) $authNo);
        }

        return ResponseHelper::success('common.success', [
            'receipt_url' => $receiptUrl,
            'cash_receipt_url' => $cashReceiptUrl,
            'is_test_mode' => $isTest,
            'payment_method_label' => $display['payment_method_label'],
            'payment_method_display_label' => $display['payment_method_display_label'],
            'selected_payment_method' => $display['selected_payment_method'],
            'embedded_pg_provider' => $display['embedded_pg_provider'],
            'embedded_pg_provider_label' => $display['embedded_pg_provider_label'],
        ]);
    }
}
