<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;

/**
 * 주문 옵션 서비스
 *
 * 주문 옵션의 수량 분할 상태 변경 등 비즈니스 로직을 처리합니다.
 */
class OrderOptionService
{
    /**
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 Repository
     * @param  OrderShippingRepositoryInterface  $orderShippingRepository  주문 배송 Repository
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository
     * @param  ProductReviewRepositoryInterface  $productReviewRepository  상품 리뷰 Repository
     * @param  MileageTransactionRepositoryInterface  $mileageTransactionRepository  마일리지 거래 Repository (병합 시 적립 거래 이전)
     */
    public function __construct(
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
        protected OrderRepositoryInterface $orderRepository,
        protected ProductReviewRepositoryInterface $productReviewRepository,
        protected StockService $stockService,
        protected EcommerceSettingsService $settingsService,
        protected MileageTransactionRepositoryInterface $mileageTransactionRepository,
    ) {}

    /**
     * 취소 → 판매 상태 복원 전이인지 판정합니다.
     *
     * 옵션의 변경 전 상태가 취소상태(cancelled/partial_cancelled)이고 변경 후 상태가
     * 판매 반영 상태(payment_complete~confirmed)일 때만 재고 재차감 대상이다.
     * OrderService::isReactivationTransition 과 동일 정책(SSoT 집합 재사용)을 옵션 단위로 적용한다.
     *
     * @param  string|null  $oldStatus  변경 전 옵션 상태 값
     * @param  OrderStatusEnum  $newStatus  변경 후 옵션 상태
     * @return bool 재차감 대상 전이 여부
     */
    protected function isReactivationTransition(?string $oldStatus, OrderStatusEnum $newStatus): bool
    {
        if ($oldStatus === null) {
            return false;
        }

        return in_array($oldStatus, OrderStatusEnum::syncExcludedValues(), true)
            && in_array($newStatus->value, OrderStatusEnum::salesEligibleValues(), true);
    }

    /**
     * 판매 상태 → 취소 전이인지 판정합니다.
     *
     * 옵션의 변경 전 상태가 판매 반영 상태(payment_complete~confirmed, 재고 차감됨)이고
     * 변경 후 상태가 취소상태(cancelled/partial_cancelled)일 때 재고 복원 대상이다.
     * isReactivationTransition(재차감) 의 역방향이며, 동일 SSoT 집합을 재사용한다.
     *
     * per-line 상태 변경 드롭다운에서 "주문취소" 로 전이할 때, PG 환불 동반(취소 모달,
     * OrderCancellationService) 경로가 아니어도 재고가 복원되어야 한다(재고-주문 정합성).
     *
     * @param  string|null  $oldStatus  변경 전 옵션 상태 값
     * @param  OrderStatusEnum  $newStatus  변경 후 옵션 상태
     * @return bool 재고 복원 대상 전이 여부
     */
    protected function isCancellationTransition(?string $oldStatus, OrderStatusEnum $newStatus): bool
    {
        if ($oldStatus === null) {
            return false;
        }

        return in_array($oldStatus, OrderStatusEnum::salesEligibleValues(), true)
            && in_array($newStatus->value, OrderStatusEnum::syncExcludedValues(), true);
    }

