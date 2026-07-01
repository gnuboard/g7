<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Services\CbtCheckoutTokenService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

class CbtCheckoutTokenController
{
    use ValidatesCbtOrderContext;

    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly OrderProcessingService $orderService,
        private readonly CbtCheckoutTokenService $checkoutTokenService,
    ) {}

    public function issue(Request $request): JsonResponse
    {
        $oid = (string) $request->input('oid', '');
        $price = (int) $request->input('price', 0);

        if ($oid === '' || $price <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required parameters: oid, price',
            ], 422);
        }

        $rateLimitKey = 'sirsoft-pay_kginicis:cbt-token:' . sha1($request->ip() . '|' . $oid);
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
