<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ItemCalculation;
use Modules\Sirsoft\Ecommerce\DTO\OrderAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\ReactivationRecalcResult;
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
use Modules\Sirsoft\Ecommerce\Support\EarnedPointsFormatter;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;
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
     * @param  OrderExchangeOptionRepositoryInterface  $exchangeOptionRepository  교환 신청상품 Repository (후속 행 회계 단가 판정)
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository (교환 차액 환불 여부 판정)
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
     * 그 주문의 후속 행 회계 단가 오버라이드를 한 번에 구합니다. (#599 S3-D3)
     *
     * 재계산(`buildRecalcInput()`)과 취소 장부(`buildAdjustedItems()`)는 **같은 단가**를 봐야
     * 한다 — 두 값이 갈라지면 재계산이 본 금액과 장부에 적힌 금액이 어긋나 S3-D3 과 같은 종류의
     * 금액 오류가 조용히 되살아난다. 종전에는 두 메서드가 후속 행 id 산출식까지 각자 복제해
     * 갖고 있어 한쪽만 고치면 그대로 갈라지는 구조였고, 주문당 리포지토리 조회도 2회였다.
     * 그래서 `calculate()` 가 한 번 구해 두 곳에 인자로 넘긴다.
     *
     * @param  Order  $order  주문 (options 로드됨)
     * @return array<int, array{option_snapshot: array, unit_price: float}> 후속 행 id → 오버라이드
     */
    private function resolveSuccessorOverrides(Order $order): array
    {
        $exchangedSourceIds = $order->options
            ->filter(fn ($option) => $option->option_status === OrderStatusEnum::EXCHANGED)
            ->pluck('id')
            ->all();

        $successorIds = $order->options
            ->filter(fn ($option) => $option->isExchanged() && in_array((int) $option->source_option_id, $exchangedSourceIds, true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->successorAccountingOverrides($order, $successorIds);
    }

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

            // 표시 축(정가·판매가)은 신규 옵션 값으로 — 정체(옵션명·SKU 등)는 후속 행 스냅샷 그대로.
            foreach (['list_price', 'selling_price'] as $key) {
                if (array_key_exists($key, $newSnapshot)) {
                    $snapshot[$key] = $newSnapshot[$key];
                }
            }

            if (! array_key_exists('selling_price', $newSnapshot)) {
                $snapshot['selling_price'] = (float) $exchangeItem->new_unit_price;
            }

            // 엔진 단가는 `product_snapshot.selling_price + option_snapshot.price_adjustment` 다
            // (`SnapshotProductOption::getSellingPrice()`). 신규 옵션의 조정액을 그대로 복사하면
            // 교환 신청 시점에 상품가가 바뀐 주문에서 엔진 단가가 `new_unit_price`(현재 상품가
            // 기준)와 어긋나, 후속 행을 취소할 때 그 차이만큼 덜(또는 더) 환불된다. 치환 패스
            // (`applySubstitutions()`)와 같은 식으로 조정액을 역산해 엔진 단가 == 신규 단가로
            // 맞춘다(#599 validate P2 #3).
            $snapshot['price_adjustment'] = (float) $exchangeItem->new_unit_price
                - (float) (($successor->product_snapshot ?? [])['selling_price'] ?? 0);

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

        // 후속 행 회계 단가는 여기서 **한 번만** 구해 재계산·취소 장부에 같은 값을 넘긴다(S3-D3).
        $successorOverrides = $this->resolveSuccessorOverrides($order);

        // 3. 잔여 옵션으로 CalculationInput 구성
        $recalcInput = $this->buildRecalcInput($order, $excludedMap, $successorOverrides);

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
                $successorOverrides,
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
        // "이번에 빠지는 상품의 값" 이 되어 회차마다 정확해진다. 종결 행이 없으면 기준선은
        // 원 결제금액과 같으므로 추가 계산도 하지 않는다(대부분의 신청이 이 경우다).
        //
        // 판정 근거는 **종결 행의 존재**다 — 누적 환불액(`total_refunded_amount`)이 아니다.
        // 환불액이 0 으로 확정된 부분 환불(운영자가 0 원으로 수정, 무상 증정 행 등)도 행은
        // 취소로 닫히는데, 누적 환불액만 보면 그 뒤의 신청이 원 결제금액을 기준선으로 삼아 닫힌
        // 행의 값까지 한 번 더 돌려준다(#599 validate P2 #2). 반대로 기준선을 다시 구해도 종결
        // 행이 없으면 값은 원 결제금액과 같으므로 판정을 넓혀 잃는 것은 계산 한 번뿐이다.
        $baselineAmount = $originalPaidAmount + $originalPointsUsed;
        // 배송비 정책별 상세(12)의 원 주문 측도 이 기준선 재계산을 쓴다 — 종결 행이 있는 주문의 배송 행은
        // 1.2.1 이 주문 전체 배송비로 부풀려 두었을 수 있다(S-Q26-b).
        $priorResult = null;

        if ($this->hasClosedRows($order)) {
            $priorInput = $this->buildRecalcInput($order, [], $successorOverrides);

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
        $refundPointsAmount = $this->pointsRefundableOnly($order, $refundPointsAmount);

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
        $rowAllocation = $this->allocateRecalcToRows($order, $recalcInput, $recalcResult);
        $optionUpdates = $this->buildOptionUpdates($order, $rowAllocation);
        $shippingShares = $this->buildShippingShares($rowAllocation);
        $orderUpdates = $this->buildOrderUpdates($order, $recalcResult);

        // 9. 취소 대상 아이템 정보
        $adjustedItems = $this->buildAdjustedItems($order, $excludedMap, $successorOverrides);

        // 10. 쿠폰 복원 대상 확인
        $restoredCouponIssueIds = $this->detectRestoredCoupons($order, $recalcResult);

        // 11. 복원 쿠폰 상세 정보 구성
        $restoredCoupons = $this->buildRestoredCouponsInfo($restoredCouponIssueIds, $order->currency_snapshot);

        // 12. 배송비 정책별 상세
        $shippingDetails = $this->buildShippingDetails($order, $recalcResult, $priorResult);

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
        $remainingPointsBalance = $this->remainingPointsBalance($order, $remainingPoints, $refundPointsAmount);

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
            shippingShares: $shippingShares,
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
     * **기왕 환불액 상한을 끈 채** 계산합니다. (회차 차분 산출 전용 — DB 변경 없음)
     *
     * `calculate()` 는 결과를 `원 결제금액 − total_refunded_amount` 로 잘라낸다. 그 상한은 실행
     * 단계의 안전장치로는 옳지만, 「누적 − 앞 회차 몫」 **차분**을 낼 때는 빼는 값(앞 회차 몫)에
     * 이미 들어 있는 기왕 환불액이 나누는 값에서 한 번 더 깎여 이중 차감이 된다. 반품 완료
     * 경로(`OrderReturnService::priorReturnsAdjustment()`·`uncappedAdjustment()`)와 환불 실행 가드
     * (`CancellableAmountCalculator::calculateForRefund()`)가 **같은 식**을 쓰도록 여기 둔다.
     *
     * 모델 속성만 잠시 0 으로 두며 저장하지 않는다. 기준선 재계산 판정은 종결 행의 존재로
     * 하므로(`hasClosedRows()`) 상한을 꺼도 기준선은 그대로다.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderAdjustment  $adjustment  변경 정보
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return AdjustmentResult 상한 없는 계산 결과
     */
    public function calculateWithoutPriorRefundCap(
        Order $order,
        OrderAdjustment $adjustment,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): AdjustmentResult {
        $refunded = $order->total_refunded_amount;
        $refundedPoints = $order->total_refunded_points_amount;

        $order->total_refunded_amount = 0;
        $order->total_refunded_points_amount = 0;

        try {
            return $this->calculate($order, $adjustment, $refundPriority);
        } finally {
            $order->total_refunded_amount = $refunded;
            $order->total_refunded_points_amount = $refundedPoints;
        }
    }

    /**
     * 재활성 복원용 재계산 — 활성 행 전체를 취소 엔진과 같은 입력으로 다시 계산합니다. (Q26 — DB 변경 없음)
     *
     * 이후 부분취소의 기준선(`calculate()` 의 priorInput)이 바로 `buildRecalcInput($order, [])` 이다.
     * 같은 식으로 헤더를 써야 재활성 뒤 첫 환불의 차액이 정확하다. 바꾸는 입력은 프로모션 스냅샷과
     * 사용 포인트 둘뿐이며, 원본 모델이 아닌 복제본에만 덮어쓴다 — 부분취소 재계산이 스냅샷을 「적용된
     * 쿠폰만」으로 바꿔 버리므로 조건을 잃고 빠진 쿠폰의 규칙은 최초 취소 직전 스냅샷에만 남아 있다.
     *
     * 결제금액(`total_paid_amount`)은 헤더 갱신값에서 뺀다(결제 기록 단계 소관). 사용 포인트·현금성
     * 금액도 뺀다 — 장부 정합 단계가 따로 쓴다.
     *
     * @param  Order  $order  options·shippings·payment 가 적재된 주문 (미리보기는 시뮬레이션 options 를 건 복제본)
     * @param  array|null  $promotionsSnapshot  쓸 프로모션 스냅샷 (null 이면 현재 값)
     * @param  float  $usePoints  사용 포인트 입력
     * @return ReactivationRecalcResult|null 활성 행이 없으면 null
     */
    public function recalculateForReactivation(Order $order, ?array $promotionsSnapshot, float $usePoints): ?ReactivationRecalcResult
    {
        $work = clone $order;
        $work->setRelation('options', $order->options);
        $work->setRelation('shippings', $order->shippings);
        $work->setRelation('payment', $order->payment);
        if ($promotionsSnapshot !== null) {
            $work->promotions_applied_snapshot = $promotionsSnapshot;
        }
        $work->total_points_used_amount = $usePoints;

        $input = $this->buildRecalcInput($work, [], $this->resolveSuccessorOverrides($work));
        if (empty($input->items)) {
            return null;
        }

        $result = $this->calculationService->calculate($input);
        $rowAllocation = $this->allocateRecalcToRows($work, $input, $result);

        $orderUpdates = $this->buildOrderUpdates($work, $result);
        $pointsUsedAfter = (float) ($orderUpdates['total_points_used_amount'] ?? 0);
        $cashEquivalentAfter = (float) ($orderUpdates['total_cash_equivalent_amount'] ?? 0);
        unset(
            $orderUpdates['total_paid_amount'],
            $orderUpdates['total_points_used_amount'],
            $orderUpdates['mc_total_points_used_amount'],
            $orderUpdates['total_cash_equivalent_amount'],
        );

        return new ReactivationRecalcResult(
            orderUpdates: $orderUpdates,
            optionUpdates: $this->buildOptionUpdates($work, $rowAllocation),
            shippingShares: $this->buildShippingShares($rowAllocation),
            appliedCouponIssueIds: array_values(array_map('intval', $result->getAppliedCouponIds())),
            pointsUsedAfter: $pointsUsedAfter,
            cashEquivalentAfter: $cashEquivalentAfter,
            totalsAfter: $this->captureRecalcSnapshot($result),
        );
    }

    /**
     * 재계산 입력에서 빠지는 종결 행(취소·교환완료)이 주문에 있는지 판정합니다.
     *
     * `buildRecalcInput()` 이 빼는 행과 같은 집합이다 — 이 행이 있으면 「이미 빠진 상품만 뺀
     * 기준선」이 원 결제금액과 달라질 수 있으므로 기준선을 다시 구해야 한다.
     *
     * @param  Order  $order  주문 (options 로드됨)
     * @return bool 종결 행이 있으면 true
     */
    private function hasClosedRows(Order $order): bool
    {
        return $order->options->contains(fn ($option) => in_array(
            $option->option_status,
            [OrderStatusEnum::CANCELLED, OrderStatusEnum::EXCHANGED],
            true,
        ));
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
            // 지금 남아 있는 결제액 — 이미 돌려준 현금은 뺀다. 비교의 다른 줄(판매가·배송비·과세)은 지금 주문을 그리므로,
            // 처음 결제 총액을 두면 일부 환불 뒤 「실결제 60,000 → 0」 옆에 「환불 예정 32,500」이 찍히고 취소 차단
            // 판정도 이미 돌려준 만큼 추가결제 필요를 놓친다(S3-D14). 첫 취소는 환불 누계가 0 이라 종전 값과 같다.
            'total_paid_amount' => max(0.0, (float) $order->total_paid_amount - (float) $order->total_refunded_amount),
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
            'item_count' => $this->countAccountableItems($order),
        ];
    }

    /**
     * 비교 카드의 상품수량 — 정가 합계(`CalculationSnapshotPresenter::listPriceTotal()`)와 같은 회계 주체만 셉니다.
     *
     * 취소 행만 빼면 교환이 끝난 주문에서 교환완료로 닫힌 원 행과 그것을 승계한 후속 행이 둘 다 세어져, 같은 카드의
     * 정가 합계는 3개 몫인데 상품수량은 4개로 나온다(S3-D16). 원 행이 아직 교환 진행 중인 후속 행은 원 행이 주체다.
     *
     * @param  Order  $order  주문 (options 로드됨)
     * @return int 상품수량
     */
    private function countAccountableItems(Order $order): int
    {
        $exchangedSourceIds = $order->options
            ->filter(fn ($option) => $option->option_status === OrderStatusEnum::EXCHANGED)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return (int) $order->options
            ->reject(fn ($option) => in_array($option->option_status, [OrderStatusEnum::CANCELLED, OrderStatusEnum::EXCHANGED], true)
                || ($option->isExchanged() && ! in_array((int) $option->source_option_id, $exchangedSourceIds, true)))
            ->sum('quantity');
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

            // 치환할 라인은 **그 주문 행이 만든 라인**이다. 수량 분할(`source_type=split`)·교환
            // 후속 행은 같은 `product_option_id` 를 갖는 라인을 여럿 만들므로, 키로 첫 라인을
            // 잡으면 다른 주문 행의 수량이 줄어든다(#599 validate P2 minor). 라인 → 주문 행
            // 대응은 `buildRecalcInput()` 이 메타데이터로 남긴다. 대응이 없으면(외부에서 만든
            // 입력) 종전대로 키의 첫 라인으로 떨어진다.
            $lineOrderOptionIds = $metadata['line_order_option_ids'] ?? [];
            $index = array_search((int) $orderOptionId, $lineOrderOptionIds, true);

            if ($index === false || ! isset($items[$index])) {
                $index = null;

                foreach ($items as $i => $item) {
                    if ((int) $item->productOptionId === $originalKey) {
                        $index = $i;
                        break;
                    }
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

                    // 두 라인을 각자 반올림하면 합이 상한을 넘을 수 있다(예: 상한 1,001 을 1:1 로 나누면
                    // 501 + 501). 한 라인만 반올림하고 나머지는 상한에서 뺀 값으로 둔다 — 합은 언제나
                    // 원 상한과 같다(#599 validate P2 minor).
                    $remainingCap = (int) round($cap * $remaining / $originalQuantity);
                    $couponSnapshots[$couponIssueId]['max_discount_amount_by_line'][$originalKey] = $remainingCap;
                    $couponSnapshots[$couponIssueId]['max_discount_amount_by_line'][$newKey] = $cap - $remainingCap;
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
     * @param  array  $successorOverrides  후속 행 회계 단가 오버라이드 (`resolveSuccessorOverrides()` 가 한 번 구해 넘긴다 — 취소 장부와 같은 값이어야 한다)
     * @return CalculationInput 재계산 입력
     */
    private function buildRecalcInput(Order $order, array $excludedMap, array $successorOverrides = []): CalculationInput
    {
        $items = [];
        // 라인 인덱스 → 주문 행 ID. 같은 `product_option_id` 를 가진 라인이 여럿일 때(분할 행)
        // 치환 패스가 올바른 라인을 고르는 근거다(`applySubstitutions()`).
        $lineOrderOptionIds = [];

        $exchangedSourceIds = $order->options
            ->filter(fn ($option) => $option->option_status === OrderStatusEnum::EXCHANGED)
            ->pluck('id')
            ->all();

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
            $lineOrderOptionIds[] = (int) $optionId;
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
                'line_order_option_ids' => $lineOrderOptionIds,
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
     * @param  array  $successorOverrides  후속 행 회계 단가 오버라이드 (`resolveSuccessorOverrides()` — 부분취소 장부와 같은 단가)
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
        array $successorOverrides = [],
    ): AdjustmentResult {
        // 취소 장부의 취소액은 부분취소(`buildAdjustedItems()`)와 같은 **할인 전 단가 × 수량**이다.
        // 환불 상세 행(`RefundExecutionService::buildRefundOptionRows()`)이 이 값을 정가로 보고 원 행의
        // 할인 비율을 뺀다 — 여기서 할인 반영 후 금액(`subtotal_paid_amount`)을 적으면 할인이 두 번
        // 빠져, 쿠폰 주문 전체취소의 행 환불액 합계가 신청서 총액보다 할인만큼 모자랐다(S3-D3).
        $adjustedItems = [];
        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }
            $unitPrice = $successorOverrides[(int) $option->id]['unit_price'] ?? (float) $option->unit_price;
            $adjustedItems[] = [
                'order_option_id' => $option->id,
                'cancel_quantity' => $option->quantity,
                'cancel_amount' => $unitPrice * $option->quantity,
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

        $refundableBase = $remainingPg + $remainingPoints;

        // 환불 0원으로 닫힌 행이 있으면 그 행 값은 환불 누계에 잡히지 않는다 — 「결제액 − 환불 누계」만 보면
        // 마지막 상품을 뺄 때 닫힌 행 값까지 다시 나간다(S3-D18). 부분 경로(`calculate()`)와 같이 종결 행을 뺀
        // 기준선(지금 남은 상품의 결제 몫)을 상한으로 둔다. 종결 행이 없으면 두 값은 같아 종전 결과와 같다.
        if ($this->hasClosedRows($order)) {
            $activeInput = $this->buildRecalcInput($order, [], $successorOverrides);

            if (! empty($activeInput->items)) {
                $activeResult = $this->calculationService->calculate($activeInput);
                $refundableBase = min(
                    $refundableBase,
                    (float) $activeResult->summary->finalAmount + (float) $activeResult->summary->pointsUsed,
                );
            }
        }

        $totalRefundable = max(0, $refundableBase - $additionalCharges);

        if ($refundPriority === RefundPriorityEnum::POINTS_FIRST) {
            $refundPointsAmount = min($totalRefundable, $remainingPoints);
            $refundAmount = $totalRefundable - $refundPointsAmount;
        } else {
            $refundAmount = min($totalRefundable, $remainingPg);
            $refundPointsAmount = $totalRefundable - $refundAmount;
        }
        $refundPointsAmount = $this->pointsRefundableOnly($order, $refundPointsAmount);

        $shippingDifference = (float) $order->total_shipping_amount;

        // 잔여 잔액
        $remainingPgBalance = max(0, $remainingPg - $refundAmount);
        $remainingPointsBalance = $this->remainingPointsBalance($order, $remainingPoints, $refundPointsAmount);

        // 복원 쿠폰 상세 정보
        $restoredCoupons = $this->buildRestoredCouponsInfo($restoredCouponIds, $order->currency_snapshot);

        // 배송비 정책별 상세 (전체취소: 원 주문 측 배송비 전부가 차액) — 정책별 한 줄
        //
        // 원 주문 측은 부분 경로와 같은 규칙으로 구한다: 종결 행이 있으면 살아 있는 행만으로 다시 계산한
        // 값(1.2.1 이 배송 행마다 주문 전체 배송비를 써 둔 레거시 행을 읽지 않는다 — S-Q26-b), 없으면 배송
        // 행 정책별 합(생성 시점 몫 그대로라 정확).
        $priorResult = null;
        if ($this->hasClosedRows($order)) {
            $priorInput = $this->buildRecalcInput($order, [], $this->resolveSuccessorOverrides($order));
            if (! empty($priorInput->items)) {
                $priorResult = $this->calculationService->calculate($priorInput);
            }
        }
        $shippingDetails = [];
        foreach ($this->originalShippingByPolicy($order, $priorResult) as $original) {
            $totalFee = $original['base'] + $original['extra'];
            if ($totalFee > 0) {
                $shippingDetails[] = [
                    'policy_name' => $original['name'],
                    'base_difference' => $original['base'],
                    'extra_difference' => $original['extra'],
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
            shippingShares: [],
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
            // 원본 스냅샷(captureOrderSnapshot = 헤더값)과 같은 의미로 — 사용 마일리지를 뺀 합계.
            'total_amount' => (float) $result->summary->finalAmount,
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
     * 재계산 결과를 주문 행 단위로 나눈 몫(allocateRecalcToRows)을 쓴다. 키(product_option_id)로 맞춰 쓰면
     * 같은 상품옵션의 활성 행 둘이 각각 키 합계를 받는다(S-Q26-c). 전량 제외 행은 재계산 입력에 없으므로
     * 결과에도 없다.
     *
     * @param  Order  $order  주문
     * @param  array<int, array{item: ItemCalculation, amounts: array<string, int>}>  $rowAllocation  행 몫 (allocateRecalcToRows)
     * @return array [order_option_id => update_data]
     */
    private function buildOptionUpdates(Order $order, array $rowAllocation): array
    {
        $updates = [];

        // 부분취소 재계산 시 옵션 레벨 다통화(mc_) 컬럼도 재산출 (정책 확정)
        $currencySnapshot = $order->currency_snapshot;

        foreach ($rowAllocation as $rowId => $allocation) {
            $option = $order->options->firstWhere('id', $rowId);

            // 이미 취소·교환으로 닫힌 행은 재계산 주체가 아니다 — `buildRecalcInput()` 이 그 행들을 입력에서
            // 빼므로 여기 오지 않지만, 닫힌 행에 살아 있는 행 금액이 덮어써지면 취소된 줄이 할인가가 원가보다
            // 큰 모습으로 렌더된다(S1-D4). 외부 입력 방어로 남긴다.
            if (! $option || in_array($option->option_status, [
                OrderStatusEnum::CANCELLED,
                OrderStatusEnum::EXCHANGED,
            ], true)) {
                continue;
            }

            $amounts = $allocation['amounts'];
            $updates[$rowId] = [
                'subtotal_paid_amount' => (float) $amounts['finalAmount'],
                'coupon_discount_amount' => (float) $amounts['productCouponDiscountAmount'],
                'code_discount_amount' => (float) $amounts['codeDiscountAmount'],
                'subtotal_points_used_amount' => (float) $amounts['pointsUsedShare'],
                // 부분취소 후 잔여 옵션의 예상 적립액 재안분 저장 (취소 상품 몫 제외 — §6.3)
                'subtotal_earned_points_amount' => (float) $amounts['pointsEarning'],
                'subtotal_tax_amount' => (float) $amounts['taxableAmount'],
                'subtotal_tax_free_amount' => (float) $amounts['taxFreeAmount'],
                // 어느 쿠폰이 이 상품옵션에 걸렸는지의 기록 — 키 단위 그대로 둔다
                'promotions_applied_snapshot' => $allocation['item']->appliedPromotions?->toArray(),
            ];

            // 다통화 컬럼 재산출 (사용/적립 비대칭 해소 — 기존 mc_subtotal_points_used 도 함께 갱신)
            if ($currencySnapshot) {
                $updates[$rowId] += $this->buildOptionMultiCurrencyUpdates(
                    $this->itemWithAmounts($allocation['item'], $amounts),
                    $currencySnapshot
                );
            }
        }

        return $updates;
    }

    /**
     * 재계산 결과를 재계산 입력의 주문 행 단위로 나눕니다. (S-Q26-b·c)
     *
     * 계산 엔진은 결과를 product_option_id 키로 합친다(calculateItemSubtotals 누적 — S3-D4). 그래서 같은 상품옵션의
     * 행이 둘이면(상태 분할·반품/교환 분할) 두 결과 아이템이 키 합계를 똑같이 들고 나오고, 키로 맞춰 쓰면 두 행이
     * 모두 합계를 받는다(1521: 30,000원 행 둘이 각 60,000원·적립 600P). 라인 → 주문 행 대응은 buildRecalcInput 이
     * line_order_option_ids 로 남긴다. 키 합계를 라인 소계 비중으로 나누고 마지막 라인이 나머지를 받아 합이 정확하다.
     * 키에 라인이 하나면 결과 그대로다.
     *
     * 결과 아이템과 입력 라인이 어긋나면(통상 모드에서 상품옵션이 사라진 레거시 라인을 엔진이 건너뛴 경우) 경고를
     * 남기고 입력 수량을 비중으로 쓴다. 결과에 없는 키의 행은 갱신하지 않는다.
     *
     * @param  Order  $order  주문 (적립 절사 기준 스냅샷)
     * @param  CalculationInput  $input  재계산 입력
     * @param  OrderCalculationResult  $result  재계산 결과
     * @return array<int, array{item: ItemCalculation, amounts: array<string, int>}> order_option_id => 행 몫
     */
    private function allocateRecalcToRows(Order $order, CalculationInput $input, OrderCalculationResult $result): array
    {
        $lineIds = array_map('intval', $input->metadata['line_order_option_ids'] ?? []);
        $items = array_values($result->items);
        $lines = array_values($input->items);

        $aligned = count($items) === count($lineIds) && count($lines) === count($lineIds);
        if ($aligned) {
            foreach ($items as $i => $item) {
                if ((int) $item->productOptionId !== (int) $lines[$i]->productOptionId) {
                    $aligned = false;
                    break;
                }
            }
        }
        if (! $aligned) {
            Log::warning('재계산 결과와 입력 라인이 어긋나 행 배분을 입력 수량 비중으로 대체합니다', [
                'order_id' => $order->id,
                'lines' => count($lineIds),
                'items' => count($items),
            ]);
        }

        // 키별 첫 결과 아이템 — 키 합계를 들고 있다
        $keyItem = [];
        foreach ($items as $item) {
            $keyItem[(int) $item->productOptionId] ??= $item;
        }

        // 키별 라인 묶음 [행 id, 비중, 결과 인덱스]
        $groups = [];
        foreach ($lines as $i => $line) {
            $poid = (int) $line->productOptionId;
            if (! isset($keyItem[$poid], $lineIds[$i])) {
                continue;
            }
            $weight = $aligned
                ? ((int) $items[$i]->unitPrice + (int) $items[$i]->additionalOptionsTotal) * (int) $items[$i]->quantity
                : (int) $line->quantity;
            $groups[$poid][] = ['row' => $lineIds[$i], 'w' => max(0, $weight), 'i' => $aligned ? $i : null];
        }

        $rounding = MileageRounding::fromOrderSnapshot($order->mileage_policy_snapshot);
        $out = [];
        foreach ($groups as $poid => $group) {
            $key = $keyItem[$poid];
            $shipping = $key->appliedShippingPolicy;
            $totals = [
                'subtotal' => $key->subtotal,
                'productCouponDiscountAmount' => $key->productCouponDiscountAmount,
                'codeDiscountAmount' => $key->codeDiscountAmount,
                'orderCouponDiscountShare' => $key->orderCouponDiscountShare,
                'pointsUsedShare' => $key->pointsUsedShare,
                'pointsEarning' => $key->pointsEarning,
                'taxableAmount' => $key->taxableAmount,
                'taxFreeAmount' => $key->taxFreeAmount,
                'finalAmount' => $key->finalAmount,
                'shipping_base' => (int) ($shipping?->shippingAmount ?? 0),
                'shipping_extra' => (int) ($shipping?->extraShippingAmount ?? 0),
            ];
            $weights = array_column($group, 'w');
            $split = [];
            foreach ($totals as $field => $total) {
                $split[$field] = $this->splitByWeight((int) $total, $weights, $field === 'pointsEarning' ? $rounding : null);
            }
            foreach ($group as $n => $line) {
                $out[$line['row']] = [
                    'item' => $line['i'] !== null ? $items[$line['i']] : $key,
                    'amounts' => array_map(fn (array $shares) => $shares[$n], $split),
                ];
            }
        }

        return $out;
    }

    /**
     * 정수 합계를 비중으로 나눕니다 — 마지막 몫이 나머지를 받아 합이 정확합니다.
     *
     * 비중 합이 0 이면 균등하게 나눈다. 적립액은 분할(applySplitAmounts)과 같은 절사 기준을 쓴다.
     *
     * @param  int  $total  나눌 합계
     * @param  array<int, int>  $weights  라인별 비중
     * @param  array|null  $rounding  적립 절사 기준 (적립액일 때만)
     * @return array<int, int> 라인별 몫
     */
    private function splitByWeight(int $total, array $weights, ?array $rounding = null): array
    {
        $count = count($weights);
        if ($count <= 1) {
            return [$total];
        }

        $sum = array_sum($weights);
        $allocated = 0;
        $shares = [];
        foreach (array_values($weights) as $idx => $weight) {
            if ($idx === $count - 1) {
                $shares[] = $total - $allocated;
                break;
            }
            $raw = $sum > 0 ? $total * $weight / $sum : $total / $count;
            $share = $rounding !== null ? (int) MileageRounding::apply($raw, $rounding) : (int) floor($raw);
            $shares[] = $share;
            $allocated += $share;
        }

        return $shares;
    }

    /**
     * 배분된 금액으로 바꾼 결과 아이템 사본을 만듭니다. (mc_* 산출용)
     *
     * @param  ItemCalculation  $item  결과 아이템
     * @param  array<string, int>  $amounts  행 몫
     * @return ItemCalculation 금액을 바꾼 사본
     */
    private function itemWithAmounts(ItemCalculation $item, array $amounts): ItemCalculation
    {
        $copy = clone $item;
        foreach (['subtotal', 'productCouponDiscountAmount', 'codeDiscountAmount', 'orderCouponDiscountShare',
            'pointsUsedShare', 'pointsEarning', 'taxableAmount', 'taxFreeAmount', 'finalAmount'] as $field) {
            $copy->{$field} = $amounts[$field];
        }

        return $copy;
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
     * 재계산 입력 행별 배송비 몫을 만듭니다. (S-Q26-b)
     *
     * 배송 행 금액은 「그 주문 행이 지금 부담하는 배송비 몫」이다(주문 생성 createOrderShippings 와 같은 뜻).
     * 종전에는 주문 전체 배송비(summary)를 모든 배송 행에 같은 값으로 써서 748 의 배송 행 네 개가 모두
     * 5,500 이 되었다. 몫은 주문 행 단위 배분기(allocateRecalcToRows)가 상품옵션 몫을 행으로 나눈 값이다.
     * 쓰기는 OrderShippingAmountWriter 가 분할이 끝난 뒤 배송 행을 다시 읽어서 한다.
     *
     * @param  array<int, array{item: ItemCalculation, amounts: array<string, int>}>  $rowAllocation  행 몫 (allocateRecalcToRows)
     * @return array<int, array{base: int, extra: int}> order_option_id => 몫
     */
    private function buildShippingShares(array $rowAllocation): array
    {
        $shares = [];
        foreach ($rowAllocation as $rowId => $allocation) {
            $shares[$rowId] = [
                'base' => (int) $allocation['amounts']['shipping_base'],
                'extra' => (int) $allocation['amounts']['shipping_extra'],
            ];
        }

        return $shares;
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

        // 옵션별 「단독 배송비」 표시 칸 — 회원·비회원 상세 「적용 배송정책」이 남은 수량 기준 값을 보이게 한다.
        // 재계산 입력 필드는 주문 시점 값 그대로다(`ShippingPolicySnapshot::withStandaloneAmounts()`).
        $standaloneByOptionId = [];
        foreach ($recalcResult->items ?? [] as $item) {
            if ($item->appliedShippingPolicy !== null) {
                $standaloneByOptionId[$item->productOptionId] = $item->appliedShippingPolicy->standaloneShippingAmount;
            }
        }
        $shippingSnapshot = ShippingPolicySnapshot::withStandaloneAmounts($order->shipping_policy_applied_snapshot, $standaloneByOptionId);

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
            // 주문 합계는 사용 마일리지를 뺀 값(finalAmount)이다 — 주문 생성(OrderProcessingService)과 같은 의미.
            // 차감 전 값(paymentAmount)을 쓰면 마일리지를 쓴 주문이 부분취소·재활성 뒤 목록에 미결제(= 사용 마일리지)로 보인다.
            'total_amount' => (float) $recalcResult->summary->finalAmount,
            'total_paid_amount' => (float) $recalcResult->summary->finalAmount,
            'total_points_used_amount' => (float) $recalcResult->summary->pointsUsed,
            // 행별 적립 예정액(buildOptionUpdates)과 같은 재계산 결과로 헤더도 맞춘다 — 빠지면 관리자 주문 상세
            // 합계 줄이 취소한 상품 몫까지 적립될 것처럼 취소 전 값을 보인다.
            'total_earned_points_amount' => (float) ($recalcResult->summary->pointsEarning ?? 0),
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
        ] + ($shippingSnapshot === [] ? [] : ['shipping_policy_applied_snapshot' => $shippingSnapshot])
            + $this->buildMcOrderUpdates($order, $recalcResult);
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
            'mc_total_amount' => $convert((float) $summary->finalAmount),
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
     * @param  array  $successorOverrides  후속 행 회계 단가 오버라이드 (`resolveSuccessorOverrides()` 가 한 번 구해 넘긴다 — 재계산과 같은 값이어야 한다)
     * @return array [{order_option_id, cancel_quantity, cancel_amount}]
     */
    private function buildAdjustedItems(Order $order, array $excludedMap, array $successorOverrides = []): array
    {
        $items = [];
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
     * @param  OrderCalculationResult|null  $priorResult  종결 행을 뺀 기준선 재계산 (종결 행이 없으면 null)
     * @return array [{policy_name, base_difference, extra_difference, total_difference}]
     */
    private function buildShippingDetails(Order $order, OrderCalculationResult $recalcResult, ?OrderCalculationResult $priorResult = null): array
    {
        $originalByPolicy = $this->originalShippingByPolicy($order, $priorResult);

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
     * 환불 후 잔여 마일리지(이 주문에 쓴 마일리지 중 아직 돌려주지 않은 몫)를 계산합니다. (Q15)
     *
     * 사용 마일리지를 차감하지 않은 주문은 그 마일리지를 돌려줄 일이 없으므로(`pointsRefundableOnly()`) 잔여도 0 이다 —
     * 남겨 두면 신청 화면·취소 창이 「사용 마일리지 반환 0원」 아래 돌려받을 것처럼 「잔여 마일리지 3,000」을 보인다.
     *
     * @param  Order  $order  주문
     * @param  int|float  $remainingPoints  아직 돌려주지 않은 사용 마일리지
     * @param  int|float  $refundPointsAmount  이번에 돌려줄 마일리지 몫
     * @return int|float 환불 후 잔여 마일리지
     */
    private function remainingPointsBalance(Order $order, int|float $remainingPoints, int|float $refundPointsAmount): int|float
    {
        if (! $order->is_mileage_deducted) {
            return 0;
        }

        return max(0, $remainingPoints - $refundPointsAmount);
    }

    /**
     * 실제로 차감된 적 없는 사용 마일리지는 환불 몫에서 뺍니다. (Q15 — 미리보기·접수 원천)
     *
     * 수동 결제완료에서 「마일리지 차감」을 끈 주문처럼 사용 마일리지를 차감하지 않은 주문은, 그 몫이 고객이 낸 적
     * 없는 돈이다. 실행(`OrderRefundRequestService` 의 Q15 보정)은 이미 0 으로 바꾸지만 미리보기·접수 저장·회원 신청
     * 화면·관리자 확인 창은 이 계산을 읽어 「사용 마일리지 반환액 3,000」을 보였다 — 원천에서 같은 수로 맞춘다.
     * 현금으로 옮기지 않는다(실행과 같은 수). 판정은 `is_mileage_deducted` 하나다 — 복원 가드
     * (`RefundExecutionService::restoreMileage`)와 같은 축.
     *
     * 받은 값의 타입(정수·실수)을 그대로 돌려준다 — 미리보기 출력은 바이트 단위 계약(픽스처)이라 0 이 0.0 으로 바뀌면 갈린다.
     *
     * @param  Order  $order  주문
     * @param  int|float  $refundPointsAmount  배분된 마일리지 환불 몫
     * @return int|float 돌려줄 마일리지 몫 (미차감 주문이면 0)
     */
    private function pointsRefundableOnly(Order $order, int|float $refundPointsAmount): int|float
    {
        if ($order->is_mileage_deducted || $refundPointsAmount <= 0) {
            return $refundPointsAmount;
        }

        return is_float($refundPointsAmount) ? 0.0 : 0;
    }

    /**
     * 차액의 「원 주문 측」 배송비를 정책별로 구합니다. (S-Q26-b)
     *
     * 종결 행이 있으면 기준선 재계산(살아 있는 행만)의 정책별 몫을 쓴다 — 1.2.1 의 부분취소는 배송 행마다
     * 주문 전체 배송비를 써 두었으므로 그 행을 더하면 차액이 부푼다(748: 정책별 11,000 기준). 종결 행이
     * 없으면 배송 행은 생성 시점 몫 그대로라 행 합이 정확하다. 정책 이름은 배송 행의 정책 관계에서 읽는다.
     *
     * @param  Order  $order  주문 (shippings 로드됨)
     * @param  OrderCalculationResult|null  $priorResult  기준선 재계산
     * @return array<int|string, array{base: float, extra: float, name: string}> 정책 id => 원 주문 측 배송비
     */
    private function originalShippingByPolicy(Order $order, ?OrderCalculationResult $priorResult): array
    {
        $names = [];
        $fromRows = [];
        foreach ($order->shippings as $shipping) {
            $policyId = $shipping->shipping_policy_id;
            $names[$policyId] ??= $shipping->shippingPolicy?->getLocalizedName() ?? __('sirsoft-ecommerce::order.default_shipping');
            $fromRows[$policyId] ??= ['base' => 0.0, 'extra' => 0.0];
            $fromRows[$policyId]['base'] += (float) $shipping->base_shipping_amount;
            $fromRows[$policyId]['extra'] += (float) $shipping->extra_shipping_amount;
        }

        $source = $priorResult !== null ? $priorResult->getShippingByPolicy() : $fromRows;

        $out = [];
        foreach ($source as $policyId => $amounts) {
            $out[$policyId] = [
                'base' => (float) ($amounts['base'] ?? 0),
                'extra' => (float) ($amounts['extra'] ?? 0),
                'name' => $names[$policyId] ?? __('sirsoft-ecommerce::order.default_shipping'),
            ];
        }

        return $out;
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

        // 적립 마일리지는 화폐가 아니다 — 위 `formatted` 맵은 기준 통화 표기(원)라 적립 줄에 그대로
        // 쓰면 「710원」이 된다. 포인트 표기를 별도 필드로 싣는다(주문 상세 리소스와 같은 식).
        if (array_key_exists('total_earned_points_amount', $snapshot)) {
            $snapshot['total_earned_points_amount_formatted'] = EarnedPointsFormatter::format(
                (float) $snapshot['total_earned_points_amount']
            );
        }

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
        // 기준 통화 줄(`captureOrderSnapshot`)과 같은 값 — 이미 돌려준 현금을 뺀 지금 남은 결제액을 환산한다.
        // 주문의 `mc_total_paid_amount` 는 결제 총액 맵이라 그대로 읽으면 한 칸에 두 시점이 섞인다(S3-D14 후속).
        $refunded = (float) $order->total_refunded_amount;
        $netPaid = $refunded > 0
            ? max(0.0, (float) $order->total_paid_amount - $refunded)
            : null;

        return $this->snapshotPresenter->mcOriginal($order, null, null, $netPaid);
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
