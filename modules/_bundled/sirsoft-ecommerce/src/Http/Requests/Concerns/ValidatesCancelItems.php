<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 취소/환불 대상 항목(items) per-item 검증 공통 로직
 *
 * 회원(EstimateRefundRequest / CancelOrderRequest)과 비회원
 * (GuestEstimateRefundRequest / GuestCancelOrderRequest) 경로가 동일한 강도로
 * 항목을 검증하도록 단일 지점에서 판정합니다. 각 항목이 대상 주문에 속하는지,
 * 이미 취소된 옵션인지, 취소 수량이 보유 수량을 넘지 않는지 확인합니다.
 */
trait ValidatesCancelItems
{
    /**
     * 취소/환불 대상 항목이 주문에 속하고 취소 가능한지 검증합니다.
     *
     * @param  Validator  $validator  검증기
     * @param  Order  $order  대상 주문(소유권은 상위에서 이미 검증됨)
     * @param  array<int, array{order_option_id?: int, cancel_quantity?: int}>  $items  검증 대상 항목 배열
     * @return void
     */
    protected function validateCancelItemsAgainstOrder(Validator $validator, Order $order, array $items): void
    {
        $order->loadMissing('options');

        foreach ($items as $index => $item) {
            $optionId = $item['order_option_id'] ?? null;
            $option = $order->options->firstWhere('id', $optionId);

            if (! $option) {
                $validator->errors()->add(
                    "items.{$index}.order_option_id",
                    __('sirsoft-ecommerce::exceptions.order_option_not_found')
                );

                continue;
            }

            // 이미 취소된 옵션은 제외
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                $validator->errors()->add(
                    "items.{$index}.order_option_id",
                    __('sirsoft-ecommerce::exceptions.order_option_already_cancelled')
                );

                continue;
            }

            // 취소 수량이 현재 수량을 초과하는지 검증
            if (($item['cancel_quantity'] ?? 0) > $option->quantity) {
                $validator->errors()->add(
                    "items.{$index}.cancel_quantity",
                    __('sirsoft-ecommerce::exceptions.cancel_quantity_exceeds', [
                        'max' => $option->quantity,
                    ])
                );
            }
        }
    }
}
