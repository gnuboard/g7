<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Plugin 측 replay attack 방어 — 동일 PG 거래 ID 의 중복 콜백을 멱등 처리.
 *
 * 결제 행의 거래번호 컬럼은 DB unique 제약이 없으므로 동일 거래번호로 콜백이 두 번 도착하면
 * 두 번 결제 완료가 실행되어 중복 처리될 위험이 있다 (예: 결제완료 알림 중복, 마일리지 중복 적립).
 *
 * 본 트레이트는 콜백 진입 시점에 이커머스 공개 결제 계약(`PaymentLookup::isTransactionPaid`)으로
 * 이 플러그인 결제 행(옛 식별자 `nicepay` 포함)이 이미 `paid` 인지 확인해 멱등 분기로 조기 리턴하게 한다.
 *
 * 호스트 요건: 생성자에서 이커머스 결제 계약 `PaymentGatewayBridge` 를 `$paymentGateway` 로 주입받는다.
 */
trait PreventsReplayCallback
{
    /**
     * 동일 transaction_id 가 이미 paid 상태로 저장되었는지 확인.
     *
     * @param  string|null  $transactionId  PG 거래 ID (NICE: tid)
     * @return bool true 면 중복 콜백 — 멱등 응답으로 처리해야 함
     */
    protected function wasAlreadyPaid(?string $transactionId): bool
    {
        if ($transactionId === null || $transactionId === '') {
            return false;
        }

        return $this->paymentGateway->isTransactionPaid(['nicepayments', 'nicepay'], $transactionId);
    }

    /**
     * Replay 감지를 로깅. 통일된 로그 형식으로 운영 모니터링 용이성 확보.
     *
     * @param  string  $tid  PG 거래 ID
     * @param  string|null  $moid  주문번호 (있을 경우)
     * @param  string  $context  콜백 종류
     * @return void 반환값 없음
     */
    protected function logReplayDetected(string $tid, ?string $moid, string $context): void
    {
        Log::info('NICE: replay detected — already paid, returning idempotent response', [
            'tid' => $tid,
            'moid' => $moid,
            'context' => $context,
        ]);
    }
}
