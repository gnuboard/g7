<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * Plugin 측 replay attack 방어 — 동일 PG 거래 ID 의 중복 콜백을 멱등 처리.
 *
 * `g7_ecommerce_order_payments.transaction_id` 컬럼은 DB unique 제약이 없으므로
 * 동일 transaction_id 로 콜백이 두 번 도착하면 두 번 `completePayment()` 가
 * 실행되어 중복 처리될 위험이 있다 (예: 결제완료 알림 중복, 마일리지 중복 적립).
 *
 * 본 트레이트는 콜백 진입 시점에 OrderPayment 를 조회하여 이미 `paid` 상태이면
 * 멱등 분기로 조기 리턴하도록 한다. modules 영역의 schema 는 변경하지 않고
 * plugin scope 내에서만 처리한다.
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

        return OrderPayment::query()
            ->where('transaction_id', $transactionId)
            ->where('payment_status', PaymentStatusEnum::PAID)
            ->exists();
    }

    /**
     * Replay 감지를 로깅. 통일된 로그 형식으로 운영 모니터링 용이성 확보.
     *
     * @param  string  $tid  PG 거래 ID
     * @param  string|null  $moid  주문번호 (있을 경우)
     * @param  string  $context  콜백 종류 (authCallback / vbankNotify / mobileCallback 등)
     */
    protected function logReplayDetected(string $tid, ?string $moid, string $context): void
    {
        Log::info('KG Inicis: replay detected — already paid, returning idempotent response', [
            'tid'     => $tid,
            'moid'    => $moid,
            'context' => $context,
        ]);
    }
}
