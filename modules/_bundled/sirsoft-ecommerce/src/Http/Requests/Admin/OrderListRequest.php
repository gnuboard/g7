<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderDateTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingTypeEnum;

/**
 * 주문 목록 조회 요청
 */
class OrderListRequest extends FormRequest
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
        $rules = [
            // 문자열 검색
            'search_field' => ['nullable', 'in:all,order_number,orderer_name,recipient_name,orderer_phone,recipient_phone,product_name,sku'],
            'search_keyword' => ['nullable', 'string', 'max:200'],

            // 날짜
            'date_type' => ['nullable', Rule::in(OrderDateTypeEnum::values())],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            // 주문상태 (다중선택)
            'order_status' => ['nullable', 'array'],
            'order_status.*' => ['string', Rule::in(OrderStatusEnum::values())],

            // 옵션상태 (다중선택)
            'option_status' => ['nullable', 'array'],
            'option_status.*' => ['string', Rule::in(OrderStatusEnum::values())],

            // 배송유형 (다중선택)
            'shipping_type' => ['nullable', 'array'],
            'shipping_type.*' => ['string', Rule::in(ShippingTypeEnum::values())],

            // 결제수단 (다중선택)
            'payment_method' => ['nullable', 'array'],
            'payment_method.*' => ['string', Rule::in(PaymentMethodEnum::values())],

            // 카테고리
            'category_id' => ['nullable', 'integer'],

            // 금액 범위
            'min_amount' => ['nullable', 'integer', 'min:0'],
            'max_amount' => ['nullable', 'integer', 'min:0'],

            // 배송국가 코드 (다중선택)
            'country_codes' => ['nullable', 'array'],
            'country_codes.*' => ['string', 'size:2'],

            // 주문 디바이스 (다중선택)
            'order_device' => ['nullable', 'array'],
            'order_device.*' => ['string', Rule::in(DeviceTypeEnum::values())],

            // 배송비 범위
            'min_shipping_amount' => ['nullable', 'integer', 'min:0'],
            'max_shipping_amount' => ['nullable', 'integer', 'min:0'],

            // 배송정책
            'shipping_policy_id' => ['nullable', 'integer'],

            // 회원 ID
            'user_id' => ['nullable', 'integer'],

            // 주문자 UUID (회원 검색 필터용)
            'orderer_uuid' => ['nullable', 'uuid'],

            // 정렬 및 페이지네이션
            'sort_by' => ['nullable', 'in:ordered_at,paid_at,total_amount'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.order.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            // 검색 필드
            'search_field.in' => __('sirsoft-ecommerce::validation.orders.search_field.in'),
            'search_keyword.string' => __('sirsoft-ecommerce::validation.orders.search_keyword.string'),
            'search_keyword.max' => __('sirsoft-ecommerce::validation.orders.search_keyword.max'),
            // 날짜
            'date_type.in' => __('sirsoft-ecommerce::validation.orders.date_type.in'),
            'start_date.date' => __('sirsoft-ecommerce::validation.orders.start_date.date'),
            'end_date.date' => __('sirsoft-ecommerce::validation.orders.end_date.date'),
            'end_date.after_or_equal' => __('sirsoft-ecommerce::validation.orders.end_date.after_or_equal'),
            // 주문상태
            'order_status.array' => __('sirsoft-ecommerce::validation.orders.order_status.array'),
            'order_status.*.string' => __('sirsoft-ecommerce::validation.orders.order_status.string'),
            'order_status.*.in' => __('sirsoft-ecommerce::validation.orders.order_status.in'),
            // 옵션상태
            'option_status.array' => __('sirsoft-ecommerce::validation.orders.option_status.array'),
            'option_status.*.string' => __('sirsoft-ecommerce::validation.orders.option_status.string'),
            'option_status.*.in' => __('sirsoft-ecommerce::validation.orders.option_status.in'),
            // 배송유형
            'shipping_type.array' => __('sirsoft-ecommerce::validation.orders.shipping_type.array'),
            'shipping_type.*.string' => __('sirsoft-ecommerce::validation.orders.shipping_type.string'),
            'shipping_type.*.in' => __('sirsoft-ecommerce::validation.orders.shipping_type.in'),
            // 결제수단
            'payment_method.array' => __('sirsoft-ecommerce::validation.orders.payment_method.array'),
            'payment_method.*.string' => __('sirsoft-ecommerce::validation.orders.payment_method.string'),
            'payment_method.*.in' => __('sirsoft-ecommerce::validation.orders.payment_method.in'),
            // 카테고리
            'category_id.integer' => __('sirsoft-ecommerce::validation.orders.category_id.integer'),
            // 금액 범위
            'min_amount.integer' => __('sirsoft-ecommerce::validation.orders.min_amount.integer'),
            'min_amount.min' => __('sirsoft-ecommerce::validation.orders.min_amount.min'),
            'max_amount.integer' => __('sirsoft-ecommerce::validation.orders.max_amount.integer'),
            'max_amount.min' => __('sirsoft-ecommerce::validation.orders.max_amount.min'),
            // 배송국가 코드
            'country_codes.array' => __('sirsoft-ecommerce::validation.orders.country_codes.array'),
            'country_codes.*.string' => __('sirsoft-ecommerce::validation.orders.country_codes.string'),
            'country_codes.*.size' => __('sirsoft-ecommerce::validation.orders.country_codes.size'),
            // 주문 디바이스
            'order_device.array' => __('sirsoft-ecommerce::validation.orders.order_device.array'),
            'order_device.*.string' => __('sirsoft-ecommerce::validation.orders.order_device.string'),
            'order_device.*.in' => __('sirsoft-ecommerce::validation.orders.order_device.in'),
            // 배송비 범위
            'min_shipping_amount.integer' => __('sirsoft-ecommerce::validation.orders.min_shipping_amount.integer'),
            'min_shipping_amount.min' => __('sirsoft-ecommerce::validation.orders.min_shipping_amount.min'),
            'max_shipping_amount.integer' => __('sirsoft-ecommerce::validation.orders.max_shipping_amount.integer'),
            'max_shipping_amount.min' => __('sirsoft-ecommerce::validation.orders.max_shipping_amount.min'),
            // 배송정책
            'shipping_policy_id.integer' => __('sirsoft-ecommerce::validation.orders.shipping_policy_id.integer'),
            // 회원 ID
            'user_id.integer' => __('sirsoft-ecommerce::validation.orders.user_id.integer'),
            // 주문자 UUID
            'orderer_uuid.uuid' => __('sirsoft-ecommerce::validation.orders.orderer_uuid.uuid'),
            // 정렬 및 페이지네이션
            'sort_by.in' => __('sirsoft-ecommerce::validation.orders.sort_by.in'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.orders.sort_order.in'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.orders.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.orders.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.orders.per_page.max'),
            'page.integer' => __('sirsoft-ecommerce::validation.orders.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.orders.page.min'),
        ];

        // 훅을 통한 validation messages 확장
        return HookManager::applyFilters('sirsoft-ecommerce.order.list_validation_messages', $messages, $this);
    }
}
