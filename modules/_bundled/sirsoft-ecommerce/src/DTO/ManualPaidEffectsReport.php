<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 수동 결제 처리 세 항목의 적용 결과 (활동 로그 `order.manual_paid_effects` 의 재료)
 *
 * 일괄 처리는 마일리지를 먼저 모든 주문에 시도하고(1단계) 재고·결제금액·복원을 뒤에 한다(2단계).
 * 두 결과를 `mergeWith()` 로 합치되 로그의 「운영자가 고른 값」은 원래 선택을 쓴다.
 */
final class ManualPaidEffectsReport
{
    /**
     * @param  ManualPaidTransition  $transition  전이
     * @param  ManualPaidEffectsPlan  $plan  계획 (잠근 값으로 다시 계산한 것)
     * @param  ManualPaidOptions  $options  운영자가 고른 값
     * @param  array{stock: bool, mileage: int, paid: float, auto_synced: ?float, restoration: array<string, mixed>}  $applied  적용 결과
     */
    public function __construct(
        public readonly ManualPaidTransition $transition,
        public readonly ManualPaidEffectsPlan $plan,
        public readonly ManualPaidOptions $options,
        public readonly array $applied,
    ) {}

    /**
     * 대상 전이가 아닌 경우의 빈 결과
     *
     * @param  ManualPaidTransition  $transition  전이
     * @param  ManualPaidOptions  $options  운영자 선택
     * @return self 빈 결과
     */
    public static function none(ManualPaidTransition $transition, ManualPaidOptions $options): self
    {
        return new self($transition, ManualPaidEffectsPlan::none(), $options, self::emptyApplied());
    }

    /**
     * 비어 있는 적용 결과
     *
     * @return array{stock: bool, mileage: int, paid: float, auto_synced: ?float, restoration: array<string, mixed>} 적용 결과
     */
    public static function emptyApplied(): array
    {
        return ['stock' => false, 'mileage' => 0, 'paid' => 0.0, 'auto_synced' => null, 'restoration' => ['applied' => false]];
    }

    /**
     * 일괄 2단계 결과를 합칩니다.
     *
     * 해당 여부는 1단계 계획(아직 아무것도 적용하기 전)을 기준으로 남긴다 — 2단계 계획은 1단계가 이미
     * 마일리지를 뺀 뒤라 마일리지가 「이미 차감」으로 보인다.
     *
     * @param  self  $second  2단계 결과
     * @param  ManualPaidOptions  $chosen  운영자가 고른 원래 값
     * @return self 합친 결과
     */
    public function mergeWith(self $second, ManualPaidOptions $chosen): self
    {
        $plan = new ManualPaidEffectsPlan(
            $second->plan->stock,
            $this->plan->mileage,
            $second->plan->paid,
            $second->plan->restoration,
            $this->plan->warnings,
        );

        return new self($this->transition, $plan, $chosen, [
            'stock' => $second->applied['stock'],
            'mileage' => $this->applied['mileage'],
            'paid' => $second->applied['paid'],
            'auto_synced' => $second->applied['auto_synced'],
            'restoration' => $second->applied['restoration'],
        ]);
    }

    /**
     * 활동 로그를 남길 만한 항목이 있었는지 여부
     *
     * @return bool 해당 항목이 있었으면 true
     */
    public function hasApplicableItems(): bool
    {
        return $this->plan->hasApplicableItems();
    }

    /**
     * 해당 항목인데 운영자가 끈 키 목록
     *
     * @return array<int, string> 끈 항목 키
     */
    public function skippedApplicableKeys(): array
    {
        $applicable = [
            'apply_stock_deduction' => $this->plan->stock['applicable'],
            'apply_mileage_deduction' => $this->plan->mileage['applicable'],
            'record_paid_amount' => $this->plan->paid['applicable'],
        ];

        return array_values(array_filter(
            $this->options->skippedKeys(),
            static fn (string $key) => $applicable[$key] ?? false,
        ));
    }

    /**
     * 실제로 적용한 항목 키 목록 (설명 문구용)
     *
     * @return array<int, string> 적용 항목 키
     */
    public function appliedKeys(): array
    {
        $keys = [];
        if ($this->applied['stock']) {
            $keys[] = 'apply_stock_deduction';
        }
        if ($this->applied['mileage'] > 0) {
            $keys[] = 'apply_mileage_deduction';
        }
        if ($this->applied['paid'] > 0 || $this->applied['auto_synced'] !== null) {
            $keys[] = 'record_paid_amount';
        }
        if (($this->applied['restoration']['applied'] ?? false) === true) {
            $keys[] = 'restoration';
        }

        return $keys;
    }

    /**
     * 활동 로그 properties·훅 페이로드
     *
     * @return array<string, mixed> 보고
     */
    public function toArray(): array
    {
        $restoration = $this->applied['restoration'];

        return [
            'trigger' => $this->transition->trigger,
            'transition' => $this->transition->toArray(),
            'options' => $this->options->toArray(),
            'items' => [
                'stock_deduction' => [
                    'applicable' => $this->plan->stock['applicable'],
                    'applied' => $this->applied['stock'],
                    'option_count' => count($this->plan->stock['option_ids']),
                ],
                'mileage_deduction' => [
                    'applicable' => $this->plan->mileage['applicable'],
                    'applied' => $this->applied['mileage'] > 0,
                    'amount' => $this->applied['mileage'],
                ],
                'paid_amount_record' => [
                    'applicable' => $this->plan->paid['applicable'],
                    'applied' => $this->applied['paid'] > 0,
                    'amount' => $this->applied['paid'],
                    'auto_synced' => $this->applied['auto_synced'],
                ],
                'restoration' => [
                    'applicable' => $this->plan->restoration->applicable,
                    'applied' => ($restoration['applied'] ?? false) === true,
                    'revived_option_count' => count($restoration['revived'] ?? []),
                    'revived_quantity' => (int) array_sum(array_column($restoration['revived'] ?? [], 'quantity')),
                    'totals_before' => $restoration['totals_before'] ?? [],
                    'totals_after' => $restoration['totals_after'] ?? [],
                    'coupons_reclaimed' => $restoration['coupons_reclaimed'] ?? [],
                    'legacy_snapshot' => $restoration['legacy_snapshot'] ?? false,
                ],
            ],
            'applied' => $this->appliedKeys(),
            'skipped' => $this->skippedApplicableKeys(),
            'warnings' => $this->plan->warnings,
        ];
    }
}