    /**
     * 주문 옵션 상태를 수량 단위로 변경합니다.
     * 변경 수량이 전체 수량보다 작으면 레코드를 분할합니다.
     *
     * @param  OrderOption  $option  대상 옵션
     * @param  OrderStatusEnum  $newStatus  변경할 상태
     * @param  int  $quantity  변경할 수량
     * @param  array  $metadata  추가 정보 (carrier, tracking_number 등)
     * @param  bool  $restoreStockOnCancel  판매 → 취소 전이 시 이 메서드가 재고를 복원할지 여부.
     *                                      per-line 상태변경(직접 호출)은 true(기본) — 이 경로가 유일한 복원 주체.
     *                                      취소 모달(OrderCancellationService)은 false — 자체 restoreStock() 이
     *                                      복원을 전담하므로 여기서 복원하면 이중 복원이 된다.
     * @return array{original: OrderOption, split: ?OrderOption} 변경 결과
     */
    public function changeStatusWithQuantity(
        OrderOption $option,
        OrderStatusEnum $newStatus,
        int $quantity,
        array $metadata = [],
        bool $restoreStockOnCancel = true
    ): array {
        // 변경 전 훅
        HookManager::doAction('sirsoft-ecommerce.order_option.before_status_change', $option, $newStatus, $quantity);

        $result = ['original' => $option, 'split' => null];

        // 변경 전 옵션 상태 캡처 (취소↔판매 전이 판정용)
        $oldStatusValue = $option->option_status instanceof OrderStatusEnum
            ? $option->option_status->value
            : $option->option_status;

        // 상태 전이 규칙 2차 도메인 불변식 가드 (우회 방어).
        // 정상 흐름은 FormRequest(단건/일괄)가 먼저 422 로 막으므로 여기 도달하지 않는다.
        // 취소(CANCELLED) 진입은 게이트가 통과 처리하므로 취소 모달 경로는 비간섭.
        $oldEnum = $oldStatusValue !== null ? OrderStatusEnum::tryFrom($oldStatusValue) : null;
        if ($oldEnum instanceof OrderStatusEnum && ! $oldEnum->canTransitionTo($newStatus)) {
            throw new OrderProcessingException(
                __('sirsoft-ecommerce::validation.orders.status_transition.invalid', [
                    'from' => $oldEnum->label(),
                    'to' => $newStatus->label(),
                ])
            );
        }

        $isReactivation = $this->isReactivationTransition($oldStatusValue, $newStatus);
        $isCancellation = $this->isCancellationTransition($oldStatusValue, $newStatus);
        // 판매 → 취소 전이 시 재고 복원 여부.
        // - $restoreStockOnCancel=false (취소 모달 경로): OrderCancellationService::restoreStock() 이 전담 → 여기선 스킵(이중 복원 방지)
        // - $restoreStockOnCancel=true (per-line 직접 경로): 이 메서드가 유일 복원 주체
        // 복원 여부는 설정(stock_restore_on_cancel)을 따른다(취소 모달 경로와 동일 정책).
        $shouldRestoreOnCancel = $restoreStockOnCancel && $isCancellation && $this->settingsService->getSetting(
            'order_settings.stock_restore_on_cancel',
            true
        );

        DB::transaction(function () use ($option, $newStatus, $quantity, $metadata, $isReactivation, $shouldRestoreOnCancel, &$result) {
            if ($quantity === $option->quantity) {
                // 전체 수량 변경 → UPDATE만
                $updateData = ['option_status' => $newStatus];
                // 배송완료 진입 시점 기록 (지연 적립 기준)
                if ($newStatus === OrderStatusEnum::DELIVERED && $option->delivered_at === null) {
                    $updateData['delivered_at'] = now();
                }
                // 구매확정 진입 시점 기록 (delivered_at 과 대칭 — 지연 적립·리뷰 기한 기준).
                // 멱등: 이미 값이 있으면 유지(상태 재진입 시 최초 확정 시점 보존).
                if ($newStatus === OrderStatusEnum::CONFIRMED && $option->confirmed_at === null) {
                    $updateData['confirmed_at'] = now();
                }
                $this->orderOptionRepository->update($option, $updateData);

                // 배송 정보(택배사·송장번호) 기록 — 병합 전 상태 변경 대상 옵션에 반영.
                $this->applyShippingTracking($option, $newStatus, $metadata);

                // 취소 → 판매 상태 복원 전이면 이 옵션(전체 수량)의 재고를 재차감한다.
                // (취소 시 복원된 재고를 되돌리는 역연산 — 부족 시 예외 전파 → 트랜잭션 롤백으로 상태 복원 차단)
                if ($isReactivation) {
                    $this->stockService->redeductOrderOptionForReactivation($option);
                }

                // 판매 → 취소 전이면 이 옵션(전체 수량)의 재고를 복원한다.
                // PG 환불 동반(취소 모달) 경로가 아니어도 재고는 복원되어야 한다(재고-주문 정합성).
                // 복원 + is_stock_deducted 플래그 정리를 원자적으로 처리.
                if ($shouldRestoreOnCancel) {
                    $this->stockService->restoreOptionStockForOrderOption($option, $quantity);
                }

                // 병합 가능한 형제 레코드 검색 → 있으면 병합
                $mergeCandidate = $this->orderOptionRepository->findMergeCandidate($option, $newStatus);
                if ($mergeCandidate && $mergeCandidate->id !== $option->id) {
                    $this->mergeOptions($mergeCandidate, $option);
                    $result['merged_into'] = $mergeCandidate;
                }
            } else {
                // 부분 수량 변경 → 레코드 분할
                $remainingQuantity = $option->quantity - $quantity;
                $ratio = $quantity / $option->quantity;

                // replicate 전 원본 금액 캡처 (replicate 후 양쪽이 동일 값을 갖기 때문)
                $origAmounts = $this->captureAmounts($option);

                // 신규 레코드: 분할된 수량 + 새 상태
                $splitOption = $option->replicate();
                $splitOption->quantity = $quantity;
                $splitOption->option_status = $newStatus;
                $splitOption->parent_option_id = $option->id;
                $splitOption->source_type = OrderOptionSourceTypeEnum::SPLIT;
                // 배송완료로 분할 전이 시 분할 레코드에 배송완료 시점 기록
                if ($newStatus === OrderStatusEnum::DELIVERED && $splitOption->delivered_at === null) {
                    $splitOption->delivered_at = now();
                }
                // 구매확정으로 분할 전이 시 분할 레코드에 구매확정 시점 기록 (delivered_at 과 대칭).
                // 원본에서 replicate 로 계승한 delivered_at 은 유지한다(배송완료·구매확정은 둘 다 실제 발생한 사건).
                if ($newStatus === OrderStatusEnum::CONFIRMED && $splitOption->confirmed_at === null) {
                    $splitOption->confirmed_at = now();
                }

                // 분할 레코드 금액 = 원본 × ratio
                $this->applySplitAmounts($splitOption, $origAmounts, $ratio, $quantity);
                $this->orderOptionRepository->save($splitOption);

                // 원본 레코드 = 원본 - 분할 (잔여분)
                $this->applyRemainingAmounts($option, $origAmounts, $splitOption, $remainingQuantity);
                $this->orderOptionRepository->save($option);

                // 배송 정보(택배사·송장번호) 기록 — 새 상태로 전이한 분할 레코드에 반영.
                $this->applyShippingTracking($splitOption, $newStatus, $metadata);

                // 취소 → 판매 상태 복원 전이면 판매 상태로 전이된 분할 레코드(quantity)만 재차감한다.
                // 잔여 원본($option)은 취소 상태를 유지하므로 재차감 대상 아님.
                if ($isReactivation) {
                    $this->stockService->redeductOrderOptionForReactivation($splitOption);
                }

                // 판매 → 취소 전이면 취소된 분할 레코드(quantity)만 재고 복원한다.
                // 잔여 원본($option)은 판매 상태를 유지하므로 복원 대상 아님(차감 유지).
                if ($shouldRestoreOnCancel) {
                    $this->stockService->restoreOptionStockForOrderOption($splitOption, $quantity);
                }

                // 병합 가능한 형제 레코드 검색 → 있으면 병합
                $mergeCandidate = $this->orderOptionRepository->findMergeCandidate($splitOption, $newStatus);
                if ($mergeCandidate && $mergeCandidate->id !== $splitOption->id) {
                    $this->mergeOptions($mergeCandidate, $splitOption);
                    $result['split'] = null;
                    $result['merged_into'] = $mergeCandidate;
                } else {
                    $result['split'] = $splitOption;
                }
            }
        });

        // 변경 후 훅
        HookManager::doAction('sirsoft-ecommerce.order_option.after_status_change', $result['original'], $newStatus, $result['split']);

        // 구매확정 전이는 유저 셀프 확정(OrderService::confirmOption)과 동일하게 order-option.after_confirm 을 발화한다.
        // (즉시 적립·구매확정 활동로그 등 확정 전용 부수효과가 관리자 경로에서도 대칭 동작하도록)
        // 즉시 적립·로그 대상은 CONFIRMED 를 보유한 (병합 후) 생존 레코드 → merged_into > split > original 순으로 선택.
        if ($newStatus === OrderStatusEnum::CONFIRMED) {
            $confirmTarget = $result['merged_into'] ?? $result['split'] ?? $result['original'];
            HookManager::doAction('sirsoft-ecommerce.order-option.after_confirm', $confirmTarget->order, $confirmTarget);
        }

        return $result;
    }

