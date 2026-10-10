<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidTransition;
use Modules\Sirsoft\Ecommerce\DTO\ReactivationRestorationPlan;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\ReactivationCouponConflictException;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 취소된 주문·상품을 되살릴 때 상품 행과 주문 합계를 복원합니다. (Q26·Q27)
 *
 * 수동 결제 처리 효과(`OrderProcessingService::applyManualPaidTransitionEffects`) 안에서 재활성 전이에만
 * 쓰인다. 종전 재활성은 상태만 바꿔, 상품은 「취소」로 남고 주문 합계는 0원이라 이후 부분취소·환불을
 * 할 수 없었다. 취소·환불 문서는 건드리지 않는다 — 재활성은 별도 사건으로 `order_meta` 와 활동 로그에
 * 남긴다.
 *
 * 합계는 취소 엔진과 **같은 입력**(`OrderAdjustmentService::recalculateForReactivation`)으로 다시 계산한다.
 * 이후 부분취소의 기준선이 같은 식이라야 재활성 뒤 첫 환불 차액이 정확하다.
 */
class OrderReactivationService
{
    /** 미리보기·로그에 싣는 합계 키 */
    private const TOTAL_KEYS = ['total_amount', 'subtotal_amount', 'total_discount_amount', 'total_shipping_amount', 'total_points_used_amount', 'total_paid_amount'];

    /** 늦은 해석 — OrderOptionService → OrderProcessingService → 이 서비스 순환을 끊는다 */
    private ?OrderOptionService $orderOptionService = null;

    /**
     * @param  OrderAdjustmentService  $adjustmentService  금액 재계산 (취소 엔진)
     * @param  OrderRepositoryInterface  $orderRepository  주문 저장소
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 저장소
     * @param  CouponIssueRepositoryInterface  $couponIssueRepository  쿠폰 발급 저장소
     * @param  CurrencyConversionService  $currencyService  통화 환산 (사용 포인트 다통화)
     * @param  OrderShippingAmountWriter  $shippingAmountWriter  배송 행 금액 기록기 (S-Q26-b)
     */
    public function __construct(
        protected OrderAdjustmentService $adjustmentService,
        protected OrderRepositoryInterface $orderRepository,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected CouponIssueRepositoryInterface $couponIssueRepository,
        protected CurrencyConversionService $currencyService,
        protected OrderShippingAmountWriter $shippingAmountWriter,
    ) {}

    /**
     * 복원 계획을 만듭니다. (DB 변경 없음 — 미리보기·실행 공용)
     *
     * 상품 일괄 실행 경로는 이 계획을 만들 때 이미 행을 되살렸다(`$rowsAlreadyRevived`) — 그때는
     * 시뮬레이션 없이 실제 행으로 계산한다. 주문 단위 재활성은 행을 아직 되살리기 전이라 늘 시뮬레이션한다.
     *
     * @param  Order  $order  주문
     * @param  ManualPaidTransition  $transition  전이
     * @param  bool  $rowsAlreadyRevived  상품 행이 이미 되살아났는지 (상품 일괄 실행)
     * @return ReactivationRestorationPlan 계획
     */
    public function plan(Order $order, ManualPaidTransition $transition, bool $rowsAlreadyRevived = false): ReactivationRestorationPlan
    {
        if (! $transition->revives()) {
            return ReactivationRestorationPlan::none();
        }

        $order->loadMissing(['options', 'shippings', 'payment']);

        $revivals = $transition->revivesWholeOrder
            ? $order->options
                ->filter(fn (OrderOption $row) => $row->option_status === OrderStatusEnum::CANCELLED)
                ->sortBy('id')
                ->map(fn (OrderOption $row) => [
                    'order_option_id' => (int) $row->id,
                    'quantity' => (int) $row->quantity,
                    'row_quantity' => (int) $row->quantity,
                ])
                ->values()
                ->all()
            : array_map(static fn (array $item) => [
                'order_option_id' => (int) $item['order_option_id'],
                'quantity' => (int) $item['quantity'],
                'row_quantity' => (int) $item['row_quantity'],
            ], $transition->revivedItems);

        if ($revivals === []) {
            return ReactivationRestorationPlan::notApplicable('no_cancelled_rows');
        }

        [$promotions, $legacy, $usePoints] = $this->recalcInputs($order);

        $work = clone $order;
        $work->setRelation('options', ($transition->revivesWholeOrder || ! $rowsAlreadyRevived)
            ? $this->simulateOptions($order->options, $revivals, $this->targetStatus($transition))
            : $order->options);
        $work->setRelation('shippings', $order->shippings);
        $work->setRelation('payment', $order->payment);

        $recalc = $this->adjustmentService->recalculateForReactivation($work, $promotions, $usePoints);
        if ($recalc === null) {
            return ReactivationRestorationPlan::notApplicable('no_active_items');
        }

        [$reclaim, $conflicts, $release] = $this->classifyCoupons(
            $order,
            $recalc->appliedCouponIssueIds,
            $promotions ?? ($order->promotions_applied_snapshot ?? []),
        );

        return new ReactivationRestorationPlan(
            applicable: true,
            reason: null,
            revivals: $revivals,
            totalsBefore: $this->pickTotals($order->getAttributes()),
            totalsAfter: $this->pickTotals($recalc->totalsAfter),
            couponsToReclaim: $reclaim,
            couponsToRelease: $release,
            couponConflicts: $conflicts,
            legacySnapshot: $legacy,
            promotionsSnapshot: $promotions,
            usePoints: $usePoints,
        );
    }

