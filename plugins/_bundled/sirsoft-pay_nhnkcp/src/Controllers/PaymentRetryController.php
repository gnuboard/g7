<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\PaymentRetryRequest;

class PaymentRetryController
{
    use RecordsPaymentWindowClosure;

    public function __construct(
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * 같은 주문번호로 NHN KCP 결제를 다시 열기 전 실패 주문을 결제대기 상태로 복구합니다.
     *
     * @param PaymentRetryRequest $request 결제 재시도 준비 요청
     * @return JsonResponse 재시도 준비 처리 결과
     */
    public function store(PaymentRetryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $oid = $validated['oid'];
        $price = (int) $validated['price'];

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::error('messages.failed', 429, [
                'message' => ['Too many NHN KCP payment retry requests. Please try again later.'],
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $this->orderService->findByOrderNumber($oid);
        if (! $order) {
            return ResponseHelper::error('messages.failed', 404, [
                'message' => ['Order not found.'],
            ]);
        }

        if (! $this->requestMatchesOrderBuyer($request, $order)) {
            return ResponseHelper::error('messages.failed', 403, [
                'message' => ['Order buyer verification failed.'],
            ]);
        }

        $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($order, 'payment_retry', [
            'oid' => $oid,
            'received_price' => $price,
            'ip' => $request->ip(),
        ]);
        if ($expectedPrice === null) {
            return ResponseHelper::error('messages.failed', 422, [
                'message' => ['Payment currency is not chargeable.'],
            ]);
        }

        if ($price !== $expectedPrice) {
            return ResponseHelper::error('messages.failed', 422, [
                'message' => ['Payment amount does not match the order amount.'],
            ]);
        }

        if ($order->order_status->isBeforePayment()) {
            return ResponseHelper::success('messages.success', [
                'status' => 'ready',
            ]);
        }

        if ($this->restoreRetryableKcpOrder($order, $price)) {
            return ResponseHelper::success('messages.success', [
                'status' => 'restored',
            ]);
        }

        return ResponseHelper::error('messages.failed', 409, [
            'message' => ['Order is not retryable for NHN KCP payment.'],
        ]);
    }

    private function rateLimitKey(PaymentRetryRequest $request, string $oid): string
    {
        return 'sirsoft-pay_nhnkcp:payment-retry:' . sha1($request->ip() . '|' . $oid);
    }
}
