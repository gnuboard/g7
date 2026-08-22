<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

trait RecordsPaymentWindowClosure
{
    private const KCP_PROVIDER = 'nhnkcp';

    protected function expectedPaymentPrice(Order $order): int
    {
        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액. base(total_due_amount) 직접 비교 시
        // base≠결제 통화에서 PG 청구 통화와 단위가 어긋난다(buildPgPaymentData 와 동일 기준).
        return app(CurrencyConversionService::class)->resolveOrderPaymentChargeAmount($order);
    }

    protected function resolveExpectedPaymentPriceOrNull(Order $order, string $context, array $logContext = []): ?int
    {
        try {
            return $this->expectedPaymentPrice($order);
        } catch (InvalidArgumentException $e) {
            $this->logInvalidPaymentCurrency($order, $e, $context, $logContext);

            return null;
        }
    }

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

    protected function requestMatchesOrderBuyer(Request $request, Order $order): bool
    {
        /** @var OrderAddress|null $address */
        $address = $order->shippingAddress;
        if (! $address) {
            return true;
        }

        $expectedEmail = strtolower(trim((string) $address->orderer_email));
        if ($expectedEmail !== '') {
            $receivedEmail = strtolower(trim((string) $request->input('buyer_email', '')));
            if ($receivedEmail === '' || $receivedEmail !== $expectedEmail) {
                return false;
            }
        }

        $expectedPhone = $this->digitsOnly((string) $address->orderer_phone);
        if ($expectedPhone !== '') {
            $receivedPhone = $this->digitsOnly((string) $request->input('buyer_phone', ''));
            if ($receivedPhone === '' || $receivedPhone !== $expectedPhone) {
                return false;
            }
        }

        return true;
    }

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

    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }
}
