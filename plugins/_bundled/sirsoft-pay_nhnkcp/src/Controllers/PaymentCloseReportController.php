<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\PaymentCloseReportRequest;

class PaymentCloseReportController
{
    use RecordsPaymentWindowClosure;
    use SerializesPaymentCallbacks;

    private const FAILURE_CODE = 'USER_CANCEL';

    private const FAILURE_MESSAGE = '사용자가 NHN KCP 결제창을 닫았습니다.';

    public function __construct(
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * PC 결제창 닫힘 보고를 검증하고 결제 실패/취소 이력을 기록합니다.
     *
     * @param  PaymentCloseReportRequest  $request  결제창 닫힘 보고 요청
     * @return JsonResponse 닫힘 보고 처리 결과
     */
    public function store(PaymentCloseReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $oid = $validated['oid'];
        $price = (int) $validated['price'];

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::error('messages.failed', 429, [
                'message' => ['Too many NHN KCP payment close reports. Please try again later.'],
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $callbackLock = null;

        try {
            // PC close-report 는 PC authCallback 과 경쟁할 수 있으므로 같은 주문 락을 공유한다.
            // authCallback 이 먼저 결제완료를 확정했다면 아래 최신 주문 조회에서 close-report 를 무시한다.
            $callbackLock = $this->acquireOrderCallbackLock('authCallback', $oid);

            $order = $this->orderService->findByOrderNumber($oid);
            if (! $order) {
                return ResponseHelper::error('messages.failed', 404, [
                    'message' => ['Order not found.'],
                ]);
            }

            if (! $order->order_status->isBeforePayment()) {
                return ResponseHelper::success('messages.success', [
                    'status' => 'ignored',
                    'reason' => 'order_not_payable',
                ]);
            }

            // 결제 성공 콜백과 결제창 닫힘 보고가 경쟁할 때, 카드 주문은 승인 직전까지
            // order_status=PENDING_ORDER 라 위 가드를 통과한다. payment_status 가 이미 PAID 면 결제가
            // 성공한 것이므로 실패 처리하지 않는다(failPayment 가 옵션을 취소로 덮어 주문/옵션 상태가
            // 어긋나는 race 차단). failPayment 자체에도 동일 가드가 있으나 여기서 차단해 불필요한
            // 결제취소 이력 기록까지 미연에 방지한다.
            if ($order->payment?->isPaid()) {
                return ResponseHelper::success('messages.success', [
                    'status' => 'ignored',
                    'reason' => 'payment_already_paid',
                ]);
            }

            if (! $this->requestMatchesOrderBuyer($request, $order)) {
                return ResponseHelper::error('messages.failed', 403, [
                    'message' => ['Order buyer verification failed.'],
                ]);
            }

            $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($order, 'close_report', [
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

            $closeReason = trim((string) ($validated['reason'] ?? ''));
            $this->markPaymentWindowClosed(
                $this->orderService,
                $order,
                self::FAILURE_CODE,
                self::FAILURE_MESSAGE,
                $closeReason !== '' ? $closeReason : self::FAILURE_MESSAGE,
            );

            return ResponseHelper::success('messages.success', [
                'status' => 'recorded',
            ]);
        } catch (LockTimeoutException) {
            return ResponseHelper::success('messages.success', [
                'status' => 'ignored',
                'reason' => 'callback_in_progress',
            ]);
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    private function rateLimitKey(PaymentCloseReportRequest $request, string $oid): string
    {
        return 'sirsoft-pay_nhnkcp:payment-close-report:'.sha1($request->ip().'|'.$oid);
    }
}
