<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ItemCalculation;
use Modules\Sirsoft\Ecommerce\DTO\OrderAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\CalculationSnapshotPresenter;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Support\VatCalculator;

/**
 * 주문 변경 금액 계산 서비스
 *
 * 취소/반품/교환 시 환불금액을 계산합니다.
 * OrderAdjustment 다형성으로 시나리오별 로직을 분기합니다.
 *
 * 핵심 공식: 원 결제금액 - 재계산 결제금액 - 추가비용 = 환불금액
 */
class OrderAdjustmentService
{
    /**
     * @param  OrderCalculationService  $calculationService  주문 계산 서비스
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     * @param  CouponIssueRepositoryInterface  $couponIssueRepository  쿠폰 발급 Repository
     * @param  CalculationSnapshotPresenter  $snapshotPresenter  비교 스냅샷 표시 재료(정가 합계·다통화 줄) 단일 출처
     */
    public function __construct(
        protected OrderCalculationService $calculationService,
        protected CurrencyConversionService $currencyService,
        protected CouponIssueRepositoryInterface $couponIssueRepository,
        protected CalculationSnapshotPresenter $snapshotPresenter,
        protected OrderExchangeOptionRepositoryInterface $exchangeOptionRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
    ) {}

    /**
     * 교환 후속 행의 **회계 단가** 오버라이드를 만듭니다. (#599 S3-D3)
     *
     * 후속 행(`source_type=exchange`)은 설계대로 원 행의 금액·스냅샷 가격을 승계한다(2026-08-22 판정 1)
     * — 그래야 교환 뒤 **다른 상품**의 부분취소가 정확하다. 그런데 저가 옵션으로 바꾼 교환은 차액을
     * 교환 발 환불신청서로 이미 돌려주므로, 후속 행 **자신**을 취소할 때까지 승계 단가로 세면 그
     * 차액이 두 번 나간다(2026-09-17 실측 주문 1497: 29,000 → 24,000 교환·5,000 환불 뒤 후속 행 취소
     * 환불액 29,000). 재계산에서만 후속 행을 신규 옵션 단가로 본다 — 저장 컬럼은 건드리지 않는다.
     *
     * 적용 조건은 셋을 모두 만족할 때뿐이다: ① 원 행이 교환완료(회계 주체가 후속 행) ② 옵션 차액이
     * 음수 ③ 그 차액의 환불신청서가 있고 반려·철회되지 않았다(「접수하지 않음」으로 완료했으면 차액이
     * 돌아가지 않았으므로 승계 단가가 맞다). 양수 차액(추가결제)은 별도 장부(클레임 결제)라 손대지 않는다.
     *
     * @param  Order  $order  주문 (options 로드됨)
     * @param  array<int, int>  $successorIds  회계 주체인 후속 행 id
     * @return array<int, array{option_snapshot: array, unit_price: float}> 후속 행 id → 오버라이드
     */
    private function successorAccountingOverrides(Order $order, array $successorIds): array
    {
        if ($successorIds === []) {
            return [];
        }

        $overrides = [];
        $refundedExchangeIds = [];

        foreach ($this->exchangeOptionRepository->listByNewOrderOptionIds($successorIds) as $exchangeItem) {
            if ((float) $exchangeItem->price_difference >= 0 || $exchangeItem->new_unit_price === null) {
                continue;
            }

            $exchangeId = (int) $exchangeItem->order_exchange_id;

            if (! array_key_exists($exchangeId, $refundedExchangeIds)) {
                $refundedExchangeIds[$exchangeId] = $this->refundRepository
                    ->listBySource(RefundSourceTypeEnum::EXCHANGE->value, $exchangeId)
                    ->contains(fn ($refund) => ! in_array($refund->refund_status, [RefundStatusEnum::REJECTED, RefundStatusEnum::WITHDRAWN], true));
            }

            if (! $refundedExchangeIds[$exchangeId]) {
                continue;
            }

            $successor = $order->options->find((int) $exchangeItem->new_order_option_id);

            if (! $successor) {
                continue;
            }

            $newSnapshot = is_array($exchangeItem->new_option_snapshot) ? $exchangeItem->new_option_snapshot : [];
            $snapshot = $successor->option_snapshot ?? [];

            // 가격 축만 신규 옵션 값으로 — 정체(옵션명·SKU 등)는 후속 행 스냅샷 그대로.
            foreach (['price_adjustment', 'list_price', 'selling_price'] as $key) {
                if (array_key_exists($key, $newSnapshot)) {
                    $snapshot[$key] = $newSnapshot[$key];
                }
            }

            if (! array_key_exists('selling_price', $newSnapshot)) {
                $snapshot['selling_price'] = (float) $exchangeItem->new_unit_price;
            }

            $overrides[(int) $successor->id] = [
                'option_snapshot' => $snapshot,
                'unit_price' => (float) $exchangeItem->new_unit_price,
            ];
        }

        return $overrides;
    }

    /**
     * 주문 변경에 따른 금액 차이를 계산합니다.
     *
     * @param  Order  $order  대상 주문 (옵션, 배송 관계 로드 필요)
     * @param  OrderAdjustment  $adjustment  변경 정보
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return AdjustmentResult 계산 결과
     */
    public function calculate(
        Order $order,
        OrderAdjustment $adjustment,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): AdjustmentResult {
        $order->loadMissing(['options', 'shippings', 'payment']);

        // 1. 원 주문 금액 스냅샷 확보
        $originalSnapshot = $this->captureOrderSnapshot($order);
        $originalPaidAmount = (float) $order->total_paid_amount;
        $originalPointsUsed = (float) $order->total_points_used_amount;

        // 2. 제외 대상 분석
        $excludedItems = $adjustment->getExcludedItems();
        $excludedMap = $this->buildExcludedMap($excludedItems);

        // 3. 잔여 옵션으로 CalculationInput 구성
        $recalcInput = $this->buildRecalcInput($order, $excludedMap);

        // 전량 제외(전체취소)인 경우
        if (empty($recalcInput->items)) {
            return $this->buildFullCancelResult(
                $order,
                $originalSnapshot,
                $originalPaidAmount,
                $originalPointsUsed,
                $excludedItems,
                $refundPriority,
                $adjustment->getAdditionalCharges(),
            );
        }

        // 4. 재계산 실행
        //
        // buildRecalcInput() 이 항상 snapshot_mode 를 켜므로 재계산은 쿠폰의 사용 상태
        // (status/used_at)를 읽지 않는다. 따라서 재계산을 위해 쿠폰을 일시적으로 미사용으로
        // 되돌릴 필요가 없다 — 조회만 수행한다.
        $recalcResult = $this->calculationService->calculate($recalcInput);

        // 5. 환불금액 계산
        $recalculatedPaidAmount = (float) $recalcResult->summary->finalAmount;
        $recalculatedPointsUsed = (float) $recalcResult->summary->pointsUsed;
        $additionalCharges = $adjustment->getAdditionalCharges();

        // 기준선은 **이번에 빼는 상품 직전의 주문 금액**이다 — 원 결제금액이 아니다.
        //
        // 새 회계는 환불이 나가도 `total_paid_amount` 를 깎지 않는다(§12-B B1 — 깎으면 환불
        // 누적과 이중으로 빠져 2차 환불이 0원이 된다). 그래서 원 결제금액을 기준으로 재계산
        // 차액을 구하면 그 값은 **누적** 환불 가능액이 되고, 앞선 회차에서 이미 나간 돈이 다시
        // 섞여 들어온다 — 3개 중 1개씩 두 번 취소하면 2차가 단가의 두 배를 돌려주어 고객이
        // 상품 1개를 가진 채 전액을 환불받는다(과다 환불).
        //
        // 잔여 한도 상한(아래 `remainingPg`)은 이것을 막지 못한다. 상품이 남는 부분취소에서는
        // 두 값이 같아져 상한에 걸리지 않고 그대로 통과한다 — 전량 제외 경로에서만 걸린다.
        //
        // 그래서 **이미 취소된 상품만 빠진 상태**를 한 번 더 계산해 기준선으로 삼는다. 차액은
        // "이번에 빠지는 상품의 값" 이 되어 회차마다 정확해진다. 앞선 환불이 없으면 기준선은
        // 원 결제금액과 같으므로 추가 계산도 하지 않는다(대부분의 신청이 이 경우다).
        $baselineAmount = $originalPaidAmount + $originalPointsUsed;

        if ((float) $order->total_refunded_amount > 0 || (float) $order->total_refunded_points_amount > 0) {
            $priorInput = $this->buildRecalcInput($order, []);

            if (! empty($priorInput->items)) {
                $priorResult = $this->calculationService->calculate($priorInput);
                $baselineAmount = (float) $priorResult->summary->finalAmount
                    + (float) $priorResult->summary->pointsUsed;
            }
        }

        $totalRefundable = $baselineAmount
            - ($recalculatedPaidAmount + $recalculatedPointsUsed)
            - $additionalCharges;
        $totalRefundable = max(0, $totalRefundable);

        // 포인트 우선순위 기반 배분
        $remainingPg = $originalPaidAmount - (float) $order->total_refunded_amount;
        $remainingPoints = $originalPointsUsed - (float) $order->total_refunded_points_amount;

        if ($refundPriority === RefundPriorityEnum::POINTS_FIRST) {
            $refundPointsAmount = min($totalRefundable, max(0, $remainingPoints));
            $refundAmount = min($totalRefundable - $refundPointsAmount, max(0, $remainingPg));
        } else {
            $refundAmount = min($totalRefundable, max(0, $remainingPg));
            $refundPointsAmount = min($totalRefundable - $refundAmount, max(0, $remainingPoints));
        }

        // 6. 배송비/할인 차이
        $originalShipping = (float) $order->total_shipping_amount;
        $recalculatedShipping = (float) $recalcResult->summary->totalShipping;
        $shippingDifference = $originalShipping - $recalculatedShipping;

        $originalDiscount = (float) $order->total_discount_amount;
        $recalculatedDiscount = (float) $recalcResult->summary->totalDiscount;
        $discountDifference = $originalDiscount - $recalculatedDiscount;

        // 7. 재계산 스냅샷 — 패스 B(옵션 치환)
        //
        // 교환은 대상 상품이 주문에 남되 다른 옵션으로 바뀌므로 패스 A 의 "후" 는 "전" 과 같다.
        // 치환 입력이 있으면 원 라인을 줄이고 신규 옵션 라인을 얹은 입력으로 한 번 더 계산해
        // **표시 열에만** 쓴다(재계산 스냅샷·다통화 스냅샷·재계산 쿠폰·정가 합계·치환 근거 행).
        // 환불 산식·배송비/할인 차·쿠폰 복원·업데이트 페이로드는 전부 패스 A 결과다 — 치환
        // 결과를 산식에 쓰면 옵션 차액이 `getAdditionalCharges()` 와 두 번 빠진다.
        // 치환이 없으면 아무것도 하지 않는다(출력은 종전과 바이트 단위로 같다).
        $substitutions = $adjustment->getSubstitutions();
        $displayResult = $recalcResult;

        if ($substitutions !== []) {
            $displayResult = $this->calculationService->calculate(
                $this->applySubstitutions($recalcInput, $order, $substitutions)
            );
        }

        $recalculatedSnapshot = $this->captureRecalcSnapshot($displayResult);
        $recalculatedSnapshot['total_list_price_amount'] = $this->calculateListPriceTotal($order, $excludedMap, $substitutions);

        // 8. 옵션별 업데이트 정보 생성
        $optionUpdates = $this->buildOptionUpdates($order, $excludedMap, $recalcResult);
        $shippingUpdates = $this->buildShippingUpdates($order, $recalcResult);
        $orderUpdates = $this->buildOrderUpdates($order, $recalcResult);

        // 9. 취소 대상 아이템 정보
        $adjustedItems = $this->buildAdjustedItems($order, $excludedMap);

        // 10. 쿠폰 복원 대상 확인
        $restoredCouponIssueIds = $this->detectRestoredCoupons($order, $recalcResult);

        // 11. 복원 쿠폰 상세 정보 구성
        $restoredCoupons = $this->buildRestoredCouponsInfo($restoredCouponIssueIds, $order->currency_snapshot);

        // 12. 배송비 정책별 상세
        $shippingDetails = $this->buildShippingDetails($order, $recalcResult);

        // 13. mc_* 다통화 환불금 변환
        $currencySnapshot = $order->currency_snapshot;
        $mcRefundAmount = null;
        $mcRefundPointsAmount = null;
        $mcRefundShippingAmount = null;

        if ($currencySnapshot) {
            $mcRefundAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                ['refund_amount' => $refundAmount],
                $currencySnapshot
            );
            $mcRefundAmount = $this->extractCurrencyAmounts($mcRefundAmount, 'refund_amount');

            $mcRefundPointsAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                ['refund_points_amount' => $refundPointsAmount],
                $currencySnapshot
            );
            $mcRefundPointsAmount = $this->extractCurrencyAmounts($mcRefundPointsAmount, 'refund_points_amount');

