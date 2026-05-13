<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 주문 옵션 일괄 상태 변경 요청
 */
class BulkChangeOrderOptionStatusRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.option_id' => ['required', 'integer', Rule::exists(OrderOption::class, 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', Rule::in(OrderStatusEnum::values())],
            'carrier_id' => ['nullable', 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            'tracking_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * 추가 검증 로직
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);

            foreach ($items as $index => $item) {
                if (! isset($item['option_id'], $item['quantity'])) {
                    continue;
                }

                $option = \Modules\Sirsoft\Ecommerce\Models\OrderOption::find($item['option_id']);
                if ($option && $item['quantity'] > $option->quantity) {
                    $validator->errors()->add(
                        "items.{$index}.quantity",
                        __('sirsoft-ecommerce::validation.quantity_exceeds_available')
                    );
                }
            }

            // 배송 관련 상태 선택 시 택배사/송장번호 필수
            $status = $this->input('status');

            if ($status && in_array($status, OrderStatusEnum::shippingInfoRequiredValues())) {
                if (! $this->input('carrier_id')) {
                    $validator->errors()->add('carrier_id', __('sirsoft-ecommerce::validation.orders.carrier_required'));
                }
                if (! $this->input('tracking_number')) {
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
            'items.required' => __('sirsoft-ecommerce::validation.order_options.items.required'),
            'items.min' => __('sirsoft-ecommerce::validation.order_options.items.min'),
            'items.*.option_id.required' => __('sirsoft-ecommerce::validation.order_options.option_id.required'),
            'items.*.option_id.exists' => __('sirsoft-ecommerce::validation.order_options.option_id.exists'),
            'items.*.quantity.required' => __('sirsoft-ecommerce::validation.order_options.quantity.required'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.order_options.quantity.min'),
            'status.required' => __('sirsoft-ecommerce::validation.order_options.status.required'),
            'status.in' => __('sirsoft-ecommerce::validation.order_options.status.in'),
        ];
    }
}