    /**
     * 배송 관련 상태 전이 시 택배사·송장번호를 해당 옵션의 배송 레코드에 기록합니다.
     *
     * 관리자 주문상태 변경(일괄변경)에서 배송준비완료/배송중/배송완료로 전이할 때 입력한
     * 택배사·송장번호(metadata)를 옵션 단위 배송 레코드에 반영한다. 배송 상태가 아니거나
     * metadata 에 배송정보가 없으면 아무 것도 하지 않는다(상태만 변경되는 경로 무영향).
     *
     * @param  OrderOption  $option  상태가 변경된 옵션
     * @param  OrderStatusEnum  $newStatus  전이 목표 상태
     * @param  array  $metadata  ['carrier_id' => int, 'tracking_number' => string] 부분 배열
     */
    private function applyShippingTracking(OrderOption $option, OrderStatusEnum $newStatus, array $metadata): void
    {
        $carrierId = $metadata['carrier_id'] ?? null;
        $trackingNumber = $metadata['tracking_number'] ?? null;

        // 배송정보가 전혀 없으면 기록할 것이 없음.
        if (($carrierId === null || $carrierId === '') && ($trackingNumber === null || $trackingNumber === '')) {
            return;
        }

        // 배송 정보가 의미 있는 상태(배송준비완료/배송중/배송완료)로의 전이에만 반영.
        $shippingStatuses = [
            OrderStatusEnum::SHIPPING_READY,
            OrderStatusEnum::SHIPPING,
            OrderStatusEnum::DELIVERED,
        ];
        if (! in_array($newStatus, $shippingStatuses, true)) {
            return;
        }

        $this->orderShippingRepository->updateTrackingByOrderOptionId(
            $option->id,
            [
                'carrier_id' => $carrierId,
                'tracking_number' => $trackingNumber,
            ]
        );
    }

