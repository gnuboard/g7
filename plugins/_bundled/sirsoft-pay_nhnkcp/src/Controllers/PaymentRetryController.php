<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNhnkcp\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNhnkcp\Concerns\VerifiesOrderOwnership;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\PaymentRetryRequest;

class PaymentRetryController
{
    use RecordsPaymentWindowClosure;
    use VerifiesOrderOwnership;

    /**
     * 이커머스 결제 계약을 주입받습니다.
     *
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약 (주문 조회, 소유자 확인, 재결제 복구)
     */
    public function __construct(
        private readonly PaymentGatewayBridge $bridge,
    ) {}

    /**
     * 같은 주문번호로 NHN KCP 결제를 다시 열기 전 실패 주문을 결제대기 상태로 복구합니다.
     *
     * @param  PaymentRetryRequest  $request  결제 재시도 준비 요청
     * @return JsonResponse 재시도 준비 처리 결과
     */
    public function store(PaymentRetryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $oid = $validated['oid'];
        $price = (int) $validated['price'];

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::error('common.failed', 429, [
                'message' => [__('sirsoft-pay_nhnkcp::messages.responses.too_many_requests')],
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $this->bridge->findOrderByNumber($oid, 'nhnkcp');
        if (! $order) {
            return ResponseHelper::error('common.failed', 404, [
                'message' => [__('sirsoft-pay_nhnkcp::messages.responses.order_not_found')],
            ]);
        }

        if (! $this->requestOwnsOrder($request, $order)) {
            return ResponseHelper::error('common.failed', 403, [
                'message' => [__('sirsoft-pay_nhnkcp::messages.responses.ownership_verification_failed')],
            ]);
        }

        $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($order, 'payment_retry', [
            'oid' => $oid,
            'received_price' => $price,
            'ip' => $request->ip(),
        ]);
        if ($expectedPrice === null) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => [__('sirsoft-pay_nhnkcp::messages.responses.currency_not_chargeable')],
            ]);
        }

        if ($price !== $expectedPrice) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => [__('sirsoft-pay_nhnkcp::messages.responses.amount_mismatch')],
            ]);
        }

        if ($order->orderStatus->isBeforePayment()) {
            return ResponseHelper::success('common.success', [
                'status' => 'ready',
            ]);
        }

        if ($this->restoreKcpOrderForRetry($order, $price) !== null) {
            return ResponseHelper::success('common.success', [
                'status' => 'restored',
            ]);
        }

        return ResponseHelper::error('common.failed', 409, [
            'message' => [__('sirsoft-pay_nhnkcp::messages.responses.order_not_retryable')],
        ]);
    }

    /**
     * 레이트리밋 키를 생성합니다 (IP + 주문번호 조합).
     *
     * @param  PaymentRetryRequest  $request  요청
     * @param  string  $oid  주문번호
     * @return string 레이트리밋 키
     */
    private function rateLimitKey(PaymentRetryRequest $request, string $oid): string
    {
        return 'sirsoft-pay_nhnkcp:payment-retry:'.sha1($request->ip().'|'.$oid);
    }
}
