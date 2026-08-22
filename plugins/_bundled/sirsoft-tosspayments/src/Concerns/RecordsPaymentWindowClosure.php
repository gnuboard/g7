<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 결제창 닫힘·결제 실패를 소유권 검증 후 기록하는 공통 절차.
 *
 * 브라우저 리턴 콜백(`/payment/success`·`/payment/fail`)은 인증도 서명도 없고 주문번호가
 * 쿼리스트링으로 오므로 주문 상태를 바꾸지 않는다. 주문을 실패로 전이시키는 것은 구매자
 * 정보를 대조한 이 경로뿐이며, 다른 결제사 플러그인(KCP·KG이니시스·나이스페이)과 같은 계약이다.
 */
trait RecordsPaymentWindowClosure
{
    private const TOSS_PROVIDER = 'tosspayments';

    /**
     * 주문의 결제 청구액을 결제 통화 기준으로 계산합니다.
     *
     * 결제 청구액 SSoT 는 결제 통화(order_currency) 환산액이다. base(total_due_amount)를 직접
     * 비교하면 base≠결제 통화인 주문에서 PG 청구 통화와 단위가 어긋난다.
     *
     * @param  Order  $order  대상 주문
     * @return int 결제 통화 기준 청구액
     */
    protected function expectedPaymentPrice(Order $order): int
    {
        return app(CurrencyConversionService::class)->resolveOrderPaymentChargeAmount($order);
    }

    /**
     * 결제 청구액을 계산하되 통화가 청구 불가하면 null 을 반환합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $context  로그 문맥
     * @param  array<string, mixed>  $logContext  추가 로그 항목
     * @return int|null 청구액, 통화가 청구 불가하면 null
     */
    protected function resolveExpectedPaymentPriceOrNull(Order $order, string $context, array $logContext = []): ?int
    {
        try {
            return $this->expectedPaymentPrice($order);
        } catch (InvalidArgumentException $e) {
            Log::error('TossPayments: payment currency is not chargeable', array_merge([
                'context' => $context,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'currency' => $order->currency,
                'error' => $e->getMessage(),
            ], $logContext));

            return null;
        }
    }

    /**
     * 요청이 주문의 구매자 본인에게서 온 것인지 대조합니다.
     *
     * 이 대조가 주문 상태를 바꿀 자격의 근거다. 배송지가 없으면 대조할 기준이 없으므로 통과시킨다
     * (다른 결제사 플러그인과 동일 계약).
     *
     * @param  Request  $request  검증 대상 요청
     * @param  Order  $order  대상 주문
     * @return bool 구매자 정보가 일치하면 true
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
     * 결제창 닫힘/실패를 주문에 반영합니다.
     *
     * @param  OrderProcessingService  $orderService  주문 처리 서비스
     * @param  Order  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string|null  $cancelMessage  취소 이력에 남길 메시지 (미전달 시 실패 메시지)
     * @param  string  $failureStage  실패 단계 (window_closed | payment_failed)
     * @return Order 반영된 주문
     */
    protected function markPaymentWindowClosed(
        OrderProcessingService $orderService,
        Order $order,
        string $failureCode,
        string $failureMessage,
        ?string $cancelMessage = null,
        string $failureStage = 'window_closed',
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

        return $this->markTossPaymentFailureRecord(
            $cancelledOrder,
            $failureCode,
            $cancelMessage ?: $failureMessage,
            $failureStage,
            PaymentStatusEnum::CANCELLED,
        );
    }

    /**
     * 결제 실패 이력을 결제 레코드에 남깁니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string  $failureStage  실패 단계
     * @param  PaymentStatusEnum  $paymentStatus  기록할 결제 상태
     * @return Order 갱신된 주문
     */
    protected function markTossPaymentFailureRecord(
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
        $paymentMeta['failure_source'] = self::TOSS_PROVIDER;
        $paymentMeta['failure_code'] = $failureCode;
        $paymentMeta['failure_message'] = $failureMessage;
        $paymentMeta['failure_stage'] = $failureStage;
        $paymentMeta['failed_at'] = $now;

        $payment->update([
            'pg_provider' => self::TOSS_PROVIDER,
            'payment_status' => $paymentStatus->value,
            'payment_meta' => $paymentMeta,
        ]);

        return $order->fresh('payment') ?? $order;
    }

    /**
     * 문자열에서 숫자만 남깁니다 (전화번호 대조용).
     *
     * @param  string  $value  원본 문자열
     * @return string 숫자만 남은 문자열
     */
    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }
}
