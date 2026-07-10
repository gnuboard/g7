<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesTimestampFreshness;
use Plugins\Sirsoft\PayKginicis\Http\Requests\SignatureRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class PaymentSignatureController
{
    use ValidatesCbtOrderContext;
    use ValidatesTimestampFreshness;

    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly OrderProcessingService $orderService,
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

    private function validateOrderContext(SignatureRequest $request, string $oid, int $price): ?JsonResponse
    {
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

    private function rateLimitKey(SignatureRequest $request, string $oid): string
    {
        return 'sirsoft-pay_kginicis:signature:' . sha1($request->ip() . '|' . $oid);
    }
}
