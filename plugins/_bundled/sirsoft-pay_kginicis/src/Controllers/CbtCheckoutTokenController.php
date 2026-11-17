<?php

declare(strict_types=1);

// audit:allow api-doc-coverage reason: 이커머스 내부 서비스·저장소 대신 공개 결제 계약(PaymentGatewayBridge)을 생성자로 주입한 내부 전환이다(#916). 응답은 공통 형식(ResponseHelper: success, message, data)으로 나가고 결제 화면이 읽는 data 항목과 오류 message 키는 그대로다. 오류 문구는 영어 고정에서 번역 키로 바뀌었다(#916). 이 엔드포인트의 API 문서는 원래 없다(`api:docgen --scope=plugin:sirsoft-pay_kginicis --check` drift 없음).

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesCbtClaimPayments;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Http\Requests\CbtCheckoutTokenRequest;
use Plugins\Sirsoft\PayKginicis\Services\CbtCheckoutTokenService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class CbtCheckoutTokenController
{
    use HandlesCbtClaimPayments;
    use HandlesClaimPaymentCallbacks;
    use ValidatesCbtOrderContext;

    /**
     * @param  KgInicisApiService  $apiService  KG 이니시스 API 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     * @param  CbtCheckoutTokenService  $checkoutTokenService  CBT 체크아웃 토큰 서비스
     */
    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly PaymentGatewayBridge $bridge,
        private readonly CbtCheckoutTokenService $checkoutTokenService,
    ) {}

    /**
     * 해외결제(CBT) 체크아웃 토큰 발급
     *
     * 형식·길이 검증은 CbtCheckoutTokenRequest 가 담당한다 (국내 SignatureRequest 와 동일 강도).
     *
     * @param  CbtCheckoutTokenRequest  $request  oid/price/buyer 정보
     * @return JsonResponse checkout_token 또는 403/404/422
     */
    public function issue(CbtCheckoutTokenRequest $request): JsonResponse
    {
        $oid = (string) $request->validated('oid');
        $price = (int) $request->validated('price');

        $rateLimitKey = 'sirsoft-pay_kginicis:cbt-token:'.sha1($request->ip().'|'.$oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.too_many_requests', 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if (! $this->apiService->isJapanEnabled()) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.cbt_disabled', 422);
        }

        if (! $this->apiService->isJapanConfigured()) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.cbt_not_configured', 422);
        }

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 Order not found 로 끝난다.
        // 검증을 통과하면 토큰 발급은 주문과 같은 경로를 쓴다(토큰은 oid·금액·구매자에 묶인다).
        if ($this->bridge->isClaimPaymentNumber($oid)) {
            $claimError = $this->validateCbtClaimPaymentContext($request, $oid, $price);

            if ($claimError !== null) {
                return $claimError;
            }

            return ResponseHelper::success('common.success', [
                'checkout_token' => $this->checkoutTokenService->issue(
                    $oid,
                    $price,
                    (string) $request->input('buyer_email', ''),
                    (string) $request->input('buyer_phone', ''),
                    $request,
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

        if ($price !== $this->cbtExpectedPrice($order)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.amount_mismatch', 422);
        }

        $token = $this->checkoutTokenService->issue(
            $oid,
            $price,
            (string) $request->input('buyer_email', ''),
            (string) $request->input('buyer_phone', ''),
            $request,
        );

        return ResponseHelper::success('common.success', [
            'checkout_token' => $token,
        ]);
    }
}
