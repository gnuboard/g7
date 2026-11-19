<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFailure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\RetryRestoreRequest;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\WindowClosure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\InvalidPaymentTransition;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ProviderMismatch;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;

/**
 * 결제창 닫힘·결제 실패를 기록하고, 재시도 가능한 KCP 실패 주문을 결제대기로 복구하는 공통 절차.
 *
 * 이 절차를 부르는 브라우저 경로(close-report·retry)는 PG 서명이 없으므로, 호출 전에 반드시
 * 주문 소유자를 확인해야 한다(VerifiesOrderOwnership). 주문자 연락처 대조는 소유 증명이 아니다.
 *
 * 쓰기는 모두 이커머스 결제 계약(`PaymentLifecycle`)으로 한다. 재시도 가능 판정은 이 트레이트가 하고,
 * 상태 되돌리기(결제완료·부분취소 이력 거부, 정상 상태 전이, 취소된 주문 상품 복구)는 계약의 `restoreForRetry` 가 맡는다.
 *
 * 호스트 요건: 이커머스 결제 계약 `PaymentGatewayBridge` 를 `$bridge` 로 주입받는다.
 */
trait RecordsPaymentWindowClosure
{
    private const KCP_PROVIDER = 'nhnkcp';

    /**
     * 주문의 결제 청구액을 결제 통화 기준으로 계산합니다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @return int 결제 통화 기준 청구액
     *
     * @throws InvalidArgumentException 청구할 수 없는 결제 통화(계약의 ChargeNotResolvable)
     */
    protected function expectedPaymentPrice(OrderRecord $order): int
    {
        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액. base(total_due_amount) 직접 비교 시
        // base≠결제 통화에서 PG 청구 통화와 단위가 어긋난다(buildPgPaymentData 와 동일 기준).
        return $this->bridge->resolveChargeAmount($order->orderNumber)->minorUnitAmount;
    }

    /**
     * 결제 청구액을 계산하고, 청구할 수 없는 통화면 기록 후 null 을 돌려줍니다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string  $context  호출 지점 식별자 (로그용)
     * @param  array<string, mixed>  $logContext  로그에 덧붙일 문맥
     * @return int|null 청구액, 청구 불가 통화면 null
     */
    protected function resolveExpectedPaymentPriceOrNull(OrderRecord $order, string $context, array $logContext = []): ?int
    {
        try {
            return $this->expectedPaymentPrice($order);
        } catch (InvalidArgumentException $e) {
            $this->logInvalidPaymentCurrency($order, $e, $context, $logContext);

            return null;
        }
    }

    /**
     * 청구할 수 없는 결제 통화를 기록합니다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  InvalidArgumentException  $e  환산 실패 예외
     * @param  string  $context  호출 지점 식별자
     * @param  array<string, mixed>  $logContext  로그에 덧붙일 문맥
     * @return void 반환값 없음
     */
    protected function logInvalidPaymentCurrency(
        OrderRecord $order,
        InvalidArgumentException $e,
        string $context,
        array $logContext = [],
    ): void {
        Log::error('NHN KCP: payment currency is not chargeable', array_merge([
            'context' => $context,
            'order_id' => $order->id,
            'order_number' => $order->orderNumber,
            'currency' => $order->currency,
            'currency_snapshot' => $order->currencySnapshot,
            'error' => $e->getMessage(),
        ], $logContext));
    }

    /**
     * 결제창 닫힘을 결제 실패 + 결제 취소 이력으로 기록합니다 (결제 전 주문만).
     *
     * 계약이 결제 실패 처리 → 결제 취소 이력 → KCP 실패 기록 순서를 지킨다. 결제 전 주문이 아니면 아무것도 바꾸지 않는다.
     * 호출 측이 주문 소유자를 먼저 확인해야 한다 — 이 메서드는 소유권을 보지 않는다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string|null  $cancelMessage  취소 이력 메시지 (없으면 실패 메시지)
     * @return OrderRecord 반영 후 주문
     */
    protected function markPaymentWindowClosed(
        OrderRecord $order,
        string $failureCode,
        string $failureMessage,
        ?string $cancelMessage = null,
    ): OrderRecord {
        if (! $order->orderStatus->isBeforePayment()) {
            return $order;
        }

        $effectiveCancelMessage = $cancelMessage ?: $failureMessage;

        $outcome = $this->bridge->markPaymentWindowClosed(
            $order->orderNumber,
            self::KCP_PROVIDER,
            new WindowClosure(
                code: $failureCode,
                message: $failureMessage,
                cancelMessage: $effectiveCancelMessage,
                failure: $this->kcpPaymentFailure($failureCode, $effectiveCancelMessage, 'window_closed', PaymentStatusEnum::CANCELLED),
            ),
        );

        return $outcome->order ?? $order;
    }

    /**
     * KCP 결제 실패 이력을 결제 메타에 남기고 결제 상태를 갱신합니다.
     *
     * 결제 행의 PG 식별자를 `nhnkcp` 로 기록한다(다른 PG 기본값으로 만들어진 결제 전 행을 인수).
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string  $failureStage  실패 단계 (window_closed 등)
     * @param  PaymentStatusEnum  $paymentStatus  기록할 결제 상태
     * @return OrderRecord 반영 후 주문
     */
    protected function markKcpPaymentFailureRecord(
        OrderRecord $order,
        string $failureCode,
        string $failureMessage,
        string $failureStage,
        PaymentStatusEnum $paymentStatus = PaymentStatusEnum::FAILED,
    ): OrderRecord {
        if ($order->payment === null) {
            return $order;
        }

        return $this->bridge->recordPaymentFailure(
            $order->orderNumber,
            self::KCP_PROVIDER,
            $this->kcpPaymentFailure($failureCode, $failureMessage, $failureStage, $paymentStatus),
        );
    }

