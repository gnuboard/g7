<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

trait RecordsPaymentWindowClosure
{
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

    protected function invalidPaymentCurrencyFailureMessage(InvalidArgumentException $e): string
    {
        $message = trim($e->getMessage());

        return $message !== ''
            ? $message
            : '나이스페이먼츠 결제 통화 환율 설정이 올바르지 않습니다.';
    }

    protected function logInvalidPaymentCurrency(
        Order $order,
        InvalidArgumentException $e,
        string $context,
        array $logContext = [],
    ): void {
        Log::error('NicePayments: payment currency is not chargeable', array_merge([
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

        return $this->markNicePaymentFailed($failedOrder, $failureCode, $failureMessage);
    }

    protected function markNicePaymentFailed(Order $order, string $failureCode, string $failureMessage): Order
    {
        $payment = $order->payment()->first();
        if (! $payment) {
            return $order;
        }

        $payment->update([
            'payment_status' => PaymentStatusEnum::FAILED->value,
            'payment_meta' => array_merge($payment->payment_meta ?? [], [
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                'failed_at' => now()->toIso8601String(),
                'failure_source' => 'nicepayments',
            ]),
        ]);

        return $order->fresh();
    }

    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }
}