    /**
     * 일괄 상태 변경 (수량 분할 지원)
     *
     * @param  array  $items  [{option_id, quantity}] 변경 대상
     * @param  OrderStatusEnum  $newStatus  변경할 상태
     * @param  array  $metadata  추가 정보
     * @return array 변경 결과
     */
    public function bulkChangeStatusWithQuantity(
        array $items,
        OrderStatusEnum $newStatus,
        array $metadata = []
    ): array {
        // 스냅샷 캡처 (ChangeDetector용)
        $optionIds = collect($items)->pluck('option_id')->filter()->unique()->toArray();
        $snapshots = $this->orderOptionRepository->getSnapshotsByIds($optionIds);

        // 결제완료(payment_complete) 목표 전이 시 본인인증(IDV) 정책 가드 (A8 / N4).
        // 관리자 주문상세 "주문상태 변경"(옵션 일괄변경)이 결제완료로 부모 주문을 전이시키는,
        // 실무에서 가장 흔한 결제승인 경로다. OrderService::update / bulkUpdate 와 동일하게
        // 변경 자체(트랜잭션) 전에 IDV 훅을 발화해 enforce 가 미인증 시 428 로 전이를 막는다.
        // 부모 주문의 결제수단에 따라 DBANK → 입금확인, 그 외 → 결제 승인으로 분기(결정 7).
        if ($newStatus === OrderStatusEnum::PAYMENT_COMPLETE) {
            $this->enforcePaymentCompleteIdv($optionIds);
        }

        // 일괄 변경 전 훅
        HookManager::doAction('sirsoft-ecommerce.order_option.before_bulk_status_change', $items, $newStatus);

        $results = [];
        $changedCount = 0;
        $splitCount = 0;

        DB::transaction(function () use ($items, $newStatus, $metadata, &$results, &$changedCount, &$splitCount) {
            foreach ($items as $item) {
                $option = $this->orderOptionRepository->findOrFail($item['option_id']);

                $result = $this->changeStatusWithQuantity(
                    $option,
                    $newStatus,
                    $item['quantity'],
                    $metadata
                );

                $changedCount++;
                if ($result['split'] !== null) {
                    $splitCount++;
                }

                $results[] = [
                    'order_option_id' => $option->id,
                    'split_order_option_id' => $result['split']?->id,
                    'merged_into_order_option_id' => $result['merged_into']?->id ?? null,
                    'quantity_changed' => $item['quantity'],
                    'is_full_quantity' => $item['quantity'] === $option->quantity,
                ];
            }
        });

        // 부모 주문 상태 동기화
        $orderIds = $this->orderOptionRepository->getOrderIdsByOptionIds($optionIds);
        foreach ($orderIds as $orderId) {
            $this->syncParentOrderStatus($orderId);
        }

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order_option.after_bulk_status_change', $results, $newStatus, $snapshots);

        return [
            'changed_count' => $changedCount,
            'split_count' => $splitCount,
            'results' => $results,
        ];
    }

