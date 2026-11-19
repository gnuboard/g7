<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청·응답 무변경 — 주문·결제 조회와 기록을 이커머스 공개 결제 계약으로 옮긴 내부 리팩토링 (#916)

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\PaymentNotFound;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ProviderMismatch;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNicepayments\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNicepayments\Concerns\VerifiesOrderOwnership;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\PaymentCloseReportRequest;

class PaymentCloseReportController
{
    use RecordsPaymentWindowClosure;
    use VerifiesOrderOwnership;

    private const PG_PROVIDER_ID = 'nicepayments';

    private const FAILURE_CODE = 'USER_CANCEL';

    private const FAILURE_MESSAGE = '사용자가 나이스페이먼츠 결제창을 닫았습니다.';

    /**
     * 이커머스 공개 결제 계약을 주입받습니다.
     *
     * @param  PaymentGatewayBridge  $paymentGateway  이커머스 공개 결제 계약 (주문 조회, 소유자 확인, 결제창 닫힘 기록)
     */
    public function __construct(
        private readonly PaymentGatewayBridge $paymentGateway,
    ) {}

    /**
     * PC 결제창 닫힘 보고를 검증하고 주문·결제 취소와 결제 취소 이력을 기록합니다.
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
            return ResponseHelper::error('common.failed', 429, [
                'message' => [__('sirsoft-pay_nicepayments::messages.responses.too_many_requests')],
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        // 클레임 추가결제(CP 채번)는 기록할 주문 결제 이력이 없다 — 결제 건은 결제 대기로 남아
        // 신청서 상세에서 재시도하므로 보고만 받고 무시한다 (주문 조회로 흘리면 404 가 프론트
        // 흐름을 끊고 error 로그만 쌓인다).
        if ($this->paymentGateway->isClaimPaymentNumber($oid)) {
            return ResponseHelper::success('common.success', [
                'recorded' => false,
                'reason' => 'claim_payment',
            ]);
        }

        $order = $this->paymentGateway->findOrderByNumber($oid, self::PG_PROVIDER_ID);
        if (! $order) {
            return ResponseHelper::error('common.failed', 404, [
                'message' => [__('sirsoft-pay_nicepayments::messages.responses.order_not_found')],
            ]);
        }

        if (! $this->requestOwnsOrder($request, $order)) {
            return ResponseHelper::error('common.failed', 403, [
                'message' => [__('sirsoft-pay_nicepayments::messages.responses.ownership_verification_failed')],
            ]);
        }

        $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($order, 'close_report', [
            'oid' => $oid,
            'received_price' => $price,
            'ip' => $request->ip(),
        ]);
        if ($expectedPrice === null) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => [__('sirsoft-pay_nicepayments::messages.responses.currency_not_chargeable')],
            ]);
        }

        if ($price !== $expectedPrice) {
            return ResponseHelper::error('common.failed', 422, [
                'message' => [__('sirsoft-pay_nicepayments::messages.responses.amount_mismatch')],
            ]);
        }

        $closeReason = trim((string) ($validated['reason'] ?? ''));
        $result = $this->recordCloseReportWithLock(
            $oid,
            $price,
            $closeReason !== '' ? $closeReason : self::FAILURE_MESSAGE,
        );

        if (($result['status'] ?? null) === 'amount_mismatch') {
            return ResponseHelper::error('common.failed', 422, [
                'message' => [__('sirsoft-pay_nicepayments::messages.responses.amount_mismatch')],
            ]);
        }

        if (($result['status'] ?? null) === 'invalid_payment_currency') {
            return ResponseHelper::error('common.failed', 422, [
                'message' => [__('sirsoft-pay_nicepayments::messages.responses.currency_not_chargeable')],
            ]);
        }

        if (($result['status'] ?? null) === 'ignored') {
            return ResponseHelper::success('common.success', [
                'status' => 'ignored',
                'reason' => $result['reason'] ?? 'order_not_payable',
            ]);
        }

        return ResponseHelper::success('common.success', [
            'status' => 'recorded',
        ]);
    }

    /**
     * 레이트리밋 키를 생성합니다 (IP + 주문번호 조합).
     *
     * @param  PaymentCloseReportRequest  $request  요청
     * @param  string  $oid  주문번호
     * @return string 레이트리밋 키
     */
    private function rateLimitKey(PaymentCloseReportRequest $request, string $oid): string
    {
        return 'sirsoft-pay_nicepayments:payment-close-report:'.sha1($request->ip().'|'.$oid);
    }

    /**
     * 주문·결제 행을 잠근 채 결제대기(READY) 주문만 결제창 닫힘으로 기록합니다.
     *
     * 잠금(주문 → 결제 행), 결제 전 주문 확인, 아래 판정, 기록 세 단계가 이커머스 계약의 한 트랜잭션이다.
     *
     * @param  string  $oid  주문번호
     * @param  int  $price  요청 금액
     * @param  string  $closeReason  취소 이력에 남길 사유
     * @return array{status: string, reason?: string} 처리 결과
     */
    private function recordCloseReportWithLock(string $oid, int $price, string $closeReason): array
    {
        $guard = function (OrderRecord $lockedOrder, ?PaymentRecord $payment) use ($oid, $price): ?string {
            // close-report 는 결제창이 승인/발급 단계로 넘어가기 전 READY 상태만 실패 처리한다.
            // 카드 승인 콜백과 경쟁해 PAID 가 먼저 저장됐거나, 가상계좌 발급으로 WAITING_DEPOSIT 이
            // 된 주문은 닫힘 보고가 뒤늦게 도착해도 주문/결제 상태를 덮으면 안 된다.
            if ($payment?->isPaid) {
                return 'payment_already_paid';
            }
            if (! $payment || $payment->paymentStatus !== PaymentStatusEnum::READY) {
                return 'payment_not_ready';
            }

            $expectedPrice = $this->resolveExpectedPaymentPriceOrNull($lockedOrder, 'close_report_locked', [
                'oid' => $oid,
                'received_price' => $price,
            ]);
            if ($expectedPrice === null) {
                return 'invalid_payment_currency';
            }

            if ($price !== $expectedPrice) {
                return 'amount_mismatch';
            }

            return null;
        };

        try {
            $outcome = $this->markPaymentWindowClosed($oid, self::FAILURE_CODE, self::FAILURE_MESSAGE, $closeReason, $guard);
        } catch (PaymentNotFound) {
            return ['status' => 'ignored', 'reason' => 'order_not_payable'];
        } catch (ProviderMismatch) {
            // 다른 결제사가 이미 결제(발급)한 행 — 결제 대기 행이 아니다
            return ['status' => 'ignored', 'reason' => 'payment_not_ready'];
        }

        if ($outcome->applied) {
            return ['status' => 'recorded'];
        }

        if (in_array($outcome->reason, ['invalid_payment_currency', 'amount_mismatch'], true)) {
            return ['status' => (string) $outcome->reason];
        }

        return ['status' => 'ignored', 'reason' => $outcome->reason ?? 'order_not_payable'];
    }
}
