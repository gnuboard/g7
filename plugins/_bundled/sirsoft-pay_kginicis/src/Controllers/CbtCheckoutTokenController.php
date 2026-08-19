<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Http\Requests\CbtCheckoutTokenRequest;
use Plugins\Sirsoft\PayKginicis\Services\CbtCheckoutTokenService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class CbtCheckoutTokenController
{
    use ValidatesCbtOrderContext;

    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly OrderProcessingService $orderService,
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
