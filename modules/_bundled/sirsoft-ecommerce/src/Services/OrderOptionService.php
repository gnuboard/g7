<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;

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
     */
    public function __construct(
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
    ) {}

    /**
     * 주문 옵션 상태를 수량 단위로 변경합니다.
     * 변경 수량이 전체 수량보다 작으면 레코드를 분할합니다.
     *
     * @param  OrderOption  $option  대상 옵션
     * @param  OrderStatusEnum  $newStatus  변경할 상태
     * @param  int  $quantity  변경할 수량
     * @param  array  $metadata  추가 정보 (carrier, tracking_number 등)
     * @return array{original: OrderOption, split: ?OrderOption} 변경 결과
     */
    public function changeStatusWithQuantity(
        OrderOption $option,
        OrderStatusEnum $newStatus,
        int $quantity,
        array $metadata = []
    ): array {
        // 변경 전 훅
        HookManager::doAction('sirsoft-ecommerce.order_option.before_status_change', $option, $newStatus, $quantity);

        $result = ['original' => $option, 'split' => null];

        DB::transaction(function () use ($option, $newStatus, $quantity, $metadata, &$result) {
            if ($quantity === $option->quantity) {
                // 전체 수량 변경 → UPDATE만
                $this->orderOptionRepository->update($option, ['option_status' => $newStatus]);

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

                // 분할 레코드 금액 = 원본 × ratio
                $this->applySplitAmounts($splitOption, $origAmounts, $ratio, $quantity);
                $this->orderOptionRepository->save($splitOption);

                // 원본 레코드 = 원본 - 분할 (잔여분)
                $this->applyRemainingAmounts($option, $origAmounts, $splitOption, $remainingQuantity);
                $this->orderOptionRepository->save($option);

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

        return $result;
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
        $snapshots = OrderOption::whereIn('id', $optionIds)->get()->keyBy('id')->map->toArray()->all();

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
        $orderIds = OrderOption::whereIn('id', $optionIds)->pluck('order_id')->unique()->toArray();
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
     * @return void
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
     * @return void
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
     * @return void
     */
    private function mergeOptions(OrderOption $survivor, OrderOption $consumed): void
    {
        // 1. 의존 레코드 이전 (cascade 삭제 방지)
        $this->orderShippingRepository->transferByOrderOptionId($consumed->id, $survivor->id);
        ProductReview::where('order_option_id', $consumed->id)
            ->update(['order_option_id' => $survivor->id]);

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
        OrderOption::where('parent_option_id', $consumed->id)
            ->update(['parent_option_id' => $survivor->id]);

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
     * 부모 주문 상태를 자식 옵션 상태에 맞게 동기화합니다.
     *
     * - 모든 활성 옵션(취소 제외)이 동일 상태 → 주문도 해당 상태로 변경
     * - 혼합 상태 → 가장 낮은 진행 단계(보수적)로 설정
     *
     * @param int $orderId 동기화할 주문 ID
     */
    private function syncParentOrderStatus(int $orderId): void
    {
        $order = Order::find($orderId);
        if (! $order) {
            return;
        }

        // 취소 상태를 제외한 활성 옵션의 상태 값 목록 (string)
        $activeStatusValues = $order->options()
            ->whereNotIn('option_status', [
                OrderStatusEnum::CANCELLED->value,
                OrderStatusEnum::PARTIAL_CANCELLED->value,
            ])
            ->pluck('option_status')
            ->map(fn ($s) => $s instanceof OrderStatusEnum ? $s->value : $s)
            ->unique()
            ->values();

        if ($activeStatusValues->isEmpty()) {
            return;
        }

        if ($activeStatusValues->count() === 1) {
            // 모든 활성 옵션이 동일 상태
            $newStatus = OrderStatusEnum::from($activeStatusValues->first());
        } else {
            // 혼합 상태 → 진행 순서상 가장 낮은 상태 (보수적)
            $progressOrder = [
                OrderStatusEnum::PENDING_ORDER->value,
                OrderStatusEnum::PENDING_PAYMENT->value,
                OrderStatusEnum::PAYMENT_COMPLETE->value,
                OrderStatusEnum::SHIPPING_HOLD->value,
                OrderStatusEnum::PREPARING->value,
                OrderStatusEnum::SHIPPING_READY->value,
                OrderStatusEnum::SHIPPING->value,
                OrderStatusEnum::DELIVERED->value,
                OrderStatusEnum::CONFIRMED->value,
            ];

            $lowestIndex = PHP_INT_MAX;
            foreach ($activeStatusValues as $statusValue) {
                $index = array_search($statusValue, $progressOrder);
                if ($index !== false && $index < $lowestIndex) {
                    $lowestIndex = $index;
                }
            }

            $newStatus = OrderStatusEnum::from($progressOrder[$lowestIndex]);
        }

        if ($order->order_status !== $newStatus) {
            $order->update(['order_status' => $newStatus->value]);
        }
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
