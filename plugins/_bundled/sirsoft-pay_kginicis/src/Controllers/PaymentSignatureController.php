<?php

declare(strict_types=1);

// audit:allow api-doc-coverage reason: 이커머스 내부 서비스·저장소 대신 공개 결제 계약(PaymentGatewayBridge)을 생성자로 주입한 내부 전환이다. 응답은 공통 형식(ResponseHelper: success, message, data)으로 나가고 결제 화면이 읽는 data 항목과 오류 message 키는 그대로다. 오류 문구는 영어 고정에서 번역 키로 바뀌었다. 이 엔드포인트의 API 문서는 원래 없다(`api:docgen --scope=plugin:sirsoft-pay_kginicis --check` drift 없음).

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesTimestampFreshness;
use Plugins\Sirsoft\PayKginicis\Http\Requests\SignatureRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class PaymentSignatureController
{
    use HandlesClaimPaymentCallbacks;
    use ValidatesCbtOrderContext;
    use ValidatesTimestampFreshness;

    /**
     * @param  KgInicisApiService  $apiService  KG 이니시스 API 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     */
    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly PaymentGatewayBridge $bridge,
    ) {}

    /**
     * PC 표준결제창 요청 서명을 만듭니다.
     *
     * @param  SignatureRequest  $request  서명 요청 폼
     * @return JsonResponse 서명 값 (주문 검증 실패면 4xx)
     */
    public function generate(SignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $oid = $validated['oid'];
        $price = (int) $validated['price'];
        $timestamp = $validated['timestamp'];

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.too_many_requests', 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if (! $this->isTimestampFresh($timestamp)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.timestamp_stale', 422);
        }

        $contextError = $this->validateOrderContext($request, $oid, $price);
        if ($contextError instanceof JsonResponse) {
            return $contextError;
        }

        if (! $this->apiService->hasStandardPaymentCredentials()) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.credentials_not_configured', 422);
        }

        $signature = $this->apiService->generateSignature($oid, $price, $timestamp);
        $verification = $this->apiService->generateVerification($oid, $price, $timestamp);
        $mKey = $this->apiService->getMKey();

        return ResponseHelper::success('common.success', [
            'signature' => $signature,
            'verification' => $verification,
            'mKey' => $mKey,
        ]);
    }

    /**
     * 서명 발급 요청의 주문(또는 클레임 결제 건) 맥락을 검증합니다.
     *
     * @param  SignatureRequest  $request  HTTP 요청
     * @param  string  $oid  주문번호 또는 클레임 결제번호
     * @param  int  $price  클라이언트가 보낸 결제 금액
     * @return JsonResponse|null 거절 응답 (통과 시 null)
     */
    private function validateOrderContext(SignatureRequest $request, string $oid, int $price): ?JsonResponse
    {
        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 결제 건 기준으로 같은 강도의 검증을 적용한다.
        if ($this->bridge->isClaimPaymentNumber($oid)) {
            return $this->validateClaimPaymentContext($request, $oid, $price);
        }

        $order = $this->bridge->findOrderByNumber($oid, 'kginicis');
        if (! $order) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.order_not_found', 404);
        }

        if (! $order->orderStatus->isBeforePayment()) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.order_not_payable', 422);
        }

        if (strtoupper((string) $order->currency) !== 'KRW') {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.krw_only', 422);
        }

        if (! $this->requestMatchesOrderBuyer($request, $order)) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.buyer_verification_failed', 403);
        }

        $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($order, 'signature', [
            'received_amount' => $price,
        ]);
        if ($expectedPrice === null) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.currency_not_chargeable', 422);
        }

        if ($price !== $expectedPrice) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.responses.amount_mismatch', 422);
        }

        return null;
    }

    /**
     * PC 서명 요청의 속도 제한 키를 만듭니다. (IP + 주문번호)
     *
     * @param  SignatureRequest  $request  HTTP 요청
     * @param  string  $oid  주문번호 또는 클레임 결제번호
     * @return string 속도 제한 키
     */
    private function rateLimitKey(SignatureRequest $request, string $oid): string
    {
        return 'sirsoft-pay_kginicis:signature:'.sha1($request->ip().'|'.$oid);
    }
}
