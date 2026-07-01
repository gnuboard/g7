<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 주문 일괄 변경 요청
 */
class BulkUpdateOrdersRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 권한 미들웨어에 위임하므로 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 필드별 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists(Order::class, 'id')],
            'order_status' => ['nullable', 'string', Rule::in(
                collect(OrderStatusEnum::values())->reject(fn ($v) => $v === OrderStatusEnum::PENDING_ORDER->value)->values()->all()
            )],
            'carrier_id' => ['nullable', 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            'tracking_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * 추가 검증 로직
     *
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $status = $this->input('order_status');
            $trackingNumber = $this->input('tracking_number');
            $carrierId = $this->input('carrier_id');

            // 상태 변경, 운송장번호, 택배사 중 하나 이상 입력 필요
            if ($status === null && $trackingNumber === null && $carrierId === null) {
                $validator->errors()->add('order_status', __('sirsoft-ecommerce::validation.orders.bulk_update.at_least_one'));
            }

            // 상태 변경 없이 운송장번호만 입력한 경우
            if ($trackingNumber && ! $status) {
                $validator->errors()->add('order_status', __('sirsoft-ecommerce::validation.orders.tracking_number.requires_status'));
            }

            // 배송 관련 상태 선택 시 택배사/송장번호 필수
            if ($status && in_array($status, OrderStatusEnum::shippingInfoRequiredValues())) {
                if (! $carrierId) {
                    $validator->errors()->add('carrier_id', __('sirsoft-ecommerce::validation.orders.carrier_required'));
                }
                if (! $trackingNumber) {
                    $validator->errors()->add('tracking_number', __('sirsoft-ecommerce::validation.orders.tracking_number_required'));
                }
            }

            // 상태 일괄 전이 검증 (역방향/비연속 역행 차단, all-or-nothing)
            // 대상 주문 중 1건이라도 비합법 전이면 422 → Service 미진입 → DB 무변경.
            if ($status !== null) {
                $target = OrderStatusEnum::tryFrom($status);
                if ($target !== null) {
                    $repo = app(OrderRepositoryInterface::class);
                    $snapshots = $repo->getSnapshotsByIds($this->input('ids', []));
                    $invalid = [];
                    foreach ($snapshots as $snap) {
                        $currentValue = $snap['order_status'] ?? null;
                        $current = $currentValue !== null ? OrderStatusEnum::tryFrom(
                            $currentValue instanceof OrderStatusEnum ? $currentValue->value : $currentValue
                        ) : null;
                        if ($current !== null && ! $current->canTransitionTo($target)) {
                            $invalid[] = $current->label();
                        }
                    }
                    if (! empty($invalid)) {
                        $validator->errors()->add('order_status', __('sirsoft-ecommerce::validation.orders.status_transition.bulk_invalid', [
                            'count' => count($invalid),
                            'to' => $target->label(),
                            'from' => implode(', ', array_values(array_unique($invalid))),
                        ]));
                    }
                }
            }
        });
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string> 규칙별 다국어 에러 메시지 배열
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ecommerce::validation.orders.ids.required'),
            'ids.min' => __('sirsoft-ecommerce::validation.orders.ids.min'),
            'ids.*.exists' => __('sirsoft-ecommerce::validation.orders.ids.exists'),
            'order_status.in' => __('sirsoft-ecommerce::validation.orders.order_status.in'),
            'carrier_id.exists' => __('sirsoft-ecommerce::validation.orders.carrier_id.exists'),
            'tracking_number.max' => __('sirsoft-ecommerce::validation.orders.tracking_number.max'),
        ];
    }
}
