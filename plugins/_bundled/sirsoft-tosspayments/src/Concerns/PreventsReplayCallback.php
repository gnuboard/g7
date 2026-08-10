<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Concerns;

use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;

/**
 * 토스 웹훅 replay(중복 통보) 방어 — 동일 PG 거래 ID 의 중복 콜백을 멱등 처리.
 *
 * transaction_id 컬럼에는 DB unique 제약이 없으므로 동일 거래로 웹훅이 두 번 도착하면
 * completePayment 가 두 번 실행되어 중복 적립·알림이 발생할 수 있다. 웹훅 진입 시점에
 * 이미 PAID 상태인지 확인하고, 그렇다면 멱등 200 으로 조기 리턴하도록 한다.
 *
 * OrderPayment 접근은 이커머스 모듈의 Repository 를 경유한다(모델 정적 쿼리 금지).
 */
trait PreventsReplayCallback
{
    /**
     * 동일 transaction_id 가 이미 PAID 상태로 저장되었는지 확인.
     *
     * @param  string|null  $transactionId  PG 거래 ID (토스: paymentKey)
     * @return bool true 면 중복 콜백 — 멱등 응답으로 처리해야 함
     */
    protected function wasAlreadyPaid(?string $transactionId): bool
    {
        return app(OrderPaymentRepositoryInterface::class)->isTransactionPaid($transactionId);
    }

    /**
     * Replay 감지를 로깅. 운영 모니터링을 위한 통일 로그 형식.
     *
     * @param  string  $transactionId  PG 거래 ID
     * @param  string|null  $orderId  주문번호 (있을 경우)
     * @param  string  $context  콜백 종류
     */
    protected function logReplayDetected(string $transactionId, ?string $orderId, string $context): void
    {
        Log::info('TossPayments: replay detected — already paid, returning idempotent response', [
            'transaction_id' => $transactionId,
            'orderId' => $orderId,
            'context' => $context,
        ]);
    }
}
