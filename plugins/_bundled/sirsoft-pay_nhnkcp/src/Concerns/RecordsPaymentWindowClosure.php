<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 결제창 닫힘·결제 실패를 기록하고, 재시도 가능한 KCP 실패 주문을 결제대기로 복구하는 공통 절차.
 *
 * 이 절차를 부르는 브라우저 경로(close-report·retry)는 PG 서명이 없으므로, 호출 전에 반드시
 * 주문 소유자를 확인해야 한다(VerifiesOrderOwnership). 주문자 연락처 대조는 소유 증명이 아니다.
 */
trait RecordsPaymentWindowClosure
{
    private const KCP_PROVIDER = 'nhnkcp';

    /**
     * 주문의 결제 청구액을 결제 통화 기준으로 계산합니다.
     *
     * @param  Order  $order  대상 주문
     * @return int 결제 통화 기준 청구액
     */
    protected function expectedPaymentPrice(Order $order): int
    {
        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액. base(total_due_amount) 직접 비교 시
        // base≠결제 통화에서 PG 청구 통화와 단위가 어긋난다(buildPgPaymentData 와 동일 기준).
        return app(CurrencyConversionService::class)->resolveOrderPaymentChargeAmount($order);
    }

    /**
     * 결제 청구액을 계산하고, 청구할 수 없는 통화면 기록 후 null 을 돌려줍니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $context  호출 지점 식별자 (로그용)
     * @param  array<string, mixed>  $logContext  로그에 덧붙일 문맥
     * @return int|null 청구액, 청구 불가 통화면 null
     */
    protected function resolveExpectedPaymentPriceOrNull(Order $order, string $context, array $logContext = []): ?int
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
     * @param  Order  $order  대상 주문
     * @param  InvalidArgumentException  $e  환산 실패 예외
     * @param  string  $context  호출 지점 식별자
     * @param  array<string, mixed>  $logContext  로그에 덧붙일 문맥
     * @return void
     */
    protected function logInvalidPaymentCurrency(
        Order $order,
        InvalidArgumentException $e,
        string $context,
        array $logContext = [],
    ): void {
        Log::error('NHN KCP: payment currency is not chargeable', array_merge([
            'context' => $context,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'currency' => $order->currency,
            'currency_snapshot' => $order->currency_snapshot,
            'error' => $e->getMessage(),
        ], $logContext));
    }

    /**
     * 결제창 닫힘을 결제 실패 + 결제 취소 이력으로 기록합니다 (결제 전 주문만).
     *
     * 호출 측이 주문 소유자를 먼저 확인해야 한다 — 이 메서드는 소유권을 보지 않는다.
     *
     * @param  OrderProcessingService  $orderService  주문 처리 서비스
     * @param  Order  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string|null  $cancelMessage  취소 이력 메시지 (없으면 실패 메시지)
     * @return Order 반영 후 주문
     */
    protected function markPaymentWindowClosed(
        OrderProcessingService $orderService,
        Order $order,
        string $failureCode,
        string $failureMessage,
        ?string $cancelMessage = null,
    ): Order {
        if (! $order->order_status->isBeforePayment()) {
            return $order;
        }

        $failedOrder = $orderService->failPayment($order, $failureCode, $failureMessage);

        $cancelledOrder = $orderService->recordPaymentCancellation(
            $failedOrder,
            $failureCode,
            $cancelMessage ?: $failureMessage,
        );

        return $this->markKcpPaymentFailureRecord(
            $cancelledOrder,
            $failureCode,
            $cancelMessage ?: $failureMessage,
            'window_closed',
            PaymentStatusEnum::CANCELLED,
        );
    }

    /**
     * KCP 결제 실패 이력을 결제 메타에 남기고 결제 상태를 갱신합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string  $failureStage  실패 단계 (window_closed 등)
     * @param  PaymentStatusEnum  $paymentStatus  기록할 결제 상태
     * @return Order 반영 후 주문
     */
    protected function markKcpPaymentFailureRecord(
        Order $order,
        string $failureCode,
        string $failureMessage,
        string $failureStage,
        PaymentStatusEnum $paymentStatus = PaymentStatusEnum::FAILED,
    ): Order {
        /** @var OrderPayment|null $payment */
        $payment = $order->payment;
        if (! $payment || ! $payment->exists) {
            return $order;
        }

        $now = now()->toIso8601String();
        $paymentMeta = $payment->payment_meta ?? [];
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

        $payment->update([
            'pg_provider' => self::KCP_PROVIDER,
            'payment_status' => $paymentStatus->value,
            'payment_meta' => $paymentMeta,
        ]);

        return $order->fresh('payment') ?? $order;
    }

