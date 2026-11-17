<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Concerns;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFailure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentOutcome;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\WindowClosure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;

/**
 * 결제창 닫힘·결제 실패를 소유권 검증 후 기록하는 공통 절차.
 *
 * 브라우저 리턴 콜백(`/payment/success`·`/payment/fail`)은 인증도 서명도 없고 주문번호가
 * 쿼리스트링으로 오므로 주문 상태를 바꾸지 않는다. 주문을 실패로 전이시키는 것은 주문
 * 소유자(회원 본인 로그인 / 비회원 조회 토큰)를 확인한 close-report 경로뿐이며(VerifiesOrderOwnership),
 * 다른 결제사 플러그인(KCP·KG이니시스·나이스페이)과 같은 계약이다.
 *
 * 주문 조회와 상태 쓰기는 이커머스 공개 결제 계약으로 한다. 이 trait 를 쓰는 클래스는
 * `$bridge`(PaymentGatewayBridge) 속성을 생성자로 주입받아야 한다.
 *
 * @property-read PaymentGatewayBridge $bridge 이커머스 공개 결제 계약 (사용하는 클래스가 주입)
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
     * @param  OrderRecord  $order  대상 주문
     * @return int 결제 통화 기준 청구액
     *
     * @throws InvalidArgumentException 결제 통화가 청구 불가할 때(공개 예외 ChargeNotResolvable)
     */
    protected function expectedPaymentPrice(OrderRecord $order): int
    {
        return $this->bridge->resolveChargeAmount($order->orderNumber)->minorUnitAmount;
    }

    /**
     * 결제 청구액을 계산하되 통화가 청구 불가하면 null 을 반환합니다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string  $context  로그 문맥
     * @param  array<string, mixed>  $logContext  추가 로그 항목
     * @return int|null 청구액, 통화가 청구 불가하면 null
     */
    protected function resolveExpectedPaymentPriceOrNull(OrderRecord $order, string $context, array $logContext = []): ?int
    {
        try {
            return $this->expectedPaymentPrice($order);
        } catch (InvalidArgumentException $e) {
            Log::error('TossPayments: payment currency is not chargeable', array_merge([
                'context' => $context,
                'order_id' => $order->id,
                'order_number' => $order->orderNumber,
                'currency' => $order->currency,
                'error' => $e->getMessage(),
            ], $logContext));

            return null;
        }
    }

    /**
     * 결제창 닫힘/실패를 주문에 반영합니다.
     *
     * 결제 실패 처리 → 결제 취소 이력 → 토스 실패 기록(결제 행 `failure_history` 최근 5건과 실패 단계) 순서는
     * 이커머스 계약 `markPaymentWindowClosed()` 가 지킨다. 결제 전 주문이 아니면 아무것도 바꾸지 않는다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string|null  $cancelMessage  취소 이력에 남길 메시지 (미전달 시 실패 메시지)
     * @param  string  $failureStage  실패 단계 (window_closed | payment_failed)
     * @return PaymentOutcome 반영 결과
     */
    protected function markPaymentWindowClosed(
        OrderRecord $order,
        string $failureCode,
        string $failureMessage,
        ?string $cancelMessage = null,
        string $failureStage = 'window_closed',
    ): PaymentOutcome {
        $recordedMessage = $cancelMessage ?: $failureMessage;

        return $this->bridge->markPaymentWindowClosed(
            $order->orderNumber,
            self::TOSS_PROVIDER,
            new WindowClosure(
                code: $failureCode,
                message: $failureMessage,
                cancelMessage: $recordedMessage,
                failure: new PaymentFailure(
                    status: PaymentStatusEnum::CANCELLED,
                    meta: fn (array $meta): array => $this->tossFailureMeta($meta, $failureCode, $recordedMessage, $failureStage),
                    takeOverProvider: true,
                ),
            ),
        );
    }

    /**
     * 결제 행 meta 에 토스 실패 이력을 얹은 새 meta 를 만듭니다.
     *
     * @param  array<string, mixed>  $paymentMeta  잠금 아래 읽은 현재 결제 meta
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string  $failureStage  실패 단계
     * @return array<string, mixed> 저장할 결제 meta
     */
    private function tossFailureMeta(array $paymentMeta, string $failureCode, string $failureMessage, string $failureStage): array
    {
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
        $paymentMeta['failure_source'] = self::TOSS_PROVIDER;
        $paymentMeta['failure_code'] = $failureCode;
        $paymentMeta['failure_message'] = $failureMessage;
        $paymentMeta['failure_stage'] = $failureStage;
        $paymentMeta['failed_at'] = $now;

        return $paymentMeta;
    }
}
