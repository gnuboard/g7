<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesTimestampFreshness;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileSignatureRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class MobileSignatureController
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
     * @param  MobileSignatureRequest  $request
     * @return JsonResponse
     */
    public function generate(MobileSignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $oid = (string) $validated['oid'];
        $price = (int) $validated['price'];

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many KG Inicis mobile signature requests. Please try again later.',
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if (! $this->isTimestampFresh((string) $validated['timestamp'])) {
            return response()->json([
                'success' => false,
                'message' => 'Timestamp is stale or invalid (signature replay protection).',
            ], 422);
        }

        $contextError = $this->validateOrderContext($request, $oid, $price);
        if ($contextError instanceof JsonResponse) {
            return $contextError;
        }

        if (! $this->apiService->hasMobilePaymentCredentials()) {
            return response()->json([
                'success' => false,
                'message' => 'KG Inicis mobile payment credentials are not configured.',
            ], 422);
        }

        $chkfake = $this->apiService->generateMobileChkfake(
            $oid,
            $price,
            $validated['timestamp'],
        );

        return response()->json([
            'data' => [
                'chkfake' => $chkfake,
                'mobile_payment_url' => $this->apiService->getMobilePaymentUrl(),
            ],
        ]);
    }

    private function validateOrderContext(MobileSignatureRequest $request, string $oid, int $price): ?JsonResponse
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

        if ($price !== $this->expectedPaymentPrice($order)) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount does not match the order amount.',
            ], 422);
        }

        return null;
    }

    private function rateLimitKey(MobileSignatureRequest $request, string $oid): string
    {
        return 'sirsoft-pay_kginicis:mobile-signature:' . sha1($request->ip() . '|' . $oid);
    }
}