    /**
     * 쿠폰 충돌이 있으면 막습니다. (Q27 — 단건 경로의 최종 방어)
     *
     * @param  Order  $order  주문
     * @param  ReactivationRestorationPlan  $plan  계획
     * @return void
     *
     * @throws ReactivationCouponConflictException 다른 주문이 쓴 쿠폰·회수된 쿠폰이 있을 때
     */
    public function assertRestorable(Order $order, ReactivationRestorationPlan $plan): void
    {
        if ($plan->couponConflicts !== []) {
            throw ReactivationCouponConflictException::forOrder($order, $plan->couponConflicts);
        }
    }

    /**
     * 행 되살림(R-주문) → 재계산 → 헤더·옵션·배송·스냅샷 기록 → 쿠폰 회수를 수행합니다.
     *
     * 호출부 트랜잭션 안에서만 부른다. 재고는 앞 단계(운영자 선택)가 이미 처리했으므로 여기서
     * 되살리는 행은 재차감하지 않는다.
     *
     * @param  Order  $locked  잠근 주문
     * @param  ManualPaidTransition  $transition  전이
     * @param  ReactivationRestorationPlan  $plan  계획
     * @return array<string, mixed> 적용 보고 (applied·revived·totals_before/after·coupons_reclaimed·legacy_snapshot·snapshot_before·points_used_after·cash_equivalent_after·final_amount)
     *
     * @throws ReactivationCouponConflictException 쿠폰 회수 경쟁에서 밀렸을 때
     */
    public function apply(Order $locked, ManualPaidTransition $transition, ReactivationRestorationPlan $plan): array
    {
        if (! $plan->applicable) {
            return ['applied' => false];
        }

        $this->assertRestorable($locked, $plan);

        $snapshotBefore = $locked->toArray();

        if ($transition->revivesWholeOrder) {
            $target = $this->targetStatus($transition);
            foreach ($plan->revivals as $revival) {
                $row = $this->orderOptionRepository->findOrFail((int) $revival['order_option_id']);
                // 앞 행과 병합되며 이미 흡수됐으면 건너뛴다.
                if ($row->option_status !== OrderStatusEnum::CANCELLED) {
                    continue;
                }

                $this->optionService()->changeStatusWithQuantity(
                    $row,
                    $target,
                    (int) $row->quantity,
                    [],
                    restoreStockOnCancel: true,
                    mergeSiblings: true,
                    redeductStockOnReactivation: false,
                );
            }
        }

        $locked->unsetRelation('options');
        $locked->unsetRelation('shippings');
        $locked->load(['options', 'shippings', 'payment']);

        $recalc = $this->adjustmentService->recalculateForReactivation($locked, $plan->promotionsSnapshot, $plan->usePoints);
        if ($recalc === null) {
            return ['applied' => false];
        }

        foreach ($recalc->optionUpdates as $optionId => $updates) {
            $option = $locked->options->find($optionId);
            if ($option) {
                $this->orderOptionRepository->update($option, $updates);
            }
        }
        // 행별 배송비 몫 — 되살린 행은 0 에서 몫으로, 남은 취소 조각은 0 으로 (S-Q26-b)
        $this->shippingAmountWriter->apply($locked, $recalc->shippingShares);
        $this->orderRepository->update($locked, $recalc->orderUpdates);

        $reclaimed = $this->reclaimCoupons($locked, $recalc->appliedCouponIssueIds);
        $release = array_values(array_diff($plan->couponsToRelease, $recalc->appliedCouponIssueIds));
        if ($release !== []) {
            HookManager::doAction('sirsoft-ecommerce.coupon.restore', $locked, $release);
        }

        return [
            'applied' => true,
            'snapshot_before' => $snapshotBefore,
            'revived' => $plan->revivals,
            'totals_before' => $plan->totalsBefore,
            'totals_after' => $this->pickTotals($recalc->totalsAfter),
            'coupons_reclaimed' => $reclaimed,
            'legacy_snapshot' => $plan->legacySnapshot,
            'points_used_after' => $recalc->pointsUsedAfter,
            'cash_equivalent_after' => $recalc->cashEquivalentAfter,
            'final_amount' => (float) ($recalc->totalsAfter['total_paid_amount'] ?? 0),
        ];
    }