    /**
     * 옵션 금액 필드를 캡처합니다.
     *
     * replicate() 전에 원본 금액을 별도 저장하여 2배 계산 버그를 방지합니다.
     *
     * @param  OrderOption  $option  캡처 대상 옵션
     * @return array 캡처된 금액 데이터
     */
    private function captureAmounts(OrderOption $option): array
    {
        return [
            'subtotal_discount_amount' => $option->subtotal_discount_amount,
            'coupon_discount_amount' => $option->coupon_discount_amount,
            'code_discount_amount' => $option->code_discount_amount,
            'subtotal_points_used_amount' => $option->subtotal_points_used_amount,
            'subtotal_deposit_used_amount' => $option->subtotal_deposit_used_amount,
            'subtotal_tax_amount' => $option->subtotal_tax_amount,
            'subtotal_tax_free_amount' => $option->subtotal_tax_free_amount,
            'subtotal_earned_points_amount' => $option->subtotal_earned_points_amount,
            // mc_* 필드
            'mc_subtotal_price' => $option->mc_subtotal_price,
            'mc_product_coupon_discount_amount' => $option->mc_product_coupon_discount_amount,
            'mc_order_coupon_discount_amount' => $option->mc_order_coupon_discount_amount,
            'mc_coupon_discount_amount' => $option->mc_coupon_discount_amount,
            'mc_code_discount_amount' => $option->mc_code_discount_amount,
            'mc_subtotal_points_used_amount' => $option->mc_subtotal_points_used_amount,
            'mc_subtotal_deposit_used_amount' => $option->mc_subtotal_deposit_used_amount,
            'mc_subtotal_tax_amount' => $option->mc_subtotal_tax_amount,
            'mc_subtotal_tax_free_amount' => $option->mc_subtotal_tax_free_amount,
            'mc_final_amount' => $option->mc_final_amount,
        ];
    }

    /**
     * 분할 레코드에 금액을 적용합니다.
     *
     * 원본 금액 × ratio 방식으로 분할 레코드의 금액을 계산합니다.
     *
     * @param  OrderOption  $splitOption  분할 레코드
     * @param  array  $origAmounts  원본 금액 캡처 데이터
     * @param  float  $ratio  분할 비율 (변경수량 / 원본수량)
     * @param  int  $quantity  분할 수량
     */
    private function applySplitAmounts(OrderOption $splitOption, array $origAmounts, float $ratio, int $quantity): void
    {
        $splitOption->subtotal_price = round($splitOption->unit_price * $quantity, 2);
        $splitOption->subtotal_discount_amount = round($origAmounts['subtotal_discount_amount'] * $ratio, 2);
        $splitOption->coupon_discount_amount = round($origAmounts['coupon_discount_amount'] * $ratio, 2);
        $splitOption->code_discount_amount = round($origAmounts['code_discount_amount'] * $ratio, 2);
        $splitOption->subtotal_points_used_amount = round($origAmounts['subtotal_points_used_amount'] * $ratio, 2);
        $splitOption->subtotal_deposit_used_amount = round($origAmounts['subtotal_deposit_used_amount'] * $ratio, 2);
        $splitOption->subtotal_tax_amount = round($origAmounts['subtotal_tax_amount'] * $ratio, 2);
        $splitOption->subtotal_tax_free_amount = round($origAmounts['subtotal_tax_free_amount'] * $ratio, 2);
        $splitOption->subtotal_earned_points_amount = round($origAmounts['subtotal_earned_points_amount'] * $ratio, 2);
        $splitOption->subtotal_weight = round($splitOption->unit_weight * $quantity, 3);
        $splitOption->subtotal_volume = round($splitOption->unit_volume * $quantity, 3);
        $splitOption->subtotal_paid_amount = $splitOption->subtotal_price
            - $splitOption->subtotal_discount_amount
            - $splitOption->subtotal_points_used_amount
            - $splitOption->subtotal_deposit_used_amount;

        // mc_* 필드 비율 분할
        $mcFields = [
            'mc_subtotal_price', 'mc_product_coupon_discount_amount', 'mc_order_coupon_discount_amount',
            'mc_coupon_discount_amount', 'mc_code_discount_amount', 'mc_subtotal_points_used_amount',
            'mc_subtotal_deposit_used_amount', 'mc_subtotal_tax_amount', 'mc_subtotal_tax_free_amount',
            'mc_final_amount',
        ];

        foreach ($mcFields as $field) {
            $splitOption->{$field} = $this->splitMcField($origAmounts[$field], $ratio);
        }
    }

