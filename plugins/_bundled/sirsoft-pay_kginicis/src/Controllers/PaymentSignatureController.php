<?php

declare(strict_types=1);

// audit:allow api-doc-coverage reason: 클레임 결제 Repository 를 서비스 로케이터 대신 생성자로 주입하고 private 메서드 PHPDoc 을 채운 내부 수정이다. 라우트·요청 파라미터·응답 구조는 불변이라 API 문서에 반영할 표면 변화가 없다(`api:docgen --scope=plugin:sirsoft-pay_kginicis --check` drift 없음).

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
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
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
     */
    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly OrderProcessingService $orderService,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
    ) {}

    /**
     * generate
     *
     * @param  SignatureRequest  $request
     * @return JsonResponse
     */
    public function generate(SignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $oid = $validated['oid'];
        $price = (int) $validated['price'];
        $timestamp = $validated['timestamp'];

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many KG Inicis signature requests. Please try again later.',
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if (! $this->isTimestampFresh($timestamp)) {
            return response()->json([
                'success' => false,
                'message' => 'Timestamp is stale or invalid (signature replay protection).',
            ], 422);
        }

        $contextError = $this->validateOrderContext($request, $oid, $price);
        if ($contextError instanceof JsonResponse) {
            return $contextError;
        }

        if (! $this->apiService->hasStandardPaymentCredentials()) {
            return response()->json([
                'success' => false,
                'message' => 'KG Inicis standard payment credentials are not configured.',
            ], 422);
        }

        $signature = $this->apiService->generateSignature($oid, $price, $timestamp);
        $verification = $this->apiService->generateVerification($oid, $price, $timestamp);
        $mKey = $this->apiService->getMKey();

        return response()->json([
            'data' => [
                'signature' => $signature,
                'verification' => $verification,
                'mKey' => $mKey,
            ],
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
        if (ClaimPayment::isClaimPaymentNumber($oid)) {
            return $this->validateClaimPaymentContext($request, $oid, $price);
        }

        $order = $this->orderService->findByOrderNumber($oid);
        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        if (! $order->order_status->isBeforePayment()) {
            return response()->json([
                'success' => false,
                'message' => 'Order is not payable.',
            ], 422);
        }

        if (strtoupper((string) $order->currency) !== 'KRW') {
            return response()->json([
                'success' => false,
                'message' => 'Standard KG Inicis signature is only available for KRW orders.',
            ], 422);
        }

        if (! $this->requestMatchesOrderBuyer($request, $order)) {
            return response()->json([
                'success' => false,
                'message' => 'Order buyer verification failed.',
            ], 403);
        }

        $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($order, 'signature', [
            'received_amount' => $price,
        ]);
        if ($expectedPrice === null) {
            return response()->json([
                'success' => false,
                'message' => 'Payment currency is not chargeable.',
            ], 422);
        }

        if ($price !== $expectedPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount does not match the order amount.',
            ], 422);
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