    /**
     * 사용 마일리지·현금성 금액·취소일 장부를 맞춥니다. (멱등)
     *
     * 복원은 사용 포인트(U)를 원래 값으로 되살린다. 이때 마일리지 차감을 끈 채 되살린 주문은 U 만 늘고
     * 실제로 쥔 마일리지는 그대로라, 맞추지 않으면 **차감하지 않은 마일리지를 다음 취소에서 돌려준다**
     * (`is_mileage_deducted` 는 이미 true 라 Q15 가드도 막지 못한다). 그래서 반환 누적(R)을
     * 「U − 실제로 쥔 몫」으로 둔다. 현금성 금액은 다시 받지 않은 현금으로 영수증 발급 가능액이 생기지
     * 않게 「결제 − 환불 누적」으로 자른다. 취소 행이 하나도 남지 않으면 취소일을 지운다.
     *
     * @param  Order  $locked  잠근 주문
     * @param  float|null  $pointsUsedAfter  재계산 사용 포인트
     * @param  float|null  $cashEquivalentAfter  재계산 현금성 금액
     * @return void
     */
    public function reconcileLedgerAfterRestoration(Order $locked, ?float $pointsUsedAfter, ?float $cashEquivalentAfter): void
    {
        $locked->refresh();

        $used = (float) $locked->total_points_used_amount;
        $returned = (float) $locked->total_refunded_points_amount;
        $held = max(0.0, $used - $returned);
        $usedNew = max($used, (float) ($pointsUsedAfter ?? $used));

        $updates = [
            'total_points_used_amount' => $usedNew,
            'total_refunded_points_amount' => max(0.0, $usedNew - $held),
        ];

        if ($cashEquivalentAfter !== null) {
            $updates['total_cash_equivalent_amount'] = min(
                $cashEquivalentAfter,
                max(0.0, (float) $locked->total_paid_amount - (float) $locked->total_refunded_amount),
            );
        }

        if (! $locked->options()->where('option_status', OrderStatusEnum::CANCELLED->value)->exists()) {
            $updates['cancelled_at'] = null;
        }

        if (! empty($locked->currency_snapshot)) {
            $updates['mc_total_points_used_amount'] = $this->currencyService
                ->convertToMultiCurrencyWithSnapshot((int) round($usedNew), $locked->currency_snapshot);
        }

        $this->orderRepository->update($locked, $updates);
    }