    /**
     * 원본 레코드에 잔여 금액을 적용합니다.
     *
     * 원본 금액 - 분할 금액 방식으로 잔여분을 계산합니다.
     *
     * @param  OrderOption  $option  원본 레코드
     * @param  array  $origAmounts  원본 금액 캡처 데이터
     * @param  OrderOption  $splitOption  분할 레코드
     * @param  int  $remainingQuantity  잔여 수량
     */
    private function applyRemainingAmounts(OrderOption $option, array $origAmounts, OrderOption $splitOption, int $remainingQuantity): void
    {
        $option->quantity = $remainingQuantity;
        $option->subtotal_price = round($option->unit_price * $remainingQuantity, 2);
        $option->subtotal_discount_amount = $origAmounts['subtotal_discount_amount'] - $splitOption->subtotal_discount_amount;
        $option->coupon_discount_amount = $origAmounts['coupon_discount_amount'] - $splitOption->coupon_discount_amount;
        $option->code_discount_amount = $origAmounts['code_discount_amount'] - $splitOption->code_discount_amount;
        $option->subtotal_points_used_amount = $origAmounts['subtotal_points_used_amount'] - $splitOption->subtotal_points_used_amount;
        $option->subtotal_deposit_used_amount = $origAmounts['subtotal_deposit_used_amount'] - $splitOption->subtotal_deposit_used_amount;
        $option->subtotal_tax_amount = $origAmounts['subtotal_tax_amount'] - $splitOption->subtotal_tax_amount;
        $option->subtotal_tax_free_amount = $origAmounts['subtotal_tax_free_amount'] - $splitOption->subtotal_tax_free_amount;
        $option->subtotal_earned_points_amount = $origAmounts['subtotal_earned_points_amount'] - $splitOption->subtotal_earned_points_amount;
        $option->subtotal_weight = round($option->unit_weight * $remainingQuantity, 3);
        $option->subtotal_volume = round($option->unit_volume * $remainingQuantity, 3);
        $option->subtotal_paid_amount = $option->subtotal_price
            - $option->subtotal_discount_amount
            - $option->subtotal_points_used_amount
            - $option->subtotal_deposit_used_amount;

        // mc_* 필드 잔여분 = 원본 - 분할
        $mcFields = [
            'mc_subtotal_price', 'mc_product_coupon_discount_amount', 'mc_order_coupon_discount_amount',
            'mc_coupon_discount_amount', 'mc_code_discount_amount', 'mc_subtotal_points_used_amount',
            'mc_subtotal_deposit_used_amount', 'mc_subtotal_tax_amount', 'mc_subtotal_tax_free_amount',
            'mc_final_amount',
        ];

        foreach ($mcFields as $field) {
            $option->{$field} = $this->subtractMcField($origAmounts[$field], $splitOption->{$field});
        }
    }

    /**
     * 두 옵션 레코드를 병합합니다.
     *
     * 생존 레코드(survivor)에 피흡수 레코드(consumed)의 수량/금액을 합산 후
     * 의존 레코드(배송, 리뷰)를 이전하고 피흡수 레코드를 삭제합니다.
     *
     * @param  OrderOption  $survivor  생존 레코드
     * @param  OrderOption  $consumed  피흡수 레코드
     */
    private function mergeOptions(OrderOption $survivor, OrderOption $consumed): void
    {
        // 1. 의존 레코드 이전 (cascade 삭제 방지)
        $this->orderShippingRepository->transferByOrderOptionId($consumed->id, $survivor->id);
        $this->productReviewRepository->transferByOrderOptionId($consumed->id, $survivor->id);
        // 피흡수 옵션의 구매 적립 거래도 생존 옵션으로 이전 — 병합 후 생존 옵션 기준 적립 델타 계산 정합.
        $this->mileageTransactionRepository->transferPurchaseEarnByOrderOptionId($consumed->id, $survivor->id);

        // 2. 수량/금액 합산
        $amountFields = [
            'subtotal_price', 'subtotal_discount_amount', 'coupon_discount_amount',
            'code_discount_amount', 'subtotal_points_used_amount', 'subtotal_deposit_used_amount',
            'subtotal_tax_amount', 'subtotal_tax_free_amount', 'subtotal_earned_points_amount',
            'subtotal_weight', 'subtotal_volume',
        ];

        $survivor->quantity += $consumed->quantity;
        foreach ($amountFields as $field) {
            $survivor->{$field} = $survivor->{$field} + $consumed->{$field};
        }
        $survivor->subtotal_paid_amount = $survivor->subtotal_price
            - $survivor->subtotal_discount_amount
            - $survivor->subtotal_points_used_amount
            - $survivor->subtotal_deposit_used_amount;

        // mc_* 필드 합산
        $mcFields = [
            'mc_subtotal_price', 'mc_product_coupon_discount_amount', 'mc_order_coupon_discount_amount',
            'mc_coupon_discount_amount', 'mc_code_discount_amount', 'mc_subtotal_points_used_amount',
            'mc_subtotal_deposit_used_amount', 'mc_subtotal_tax_amount', 'mc_subtotal_tax_free_amount',
            'mc_final_amount',
        ];

        foreach ($mcFields as $field) {
            $survivor->{$field} = $this->sumMcField($survivor->{$field}, $consumed->{$field});
        }

        $this->orderOptionRepository->save($survivor);

        // 3. 피흡수 레코드의 분할 자식들을 생존 레코드로 이전
        $this->orderOptionRepository->transferChildren($consumed->id, $survivor->id);

        // 4. 피흡수 레코드 삭제 (의존 레코드 이미 이전됨 → cascade 안전)
        $this->orderOptionRepository->delete($consumed);
    }