            if ($shippingDifference > 0) {
                $mcRefundShippingAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                    ['refund_shipping_amount' => $shippingDifference],
                    $currencySnapshot
                );
                $mcRefundShippingAmount = $this->extractCurrencyAmounts($mcRefundShippingAmount, 'refund_shipping_amount');
            }
        }

        // 14. 환불 후 잔여 잔액
        $remainingPgBalance = max(0, $remainingPg - $refundAmount);
        $remainingPointsBalance = max(0, $remainingPoints - $refundPointsAmount);

        // 15. 다통화 스냅샷 (총 정가금액 + 실결제금액)
        $mcOriginalSnapshot = $this->buildMcOriginalSnapshot($order);
        $mcRecalculatedSnapshot = $this->buildMcRecalcSnapshot($recalculatedSnapshot, $order->currency_snapshot);

        // 16. 쿠폰 상세 (전/후)
        $originalCoupons = $this->extractCouponDetails($order->promotions_applied_snapshot ?? [], $order->currency_snapshot);
        $recalculatedCoupons = $this->extractCouponDetails($displayResult->promotions?->toArray() ?? [], $order->currency_snapshot);

        // 17. 스냅샷 각 줄을 base 통화로 포맷(취소 모달 primary 표기 = base 통화 기호 고정)
        $originalSnapshot = $this->enrichSnapshotWithBaseFormat($originalSnapshot, $order->currency_snapshot);
        $recalculatedSnapshot = $this->enrichSnapshotWithBaseFormat($recalculatedSnapshot, $order->currency_snapshot);

        // 18. 환불 총액/잔액 base 포맷 + 결제 통화 병기
        $refundFormatted = $this->buildRefundFormatted([
            'refund_total' => max(0, $refundAmount) + $refundPointsAmount,
            'refund_amount' => max(0, $refundAmount),
            'remaining_pg_balance' => $remainingPgBalance,
            'remaining_points_balance' => $remainingPointsBalance,
        ], $order->currency_snapshot);

        return new AdjustmentResult(
            refundAmount: $refundAmount,
            refundPointsAmount: $refundPointsAmount,
            originalPaidAmount: $originalPaidAmount,
            recalculatedPaidAmount: $recalculatedPaidAmount,
            shippingDifference: $shippingDifference,
            discountDifference: $discountDifference,
            recalculated: $recalcResult,
            adjustedItems: $adjustedItems,
            orderUpdates: $orderUpdates,
            optionUpdates: $optionUpdates,
            shippingUpdates: $shippingUpdates,
            originalSnapshot: $originalSnapshot,
            recalculatedSnapshot: $recalculatedSnapshot,
            restoredCouponIssueIds: $restoredCouponIssueIds,
            refundPriority: $refundPriority,
            remainingPgBalance: $remainingPgBalance,
            remainingPointsBalance: $remainingPointsBalance,
            restoredCoupons: $restoredCoupons,
            shippingDetails: $shippingDetails,
            mcRefundAmount: $mcRefundAmount,
            mcRefundPointsAmount: $mcRefundPointsAmount,
            mcRefundShippingAmount: $mcRefundShippingAmount,
            mcOriginalSnapshot: $mcOriginalSnapshot,
            mcRecalculatedSnapshot: $mcRecalculatedSnapshot,
            originalCoupons: $originalCoupons,
            recalculatedCoupons: $recalculatedCoupons,
            refundFormatted: $refundFormatted,
            substitutedItems: $this->buildSubstitutedItems($order, $substitutions),
        );
    }

    /**
     * 미리보기용 환불금액을 계산합니다. (DB 변경 없음)
     *
     * @param  Order  $order  대상 주문
     * @param  OrderAdjustment  $adjustment  변경 정보
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return AdjustmentResult 계산 결과
     */
    public function preview(
        Order $order,
        OrderAdjustment $adjustment,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): AdjustmentResult {
        return $this->calculate($order, $adjustment, $refundPriority);
    }

    /**
     * 주문 금액 스냅샷을 캡처합니다.
     *
     * @param  Order  $order  주문
     * @return array 스냅샷
     */
    private function captureOrderSnapshot(Order $order): array
    {
        return [
            'total_amount' => (float) $order->total_amount,
            'subtotal_amount' => (float) $order->subtotal_amount,
            'total_list_price_amount' => $this->calculateListPriceTotal($order),
            'total_discount_amount' => (float) $order->total_discount_amount,
            'total_coupon_discount_amount' => (float) $order->total_coupon_discount_amount,
            'total_product_coupon_discount_amount' => (float) $order->total_product_coupon_discount_amount,
            'total_order_coupon_discount_amount' => (float) $order->total_order_coupon_discount_amount,
            'total_code_discount_amount' => (float) $order->total_code_discount_amount,
            'total_shipping_amount' => (float) $order->total_shipping_amount,
            'base_shipping_amount' => (float) $order->base_shipping_amount,
            'extra_shipping_amount' => (float) $order->extra_shipping_amount,
            'shipping_discount_amount' => (float) $order->shipping_discount_amount,
            'total_paid_amount' => (float) $order->total_paid_amount,
            'total_points_used_amount' => (float) $order->total_points_used_amount,
            'total_deposit_used_amount' => (float) $order->total_deposit_used_amount,
            // 실결제 발생 신호(취소 차단 게이트 SSoT) — payment_status === PAID 가 1차 신호(운영자 강제
            // order_status 변경에 오염 안 됨), 보조로 실사용 금액 합(결제+포인트+예치금) > 0.
            'has_actual_payment' => (bool) $order->payment?->isPaid()
                || ((float) $order->total_paid_amount
                    + (float) $order->total_points_used_amount
                    + (float) $order->total_deposit_used_amount) > 0.01,
            'total_tax_amount' => (float) $order->total_tax_amount,
            'total_tax_free_amount' => (float) $order->total_tax_free_amount,
            'total_vat_amount' => (float) $order->total_vat_amount,
            'total_earned_points_amount' => (float) $order->total_earned_points_amount,
            'item_count' => $order->options
                ->where('option_status', '!=', OrderStatusEnum::CANCELLED)
                ->sum('quantity'),
        ];
    }

    /**
     * 주문 옵션의 정가(list_price) 합계를 계산합니다. (`CalculationSnapshotPresenter::listPriceTotal()` 위임)
     *
     * @param  Order  $order  주문
     * @param  array|null  $excludedMap  제외 맵 (null이면 전체 옵션 대상)
     * @param  array  $substitutions  옵션 치환 입력 (패스 B 표시 열에만 적용)
     * @return float 정가 합계
     */
    private function calculateListPriceTotal(Order $order, ?array $excludedMap = null, array $substitutions = []): float
    {
        return $this->snapshotPresenter->listPriceTotal($order, $excludedMap, $substitutions);
    }

    /**
     * 옵션 치환을 반영한 표시용 재계산 입력을 만듭니다. (패스 B)
     *
     * `buildRecalcInput()` 은 손대지 않고 그 결과를 복제해 바꾼다. 치환 대상 주문 행의 라인은
     * 수량을 `잔여 − 치환` 으로 줄이고(0 이면 라인 제거), 치환 라인을 **음수 라인키**로 추가한다 —
     * 엔진의 내부 맵이 전부 `product_option_id` 키라 같은 키를 두 번 넣으면 나중 것이 조용히
     * 덮어쓰고, 옵션 변경 없이 커스텀·추가옵션만 바꾸는 교환은 원 키와 반드시 충돌하기 때문이다.
     *
     * 치환 라인은 원 라인의 배송정책 스냅샷·상품쿠폰 슬롯·주문레벨 상품쿠폰 적용 대상을 승계한다.
     * 정률+상한 쿠폰은 라인이 갈리면 상한이 두 번 걸리므로 두 라인의 상한을 수량 비례로 나눠
     * 라인별 오버라이드(`max_discount_amount_by_line`)로 싣는다. 훅 오버라이드 배송비는 옵션 단위
     * 정액이라 원 라인이 남으면 복제본의 금액을 0 으로 둔다.
     *
     * @param  CalculationInput  $base  패스 A 입력
     * @param  Order  $order  주문
     * @param  array  $substitutions  옵션 치환 입력 [order_option_id => {quantity, product_option_id, unit_price, option_snapshot, additional_options_snapshot}]
     * @return CalculationInput 표시용 입력
     */
    private function applySubstitutions(CalculationInput $base, Order $order, array $substitutions): CalculationInput
    {
        $items = array_map(fn (CalculationItem $item) => clone $item, $base->items);
        $itemCoupons = $base->itemCoupons;
        $shippingPolicySnapshots = $base->shippingPolicySnapshots ?? [];
        $metadata = $base->metadata;
        $couponSnapshots = $metadata['coupon_snapshots'] ?? [];

        foreach ($substitutions as $orderOptionId => $substitution) {
            $option = $order->options->find($orderOptionId);
            $quantity = (int) ($substitution['quantity'] ?? 0);

            if (! $option instanceof OrderOption || $quantity <= 0) {
                continue;
            }

            $originalKey = (int) $option->product_option_id;
            $newKey = self::substitutionLineKey((int) $orderOptionId);

            $index = null;
            foreach ($items as $i => $item) {
                if ((int) $item->productOptionId === $originalKey) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                continue;
            }

            $original = $items[$index];
            $originalQuantity = (int) $original->quantity;
            $quantity = min($quantity, $originalQuantity);
            $remaining = $originalQuantity - $quantity;
            $productSnapshot = $original->productSnapshot ?? [];

            // 단가 어댑터 — 엔진 단가는 `product_snapshot.selling_price + option_snapshot.price_adjustment` 다.
            // 신규 단가(`new_unit_price`, 현재 상품가 기준)와 동결 스냅샷이 어긋날 수 있으므로 조정액을
            // 덮어써 엔진 단가 == 신규 단가 == 차액 산식 기준으로 맞춘다. 적립 규칙(mileage_*)은 신규 옵션 값 그대로.
            $optionSnapshot = (array) ($substitution['option_snapshot'] ?? $original->optionSnapshot ?? []);
            $optionSnapshot['price_adjustment'] = (float) ($substitution['unit_price'] ?? 0) - (float) ($productSnapshot['selling_price'] ?? 0);

            $items[] = new CalculationItem(
                productId: $original->productId,
                productOptionId: $newKey,
                quantity: $quantity,
                productSnapshot: $productSnapshot,
                optionSnapshot: $optionSnapshot,
                additionalOptionsSnapshot: (array) ($substitution['additional_options_snapshot'] ?? []),
            );

            if ($remaining > 0) {
                $items[$index]->quantity = $remaining;
            } else {
                unset($items[$index]);
            }

            // 배송정책 승계 — 키가 없으면 스냅샷 모드는 폴백 없이 라인을 배송 그룹에서 뺀다.
            if (isset($shippingPolicySnapshots[$originalKey])) {
                $inherited = $shippingPolicySnapshots[$originalKey];

                if ($remaining > 0 && ! empty($inherited['hook_overridden']) && isset($inherited['shipping_amount'])) {
                    $inherited['shipping_amount'] = 0;
                }

                $shippingPolicySnapshots[$newKey] = $inherited;
            }

            // 상품쿠폰 승계 — 상품별 슬롯과 주문레벨 상품쿠폰의 적용 대상 둘 다.
            $touchingCoupons = [];

            if (isset($itemCoupons[$originalKey])) {
                $itemCoupons[$newKey] = $itemCoupons[$originalKey];
                $touchingCoupons = array_merge($touchingCoupons, (array) $itemCoupons[$originalKey]);
            }

            foreach ($couponSnapshots as $couponIssueId => $snapshot) {
                $applied = $snapshot['applied_items'] ?? null;

                if (! is_array($applied)) {
                    continue;
                }

                if (in_array($originalKey, array_map('intval', array_column($applied, 'product_option_id')), true)) {
                    $couponSnapshots[$couponIssueId]['applied_items'][] = ['product_option_id' => $newKey];
                    $touchingCoupons[] = $couponIssueId;
                }
            }

            // 쿠폰 상한 분배 — 라인이 갈릴 때만(원 잔여 > 0). 전량 치환이면 한 라인이라 분배하지 않는다.
            // 정액 쿠폰은 수량 비례라 분배가 필요 없다.
            if ($remaining > 0) {
                foreach (array_unique($touchingCoupons) as $couponIssueId) {
                    $snapshot = $couponSnapshots[$couponIssueId] ?? null;
                    $cap = (int) ($snapshot['max_discount_amount'] ?? 0);

                    if ($snapshot === null || $cap <= 0 || ($snapshot['discount_type'] ?? null) !== CouponDiscountType::RATE->value) {
                        continue;
                    }

                    $couponSnapshots[$couponIssueId]['max_discount_amount_by_line'][$originalKey] = (int) round($cap * $remaining / $originalQuantity);
                    $couponSnapshots[$couponIssueId]['max_discount_amount_by_line'][$newKey] = (int) round($cap * $quantity / $originalQuantity);
                }
            }
        }

        $metadata['coupon_snapshots'] = $couponSnapshots;

        return new CalculationInput(
            items: array_values($items),
            couponIssueIds: $base->couponIssueIds,
            itemCoupons: $itemCoupons,
            discountCode: $base->discountCode,
            usePoints: $base->usePoints,
            shippingAddress: $base->shippingAddress,
            paymentCurrency: $base->paymentCurrency,
            metadata: $metadata,
            shippingPolicySnapshots: $shippingPolicySnapshots,
            promotionSnapshots: $base->promotionSnapshots,
            userId: $base->userId,
            mileagePolicySnapshot: $base->mileagePolicySnapshot,
        );
    }

    /**
     * 치환 라인의 엔진 키를 만듭니다. (주문옵션 ID 의 음수 — 어떤 상품옵션 ID 와도 겹치지 않는다)
     *
     * `buildOptionUpdates()` 는 `product_option_id ==` 매칭이라 음수 키는 어느 주문 행과도 맞지 않아
     * 업데이트 페이로드에서 자연히 빠진다(교환은 어차피 그 페이로드를 DB 에 쓰지 않는다).
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @return int 라인 키
     */
    private static function substitutionLineKey(int $orderOptionId): int
    {
        return -1 * $orderOptionId;
    }

    /**
     * 치환 근거 행을 만듭니다. (화면의 "교환 대상" 행 — 상품별 old→new)
     *
     * @param  Order  $order  주문
     * @param  array  $substitutions  옵션 치환 입력
     * @return array [{order_option_id, quantity, product_option_id, unit_price, new_unit_price, line_key, option_label}]
     */
    private function buildSubstitutedItems(Order $order, array $substitutions): array
    {
        $rows = [];

        foreach ($substitutions as $orderOptionId => $substitution) {
            $option = $order->options->find($orderOptionId);

            if (! $option instanceof OrderOption) {
                continue;
            }

            $optionSnapshot = (array) ($substitution['option_snapshot'] ?? []);

            $rows[] = [
                'order_option_id' => (int) $orderOptionId,
                'quantity' => (int) ($substitution['quantity'] ?? 0),
                'product_option_id' => isset($substitution['product_option_id']) ? (int) $substitution['product_option_id'] : null,
                'unit_price' => (float) $option->unit_price,
                'new_unit_price' => (float) ($substitution['unit_price'] ?? 0),
                'line_key' => self::substitutionLineKey((int) $orderOptionId),
                'option_label' => $this->localizedText($optionSnapshot['option_value'] ?? null)
                    ?: $this->localizedText($optionSnapshot['option_name'] ?? null)
                    ?: $this->localizedText($option->option_value ?? null)
                    ?: $this->localizedText($option->option_name ?? null),
            ];
        }

        return $rows;
    }

    /**
     * 로케일 맵·문자열을 현재 로케일 문자열로 폅니다.
     *
     * @param  mixed  $value  로케일 맵 또는 문자열
     * @return string 표시 문자열 (없으면 빈 문자열)
     */
    private function localizedText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || $value === []) {
            return '';
        }

        $text = $value[app()->getLocale()] ?? $value[config('app.fallback_locale', 'ko')] ?? reset($value);

        return is_string($text) ? $text : '';
    }

    /**
     * 제외 대상 맵을 생성합니다.
     *
     * @param  array  $excludedItems  [{order_option_id, cancel_quantity}]
     * @return array [order_option_id => cancel_quantity]
     */
    private function buildExcludedMap(array $excludedItems): array
    {
        $map = [];
        foreach ($excludedItems as $item) {
            $map[$item['order_option_id']] = $item['cancel_quantity'];
        }

        return $map;
    }

    /**
     * 잔여 옵션으로 재계산 입력을 구성합니다.
     *
     * 교환이 끝난 주문에는 같은 상품이 두 행으로 남는다 — 교환완료(`EXCHANGED`)로 닫힌 원 행과,
     * 재발송으로 생성된 후속 행(`source_type=exchange`)이다. 후속 행은 원 행의 금액 컬럼·스냅샷
     * 가격을 그대로 승계하므로 **둘 중 하나만** 세야 한다. 둘 다 세면 교환 뒤의 부분취소에서
     * 그 상품이 두 번 잔여로 잡혀 환불액이 상품 금액만큼 작아진다 — 오류도 로그도 없다.
     *
     * 규칙: 원 행이 아직 교환 진행 중이면 원 행이 회계 주체이고 후속 행은 뺀다(재발송중~완료 전
     * 구간). 원 행이 교환완료로 닫히면 후속 행이 주체가 되고 원 행을 뺀다. 반품완료(`RETURNED`)는
     * 승계 행이 없으므로 종전대로 잔여로 센다.
     *
     * @param  Order  $order  주문
     * @param  array  $excludedMap  제외 맵
     * @return CalculationInput 재계산 입력
     */
    private function buildRecalcInput(Order $order, array $excludedMap): CalculationInput
    {
        $items = [];
        $restoredCoupons = [];

        $exchangedSourceIds = $order->options
            ->filter(fn ($option) => $option->option_status === OrderStatusEnum::EXCHANGED)
            ->pluck('id')
            ->all();

        // 회계 주체가 된 후속 행 — 저가 옵션 교환이면 신규 옵션 단가로 센다(S3-D3).
        $successorIds = $order->options
            ->filter(fn ($option) => $option->isExchanged() && in_array((int) $option->source_option_id, $exchangedSourceIds, true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $successorOverrides = $this->successorAccountingOverrides($order, $successorIds);

        foreach ($order->options as $option) {
            // 이미 취소된 옵션은 제외
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }

            // 교환완료로 닫힌 원 행은 후속 행이 승계한다.
            if ($option->option_status === OrderStatusEnum::EXCHANGED) {
                continue;
            }

            // 원 행이 아직 교환 진행 중인 후속 행은 회계 주체가 아니다.
            if ($option->isExchanged() && ! in_array((int) $option->source_option_id, $exchangedSourceIds, true)) {
                continue;
            }

            $optionId = $option->id;
            $cancelQty = $excludedMap[$optionId] ?? 0;
            $remainingQty = $option->quantity - $cancelQty;

            if ($remainingQty <= 0) {
                continue;
            }

            $items[] = CalculationItem::fromArray([
                'product_id' => $option->product_id,
                'product_option_id' => $option->product_option_id,
                'quantity' => $remainingQty,
                'product_snapshot' => $option->product_snapshot,
                'option_snapshot' => $successorOverrides[(int) $optionId]['option_snapshot'] ?? $option->option_snapshot,
                'additional_options_snapshot' => $option->additional_options_snapshot,
            ]);
        }

        // 쿠폰 정보 복원 (프로모션 스냅샷에서)
        $couponIssueIds = [];
        $itemCoupons = [];
        $discountCode = null;

        $promoSnapshot = $order->promotions_applied_snapshot ?? [];

        // 플러그인이 스냅샷에서 자체 할인 데이터를 해석/복원할 수 있는 훅
        // 플러그인 OFF 시 훅 미등록 → $promoSnapshot 그대로 반환 (쿠폰만)
        $promoSnapshot = HookManager::applyFilters(
            'sirsoft-ecommerce.adjustment.filter_restore_promotions',
            $promoSnapshot,
            $order
        );

        if (! empty($promoSnapshot)) {
            $couponIssueIds = $promoSnapshot['coupon_issue_ids'] ?? [];
            $itemCoupons = $promoSnapshot['item_coupons'] ?? [];
            $discountCode = $promoSnapshot['discount_code'] ?? null;

            // 상품쿠폰은 `itemCoupons` 슬롯으로만 넘긴다 — 주문 스냅샷의 `coupon_issue_ids` 는
            // 복원·탈락검출의 SSoT 라 상품쿠폰까지 **전부** 담지만(PromotionsSummary::toArray),
            // 계산 엔진은 두 슬롯을 각각 적용하므로 그대로 넘기면 상품쿠폰이 두 번 먹는다
            // (상품별 슬롯 1회 + 주문레벨 슬롯 1회 → 할인 2배 → 과다 환불, S1-D2).
            // 주문서 계산도 주문·배송비 쿠폰만 `couponIssueIds` 에 넘긴다(TempOrderService:377)
            // — 재계산이 그 계약을 그대로 따른다.
            $itemCouponIssueIds = [];
            foreach ($itemCoupons as $issueIds) {
                foreach ((array) $issueIds as $issueId) {
                    $itemCouponIssueIds[(int) $issueId] = true;
                }
            }

            if ($itemCouponIssueIds !== []) {
                $couponIssueIds = array_values(array_filter(
                    $couponIssueIds,
                    static fn ($issueId) => ! isset($itemCouponIssueIds[(int) $issueId])
                ));
            }
        }

        // 배송지 복원
        $shippingAddress = null;
        $shippingSnapshot = $order->shipping_policy_applied_snapshot ?? [];
        $snapshotAddress = ShippingPolicySnapshot::address($shippingSnapshot);
        if (! empty($snapshotAddress)) {
            $shippingAddress = ShippingAddress::fromArray($snapshotAddress);
        }

        // 배송정책 스냅샷을 product_option_id 키 맵으로 변환
        $shippingPolicySnapshots = $this->buildShippingPolicySnapshotMap($shippingSnapshot);

        // 쿠폰 스냅샷 구성 (스냅샷 모드에서 쿠폰 규칙 복원용)
        $couponSnapshots = $this->buildCouponSnapshotsFromOrder($order);

        return new CalculationInput(
            items: $items,
            couponIssueIds: $couponIssueIds,
            itemCoupons: $itemCoupons,
            discountCode: $discountCode,
            usePoints: (int) $order->total_points_used_amount,
            shippingAddress: $shippingAddress,
            paymentCurrency: $order->currency,
            metadata: [
                'snapshot_mode' => true,
                'coupon_snapshots' => $couponSnapshots,
                'currency_snapshot' => $order->currency_snapshot,
            ],
            shippingPolicySnapshots: $shippingPolicySnapshots,
            promotionSnapshots: $order->promotions_applied_snapshot,
            // 적립 절사 기준은 주문 시점 값으로 고정한다 — 설정을 다시 읽으면 운영자가 이후에
            // 바꾼 기준이 소급 적용돼, 취소하지 않은 잔여분의 적립액이 취소 처리만으로 달라진다.
            mileagePolicySnapshot: $order->mileage_policy_snapshot,
        );
    }

    /**
     * 전량 제외(전체취소·전량 반품) 결과를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $originalSnapshot  원 스냅샷
     * @param  float  $originalPaidAmount  원 결제금액
     * @param  float  $originalPointsUsed  원 포인트 사용액
     * @param  array  $excludedItems  제외 대상 아이템
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  float  $additionalCharges  환불액에서 차감할 추가 비용 (취소는 0)
     * @return AdjustmentResult 전량 제외 결과
     */
    private function buildFullCancelResult(
        Order $order,
        array $originalSnapshot,
        float $originalPaidAmount,
        float $originalPointsUsed,
        array $excludedItems,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
        float $additionalCharges = 0,
    ): AdjustmentResult {
        $adjustedItems = [];
        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }
            $adjustedItems[] = [
                'order_option_id' => $option->id,
                'cancel_quantity' => $option->quantity,
                'cancel_amount' => (float) $option->subtotal_paid_amount,
            ];
        }

        // 모든 쿠폰 복원
        $restoredCouponIds = [];
        $promoSnapshot = $order->promotions_applied_snapshot ?? [];
        if (! empty($promoSnapshot['coupon_issue_ids'])) {
            $restoredCouponIds = $promoSnapshot['coupon_issue_ids'];
        }

        // 환불 우선순위 기반 배분 (전량 제외 시)
        //
        // 추가 비용(반품 회수배송비의 환불금 차감 등)은 여기서도 빼야 한다. 취소는 추가 비용이
        // 항상 0 이라 이 경로가 그 축을 몰라도 성립했지만, 전량 반품은 가장 흔한 반품이면서
        // 동시에 이 경로를 타므로 빼지 않으면 차감하기로 한 회수배송비가 조용히 사라진다.
        // 추가 비용이 0 이면 아래 식은 종전 값(`refundAmount = 원 결제금액`)과 완전히 같다.
        // **이미 환불된 몫을 뺀다**(#599 후속 — 새 결함 N1).
        //
        // 이 경로는 "남은 상품이 없다" 는 이유로 재계산을 건너뛰고 원 결제금액 전액을 환불 대상으로
        // 삼는다. 그런데 같은 주문에서 앞서 부분 환불이 있었으면 그 몫이 이미 나갔다 — 빼지 않으면
        // 환불 대상이 **원 결제금액 전액**으로 부풀고, D19 상한(결제수단 잔여 한도)이 그것을 거절해
        // **마지막 남은 상품의 정당한 환불이 통째로 막힌다**. 일반 경로(`calculate()`)는 같은 축을
        // `remainingPg` 로 이미 빼고 있었고, 전량 제외 경로만 그 축을 몰랐다.
        $remainingPg = max(0.0, $originalPaidAmount - (float) $order->total_refunded_amount);
        $remainingPoints = max(0.0, $originalPointsUsed - (float) $order->total_refunded_points_amount);

        $totalRefundable = max(0, ($remainingPg + $remainingPoints) - $additionalCharges);

        if ($refundPriority === RefundPriorityEnum::POINTS_FIRST) {
            $refundPointsAmount = min($totalRefundable, $remainingPoints);
            $refundAmount = $totalRefundable - $refundPointsAmount;
        } else {
            $refundAmount = min($totalRefundable, $remainingPg);
            $refundPointsAmount = $totalRefundable - $refundAmount;
        }

        $shippingDifference = (float) $order->total_shipping_amount;

        // 잔여 잔액
        $remainingPgBalance = max(0, $remainingPg - $refundAmount);
        $remainingPointsBalance = max(0, $remainingPoints - $refundPointsAmount);

        // 복원 쿠폰 상세 정보
        $restoredCoupons = $this->buildRestoredCouponsInfo($restoredCouponIds, $order->currency_snapshot);

        // 배송비 정책별 상세 (전체취소: 모든 배송비 환불)
        $shippingDetails = [];
        foreach ($order->shippings as $shipping) {
            $baseFee = (float) ($shipping->base_shipping_amount ?? 0);
            $extraFee = (float) ($shipping->extra_shipping_amount ?? 0);
            $totalFee = $baseFee + $extraFee;
            if ($totalFee > 0) {
                $shippingDetails[] = [
                    'policy_name' => $shipping->shippingPolicy?->getLocalizedName() ?? __('sirsoft-ecommerce::order.default_shipping'),
                    'base_difference' => $baseFee,
                    'extra_difference' => $extraFee,
                    'total_difference' => $totalFee,
                ];
            }
        }

        // mc_* 다통화 환불금 변환
        $currencySnapshot = $order->currency_snapshot;
        $mcRefundAmount = null;
        $mcRefundPointsAmount = null;
        $mcRefundShippingAmount = null;

        if ($currencySnapshot) {
            $mcRefundAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                ['refund_amount' => $refundAmount],
                $currencySnapshot
            );
            $mcRefundAmount = $this->extractCurrencyAmounts($mcRefundAmount, 'refund_amount');

            $mcRefundPointsAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                ['refund_points_amount' => $refundPointsAmount],
                $currencySnapshot
            );
            $mcRefundPointsAmount = $this->extractCurrencyAmounts($mcRefundPointsAmount, 'refund_points_amount');

            if ($shippingDifference > 0) {
                $mcRefundShippingAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                    ['refund_shipping_amount' => $shippingDifference],
                    $currencySnapshot
                );
                $mcRefundShippingAmount = $this->extractCurrencyAmounts($mcRefundShippingAmount, 'refund_shipping_amount');
            }
        }

        // 다통화 스냅샷
        $mcOriginalSnapshot = $this->buildMcOriginalSnapshot($order);
        $zeroMcSnapshot = null;
        if ($mcOriginalSnapshot) {
            $zeroFormattedSubtotal = [];
            foreach ($mcOriginalSnapshot['mc_subtotal_amount'] ?? [] as $code => $amount) {
                $zeroFormattedSubtotal[$code] = $this->currencyService->formatPrice(0, $code, $currencySnapshot);
            }
            $zeroFormattedTotalPaid = [];
            foreach ($mcOriginalSnapshot['mc_total_paid_amount'] ?? [] as $code => $amount) {
                $zeroFormattedTotalPaid[$code] = $this->currencyService->formatPrice(0, $code, $currencySnapshot);
            }
            $zeroFormattedListPrice = [];
            foreach ($mcOriginalSnapshot['mc_total_list_price_amount'] ?? [] as $code => $amount) {
                $zeroFormattedListPrice[$code] = $this->currencyService->formatPrice(0, $code, $currencySnapshot);
            }

            $zeroMcSnapshot = [
                'mc_subtotal_amount' => array_map(fn () => 0, $mcOriginalSnapshot['mc_subtotal_amount'] ?? []),
                'mc_total_paid_amount' => array_map(fn () => 0, $mcOriginalSnapshot['mc_total_paid_amount'] ?? []),
                'mc_total_list_price_amount' => array_map(fn () => 0, $mcOriginalSnapshot['mc_total_list_price_amount'] ?? []),
                'mc_subtotal_amount_formatted' => $zeroFormattedSubtotal,
                'mc_total_paid_amount_formatted' => $zeroFormattedTotalPaid,
                'mc_total_list_price_amount_formatted' => $zeroFormattedListPrice,
            ];
        }

        // 쿠폰 상세 (전체취소 시 취소 후는 빈 배열)
        $originalCoupons = $this->extractCouponDetails($order->promotions_applied_snapshot ?? [], $order->currency_snapshot);

        // 환불 총액/잔액 base 포맷 + 결제 통화 병기
        $refundFormatted = $this->buildRefundFormatted([
            'refund_total' => max(0, $refundAmount) + $refundPointsAmount,
            'refund_amount' => max(0, $refundAmount),
            'remaining_pg_balance' => $remainingPgBalance,
            'remaining_points_balance' => $remainingPointsBalance,
        ], $currencySnapshot);

        // 스냅샷 각 줄을 base 통화로 포맷(취소 모달 primary 표기 = base 통화 기호 고정)
        $originalSnapshot = $this->enrichSnapshotWithBaseFormat($originalSnapshot, $currencySnapshot);
        $zeroRecalculatedSnapshot = $this->enrichSnapshotWithBaseFormat([
            'total_amount' => 0,
            'subtotal_amount' => 0,
            'total_list_price_amount' => 0,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_paid_amount' => 0,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_tax_amount' => 0,
            'total_tax_free_amount' => 0,
            'total_vat_amount' => 0,
            'total_earned_points_amount' => 0,
            'item_count' => 0,
        ], $currencySnapshot);

        return new AdjustmentResult(
            refundAmount: $refundAmount,
            refundPointsAmount: $refundPointsAmount,
            originalPaidAmount: $originalPaidAmount,
            recalculatedPaidAmount: 0,
            shippingDifference: $shippingDifference,
            discountDifference: (float) $order->total_discount_amount,
            recalculated: null,
            adjustedItems: $adjustedItems,
            orderUpdates: [
                'subtotal_amount' => 0,
                'total_discount_amount' => 0,
                'total_coupon_discount_amount' => 0,
                'total_shipping_amount' => 0,
                'total_amount' => 0,
                'total_paid_amount' => 0,
                'total_points_used_amount' => 0,
                // 전액취소면 남은 현금성 금액도 0 이다. 갱신하지 않으면 CashReceiptService 가
                // 여전히 발급 대상이 있다고 보고 전체취소 대신 재발급을 시도한다.
                'total_cash_equivalent_amount' => 0,
                // 과세·부가세도 함께 내린다 (S1-D22). 이 둘을 빼놓으면 `total_amount` 는 0 인데
                // `total_tax_amount`·`total_vat_amount` 만 취소 전 값으로 남아, 화면에
                // `최종 결제금액 0원` 옆에 `과세 금액 10,500원 · 부가세 954원` 이 나란히 선다.
                // 아래 `buildZeroMcOrderUpdates()` 는 `mc_total_tax_amount` 를 0 으로 내리므로
                // **한 행 안에서 base 와 mc 가 어긋나기까지 한다.** 세금계산서·정산이 이 컬럼을
                // 읽으면 취소된 주문에 과세 근거가 남아 있는 것으로 읽는다.
                'total_tax_amount' => 0,
                'total_vat_amount' => 0,
                'total_tax_free_amount' => 0,
                // base 를 0 으로 내리면서 짝이 되는 mc_* 를 두면 주문상세 보조통화 줄이
                // **직전 회차 금액**을 계속 보여준다 (S1-D3 — 부분취소 경로와 같은 결함).
                // 실측: `총 결제금액 0원` 옆에 `$22.00 / ¥2,530`(마지막 회차 환불액) 이 찍혔다.
                ...$this->buildZeroMcOrderUpdates($order),
            ],
            optionUpdates: [],
            shippingUpdates: [],
            originalSnapshot: $originalSnapshot,
            recalculatedSnapshot: $zeroRecalculatedSnapshot,
            restoredCouponIssueIds: $restoredCouponIds,
            refundPriority: $refundPriority,
            remainingPgBalance: $remainingPgBalance,
            remainingPointsBalance: $remainingPointsBalance,
            restoredCoupons: $restoredCoupons,
            shippingDetails: $shippingDetails,
            mcRefundAmount: $mcRefundAmount,
            mcRefundPointsAmount: $mcRefundPointsAmount,
            mcRefundShippingAmount: $mcRefundShippingAmount,
            mcOriginalSnapshot: $mcOriginalSnapshot,
            mcRecalculatedSnapshot: $zeroMcSnapshot,
            originalCoupons: $originalCoupons,
            recalculatedCoupons: [],
            refundFormatted: $refundFormatted,
        );
    }

    /**
     * 전액취소 시 `mc_*` 금액 컬럼을 0 으로 내리는 갱신값을 만듭니다. (S1-D3)
     *
     * base 8종을 0 으로 내리는 전액취소 경로가 짝이 되는 `mc_*` 를 두면, 주문상세 보조통화
     * 줄이 직전 회차 금액을 계속 보여준다. `mc_total_paid_amount` 는 제외한다 —
     * 총 결제액은 환불로 깎지 않는 것이 회계 계약이다(§12-B B1).
     *
     * @param  Order  $order  대상 주문
     * @return array `mc_*` 컬럼 0 갱신값 (스냅샷 없으면 빈 배열)
     */
    private function buildZeroMcOrderUpdates(Order $order): array
    {
        $currencySnapshot = $order->currency_snapshot;

        if (empty($currencySnapshot)) {
            return [];
        }

        $zero = $this->currencyService->convertToMultiCurrencyWithSnapshot(0, $currencySnapshot);

        return [
            'mc_subtotal_amount' => $zero,
            'mc_total_discount_amount' => $zero,
            'mc_total_product_coupon_discount_amount' => $zero,
            'mc_total_order_coupon_discount_amount' => $zero,
            'mc_total_coupon_discount_amount' => $zero,
            'mc_total_code_discount_amount' => $zero,
            'mc_base_shipping_amount' => $zero,
            'mc_extra_shipping_amount' => $zero,
            'mc_shipping_discount_amount' => $zero,
            'mc_total_shipping_amount' => $zero,
            'mc_total_points_used_amount' => $zero,
            'mc_total_tax_amount' => $zero,
            'mc_total_tax_free_amount' => $zero,
            'mc_total_amount' => $zero,
        ];
    }

    /**
     * 재계산 결과 스냅샷을 캡처합니다.
     *
     * @param  OrderCalculationResult  $result  재계산 결과
     * @return array 스냅샷
     */
    private function captureRecalcSnapshot($result): array
    {
        $taxableAmount = (float) $result->summary->taxableAmount;

        // 남은 아이템 수량 합계
        $itemCount = 0;
        foreach ($result->items ?? [] as $item) {
            $itemCount += (int) ($item->quantity ?? 0);
        }

        return [
            'total_amount' => (float) $result->summary->paymentAmount,
            'subtotal_amount' => (float) $result->summary->subtotal,
            'total_discount_amount' => (float) $result->summary->totalDiscount,
            'total_coupon_discount_amount' => (float) ($result->summary->productCouponDiscount + $result->summary->orderCouponDiscount),
            'total_product_coupon_discount_amount' => (float) $result->summary->productCouponDiscount,
            'total_order_coupon_discount_amount' => (float) $result->summary->orderCouponDiscount,
            'total_code_discount_amount' => (float) $result->summary->codeDiscount,
            'total_shipping_amount' => (float) $result->summary->totalShipping,
            'base_shipping_amount' => (float) $result->summary->baseShippingTotal,
            'extra_shipping_amount' => (float) $result->summary->extraShippingTotal,
            'shipping_discount_amount' => (float) $result->summary->shippingDiscount,
            'total_paid_amount' => (float) $result->summary->finalAmount,
            'total_points_used_amount' => (float) $result->summary->pointsUsed,
            'total_deposit_used_amount' => 0,
            'total_tax_amount' => $taxableAmount,
            'total_tax_free_amount' => (float) $result->summary->taxFreeAmount,
            // 재계산 결과의 옵션별 세율 반영 부가세를 그대로 사용 (주문 생성 시점과 동일 산식)
            'total_vat_amount' => (float) ($result->summary->vatAmount ?? VatCalculator::fromTaxableAmount((int) $taxableAmount)),
            'total_earned_points_amount' => (float) ($result->summary->pointsEarning ?? 0),
            'item_count' => $itemCount,
        ];
    }

    /**
     * 옵션별 업데이트 정보를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $excludedMap  제외 맵
     * @param  OrderCalculationResult  $recalcResult  재계산 결과
     * @return array [order_option_id => update_data]
     */
    private function buildOptionUpdates(Order $order, array $excludedMap, $recalcResult): array
    {
        $updates = [];

        // 부분취소 재계산 시 옵션 레벨 다통화(mc_) 컬럼도 재산출 (정책 확정)
        $currencySnapshot = $order->currency_snapshot;

        // 재계산 결과에서 옵션별 금액 반영
        foreach ($recalcResult->items as $item) {
            foreach ($order->options as $option) {
                if ($option->product_option_id == $item->productOptionId) {
                    // 이미 취소·교환으로 닫힌 행은 재계산 주체가 아니다 — `buildRecalcInput()` 이
                    // 그 행들을 입력에서 빼므로 재계산 결과에는 **살아 있는 행 몫만** 들어 있다.
                    // 수량 분할(`source_type=split`)이 일어나면 닫힌 행과 살아 있는 행이 **같은
                    // `product_option_id`** 를 갖는데, 여기서 걸러 내지 않으면 살아 있는 행의
                    // 줄 합계가 닫힌 행에도 덮어써진다. 그러면 닫힌 행의 base(`subtotal_price`)와
                    // mc(`mc_subtotal_price`)가 어긋나, 취소된 줄이 **할인가가 원가보다 큰**
                    // 모습으로 렌더된다(S1-D4 — 실측: 양말 3개 줄 `12,000원` → `16,000원`).
                    if (in_array($option->option_status, [
                        OrderStatusEnum::CANCELLED,
                        OrderStatusEnum::EXCHANGED,
                    ], true)) {
                        continue;
                    }

                    $cancelQty = $excludedMap[$option->id] ?? 0;
                    $remainingQty = $option->quantity - $cancelQty;

                    // 전량 취소된 옵션은 제외 (잔여 수량 있는 부분취소는 포함)
                    if ($remainingQty <= 0) {
                        continue;
                    }

                    $updates[$option->id] = [
                        'subtotal_paid_amount' => (float) $item->finalAmount,
                        'coupon_discount_amount' => (float) $item->productCouponDiscountAmount,
                        'code_discount_amount' => (float) $item->codeDiscountAmount,
                        'subtotal_points_used_amount' => (float) $item->pointsUsedShare,
                        // 부분취소 후 잔여 옵션의 예상 적립액 재안분 저장 (취소 상품 몫 제외 — §6.3)
                        'subtotal_earned_points_amount' => (float) $item->pointsEarning,
                        'subtotal_tax_amount' => (float) $item->taxableAmount,
                        'subtotal_tax_free_amount' => (float) $item->taxFreeAmount,
                        'promotions_applied_snapshot' => $item->appliedPromotions?->toArray(),
                    ];

                    // 다통화 컬럼 재산출 (사용/적립 비대칭 해소 — 기존 mc_subtotal_points_used 도 함께 갱신)
                    if ($currencySnapshot) {
                        $updates[$option->id] += $this->buildOptionMultiCurrencyUpdates($item, $currencySnapshot);
                    }
                }
            }
        }

        return $updates;
    }

    /**
     * 부분취소 재계산된 옵션 금액의 다통화(mc_) 컬럼을 산출합니다.
     *
     * 신규 주문 경로(OrderProcessingService::buildOptionMultiCurrency)와 동일한 키 집합을
     * 사용하여, 부분취소 후 옵션 레벨 mc_ 컬럼이 재안분값과 정합되도록 한다.
     *
     * @param  ItemCalculation  $item  재계산된 옵션 항목
     * @param  array  $currencySnapshot  주문 통화 스냅샷
     * @return array<string, array<string, int|float>> mc_ 컬럼 갱신값
     */
    private function buildOptionMultiCurrencyUpdates($item, array $currencySnapshot): array
    {
        $convert = fn (int|float $amount): array => $this->currencyService->convertToMultiCurrencyWithSnapshot(
            (int) $amount,
            $currencySnapshot
        );

        return [
            'mc_subtotal_price' => $convert($item->subtotal ?? 0),
            'mc_product_coupon_discount_amount' => $convert($item->productCouponDiscountAmount ?? 0),
            'mc_order_coupon_discount_amount' => $convert($item->orderCouponDiscountShare ?? 0),
            'mc_coupon_discount_amount' => $convert($item->productCouponDiscountAmount ?? 0),
            'mc_code_discount_amount' => $convert($item->codeDiscountAmount ?? 0),
            'mc_subtotal_points_used_amount' => $convert($item->pointsUsedShare ?? 0),
            'mc_subtotal_earned_points_amount' => $convert($item->pointsEarning ?? 0),
            'mc_subtotal_tax_amount' => $convert($item->taxableAmount ?? 0),
            'mc_subtotal_tax_free_amount' => $convert($item->taxFreeAmount ?? 0),
            'mc_final_amount' => $convert($item->finalAmount ?? 0),
        ];
    }

    /**
     * 배송비 업데이트 정보를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderCalculationResult  $recalcResult  재계산 결과
     * @return array [order_shipping_id => update_data]
     */
    private function buildShippingUpdates(Order $order, $recalcResult): array
    {
        // 배송비 재계산 결과를 기존 배송 레코드에 매핑
        $updates = [];

        foreach ($order->shippings as $shipping) {
            $updates[$shipping->id] = [
                'base_shipping_amount' => (float) ($recalcResult->summary->baseShippingTotal ?? 0),
                'extra_shipping_amount' => (float) ($recalcResult->summary->extraShippingTotal ?? 0),
                'total_shipping_amount' => (float) ($recalcResult->summary->totalShipping ?? 0),
                'shipping_discount_amount' => (float) ($recalcResult->summary->shippingDiscount ?? 0),
            ];
        }

        return $updates;
    }

    /**
     * 주문 테이블 업데이트 정보를 생성합니다.
     *
     * @param  Order  $order  주문 (결제수단 판정용)
     * @param  OrderCalculationResult  $recalcResult  재계산 결과
     * @return array 업데이트 데이터
     */
    private function buildOrderUpdates(Order $order, $recalcResult): array
    {
        // 재계산된 프로모션 스냅샷 (부분취소 후 남은 아이템 기준 할인 배분)
        $promotionsSnapshot = $recalcResult->promotions?->toArray() ?? [];

        // 플러그인이 스냅샷에 자체 할인 데이터를 추가할 수 있는 훅
        $promotionsSnapshot = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.filter_promotions_snapshot',
            $promotionsSnapshot,
            $recalcResult
        );

        return [
            'subtotal_amount' => (float) $recalcResult->summary->subtotal,
            'total_discount_amount' => (float) $recalcResult->summary->totalDiscount,
            'total_coupon_discount_amount' => (float) ($recalcResult->summary->productCouponDiscount + $recalcResult->summary->orderCouponDiscount),
            'total_product_coupon_discount_amount' => (float) $recalcResult->summary->productCouponDiscount,
            'total_order_coupon_discount_amount' => (float) $recalcResult->summary->orderCouponDiscount,
            'total_code_discount_amount' => (float) $recalcResult->summary->codeDiscount,
            'base_shipping_amount' => (float) $recalcResult->summary->baseShippingTotal,
            'extra_shipping_amount' => (float) $recalcResult->summary->extraShippingTotal,
            'shipping_discount_amount' => (float) $recalcResult->summary->shippingDiscount,
            'total_shipping_amount' => (float) $recalcResult->summary->totalShipping,
            'total_amount' => (float) $recalcResult->summary->paymentAmount,
            'total_paid_amount' => (float) $recalcResult->summary->finalAmount,
            'total_points_used_amount' => (float) $recalcResult->summary->pointsUsed,
            'total_tax_amount' => (float) $recalcResult->summary->taxableAmount,
            'total_tax_free_amount' => (float) $recalcResult->summary->taxFreeAmount,
            // 과세표준을 갱신하면서 부가세를 그대로 두면 둘이 어긋나, 주문 화면의 공급가액
            // (과세표준 - 부가세)이 음수까지 갈 수 있다. 같은 재계산 결과에서 함께 쓴다.
            'total_vat_amount' => (float) (
                $recalcResult->summary->vatAmount
                ?? VatCalculator::fromTaxableAmount((int) $recalcResult->summary->taxableAmount)
            ),
            // 현금성 금액도 잔여 실결제액 기준으로 재계산한다. 이 값을 갱신하지 않으면
            // CashReceiptService::syncFromOrder() 가 "금액 변동 없음"으로 판정해 부분환불 후
            // 현금영수증 재발급이 일어나지 않는다.
            'total_cash_equivalent_amount' => $this->resolveCashEquivalentAmount(
                $order,
                (int) round((float) $recalcResult->summary->finalAmount),
            ),
            'promotions_applied_snapshot' => $promotionsSnapshot,
        ] + $this->buildMcOrderUpdates($order, $recalcResult);
    }

    /**
     * 부분취소 재계산 결과의 `mc_*` 금액 컬럼 갱신값을 만듭니다. (S1-D3)
     *
     * base 금액 컬럼만 갱신하고 짝이 되는 `mc_*` 컬럼을 두면, 부분취소 뒤 둘이 어긋난 채 남아
     * 비교표·주문상세의 보조통화 줄이 **취소 전 금액**을 보여준다(주문 생성은 두 벌을 함께
     * 쓴다 — `OrderProcessingService::buildOrderMultiCurrency()`).
     * 환산 규칙은 주문 시점 환율 스냅샷을 그대로 쓴다 — 취소 시점 환율로 바꾸면 같은 주문의
     * 보조통화 표기가 회차마다 달라진다.
     *
     * `currency_snapshot` 이 없는 레거시 주문은 빈 배열을 돌려준다(종전 동작 유지).
     *
     * @param  Order  $order  대상 주문
     * @param  OrderCalculationResult  $recalcResult  재계산 결과
     * @return array `mc_*` 컬럼 갱신값 (스냅샷 없으면 빈 배열)
     */
    private function buildMcOrderUpdates(Order $order, $recalcResult): array
    {
        $currencySnapshot = $order->currency_snapshot;

        if (empty($currencySnapshot)) {
            return [];
        }

        $summary = $recalcResult->summary;
        $convert = fn (float $amount): array => $this->currencyService
            ->convertToMultiCurrencyWithSnapshot((int) round($amount), $currencySnapshot);

        $productCoupon = (float) ($summary->productCouponDiscount ?? 0);
        $orderCoupon = (float) ($summary->orderCouponDiscount ?? 0);

        return [
            'mc_subtotal_amount' => $convert((float) $summary->subtotal),
            'mc_total_discount_amount' => $convert((float) $summary->totalDiscount),
            'mc_total_product_coupon_discount_amount' => $convert($productCoupon),
            'mc_total_order_coupon_discount_amount' => $convert($orderCoupon),
            'mc_total_coupon_discount_amount' => $convert($productCoupon + $orderCoupon),
            'mc_total_code_discount_amount' => $convert((float) ($summary->codeDiscount ?? 0)),
            'mc_base_shipping_amount' => $convert((float) $summary->baseShippingTotal),
            'mc_extra_shipping_amount' => $convert((float) $summary->extraShippingTotal),
            'mc_shipping_discount_amount' => $convert((float) $summary->shippingDiscount),
            'mc_total_shipping_amount' => $convert((float) $summary->totalShipping),
            'mc_total_points_used_amount' => $convert((float) $summary->pointsUsed),
            'mc_total_tax_amount' => $convert((float) $summary->taxableAmount),
            'mc_total_tax_free_amount' => $convert((float) $summary->taxFreeAmount),
            'mc_total_amount' => $convert((float) $summary->paymentAmount),
        ];
    }

    /**
     * 잔여 실결제액에 대응하는 현금성 금액을 산정합니다.
     *
     * 산정 규칙은 PaymentMethodEnum::resolveCashEquivalentAmount() 가 SSoT 다
     * (주문 생성 시점의 OrderProcessingService 와 동일 규칙).
     *
     * @param  Order  $order  주문
     * @param  int  $finalAmount  잔여 실결제액 (마일리지·예치금 차감 후)
     * @return int 현금성 금액
     */
    private function resolveCashEquivalentAmount(Order $order, int $finalAmount): int
    {
        $order->loadMissing('payment');

        // 카탈로그 선언이 있으면 그것이 먼저다(P16) — 주문 생성 시점과 같은 규칙을 써야
        // 부분환불 후 재발급액이 잔여 실결제액과 일치한다.
        $methodId = (string) ($order->payment->payment_method ?? '');

        if ($methodId !== '') {
            $declared = app(PaymentMethodResolver::class)->declaresCashEquivalent($methodId);

            if ($declared !== null) {
                return $declared ? max(0, $finalAmount) : 0;
            }
        }

        // payment_method 는 순수 string (#475 — 확장 결제수단 ID 허용). 코어 enum 에 없는
        // 확장 결제수단은 현금성 0 으로 판정한다 (OrderProcessingService 와 동일 idiom).
        return PaymentMethodEnum::tryFrom($order->payment?->paymentMethodId() ?? '')
            ?->resolveCashEquivalentAmount($finalAmount)
            ?? 0;
    }

    /**
     * 취소 대상 아이템 정보를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $excludedMap  제외 맵
     * @return array [{order_option_id, cancel_quantity, cancel_amount}]
     */
    private function buildAdjustedItems(Order $order, array $excludedMap): array
    {
        $items = [];
        $exchangedSourceIds = $order->options
            ->filter(fn ($option) => $option->option_status === OrderStatusEnum::EXCHANGED)
            ->pluck('id')
            ->all();
        $successorOverrides = $this->successorAccountingOverrides(
            $order,
            $order->options
                ->filter(fn ($option) => $option->isExchanged() && in_array((int) $option->source_option_id, $exchangedSourceIds, true))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );
        foreach ($excludedMap as $optionId => $cancelQty) {
            $option = $order->options->find($optionId);
            if ($option) {
                // 후속 행은 재계산과 같은 회계 단가로 취소 금액을 적는다(S3-D3).
                $unitPrice = $successorOverrides[(int) $optionId]['unit_price'] ?? (float) $option->unit_price;
                $items[] = [
                    'order_option_id' => $optionId,
                    'cancel_quantity' => $cancelQty,
                    'cancel_amount' => $unitPrice * $cancelQty,
                ];
            }
        }

        return $items;
    }

    /**
     * 복원 대상 쿠폰을 감지합니다.
     *
     * 부분취소로 최소주문금액 미달 시 해당 쿠폰을 복원 대상으로 반환합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderCalculationResult  $recalcResult  재계산 결과
     * @return array 복원 대상 쿠폰 발급 ID 배열
     */
    private function detectRestoredCoupons(Order $order, $recalcResult): array
    {
        $originalCoupons = $order->promotions_applied_snapshot['coupon_issue_ids'] ?? [];
        $recalcCoupons = $recalcResult->getAppliedCouponIds();

        return array_values(array_diff($originalCoupons, $recalcCoupons));
    }

    /**
     * 배송정책 스냅샷을 product_option_id 키 맵으로 변환합니다.
     *
     * OrderProcessingService::buildShippingPolicyAppliedSnapshot()은 인덱스 배열로 저장하지만,
     * OrderCalculationService::groupByShippingPolicy()는 $snapshots[$optionId]로 접근하므로 변환이 필요합니다.
     *
     * @param  array  $shippingSnapshot  주문의 shipping_policy_applied_snapshot
     * @return array product_option_id => AppliedShippingPolicy 직렬화 데이터
     */
    private function buildShippingPolicySnapshotMap(array $shippingSnapshot): array
    {
        // 형태 판별은 ShippingPolicySnapshot 단일 출처에 위임한다 — 종전에는 이 메서드와
        // OrderCancellationService::buildShippingSnapshot() 이 `is_int($key)` 판별을
        // 각자 복제하고 있어, 구조가 바뀔 때 한쪽만 고쳐질 위험이 있었다.
        return ShippingPolicySnapshot::toOptionPolicyMap($shippingSnapshot);
    }

    /**
     * 주문의 프로모션 스냅샷에서 쿠폰 규칙 스냅샷을 구성합니다.
     *
     * @param  Order  $order  주문
     * @return array 쿠폰 발급 ID => 규칙 배열
     */
    private function buildCouponSnapshotsFromOrder(Order $order): array
    {
        $snapshots = [];
        $promoSnapshot = $order->promotions_applied_snapshot ?? [];

        // product_promotions.coupons + order_promotions.coupons 모두 탐색
        $couponSources = [
            $promoSnapshot['product_promotions']['coupons'] ?? [],
            $promoSnapshot['order_promotions']['coupons'] ?? [],
        ];

        foreach ($couponSources as $coupons) {
            foreach ($coupons as $coupon) {
                $couponIssueId = $coupon['coupon_issue_id'] ?? null;
                if ($couponIssueId === null) {
                    continue;
                }
                $snapshots[$couponIssueId] = [
                    'discount_type' => $coupon['discount_type'] ?? null,
                    'discount_value' => $coupon['discount_value'] ?? 0,
                    'min_order_amount' => $coupon['min_order_amount'] ?? 0,
                    'max_discount_amount' => $coupon['max_discount_amount'] ?? 0,
                    'target_type' => $coupon['target_type'] ?? null,
                    'target_scope' => $coupon['target_scope'] ?? null,
                    'applied_items' => $coupon['applied_items'] ?? null,
                ];
            }
        }

        return $snapshots;
    }

    /**
     * 복원 쿠폰 상세 정보를 구성합니다.
     *
     * @param  array  $restoredCouponIssueIds  복원 대상 쿠폰 발급 ID 배열
     * @param  array|null  $currencySnapshot  주문 시점 통화 스냅샷
     * @return array [{coupon_name, discount_amount, discount_amount_formatted}]
     */
    private function buildRestoredCouponsInfo(array $restoredCouponIssueIds, ?array $currencySnapshot = null): array
    {
        if (empty($restoredCouponIssueIds)) {
            return [];
        }

        $couponIssues = $this->couponIssueRepository->findByIdsWithRelations($restoredCouponIssueIds, ['coupon']);
        $baseCurrency = $currencySnapshot['base_currency'] ?? $this->currencyService->getDefaultCurrency();

        $restoredCoupons = [];
        foreach ($couponIssues as $issue) {
            $discountAmount = (float) ($issue->discount_amount ?? 0);

            $restoredCoupons[] = [
                'coupon_name' => $issue->coupon?->getLocalizedName() ?? '',
                'discount_amount' => $discountAmount,
                'discount_amount_formatted' => $this->currencyService->formatPrice($discountAmount, $baseCurrency, $currencySnapshot),
            ];
        }

        return $restoredCoupons;
    }

    /**
     * 배송비 정책별 상세 차이를 구성합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderCalculationResult  $recalcResult  재계산 결과
     * @return array [{policy_name, base_difference, extra_difference, total_difference}]
     */
    private function buildShippingDetails(Order $order, OrderCalculationResult $recalcResult): array
    {
        // 원 주문의 배송비를 정책별로 집계
        $originalByPolicy = [];
        foreach ($order->shippings as $shipping) {
            $policyId = $shipping->shipping_policy_id;
            if (! isset($originalByPolicy[$policyId])) {
                $originalByPolicy[$policyId] = [
                    'base' => 0,
                    'extra' => 0,
                    'name' => $shipping->shippingPolicy?->getLocalizedName() ?? __('sirsoft-ecommerce::order.default_shipping'),
                ];
            }
            $originalByPolicy[$policyId]['base'] += (float) $shipping->base_shipping_amount;
            $originalByPolicy[$policyId]['extra'] += (float) $shipping->extra_shipping_amount;
        }

        // 재계산 결과의 배송비를 정책별로 집계
        $recalcByPolicy = $recalcResult->getShippingByPolicy();

        // 차이 계산
        $details = [];
        foreach ($originalByPolicy as $policyId => $original) {
            $recalc = $recalcByPolicy[$policyId] ?? ['base' => 0, 'extra' => 0];
            $baseDiff = $original['base'] - ($recalc['base'] ?? 0);
            $extraDiff = $original['extra'] - ($recalc['extra'] ?? 0);
            if ($baseDiff != 0 || $extraDiff != 0) {
                $details[] = [
                    'policy_name' => $original['name'],
                    'base_difference' => $baseDiff,
                    'extra_difference' => $extraDiff,
                    'total_difference' => $baseDiff + $extraDiff,
                ];
            }
        }

        return $details;
    }

    /**
     * 프로모션 스냅샷에서 쿠폰 상세 배열을 추출합니다.
     *
     * 스냅샷 금액 줄(enrichSnapshotWithBaseFormat)과 같은 규칙으로, raw 금액에 더해
     * 주문 시점 기준 통화로 포맷한 동반 키를 함께 싣는다. 화면이 통화 기호를 직접
     * 이어 붙이면 기준 통화가 원화가 아닌 상점에서 단위만 원으로 표기된다.
     *
     * @param  array  $promotionsSnapshot  promotions_applied_snapshot 또는 PromotionsSummary::toArray()
     * @param  array|null  $currencySnapshot  주문 시점 통화 스냅샷
     * @return array [{name, target_type, discount_amount, discount_amount_formatted}]
     */
    private function extractCouponDetails(array $promotionsSnapshot, ?array $currencySnapshot = null): array
    {
        $baseCurrency = $currencySnapshot['base_currency'] ?? $this->currencyService->getDefaultCurrency();
        $details = [];

        $sections = ['product_promotions', 'order_promotions'];
        foreach ($sections as $section) {
            $coupons = $promotionsSnapshot[$section]['coupons'] ?? [];
            foreach ($coupons as $coupon) {
                $discountAmount = (float) ($coupon['total_discount'] ?? 0);

                $details[] = [
                    'name' => $coupon['name'] ?? '',
                    'target_type' => $coupon['target_type'] ?? '',
                    'discount_amount' => $discountAmount,
                    'discount_amount_formatted' => $this->currencyService->formatPrice($discountAmount, $baseCurrency, $currencySnapshot),
                ];
            }
        }

        return $details;
    }

    /**
     * 취소 비교 스냅샷의 각 금액 줄을 주문 시점 기준 통화(base_currency)로 포맷해 동반 키를 추가합니다.
     *
     * 취소 모달의 "주문금액 비교"는 모든 줄을 base 통화 기호로 표기해야 하므로(운영자가 이후 기본 통화를
     * 바꿔도 과거 주문 표기는 불변), raw base 숫자에 더해 base 통화 포맷 문자열을 `formatted` 하위 맵으로
     * 함께 제공한다. 결제 통화 환산 병기는 mc_*_formatted(실결제/정가/소계)로 별도 제공된다.
     *
     * @param  array  $snapshot  금액 스냅샷(captureOrderSnapshot/captureRecalcSnapshot 형식)
     * @param  array|null  $currencySnapshot  주문 시점 통화 스냅샷
     * @return array base_currency + formatted 동반 키가 추가된 스냅샷
     */
    private function enrichSnapshotWithBaseFormat(array $snapshot, ?array $currencySnapshot): array
    {
        $baseCurrency = $currencySnapshot['base_currency'] ?? $this->currencyService->getDefaultCurrency();

        $formatted = [];
        foreach ($snapshot as $key => $value) {
            if (is_numeric($value)) {
                $formatted[$key] = $this->currencyService->formatPrice((float) $value, $baseCurrency, $currencySnapshot);
            }
        }

        $snapshot['base_currency'] = $baseCurrency;
        $snapshot['formatted'] = $formatted;

        return $snapshot;
    }

    /**
     * 환불 총액·잔액을 base 통화 포맷 + 결제 통화 포함 다통화 포맷으로 구성합니다.
     *
     * 취소 모달의 "환불 예정액/잔여 잔액"은 base 통화 기호로 primary 표기하고, base≠결제 통화일 때
     * 결제 통화 환산액을 병기해야 한다. base 포맷은 formatPrice, 다통화는 스냅샷 환율 변환을 사용한다.
     *
     * @param  array<string, float>  $amounts  필드명 → base 통화 금액
     * @param  array|null  $currencySnapshot  주문 시점 통화 스냅샷
     * @return array{base_currency: string, base: array<string,string>, mc: array<string,array<string,string>>}
     */
    private function buildRefundFormatted(array $amounts, ?array $currencySnapshot): array
    {
        $baseCurrency = $currencySnapshot['base_currency'] ?? $this->currencyService->getDefaultCurrency();

        $base = [];
        foreach ($amounts as $field => $value) {
            $base[$field] = $this->currencyService->formatPrice((float) $value, $baseCurrency, $currencySnapshot);
        }

        $mc = [];
        if ($currencySnapshot) {
            $converted = $this->currencyService->convertMultipleAmountsWithSnapshot($amounts, $currencySnapshot);
            foreach ($converted as $code => $fields) {
                if ($code === $baseCurrency || ! is_array($fields)) {
                    continue;
                }
                foreach ($amounts as $field => $value) {
                    if (isset($fields[$field.'_formatted'])) {
                        $mc[$field][$code] = $fields[$field.'_formatted'];
                    }
                }
            }
        }

        return [
            'base_currency' => $baseCurrency,
            'base' => $base,
            'mc' => $mc,
        ];
    }

    /**
     * 원 주문의 다통화 스냅샷을 생성합니다. (`CalculationSnapshotPresenter::mcOriginal()` 위임)
     *
     * @param  Order  $order  주문
     * @return array|null {mc_subtotal_amount, mc_total_paid_amount, mc_total_list_price_amount, *_formatted}
     */
    private function buildMcOriginalSnapshot(Order $order): ?array
    {
        return $this->snapshotPresenter->mcOriginal($order);
    }

    /**
     * 재계산 결과의 다통화 스냅샷을 생성합니다. (`CalculationSnapshotPresenter::mcRecalculated()` 위임)
     *
     * @param  array  $recalcSnapshot  재계산 스냅샷
     * @param  array|null  $currencySnapshot  주문 시점 환율 스냅샷
     * @return array|null {mc_subtotal_amount, mc_total_paid_amount, mc_total_list_price_amount, *_formatted}
     */
    private function buildMcRecalcSnapshot(array $recalcSnapshot, ?array $currencySnapshot): ?array
    {
        return $this->snapshotPresenter->mcRecalculated($recalcSnapshot, $currencySnapshot);
    }

    /**
     * 다통화 변환 결과에서 통화별 금액을 추출합니다.
     *
     * @param  array|null  $conversionResult  변환 결과
     * @param  string  $amountKey  금액 키
     * @return array|null {통화코드 => 금액}
     */
    private function extractCurrencyAmounts(?array $conversionResult, string $amountKey): ?array
    {
        if ($conversionResult === null) {
            return null;
        }

        $result = [];
        foreach ($conversionResult as $currencyCode => $amounts) {
            if (is_array($amounts) && isset($amounts[$amountKey])) {
                $result[$currencyCode] = $amounts[$amountKey];
            } elseif (is_numeric($amounts)) {
                $result[$currencyCode] = $amounts;
            }
        }

        return ! empty($result) ? $result : null;
    }
}