    /**
     * 재계산 입력 — 최초 취소 직전 프로모션 스냅샷과 원래 사용 포인트를 고릅니다.
     *
     * @param  Order  $order  주문
     * @return array{0: ?array, 1: bool, 2: float} [프로모션 스냅샷(null=현재 값), 레거시 여부, 사용 포인트]
     */
    private function recalcInputs(Order $order): array
    {
        $meta = $order->order_meta ?? [];
        $pre = $meta['pre_cancel_snapshot'] ?? null;

        $promotions = is_array($pre) ? ($pre['promotions_applied_snapshot'] ?? null) : null;
        $usePoints = (float) ($pre['total_points_used_amount']
            ?? $meta['calculation_input']['use_points']
            ?? $order->total_points_used_amount);

        return [$promotions, ! is_array($pre), $usePoints];
    }

    /**
     * 되살린 뒤의 상품 행을 시뮬레이션합니다. (복제본 — DB 변경 없음)
     *
     * 재계산 입력은 행 상태·수량·스냅샷만 본다. 일부 수량만 되살리면 되살린 조각만 활성으로 남기고
     * 남는 취소 조각은 재계산에서 빠지므로 만들지 않는다. 복제본은 원 행 id 를 그대로 가진다.
     *
     * @param  Collection<int, OrderOption>  $options  현재 행
     * @param  array<int, array{order_option_id: int, quantity: int, row_quantity: int}>  $revivals  되살릴 행
     * @param  OrderStatusEnum  $target  목표 상태
     * @return \Illuminate\Database\Eloquent\Collection<int, OrderOption> 시뮬레이션 행
     */
    private function simulateOptions($options, array $revivals, OrderStatusEnum $target)
    {
        $quantities = [];
        foreach ($revivals as $revival) {
            $quantities[(int) $revival['order_option_id']] = ($quantities[(int) $revival['order_option_id']] ?? 0) + (int) $revival['quantity'];
        }

        return $options->map(function (OrderOption $row) use ($quantities, $target) {
            $quantity = $quantities[(int) $row->id] ?? 0;
            if ($quantity <= 0 || $row->option_status !== OrderStatusEnum::CANCELLED) {
                return $row;
            }

            $clone = clone $row;
            $clone->quantity = min($quantity, (int) $row->quantity);
            $clone->option_status = $target;

            return $clone;
        });
    }

    /**
     * 재계산에 적용된 쿠폰을 회수·충돌·되돌림으로 나눕니다.
     *
     * - 이 주문 몫으로 이미 `used` → 할 일 없음
     * - `available`·`expired` → 회수(다시 「사용됨」). 만료 쿠폰도 주문 당시 정당하게 쓴 것이다
     * - 다른 주문이 `used` → 충돌 `used_by_other_order` / `cancelled` → `revoked` / 행 없음 → `missing`
     * - 원 스냅샷의 쿠폰 중 이 주문 몫으로 `used` 인데 재계산에서 빠진 것 → 되돌림
     *
     * @param  Order  $order  주문
     * @param  array<int, int>  $applied  재계산에 적용된 쿠폰 발급 ID
     * @param  array  $originalSnapshot  원 프로모션 스냅샷
     * @return array{0: array<int, int>, 1: array<int, array<string, mixed>>, 2: array<int, int>} [회수, 충돌, 되돌림]
     */
    private function classifyCoupons(Order $order, array $applied, array $originalSnapshot): array
    {
        $original = array_values(array_unique(array_map('intval', $originalSnapshot['coupon_issue_ids'] ?? [])));
        $issues = collect($this->couponIssueRepository->findByIdsWithRelations(
            array_values(array_unique(array_merge($applied, $original))),
            ['coupon'],
        ))->keyBy('id');

        $reclaim = [];
        $conflicts = [];
        foreach ($applied as $issueId) {
            /** @var CouponIssue|null $issue */
            $issue = $issues->get($issueId);
            $status = $issue?->status;

            if ($issue === null) {
                $conflicts[] = $this->describeConflict(null, $issueId, 'missing');
            } elseif ($status === CouponIssueRecordStatus::USED && (int) $issue->order_id === (int) $order->id) {
                continue;
            } elseif (in_array($status, [CouponIssueRecordStatus::AVAILABLE, CouponIssueRecordStatus::EXPIRED], true)) {
                $reclaim[] = (int) $issueId;
            } elseif ($status === CouponIssueRecordStatus::USED) {
                $conflicts[] = $this->describeConflict($issue, $issueId, 'used_by_other_order');
            } else {
                $conflicts[] = $this->describeConflict($issue, $issueId, 'revoked');
            }
        }

        $release = [];
        foreach ($original as $issueId) {
            $issue = $issues->get($issueId);
            if ($issue !== null && ! in_array($issueId, $applied, true)
                && $issue->status === CouponIssueRecordStatus::USED && (int) $issue->order_id === (int) $order->id) {
                $release[] = $issueId;
            }
        }

        return [$reclaim, $conflicts, $release];
    }

