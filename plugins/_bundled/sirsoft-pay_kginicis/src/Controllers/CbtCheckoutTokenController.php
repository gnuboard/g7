<?php

declare(strict_types=1);

// audit:allow api-doc-coverage reason: 클레임 결제 Repository 를 서비스 로케이터 대신 생성자로 주입하고 private 메서드 PHPDoc 을 채운 내부 수정이다. 라우트·요청 파라미터·응답 구조는 불변이라 API 문서에 반영할 표면 변화가 없다(`api:docgen --scope=plugin:sirsoft-pay_kginicis --check` drift 없음).

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
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
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  CbtCheckoutTokenService  $checkoutTokenService  CBT 체크아웃 토큰 서비스
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
     */
    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly OrderProcessingService $orderService,
        private readonly CbtCheckoutTokenService $checkoutTokenService,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
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
            return response()->json([
                'success' => false,
                'message' => 'Too many CBT checkout token requests. Please try again later.',
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

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
        // 검증을 통과하면 토큰 발급은 주문과 같은 경로를 쓴다(토큰은 oid·금액·구매자에 묶인다).
        if (ClaimPayment::isClaimPaymentNumber($oid)) {
            $claimError = $this->validateCbtClaimPaymentContext($request, $oid, $price);

            if ($claimError !== null) {
                return $claimError;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'checkout_token' => $this->checkoutTokenService->issue(
                        $oid,
                        $price,
                        (string) $request->input('buyer_email', ''),
                        (string) $request->input('buyer_phone', ''),
                        $request,
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

        if ($price !== $this->cbtExpectedPrice($order)) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount does not match the order amount.',
            ], 422);
        }

        $token = $this->checkoutTokenService->issue(
            $oid,
            $price,
            (string) $request->input('buyer_email', ''),
            (string) $request->input('buyer_phone', ''),
            $request,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'checkout_token' => $token,
            ],
        ]);
    }
}
