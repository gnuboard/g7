<?php

declare(strict_types=1);

// audit:allow api-doc-coverage reason: 이커머스 내부 서비스·저장소 대신 공개 결제 계약(PaymentGatewayBridge)을 생성자로 주입한 내부 전환이다. 응답은 공통 형식(ResponseHelper: success, message, data)으로 나가고 결제 화면이 읽는 data 항목과 오류 message 키는 그대로다. 오류 문구는 영어 고정에서 번역 키로 바뀌었다. 이 엔드포인트의 API 문서는 원래 없다(`api:docgen --scope=plugin:sirsoft-pay_kginicis --check` drift 없음).

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesCbtClaimPayments;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesTimestampFreshness;
use Plugins\Sirsoft\PayKginicis\Http\Requests\CbtHashDataRequest;
use Plugins\Sirsoft\PayKginicis\Services\CbtCheckoutTokenService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\CbtClaimAuthAmount;

class CbtHashDataController
{
    use HandlesCbtClaimPayments;
    use HandlesClaimPaymentCallbacks;
    use ValidatesCbtOrderContext;
    use ValidatesTimestampFreshness;

    /**
     * @param  KgInicisApiService  $apiService  KG 이니시스 API 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     * @param  CbtCheckoutTokenService  $checkoutTokenService  CBT 체크아웃 토큰 서비스
     * @param  CbtClaimAuthAmount  $claimAuthAmount  CBT 클레임 결제 인증요청 금액 기록
     */
    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly PaymentGatewayBridge $bridge,
        private readonly CbtCheckoutTokenService $checkoutTokenService,
        private readonly CbtClaimAuthAmount $claimAuthAmount,
    ) {}

    /**
     * generate
     *
     * 형식·길이 검증은 CbtHashDataRequest 가 담당한다 (국내 SignatureRequest 와 동일 강도).
     * 계약 변경: 빈 checkout_token 은 종전 403(토큰 검증 실패) → 422(필수 검증).
     *
     * @param  CbtHashDataRequest  $request  oid/price/timestamp/checkout_token/buyer 정보
     * @return JsonResponse hash_data 또는 403/404/422
     */
    public function generate(CbtHashDataRequest $request): JsonResponse
    {
        $oid = (string) $request->validated('oid');
        $price = (int) $request->validated('price');
        $timestamp = (string) $request->validated('timestamp');
        $checkoutToken = (string) $request->validated('checkout_token');

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.too_many_requests', 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if (! $this->isTimestampFresh($timestamp)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.timestamp_stale', 422);
        }

        if (! $this->apiService->isJapanEnabled()) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.cbt_disabled', 422);
        }

        if (! $this->apiService->isJapanConfigured()) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.cbt_not_configured', 422);
        }

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 Order not found 로 끝난다.
        // 검증 축은 토큰 발급 단계와 같고, 체크아웃 토큰 검증도 주문과 같은 경로를 그대로 쓴다.
        if ($this->bridge->isClaimPaymentNumber($oid)) {
            $claimError = $this->validateCbtClaimPaymentContext($request, $oid, $price);

            if ($claimError !== null) {
                return $claimError;
            }

            if (! $this->checkoutTokenService->verify(
                $checkoutToken,
                $oid,
                $price,
                (string) $request->input('buyer_email', ''),
                (string) $request->input('buyer_phone', ''),
                $request,
            )) {
                return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.cbt_token_invalid', 403);
            }

            // CBT 승인 응답에는 금액이 없다 — 이 해시로 서명하는 인증요청 금액을 적어 두어 승인 리스너가
            // 승인액으로 보고하게 한다(결제창을 연 뒤 청구액이 바뀌면 모듈 대조가 잡는다).
            $this->claimAuthAmount->remember($oid, $price);

            return ResponseHelper::success('common.success', [
                'hash_data' => $this->apiService->generateCbtHashData(
                    $this->apiService->getJapanMid(),
                    $timestamp,
                    $price,
                    $oid,
                ),
            ]);
        }

        $order = $this->bridge->findOrderByNumber($oid, 'kginicis');
        if (! $order) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.order_not_found', 404);
        }

        if (! $order->orderStatus->isBeforePayment()) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.order_not_payable', 422);
        }

        if ((string) $order->currency !== 'JPY') {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.cbt_jpy_only', 422);
        }

        if (! $this->cbtRequestMatchesOrderBuyer($request, $order)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.buyer_verification_failed', 403);
        }

        $expectedPrice = $this->cbtExpectedPrice($order);
        if ($price !== $expectedPrice) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.amount_mismatch', 422);
        }

        if (! $this->checkoutTokenService->verify(
            $checkoutToken,
            $oid,
            $price,
            (string) $request->input('buyer_email', ''),
            (string) $request->input('buyer_phone', ''),
            $request,
        )) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.cbt_token_invalid', 403);
        }

        $mid = $this->apiService->getJapanMid();
        $hashData = $this->apiService->generateCbtHashData($mid, $timestamp, $price, $oid);

        return ResponseHelper::success('common.success', [
            'hash_data' => $hashData,
        ]);
    }

    /**
     * 해시 발급 요청의 속도 제한 키를 만듭니다. (IP + 주문번호)
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $oid  주문번호 또는 클레임 결제번호
     * @return string 속도 제한 키
     */
    private function rateLimitKey(Request $request, string $oid): string
    {
        return 'sirsoft-pay_kginicis:cbt-hash:'.sha1($request->ip().'|'.$oid);
    }
}