    /**
     * 재계산에 적용된 쿠폰을 이 주문 몫의 「사용됨」으로 되돌립니다. (조건부 갱신 — 경쟁에서 밀리면 충돌)
     *
     * @param  Order  $locked  주문
     * @param  array<int, int>  $applied  재계산에 적용된 쿠폰 발급 ID
     * @return array<int, int> 회수한 쿠폰 발급 ID
     *
     * @throws ReactivationCouponConflictException 회수할 수 없을 때
     */
    private function reclaimCoupons(Order $locked, array $applied): array
    {
        $reclaimed = [];

        foreach ($applied as $issueId) {
            $issue = $this->couponIssueRepository->findById((int) $issueId);
            if ($issue?->status === CouponIssueRecordStatus::USED && (int) $issue->order_id === (int) $locked->id) {
                continue;
            }

            $from = $issue?->status;
            $ok = in_array($from, [CouponIssueRecordStatus::AVAILABLE, CouponIssueRecordStatus::EXPIRED], true)
                && $this->couponIssueRepository->updateIfStatus((int) $issueId, $from, [
                    'status' => CouponIssueRecordStatus::USED,
                    'used_at' => now(),
                    'order_id' => $locked->id,
                ]) > 0;

            if (! $ok) {
                $reason = $issue === null ? 'missing' : ($from === CouponIssueRecordStatus::USED ? 'used_by_other_order' : 'revoked');
                throw ReactivationCouponConflictException::forOrder($locked, [$this->describeConflict($issue, (int) $issueId, $reason)]);
            }

            $reclaimed[] = (int) $issueId;
        }

        return $reclaimed;
    }

    /**
     * 쿠폰 충돌 한 건을 설명합니다.
     *
     * @param  CouponIssue|null  $issue  발급 행
     * @param  int  $issueId  발급 ID
     * @param  string  $reason  사유
     * @return array{coupon_issue_id: int, coupon_name: ?string, reason: string, other_order_number: ?string} 충돌
     */
    private function describeConflict(?CouponIssue $issue, int $issueId, string $reason): array
    {
        $otherOrderNumber = null;
        if ($reason === 'used_by_other_order' && $issue?->order_id) {
            $otherOrderNumber = $this->orderRepository->find((int) $issue->order_id)?->order_number;
        }

        return [
            'coupon_issue_id' => $issueId,
            'coupon_name' => $issue?->coupon?->getLocalizedName(),
            'reason' => $reason,
            'other_order_number' => $otherOrderNumber,
        ];
    }

    /**
     * 미리보기·로그에 싣는 합계만 고릅니다.
     *
     * @param  array<string, mixed>  $values  헤더 속성 또는 재계산 스냅샷
     * @return array<string, float> 합계
     */
    private function pickTotals(array $values): array
    {
        $totals = [];
        foreach (self::TOTAL_KEYS as $key) {
            $totals[$key] = (float) ($values[$key] ?? 0);
        }

        return $totals;
    }

    /**
     * 전이의 목표 상태 (없으면 결제완료)
     *
     * @param  ManualPaidTransition  $transition  전이
     * @return OrderStatusEnum 목표 상태
     */
    private function targetStatus(ManualPaidTransition $transition): OrderStatusEnum
    {
        return OrderStatusEnum::tryFrom((string) $transition->newStatus) ?? OrderStatusEnum::PAYMENT_COMPLETE;
    }

    /**
     * 주문 옵션 서비스를 늦게 해석합니다.
     *
     * @return OrderOptionService 주문 옵션 서비스
     */
    private function optionService(): OrderOptionService
    {
        return $this->orderOptionService ??= app(OrderOptionService::class);
    }
}
