<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Plugin 측 replay attack 방어 — 동일 PG 거래 ID 의 중복 콜백을 멱등 처리.
 *
 * 이커머스 결제 행의 거래번호(`transaction_id`)는 DB unique 제약이 없으므로
 * 동일 transaction_id 로 콜백이 두 번 도착하면 두 번 `completePayment()` 가
 * 실행되어 중복 처리될 위험이 있다 (예: 결제완료 알림 중복, 마일리지 중복 적립).
 *
 * 본 트레이트는 콜백 진입 시점에 이커머스 결제 계약(`PaymentLookup::isTransactionPaid`)으로
 * 이 PG(`nhnkcp`) 결제 행이 이미 `paid` 상태인지 확인해 멱등 분기로 조기 리턴하도록 한다.
 * 결제완료와 PG 식별자 기록은 계약의 결제완료가 한 트랜잭션에서 쓰므로 그 사이 틈이 없다.
 *
 * 호스트 요건: 이커머스 결제 계약 `PaymentGatewayBridge` 를 `$bridge` 로 주입받는다.
 */
trait PreventsReplayCallback
{
    /**
     * 동일 transaction_id 가 이미 paid 상태로 저장되었는지 확인.
     *
     * @param  string|null  $transactionId  PG 거래 ID (NHN KCP: tno)
     * @return bool true 면 중복 콜백 — 멱등 응답으로 처리해야 함
     */
    protected function wasAlreadyPaid(?string $transactionId): bool
    {
        if ($transactionId === null || $transactionId === '') {
            return false;
        }

        return $this->bridge->isTransactionPaid('nhnkcp', $transactionId);
    }

    /**
     * Replay 감지를 로깅. 통일된 로그 형식으로 운영 모니터링 용이성 확보.
     *
     * @param  string  $tid  PG 거래 ID
     * @param  string|null  $moid  주문번호 (있을 경우)
     * @param  string  $context  콜백 종류 (authCallback / vbankNotify 등)
     * @return void 반환값 없음
     */
    protected function logReplayDetected(string $tid, ?string $moid, string $context): void
    {
        Log::info('NHN KCP: replay detected — already paid, returning idempotent response', [
            'tid' => $tid,
            'moid' => $moid,
            'context' => $context,
        ]);
    }
}
