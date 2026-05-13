<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 주문 일괄 변경 요청
 */
class BulkUpdateOrdersRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array
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
     * @param \Illuminate\Validation\Validator $validator
     * @return void
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
        });
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
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
