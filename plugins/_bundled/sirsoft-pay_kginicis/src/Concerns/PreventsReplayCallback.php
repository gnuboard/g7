<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLookup;

/**
 * Plugin 측 replay attack 방어 — 동일 PG 거래 ID 의 중복 콜백을 멱등 처리.
 *
 * 결제 행의 transaction_id 컬럼은 DB unique 제약이 없으므로
 * 동일 transaction_id 로 콜백이 두 번 도착하면 두 번 `completePayment()` 가
 * 실행되어 중복 처리될 위험이 있다 (예: 결제완료 알림 중복, 마일리지 중복 적립).
 *
 * 본 트레이트는 콜백 진입 시점에 이 결제사의 결제 행이 이미 `paid` 상태인지 이커머스 공개 계약
 * {@see PaymentLookup::isTransactionPaid()} 로 확인해 멱등 분기로 조기 리턴하도록 한다.
 * 호스트 요건: 생성자에서 `PaymentGatewayBridge`(또는 `PaymentLookup`)를 `$bridge` 로 주입받는다.
 */
trait PreventsReplayCallback
{
    /**
     * 동일 transaction_id 가 이미 paid 상태로 저장되었는지 확인.
     *
     * @param  string|null  $transactionId  PG 거래 ID (KG 이니시스: tid)
     * @return bool true 면 중복 콜백 — 멱등 응답으로 처리해야 함
     */
    protected function wasAlreadyPaid(?string $transactionId): bool
    {
        if ($transactionId === null || $transactionId === '') {
            return false;
        }

        return $this->bridge->isTransactionPaid('kginicis', $transactionId);
    }

    /**
     * Replay 감지를 로깅. 통일된 로그 형식으로 운영 모니터링 용이성 확보.
     *
     * @param  string  $tid  PG 거래 ID
     * @param  string|null  $moid  주문번호 (있을 경우)
     * @param  string  $context  콜백 종류 (authCallback / vbankNotify / mobileCallback 등)
     * @return void 반환값 없음
     */
    protected function logReplayDetected(string $tid, ?string $moid, string $context): void
    {
        Log::info('KG Inicis: replay detected — already paid, returning idempotent response', [
            'tid' => $tid,
            'moid' => $moid,
            'context' => $context,
        ]);
    }
}