    /**
     * 재시도 가능한 KCP 실패·취소 주문을 같은 주문번호로 다시 결제할 수 있게 결제대기로 복구합니다.
     *
     * 호출 측이 주문 소유자를 먼저 확인해야 한다 — 이 메서드는 소유권을 보지 않는다.
     *
     * @param  Order  $order  대상 주문
     * @param  int|null  $amount  요청 금액 (주어지면 청구액과 일치해야 복구)
     * @return bool 복구했으면 true
     */
    protected function restoreRetryableKcpOrder(Order $order, ?int $amount = null): bool
    {
        /** @var OrderPayment|null $payment */
        $payment = $order->payment;
        if (! $payment || ! $payment->exists) {
            return false;
        }

        if ($amount !== null) {
            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'retry_restore', [
                'received_amount' => $amount,
            ]);
            if ($expectedAmount === null || $amount !== $expectedAmount) {
                return false;
            }
        }

        if (! $this->isRetryableKcpFailure($order, $payment)) {
            return false;
        }

        DB::transaction(function () use ($order, $payment) {
            $now = now()->toIso8601String();
            $orderMeta = $order->order_meta ?? [];
            $paymentMeta = $payment->payment_meta ?? [];

            $previousFailure = array_filter([
                'code' => $orderMeta['payment_failure_code'] ?? $paymentMeta['failure_code'] ?? null,
                'message' => $orderMeta['payment_failure_message'] ?? $paymentMeta['failure_message'] ?? null,
                'failed_at' => $orderMeta['payment_failed_at'] ?? $paymentMeta['failed_at'] ?? null,
                'stage' => $paymentMeta['failure_stage'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            if ($previousFailure !== []) {
                $retryFailures = $orderMeta['nhnkcp_retry_failures'] ?? [];
                $retryFailures = is_array($retryFailures) ? $retryFailures : [];
                $retryFailures[] = $previousFailure;
                $orderMeta['nhnkcp_retry_failures'] = array_slice($retryFailures, -5);
            }

            unset(
                $orderMeta['payment_failure_code'],
                $orderMeta['payment_failure_message'],
                $orderMeta['payment_failed_at'],
                $paymentMeta['failure_code'],
                $paymentMeta['failure_message'],
                $paymentMeta['failure_source'],
                $paymentMeta['failure_stage'],
                $paymentMeta['failed_at'],
            );

            $orderMeta['nhnkcp_retry_count'] = (int) ($orderMeta['nhnkcp_retry_count'] ?? 0) + 1;
            $orderMeta['nhnkcp_retry_started_at'] = $now;
            $paymentMeta['retry_count'] = (int) ($paymentMeta['retry_count'] ?? 0) + 1;
            $paymentMeta['retry_started_at'] = $now;

            $order->update([
                'order_status' => OrderStatusEnum::PENDING_ORDER,
                'order_meta' => $orderMeta,
            ]);

            $payment->update([
                'payment_status' => PaymentStatusEnum::READY->value,
                'payment_meta' => $paymentMeta,
                'paid_at' => null,
                'cancelled_at' => null,
                'transaction_id' => null,
                'card_approval_number' => null,
            ]);
        });

        Log::info('KCP: retryable failed order restored', [
            'ordr_idxx' => $order->order_number,
            'payment_id' => $payment->id,
        ]);

        return true;
    }

    /**
     * KCP 가 남긴 결제 실패(승인 흔적 없음)로 취소된 주문인지 판정합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderPayment  $payment  대상 결제
     * @return bool 재시도 가능하면 true
     */
    private function isRetryableKcpFailure(Order $order, OrderPayment $payment): bool
    {
        $paymentMeta = $payment->payment_meta ?? [];
        $failureCode = (string) ($paymentMeta['failure_code'] ?? $order->order_meta['payment_failure_code'] ?? '');

        return $order->order_status === OrderStatusEnum::CANCELLED
            && $payment->pg_provider === self::KCP_PROVIDER
            && in_array($payment->payment_status, [PaymentStatusEnum::FAILED, PaymentStatusEnum::CANCELLED], true)
            && ($paymentMeta['failure_source'] ?? null) === self::KCP_PROVIDER
            && $failureCode !== 'AMOUNT_MISMATCH'
            && blank($payment->transaction_id)
            && blank($payment->card_approval_number)
            && $payment->paid_at === null;
    }
}
