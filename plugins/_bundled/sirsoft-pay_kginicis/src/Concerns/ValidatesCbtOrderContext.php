<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

trait ValidatesCbtOrderContext
{
    protected function expectedPaymentPrice(Order $order): int
    {
        // PG 청구 금액 = 결제 통화(order_currency) 환산 최소 화폐단위 정수. 모듈의 환산 SSoT
        // (resolveOrderPaymentChargeAmount)를 재사용해 buildPgPaymentData(클라이언트가 보내는 price)·
        // 코어 최종 승인 검증과 동일 기준으로 맞춘다.
        return app(CurrencyConversionService::class)->resolveOrderPaymentChargeAmount($order);
    }

    protected function cbtExpectedPrice(Order $order): int
    {
        return $this->expectedPaymentPrice($order);
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
        Log::error('KG Inicis: payment currency is not chargeable', array_merge([
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

    protected function cbtRequestMatchesOrderBuyer(Request $request, Order $order): bool
    {
        return $this->requestMatchesOrderBuyer($request, $order);
    }

    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    protected function cbtDigitsOnly(string $value): string
    {
        return $this->digitsOnly($value);
    }
}
