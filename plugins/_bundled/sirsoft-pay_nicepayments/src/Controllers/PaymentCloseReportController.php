<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNicepayments\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\PaymentCloseReportRequest;

class PaymentCloseReportController
{
    use RecordsPaymentWindowClosure;

    private const FAILURE_CODE = 'USER_CANCEL';

    private const FAILURE_MESSAGE = '사용자가 나이스페이먼츠 결제창을 닫았습니다.';

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
                'message' => ['Too many NicePayments payment close reports. Please try again later.'],
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
        $result = $this->recordCloseReportWithLock(
            $oid,
            $price,
            $closeReason !== '' ? $closeReason : self::FAILURE_MESSAGE,
        );

        if (($result['status'] ?? null) === 'amount_mismatch') {
            return ResponseHelper::error('messages.failed', 422, [
                'message' => ['Payment amount does not match the order amount.'],
            ]);
        }

        if (($result['status'] ?? null) === 'invalid_payment_currency') {
            return ResponseHelper::error('messages.failed', 422, [
                'message' => ['Payment currency is not chargeable.'],
            ]);
        }

        if (($result['status'] ?? null) === 'ignored') {
            return ResponseHelper::success('messages.success', [
                'status' => 'ignored',
                'reason' => $result['reason'] ?? 'order_not_payable',
            ]);
        }

        return ResponseHelper::success('messages.success', [
            'status' => 'recorded',
        ]);
    }

    private function rateLimitKey(PaymentCloseReportRequest $request, string $oid): string
    {
        return 'sirsoft-pay_nicepayments:payment-close-report:'.sha1($request->ip().'|'.$oid);
    }

    /**
     * @return array{status: string, reason?: string}
     */
    private function recordCloseReportWithLock(string $oid, int $price, string $closeReason): array
    {
        return DB::transaction(function () use ($oid, $price, $closeReason): array {
            $lockedOrder = Order::query()
                ->where('order_number', $oid)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder || ! $lockedOrder->order_status->isBeforePayment()) {
                return ['status' => 'ignored', 'reason' => 'order_not_payable'];
            }

            $payment = $lockedOrder->payment()->lockForUpdate()->first();
            $lockedOrder->setRelation('payment', $payment);

            // close-report 는 결제창이 승인/발급 단계로 넘어가기 전 READY 상태만 실패 처리한다.
            // 카드 승인 콜백과 경쟁해 PAID 가 먼저 저장됐거나, 가상계좌 발급으로 WAITING_DEPOSIT 이
            // 된 주문은 닫힘 보고가 뒤늦게 도착해도 주문/결제 상태를 덮으면 안 된다.
            if ($payment?->isPaid()) {
                return ['status' => 'ignored', 'reason' => 'payment_already_paid'];
            }
            if (! $payment || $payment->payment_status !== PaymentStatusEnum::READY) {
                return ['status' => 'ignored', 'reason' => 'payment_not_ready'];
            }

            $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($lockedOrder, 'close_report_locked', [
                'oid' => $oid,
                'received_price' => $price,
            ]);
            if ($expectedPrice === null) {
                return ['status' => 'invalid_payment_currency'];
            }

            if ($price !== $expectedPrice) {
                return ['status' => 'amount_mismatch'];
            }

            $this->markPaymentWindowClosed(
                $this->orderService,
                $lockedOrder,
                self::FAILURE_CODE,
                self::FAILURE_MESSAGE,
                $closeReason,
            );

            return ['status' => 'recorded'];
        });
    }
}
