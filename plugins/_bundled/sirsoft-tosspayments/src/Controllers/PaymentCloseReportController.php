<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\Tosspayments\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\Tosspayments\Http\Requests\PaymentCloseReportRequest;

/**
 * 토스페이먼츠 결제창 닫힘·결제 실패 보고 컨트롤러
 *
 * 브라우저 리턴 콜백(`/payment/fail`)은 인증도 서명도 없고 주문번호가 쿼리스트링으로 오므로
 * 주문 상태를 바꾸지 않는다. 주문을 실패로 전이시키는 것은 구매자 정보를 대조한 이 경로뿐이며,
 * KCP·KG이니시스·나이스페이 플러그인의 close-report 와 같은 계약이다.
 */
class PaymentCloseReportController
{
    use RecordsPaymentWindowClosure;

    private const FAILURE_CODE = 'USER_CANCEL';

    private const FAILURE_MESSAGE = '사용자가 토스페이먼츠 결제창을 닫았습니다.';

    public function __construct(
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * 결제창 닫힘·결제 실패 보고를 검증하고 결제 실패/취소 이력을 기록합니다.
     *
     * @param  PaymentCloseReportRequest  $request  결제창 닫힘 보고 요청
     * @return JsonResponse 닫힘 보고 처리 결과
     */
    public function store(PaymentCloseReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $orderId = $validated['orderId'];
        $amount = (int) $validated['amount'];

        $rateLimitKey = $this->rateLimitKey($request->ip() ?? '', $orderId);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::error('common.failed', 429, [
                'message' => ['Too many TossPayments payment close reports. Please try again later.'],
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $this->orderService->findByOrderNumber($orderId);
        if (! $order) {
            return ResponseHelper::error('common.failed', 404, [
                'message' => ['Order not found.'],
            ]);
        }

        if (! $order->order_status->isBeforePayment()) {
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => 'order_not_payable',
            ]);
        }

        // 결제 성공 콜백(success → confirmPayment)과 이 보고가 경쟁할 수 있다. 카드 주문은 승인
        // 직전까지 order_status=PENDING_ORDER 라 위 가드를 통과하므로, 결제가 이미 성공했으면
        // 여기서 차단해 옵션이 취소로 덮이는 것을 막는다.
        if ($order->payment?->isPaid()) {
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => 'payment_already_paid',
            ]);
        }

        if (! $this->requestMatchesOrderBuyer($request, $order)) {
            return ResponseHelper::error('common.failed', 403, [
                'message' => ['Order buyer verification failed.'],
            ]);
        }

        $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'close_report', [
            'orderId' => $orderId,
            'received_amount' => $amount,
            'ip' => $request->ip(),
        ]);
        if ($expectedAmount === null) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => ['Payment currency is not chargeable.'],
            ]);
        }

        if ($amount !== $expectedAmount) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => ['Payment amount does not match the order amount.'],
            ]);
        }

        // 결제창을 닫은 것인지 결제가 거절된 것인지는 실패 코드 유무로 갈린다. 두 경우 모두
        // 주문을 실패로 전이시키지만, 운영자가 원인을 구분할 수 있도록 단계를 나눠 기록한다.
        $failureCode = trim((string) ($validated['code'] ?? ''));
        $closeReason = trim((string) ($validated['reason'] ?? ''));

        $this->markPaymentWindowClosed(
            $this->orderService,
            $order,
            $failureCode !== '' ? $failureCode : self::FAILURE_CODE,
            self::FAILURE_MESSAGE,
            $closeReason !== '' ? $closeReason : self::FAILURE_MESSAGE,
            $failureCode !== '' ? 'payment_failed' : 'window_closed',
        );

        return ResponseHelper::success('common.success', [
            'status' => 'recorded',
        ]);
    }

    /**
     * 레이트리밋 키를 생성합니다 (IP + 주문번호 조합).
     *
     * @param  string  $ip  요청 IP
     * @param  string  $orderId  주문번호
     * @return string 레이트리밋 키
     */
    private function rateLimitKey(string $ip, string $orderId): string
    {
        return 'sirsoft-tosspayments:payment-close-report:'.sha1($ip.'|'.$orderId);
    }
}