    /**
     * mc_* JSON 필드를 비율 분할합니다.
     *
     * @param  array|null  $mcData  원본 mc 데이터
     * @param  float  $ratio  분할 비율
     * @return array|null 분할된 mc 데이터
     */
    private function splitMcField(?array $mcData, float $ratio): ?array
    {
        if (! $mcData) {
            return null;
        }

        $result = [];
        foreach ($mcData as $currency => $amount) {
            $result[$currency] = round((float) $amount * $ratio, 2);
        }

        return $result;
    }

    /**
     * mc_* JSON 필드에서 분할 금액을 차감합니다.
     *
     * @param  array|null  $original  원본 mc 데이터
     * @param  array|null  $split  분할된 mc 데이터
     * @return array|null 잔여 mc 데이터
     */
    private function subtractMcField(?array $original, ?array $split): ?array
    {
        if (! $original) {
            return null;
        }

        $result = [];
        foreach ($original as $currency => $amount) {
            $result[$currency] = round((float) $amount - (float) ($split[$currency] ?? 0), 2);
        }

        return $result;
    }

    /**
     * 결제완료 전이 대상 주문들에 대해 본인인증(IDV) 정책 가드 훅을 발화합니다 (A8 / N4).
     *
     * 옵션 일괄변경으로 부모 주문이 결제완료로 전이되기 직전, 이미 결제완료가 아닌 주문만
     * 골라 결제수단별로 IDV 훅을 발화한다(DBANK → 입금확인, 그 외 → 결제 승인). 미인증 시
     * EnforceIdentityPolicyListener 가 428(IdentityVerificationRequiredException)을 던져
     * 트랜잭션 진입 전에 변경을 차단한다. 정책 비활성(enabled=false) 시에는 enforce 가 통과시킨다.
     *
     * @param  array<int>  $optionIds  변경 대상 옵션 ID 배열
     */
    private function enforcePaymentCompleteIdv(array $optionIds): void
    {
        $orderIds = $this->orderOptionRepository->getOrderIdsByOptionIds($optionIds);

        foreach ($orderIds as $orderId) {
            $order = $this->orderRepository->find($orderId);
            if (! $order) {
                continue;
            }

            // 이미 결제완료인 주문은 전이가 일어나지 않으므로 가드 대상에서 제외.
            $currentStatus = $order->order_status instanceof OrderStatusEnum
                ? $order->order_status->value
                : $order->order_status;
            if ($currentStatus === OrderStatusEnum::PAYMENT_COMPLETE->value) {
                continue;
            }

            $paymentMethod = $order->payment?->payment_method;
            $paymentMethod = $paymentMethod instanceof PaymentMethodEnum
                ? $paymentMethod->value
                : $paymentMethod;
            $idvHook = $paymentMethod === PaymentMethodEnum::DBANK->value
                ? 'sirsoft-ecommerce.payment.before_confirm_deposit'
                : 'sirsoft-ecommerce.payment.before_approve';
            HookManager::doAction($idvHook, $order);
        }
    }

