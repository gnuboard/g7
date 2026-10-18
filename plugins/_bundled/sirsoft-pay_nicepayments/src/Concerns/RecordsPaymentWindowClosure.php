<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

trait RecordsPaymentWindowClosure
{
    /**
     * 주문의 결제 청구액을 결제 통화 기준으로 계산합니다.
     *
     * @param  Order  $order  대상 주문
     * @return int 계산한 값
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
     * @param  string  $context  호출 지점 식별자
     * @param  array  $logContext  로그에 덧붙일 문맥
     * @return int|null 계산한 값
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
     * @param  InvalidArgumentException  $e  발생한 예외
     * @param  string  $context  호출 지점 식별자
     * @param  array  $logContext  로그에 덧붙일 문맥
     * @return void 반환값 없음
     */
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

    /**
     * 요청의 주문자 연락처가 주문과 같은지 대조합니다 (서명 발급 전용 — 소유 증명이 아니므로 상태 변경 근거로 쓰지 않는다).
     *
     * @param  Request  $request  요청
     * @param  Order  $order  대상 주문
     * @return bool 판정 결과
     */
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

    /**
     * 결제창 닫힘을 주문 취소와 결제 취소 이력으로 기록합니다 (호출 측이 소유자를 먼저 확인해야 한다).
     *
     * 사용자가 결제창을 닫은 것은 PG 오류가 아니라 사용자 취소이므로, 다른 PG(KG이니시스·NHN KCP·토스페이먼츠)와
     * 같이 결제 상태를 취소로 두고 결제 취소 이력 1건을 남긴다. 실패 코드·메시지는 결제 메타에 함께 남겨
     * 같은 주문번호 재결제 복구(SignData)가 판정 근거로 쓴다.
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

        return $this->markNicePaymentFailed(
            $cancelledOrder,
            $failureCode,
            $failureMessage,
            PaymentStatusEnum::CANCELLED,
        );
    }

    /**
     * 나이스페이 결제 실패 이력을 결제 메타에 남기고 결제 상태를 갱신합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  PaymentStatusEnum  $paymentStatus  기록할 결제 상태 (결제창 닫힘은 취소, 그 밖의 실패는 실패)
     * @return Order 반영 후 주문
     */
    protected function markNicePaymentFailed(
        Order $order,
        string $failureCode,
        string $failureMessage,
        PaymentStatusEnum $paymentStatus = PaymentStatusEnum::FAILED,
    ): Order {
        $payment = $order->payment()->first();
        if (! $payment) {
            return $order;
        }

        $payment->update([
            'payment_status' => $paymentStatus->value,
            'payment_meta' => array_merge($payment->payment_meta ?? [], [
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                'failed_at' => now()->toIso8601String(),
                'failure_source' => 'nicepayments',
            ]),
        ]);

        return $order->fresh();
    }

    /**
     * 문자열에서 숫자만 남깁니다 (전화번호 대조용).
     *
     * @param  string  $value  값
     * @return string 생성한 문자열
     */
    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }
}
