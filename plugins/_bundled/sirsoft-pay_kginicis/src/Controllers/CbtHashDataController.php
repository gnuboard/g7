<?php

declare(strict_types=1);

// audit:allow api-doc-coverage reason: 클레임 결제 Repository 를 서비스 로케이터 대신 생성자로 주입하고 private 메서드 PHPDoc 을 채운 내부 수정이다. 라우트·요청 파라미터·응답 구조는 불변이라 API 문서에 반영할 표면 변화가 없다(`api:docgen --scope=plugin:sirsoft-pay_kginicis --check` drift 없음).

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
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
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  CbtCheckoutTokenService  $checkoutTokenService  CBT 체크아웃 토큰 서비스
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
     * @param  CbtClaimAuthAmount  $claimAuthAmount  CBT 클레임 결제 인증요청 금액 기록
     */
    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly OrderProcessingService $orderService,
        private readonly CbtCheckoutTokenService $checkoutTokenService,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
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
            return response()->json([
                'success' => false,
                'message' => 'Too many CBT hash requests. Please try again later.',
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if (! $this->isTimestampFresh($timestamp)) {
            return response()->json([
                'success' => false,
                'message' => 'Timestamp is stale or invalid (signature replay protection).',
            ], 422);
        }

        if (! $this->apiService->isJapanEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Japan CBT payment is disabled.',
            ], 422);
        }

        if (! $this->apiService->isJapanConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Japan CBT payment is not configured.',
            ], 422);
        }

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 Order not found 로 끝난다.
        // 검증 축은 토큰 발급 단계와 같고, 체크아웃 토큰 검증도 주문과 같은 경로를 그대로 쓴다.
        if (ClaimPayment::isClaimPaymentNumber($oid)) {
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
                return response()->json([
                    'success' => false,
                    'message' => 'CBT checkout token verification failed.',
                ], 403);
            }

            // CBT 승인 응답에는 금액이 없다 — 이 해시로 서명하는 인증요청 금액을 적어 두어 승인 리스너가
            // 승인액으로 보고하게 한다(결제창을 연 뒤 청구액이 바뀌면 모듈 대조가 잡는다).
            $this->claimAuthAmount->remember($oid, $price);

            return response()->json([
                'success' => true,
                'data' => [
                    'hash_data' => $this->apiService->generateCbtHashData(
                        $this->apiService->getJapanMid(),
                        $timestamp,
                        $price,
                        $oid,
                    ),
                ],
            ]);
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

        if ((string) $order->currency !== 'JPY') {
            return response()->json([
                'success' => false,
                'message' => 'CBT payment is only available for JPY orders.',
            ], 422);
        }

        if (! $this->cbtRequestMatchesOrderBuyer($request, $order)) {
            return response()->json([
                'success' => false,
                'message' => 'Order buyer verification failed.',
            ], 403);
        }

        $expectedPrice = $this->cbtExpectedPrice($order);
        if ($price !== $expectedPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount does not match the order amount.',
            ], 422);
        }

        if (! $this->checkoutTokenService->verify(
            $checkoutToken,
            $oid,
            $price,
            (string) $request->input('buyer_email', ''),
            (string) $request->input('buyer_phone', ''),
            $request,
        )) {
            return response()->json([
                'success' => false,
                'message' => 'CBT checkout token verification failed.',
            ], 403);
        }

        $mid = $this->apiService->getJapanMid();
        $hashData = $this->apiService->generateCbtHashData($mid, $timestamp, $price, $oid);

        return response()->json([
            'success' => true,
            'data' => [
                'hash_data' => $hashData,
            ],
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
