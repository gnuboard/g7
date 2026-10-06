<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 수동 결제 처리 세 항목의 해당 여부·금액 계획 (Q13·Q14·Q24·Q26·Q28)
 *
 * `OrderProcessingService::planManualPaidTransitionEffects()` 가 DB 변경 없이 만든다. 미리보기와
 * 실행이 같은 계획을 쓴다 — 실행은 주문 행을 잠근 뒤 다시 만든다(미리보기 이후 바뀐 값 반영).
 *
 * - stock: `{applicable, option_ids, reason}` — 사유 `no_pending_stock`
 * - mileage: `{applicable, amount_p, amount_r, amount, currency, reason}` — 사유 `guest`·`not_used`·`already_deducted`·`nothing_to_reclaim`
 * - paid: `{applicable, record_amount_p, reclaim_cash_r, never_paid_amount, amount, auto_sync_amount, reason}`
 *   — 사유 `already_recorded`·`payment_row_settled`·`nothing_due`·`nothing_to_reclaim`·`never_paid`(Q28: 해당이며 안내용)
 * - warnings: `pg_already_cancelled`(Q24)
 */
final class ManualPaidEffectsPlan
{
    /**
     * @param  array{applicable: bool, option_ids: array<int, int>, reason: ?string}  $stock  재고 차감
     * @param  array{applicable: bool, amount_p: int, amount_r: int, amount: int, currency: string, reason: ?string}  $mileage  사용 마일리지 차감
     * @param  array{applicable: bool, record_amount_p: float, reclaim_cash_r: float, never_paid_amount: float, amount: float, auto_sync_amount: ?float, reason: ?string}  $paid  결제금액 기록
     * @param  ReactivationRestorationPlan  $restoration  재활성 복원 계획
     * @param  array<int, string>  $warnings  확인 창 경고 코드
     */
    public function __construct(
        public readonly array $stock,
        public readonly array $mileage,
        public readonly array $paid,
        public readonly ReactivationRestorationPlan $restoration,
        public readonly array $warnings = [],
    ) {}

    /**
     * 빈 전이의 계획 (세 항목 모두 해당 없음)
     *
     * @param  string  $currency  기준 통화
     * @return self 빈 계획
     */
    public static function none(string $currency = 'KRW'): self
    {
        return new self(
            ['applicable' => false, 'option_ids' => [], 'reason' => null],
            ['applicable' => false, 'amount_p' => 0, 'amount_r' => 0, 'amount' => 0, 'currency' => $currency, 'reason' => null],
            ['applicable' => false, 'record_amount_p' => 0.0, 'reclaim_cash_r' => 0.0, 'never_paid_amount' => 0.0, 'amount' => 0.0, 'auto_sync_amount' => null, 'reason' => null],
            ReactivationRestorationPlan::none(),
        );
    }

    /**
     * 운영자가 고를 항목이나 자동 정합·복원이 하나라도 있는지 여부
     *
     * @return bool 해당 항목이 있으면 true
     */
    public function hasApplicableItems(): bool
    {
        return $this->stock['applicable'] || $this->mileage['applicable'] || $this->paid['applicable']
            || $this->paid['auto_sync_amount'] !== null || $this->restoration->applicable;
    }

    /**
     * 배열 표현
     *
     * @return array<string, mixed> 계획
     */
    public function toArray(): array
    {
        return [
            'stock' => $this->stock,
            'mileage' => $this->mileage,
            'paid' => $this->paid,
            'restoration' => $this->restoration->toArray(),
            'warnings' => $this->warnings,
        ];
    }
}