    /**
     * 부모 주문 상태를 자식 옵션 상태에 맞게 동기화합니다.
     *
     * - 활성 옵션(취소 제외)이 모두 동일 상태 → 주문도 해당 상태로 변경
     * - 혼합 상태 → 가장 낮은 진행 단계(보수적)로 설정
     * - 활성 옵션이 0개(전부 취소) → 주문도 취소(CANCELLED)로 전이
     *
     * 부분취소는 별도 주문 상태가 아니라 "취소 옵션 + 잔여 활성 옵션 공존" 파생 상태다.
     * 따라서 주문 상태는 항상 잔여 활성 옵션 기준으로 결정하고, 부분취소 여부는
     * Order::isPartiallyCancelled() 파생 플래그로 노출한다 (2026-06-22, partial_cancelled 제거).
     *
     * @param  int  $orderId  동기화할 주문 ID
     */
    public function syncParentOrderStatus(int $orderId): void
    {
        $order = $this->orderRepository->find($orderId);
        if (! $order) {
            return;
        }

        // 취소/클레임 등 별도 라이프사이클을 제외한 활성 옵션의 상태 값 목록 (string)
        // 제외 목록은 OrderStatusEnum SSoT 로 일원화한다.
        $activeStatusValues = $order->options()
            ->whereNotIn('option_status', OrderStatusEnum::syncExcludedValues())
            ->pluck('option_status')
            ->map(fn ($s) => $s instanceof OrderStatusEnum ? $s->value : $s)
            ->unique()
            ->values();

        if ($activeStatusValues->isEmpty()) {
            // 활성 옵션이 하나도 없으면 주문 전체가 취소된 것 → CANCELLED 로 전이.
            // (옵션이 아예 없는 비정상 데이터는 전이 대상에서 제외.)
            if ($order->options()->exists()) {
                $this->transitionOrderStatus($order, OrderStatusEnum::CANCELLED);
            }

            return;
        }

        if ($activeStatusValues->count() === 1) {
            // 모든 활성 옵션이 동일 상태
            $newStatus = OrderStatusEnum::from($activeStatusValues->first());
        } else {
            // 혼합 상태 → 진행 순서상 가장 낮은 상태 (보수적)
            // 진행 순서는 OrderStatusEnum SSoT 로 일원화한다 (전이 규칙과 동일 배열 공유).
            $progressOrder = OrderStatusEnum::progressOrderValues();

            $lowestIndex = PHP_INT_MAX;
            foreach ($activeStatusValues as $statusValue) {
                $index = array_search($statusValue, $progressOrder);
                if ($index !== false && $index < $lowestIndex) {
                    $lowestIndex = $index;
                }
            }

            $newStatus = OrderStatusEnum::from($progressOrder[$lowestIndex]);
        }

        $this->transitionOrderStatus($order, $newStatus);
    }

    /**
     * 주문 상태를 목표 상태로 전이하고 변경 시 상태변경 알림 훅을 발화합니다.
     *
     * syncParentOrderStatus 의 잔여옵션 파생 전이와 전부취소(CANCELLED) 전이가 공유하는 헬퍼.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderStatusEnum  $newStatus  목표 주문 상태
     */
    private function transitionOrderStatus($order, OrderStatusEnum $newStatus): void
    {
        if ($order->order_status === $newStatus) {
            return;
        }

        $previousStatus = $order->order_status?->value;
        $orderUpdate = ['order_status' => $newStatus->value];
        // 주문 전체가 구매확정으로 전이되면 헤더에도 확정 시점 기록 (유저 셀프 확정 OrderService::confirmOption 과 대칭).
        // 멱등: 이미 값이 있으면 유지(최초 확정 시점 보존).
        if ($newStatus === OrderStatusEnum::CONFIRMED && $order->confirmed_at === null) {
            $orderUpdate['confirmed_at'] = now();
        }
        $order->update($orderUpdate);

        // 옵션별 일괄 상태변경(운송장 등)으로 부모 주문 상태가 전이되면 알림 훅 발화 (A35/A36/C3).
        // OrderStatusNotificationListener 가 결제완료/배송중/배송완료/구매확정 알림으로 매핑한다.
        // 목표 상태($newStatus->value)를 스칼라로 명시 전달 — 큐 지연 재로드 오매핑 방지 (N1).
        HookManager::doAction('sirsoft-ecommerce.order.after_status_change', $order->fresh(), $previousStatus, $newStatus->value);
    }

    /**
     * mc_* JSON 필드를 합산합니다.
     *
     * @param  array|null  $a  첫 번째 mc 데이터
     * @param  array|null  $b  두 번째 mc 데이터
     * @return array|null 합산된 mc 데이터
     */
    private function sumMcField(?array $a, ?array $b): ?array
    {
        if (! $a && ! $b) {
            return null;
        }

        $merged = $a ?? [];
        foreach ($b ?? [] as $currency => $amount) {
            $merged[$currency] = round(($merged[$currency] ?? 0) + (float) $amount, 2);
        }

        return $merged;
    }
}