    /**
     * KCP 실패 기록(결제 상태 + 결제 meta 실패 키)을 계약 요청으로 만듭니다.
     *
     * meta 는 잠긴 결제 행의 현재 값에서 계산한다 — `failure_history`(최근 5건, 항목 {code, message, stage, failed_at}),
     * `failure_source`·`failure_code`·`failure_message`·`failure_stage`·`failed_at`.
     *
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string  $failureStage  실패 단계
     * @param  PaymentStatusEnum  $paymentStatus  기록할 결제 상태(failed 또는 cancelled)
     * @return PaymentFailure 계약 요청
     */
    private function kcpPaymentFailure(
        string $failureCode,
        string $failureMessage,
        string $failureStage,
        PaymentStatusEnum $paymentStatus,
    ): PaymentFailure {
        return new PaymentFailure(
            status: $paymentStatus,
            meta: function (array $paymentMeta) use ($failureCode, $failureMessage, $failureStage): array {
                // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
                $now = now()->toIso8601String();
                $history = $paymentMeta['failure_history'] ?? [];
                $history = is_array($history) ? $history : [];
                $history[] = [
                    'code' => $failureCode,
                    'message' => $failureMessage,
                    'stage' => $failureStage,
                    'failed_at' => $now,
                ];

                $paymentMeta['failure_history'] = array_slice($history, -5);
                $paymentMeta['failure_source'] = self::KCP_PROVIDER;
                $paymentMeta['failure_code'] = $failureCode;
                $paymentMeta['failure_message'] = $failureMessage;
                $paymentMeta['failure_stage'] = $failureStage;
                $paymentMeta['failed_at'] = $now;

                return $paymentMeta;
            },
            takeOverProvider: true,
        );
    }

    /**
     * 재시도 가능한 KCP 실패·취소 주문을 같은 주문번호로 다시 결제할 수 있게 결제대기로 복구합니다.
     *
     * 재시도 가능 여부는 여기서 판정하고, 되돌리기는 계약(`restoreForRetry`)이 잠금 아래 한다 — 결제완료·부분취소
     * 이력이 있으면 계약이 거부하고(복구 안 함), 주문 meta 의 `nhnkcp_retry_*` 와 결제 meta 의 `retry_*` 는 종전과 같은
     * 값으로 쓰인다. 금액을 주면 계약이 잠긴 주문의 청구액과 한 번 더 대조한다.
     * 호출 측이 주문 소유자를 먼저 확인해야 한다 — 이 메서드는 소유권을 보지 않는다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  int|null  $amount  요청 금액 (주어지면 청구액과 일치해야 복구)
     * @return OrderRecord|null 복구한 주문, 복구하지 않았으면 null
     */
    protected function restoreKcpOrderForRetry(OrderRecord $order, ?int $amount = null): ?OrderRecord
    {
        $payment = $order->payment;
        if ($payment === null) {
            return null;
        }

        if ($amount !== null) {
            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'retry_restore', [
                'received_amount' => $amount,
            ]);
            if ($expectedAmount === null || $amount !== $expectedAmount) {
                return null;
            }
        }

        if (! $this->isRetryableKcpFailure($order, $payment)) {
            return null;
        }

        try {
            $result = $this->bridge->restoreForRetry(
                $order->orderNumber,
                self::KCP_PROVIDER,
                new RetryRestoreRequest(
                    metaPrefix: self::KCP_PROVIDER,
                    expectedAmount: $amount,
                    clearTransaction: true,
                    recordFailureStage: true,
                ),
            );
        } catch (InvalidPaymentTransition|ProviderMismatch $e) {
            Log::warning('KCP: retryable failed order not restored — payment history blocks the restore', [
                'ordr_idxx' => $order->orderNumber,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $result->restored || $result->order === null) {
            return null;
        }

        Log::info('KCP: retryable failed order restored', [
            'ordr_idxx' => $order->orderNumber,
            'payment_id' => $payment->id,
        ]);

        return $result->order;
    }

    /**
     * KCP 가 남긴 결제 실패(승인 흔적 없음)로 취소된 주문인지 판정합니다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  PaymentRecord|null  $payment  대상 결제 (없으면 재시도 불가)
     * @return bool 재시도 가능하면 true
     */
    private function isRetryableKcpFailure(OrderRecord $order, ?PaymentRecord $payment): bool
    {
        if ($payment === null) {
            return false;
        }

        $paymentMeta = $payment->paymentMeta;
        $failureCode = (string) ($paymentMeta['failure_code'] ?? $order->paymentFailureCode ?? '');

        return $order->orderStatus === OrderStatusEnum::CANCELLED
            && $payment->pgProvider === self::KCP_PROVIDER
            && in_array($payment->paymentStatus, [PaymentStatusEnum::FAILED, PaymentStatusEnum::CANCELLED], true)
            && ($paymentMeta['failure_source'] ?? null) === self::KCP_PROVIDER
            && $failureCode !== 'AMOUNT_MISMATCH'
            && blank($payment->transactionId)
            && blank($payment->cardApprovalNumber)
            && $payment->paidAt === null;
    }
}
