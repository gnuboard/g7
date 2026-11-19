<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청·응답 무변경 — 주문·결제 조회와 기록을 이커머스 공개 결제 계약으로 옮긴 내부 리팩토링 (#916)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ReceiptRequester;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNicepayments\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;

class UserReceiptController extends PublicBaseController
{
    use IssuesReceiptCookie;
    use ResolvesEasyPayDisplay;

    private const RECEIPT_BASE_URL = 'https://npg.nicepay.co.kr/issue/IssueLoader.do';

    /**
     * 이커머스 공개 결제 계약을 주입받습니다.
     *
     * @param  PaymentGatewayBridge  $paymentGateway  이커머스 공개 결제 계약 (영수증 결제 행 조회, 비회원 조회 토큰 검증)
     */
    public function __construct(
        private readonly PaymentGatewayBridge $paymentGateway,
    ) {
        parent::__construct();
    }

    /**
     * 사용자 마이페이지 영수증 정보 조회
     *
     * 결제 영수증 URL(신용카드)과 현금영수증 URL(현금/계좌이체)을 반환한다.
     * receipt_url 이 비어있으면 transaction_id 로 NicePay IssueLoader URL 을 동적 생성.
     * 테스트 모드 결제는 is_test_mode=true 플래그로 표시되어 UI 에서 안내 가능.
     *
     * 소유 조건(회원 본인, 비회원 조회 토큰, 이 플러그인이 서명한 비회원 영수증 쿠키)은 이커머스 계약이 결제 행 조회와 함께 건다.
     *
     * @param  Request  $request  회원 또는 비회원 영수증 요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse receipt_url / payment_receipt_url / cash_receipt_url / is_test_mode 또는 404
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — user()/비회원 영수증 쿠키만 참조 (검증 대상 필드 없음)
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $requester = ReceiptRequester::member((int) $user->id);
        } else {
            $token = $request->header('X-Guest-Order-Token');

            if ($this->paymentGateway->verifyGuestOrderToken($token, $orderNumber)) {
                $requester = ReceiptRequester::guestToken($token);
            } elseif ($this->verifyReceiptCookie($request->cookie(self::RECEIPT_COOKIE_NAME), $orderNumber)) {
                // 이 플러그인 서명 쿠키로 확인한 비회원 — 비회원 주문인지는 이커머스가 확인한다
                $requester = ReceiptRequester::guestVerifiedByPg();
            } else {
                return ResponseHelper::notFound();
            }
        }

        $payment = $this->paymentGateway->findReceiptPayment('nicepayments', $orderNumber, $requester);

        if (! $payment) {
            return ResponseHelper::notFound();
        }

        $receiptUrl = $payment->receiptUrl;
        if (! $receiptUrl && $payment->transactionId) {
            $receiptUrl = self::RECEIPT_BASE_URL.'?type=0&TID='.rawurlencode($payment->transactionId);
        }

        $cashReceiptUrl = null;
        $display = $this->resolvePaymentDisplay($payment);
        $meta = $payment->paymentMeta;
        $rcptTid = $meta['rcpt_tid'] ?? ($meta['pg_raw_response']['RcptTID'] ?? null);
        if ($rcptTid) {
            $cashReceiptUrl = self::RECEIPT_BASE_URL.'?type=1&TID='.rawurlencode($rcptTid);
        }
        $isTestMode = (bool) ($meta['is_test_mode'] ?? false);

        // 마이페이지 「결제 영수증」 링크가 첫 출시부터 열어 온 주소(type=2). 레이아웃에 결제사 외부 주소를 적으면
        // 편집기 저장 게이트(외부 URL 금지)가 거절하므로 서버가 내려준다. receipt_url(매출전표, type=0)과는
        // 다른 화면이 쓰는 다른 값이라 서로 대신하지 않는다.
        $paymentReceiptUrl = $payment->transactionId
            ? self::RECEIPT_BASE_URL.'?type=2&TID='.rawurlencode($payment->transactionId)
            : null;

        return ResponseHelper::success('common.success', [
            'receipt_url' => $receiptUrl,
            'payment_receipt_url' => $paymentReceiptUrl,
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
