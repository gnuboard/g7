<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ChargeNotResolvable;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLookup;

/**
 * 주문 결제 요청의 청구액·구매자 대조 공용 조각.
 *
 * 청구액은 이커머스 공개 계약 {@see PaymentLookup::resolveChargeAmount()} 로 얻는다(#916).
 * 호스트 요건: 생성자에서 `PaymentGatewayBridge`(또는 `PaymentLookup`)를 `$bridge` 로 주입받는다.
 */
trait ValidatesCbtOrderContext
{
    /**
     * 주문의 PG 청구 금액(결제 통화 최소 화폐단위)을 계산합니다.
     *
     * @param  OrderRecord  $order  주문
     * @return int 청구 금액
     *
     * @throws ChargeNotResolvable 결제 통화 환율이 없을 때(InvalidArgumentException 하위)
     */
    protected function expectedPaymentPrice(OrderRecord $order): int
    {
        // PG 청구 금액 = 결제 통화(order_currency) 환산 최소 화폐단위 정수. 이커머스 환산 SSoT 를 계약으로
        // 재사용해 buildPgPaymentData(클라이언트가 보내는 price)·코어 최종 승인 검증과 동일 기준으로 맞춘다.
        return $this->bridge->resolveChargeAmount($order->orderNumber)->minorUnitAmount;
    }

    /**
     * CBT 경로의 청구 금액을 계산합니다.
     *
     * @param  OrderRecord  $order  주문
     * @return int 청구 금액
     */
    protected function cbtExpectedPrice(OrderRecord $order): int
    {
        return $this->expectedPaymentPrice($order);
    }

    /**
     * 청구 금액을 계산하고, 결제 통화를 청구할 수 없으면 로그를 남기고 null 을 돌려줍니다.
     *
     * @param  OrderRecord  $order  주문
     * @param  string  $context  호출 문맥(로그용)
     * @param  array<string, mixed>  $logContext  로그에 더할 값
     * @return int|null 청구 금액, 청구 불가면 null
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
     * 청구 불가 통화 오류를 기록합니다.
     *
     * @param  OrderRecord  $order  주문
     * @param  InvalidArgumentException  $e  환산 오류
     * @param  string  $context  호출 문맥
     * @param  array<string, mixed>  $logContext  로그에 더할 값
     * @return void 반환값 없음
     */
    protected function logInvalidPaymentCurrency(
        OrderRecord $order,
        InvalidArgumentException $e,
        string $context,
        array $logContext = [],
    ): void {
        Log::error('KG Inicis: payment currency is not chargeable', array_merge([
            'context' => $context,
            'order_id' => $order->id,
            'order_number' => $order->orderNumber,
            'currency' => $order->currency,
            'currency_snapshot' => $order->currencySnapshot,
            'error' => $e->getMessage(),
        ], $logContext));
    }

    /**
     * 요청의 구매자 연락처가 주문자 연락처와 맞는지 확인합니다.
     *
     * 주문자 값이 비어 있으면(배송지 행이 없거나 칸이 비었으면) 그 항목은 대조하지 않는다.
     *
     * @param  Request  $request  결제 요청
     * @param  OrderRecord  $order  주문
     * @return bool 맞거나 대조할 값이 없으면 true
     */
    protected function requestMatchesOrderBuyer(Request $request, OrderRecord $order): bool
    {
        $expectedEmail = strtolower(trim((string) $order->ordererEmail));
        if ($expectedEmail !== '') {
            $receivedEmail = strtolower(trim((string) $request->input('buyer_email', '')));
            if ($receivedEmail === '' || $receivedEmail !== $expectedEmail) {
                return false;
            }
        }

        $expectedPhone = $this->digitsOnly((string) $order->ordererPhone);
        if ($expectedPhone !== '') {
            $receivedPhone = $this->digitsOnly((string) $request->input('buyer_phone', ''));
            if ($receivedPhone === '' || $receivedPhone !== $expectedPhone) {
                return false;
            }
        }

        return true;
    }

    /**
     * CBT 요청의 구매자 연락처를 대조합니다.
     *
     * @param  Request  $request  결제 요청
     * @param  OrderRecord  $order  주문
     * @return bool 맞거나 대조할 값이 없으면 true
     */
    protected function cbtRequestMatchesOrderBuyer(Request $request, OrderRecord $order): bool
    {
        return $this->requestMatchesOrderBuyer($request, $order);
    }

    /**
     * 숫자만 남깁니다.
     *
     * @param  string  $value  원문
     * @return string 숫자만
     */
    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    /**
     * 숫자만 남깁니다(CBT 경로 별칭).
     *
     * @param  string  $value  원문
     * @return string 숫자만
     */
    protected function cbtDigitsOnly(string $value): string
    {
        return $this->digitsOnly($value);
    }
}
