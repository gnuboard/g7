<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFailure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentOutcome;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\WindowClosure;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;

/**
 * 결제 청구액 대조, 주문자 연락처 대조, 결제창 닫힘 기록을 이커머스 공개 결제 계약으로 처리합니다.
 *
 * 호스트 요건: 생성자에서 이커머스 결제 계약 `PaymentGatewayBridge` 를 `$paymentGateway` 로 주입받는다.
 */
trait RecordsPaymentWindowClosure
{
    /**
     * 주문의 결제 청구액을 결제 통화 기준으로 계산합니다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @return int 계산한 값
     *
     * @throws InvalidArgumentException 청구할 수 없는 결제 통화일 때(계약 예외 ChargeNotResolvable 이 이 계열이다)
     */
    protected function expectedPaymentPrice(OrderRecord $order): int
    {
        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액. base(total_due_amount) 직접 비교 시
        // base≠결제 통화에서 PG 청구 통화와 단위가 어긋난다(buildPgPaymentData 와 동일 기준).
        return $this->paymentGateway->resolveChargeAmount($order->orderNumber)->minorUnitAmount;
    }

    /**
     * 결제 청구액을 계산하고, 청구할 수 없는 통화면 기록 후 null 을 돌려줍니다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string  $context  호출 지점 식별자
     * @param  array  $logContext  로그에 덧붙일 문맥
     * @return int|null 계산한 값
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
     * @param  InvalidArgumentException  $e  발생한 예외
     * @param  string  $context  호출 지점 식별자
     * @param  array  $logContext  로그에 덧붙일 문맥
     * @return void 반환값 없음
     */
    protected function logInvalidPaymentCurrency(
        OrderRecord $order,
        InvalidArgumentException $e,
        string $context,
        array $logContext = [],
    ): void {
        Log::error('NicePayments: payment currency is not chargeable', array_merge([
            'context' => $context,
            'order_id' => $order->id,
            'order_number' => $order->orderNumber,
            'currency' => $order->currency,
            'currency_snapshot' => $order->currencySnapshot,
            'error' => $e->getMessage(),
        ], $logContext));
    }

    /**
     * 요청의 주문자 연락처가 주문과 같은지 대조합니다 (서명 발급 전용 — 소유 증명이 아니므로 상태 변경 근거로 쓰지 않는다).
     *
     * @param  Request  $request  요청
     * @param  string  $orderNumber  대상 주문번호
     * @return bool 판정 결과
     */
    protected function requestMatchesOrderBuyer(Request $request, string $orderNumber): bool
    {
        $address = $this->paymentGateway->getShippingAddress($orderNumber);
        if (! $address) {
            return true;
        }

        $expectedEmail = strtolower(trim((string) $address->ordererEmail));
        if ($expectedEmail !== '') {
            $receivedEmail = strtolower(trim((string) $request->input('buyer_email', '')));
            if ($receivedEmail === '' || $receivedEmail !== $expectedEmail) {
                return false;
            }
        }

        $expectedPhone = $this->digitsOnly((string) $address->ordererPhone);
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
     * 이커머스 계약이 결제 전 주문만(주문 → 결제 행 잠금 아래) 결제 실패 처리 → 결제 취소 이력 → 결제 실패 기록 순으로
     * 쓴다. 판정 클로저를 넘기면 잠금 안 판정과 세 단계가 한 트랜잭션이다.
     *
     * @param  string  $orderNumber  대상 주문번호
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string|null  $cancelMessage  취소 이력 메시지 (없으면 실패 메시지)
     * @param  Closure|null  $guard  Closure(OrderRecord, ?PaymentRecord): ?string — 잠금 안 판정(사유 문자열이면 기록하지 않음)
     * @return PaymentOutcome 결과 (결제 전 주문이 아니면 applied=false, reason=order_not_payable)
     */
    protected function markPaymentWindowClosed(
        string $orderNumber,
        string $failureCode,
        string $failureMessage,
        ?string $cancelMessage = null,
        ?Closure $guard = null,
    ): PaymentOutcome {
        return $this->paymentGateway->markPaymentWindowClosed($orderNumber, 'nicepayments', new WindowClosure(
            code: $failureCode,
            message: $failureMessage,
            cancelMessage: $cancelMessage ?: $failureMessage,
            failure: $this->nicePaymentFailure($failureCode, $failureMessage, PaymentStatusEnum::CANCELLED),
        ), $guard);
    }

    /**
     * 나이스페이 결제 실패 이력(결제 메타 4키)과 결제 상태 기록을 만듭니다.
     *
     * 결제 행의 결제사 id 는 바꾸지 않는다. `failure_source` 는 재결제 복구 판정 조건이다.
     *
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  PaymentStatusEnum  $paymentStatus  기록할 결제 상태 (결제창 닫힘은 취소, 그 밖의 실패는 실패)
     * @return PaymentFailure 계약 실패 기록
     */
    protected function nicePaymentFailure(
        string $failureCode,
        string $failureMessage,
        PaymentStatusEnum $paymentStatus = PaymentStatusEnum::FAILED,
    ): PaymentFailure {
        return new PaymentFailure(
            status: $paymentStatus,
            meta: fn (array $meta): array => array_merge($meta, [
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
                'failed_at' => now()->toIso8601String(),
                'failure_source' => 'nicepayments',
            ]),
            takeOverProvider: false,
        );
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
