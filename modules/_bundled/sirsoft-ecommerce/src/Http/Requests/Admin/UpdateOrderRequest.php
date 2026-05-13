<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 주문 수정 요청
 */
class UpdateOrderRequest extends FormRequest
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
            'order_status' => ['nullable', 'string', Rule::in(OrderStatusEnum::values())],
            'admin_memo' => ['nullable', 'string', 'max:2000'],
            'recipient_name' => ['required', 'string', 'max:50'],
            'recipient_phone' => ['required_without:recipient_tel', 'nullable', 'string', 'max:20'],
            'recipient_tel' => ['required_without:recipient_phone', 'nullable', 'string', 'max:20'],
            'recipient_zipcode' => ['required', 'string', 'max:10'],
            'recipient_address' => ['required', 'string', 'max:255'],
            'recipient_detail_address' => ['required', 'string', 'max:255'],
            'delivery_memo' => ['nullable', 'string', 'max:500'],
            'recipient_country_code' => ['nullable', 'string', 'size:2'],
        ];
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'order_status.in' => __('sirsoft-ecommerce::validation.orders.order_status.in'),
            'admin_memo.max' => __('sirsoft-ecommerce::validation.orders.admin_memo.max'),
            'recipient_name.required' => __('sirsoft-ecommerce::validation.orders.recipient_name.required'),
            'recipient_name.max' => __('sirsoft-ecommerce::validation.orders.recipient_name.max'),
            'recipient_phone.required_without' => __('sirsoft-ecommerce::validation.orders.recipient_phone.required_without'),
            'recipient_phone.max' => __('sirsoft-ecommerce::validation.orders.recipient_phone.max'),
            'recipient_tel.required_without' => __('sirsoft-ecommerce::validation.orders.recipient_tel.required_without'),
            'recipient_tel.max' => __('sirsoft-ecommerce::validation.orders.recipient_tel.max'),
            'recipient_zipcode.required' => __('sirsoft-ecommerce::validation.orders.recipient_zipcode.required'),
            'recipient_zipcode.max' => __('sirsoft-ecommerce::validation.orders.recipient_zipcode.max'),
            'recipient_address.required' => __('sirsoft-ecommerce::validation.orders.recipient_address.required'),
            'recipient_address.max' => __('sirsoft-ecommerce::validation.orders.recipient_address.max'),
            'recipient_detail_address.required' => __('sirsoft-ecommerce::validation.orders.recipient_detail_address.required'),
            'recipient_detail_address.max' => __('sirsoft-ecommerce::validation.orders.recipient_detail_address.max'),
            'delivery_memo.max' => __('sirsoft-ecommerce::validation.orders.delivery_memo.max'),
            'recipient_country_code.size' => __('sirsoft-ecommerce::validation.orders.recipient_country_code.size'),
        ];
    }
}
