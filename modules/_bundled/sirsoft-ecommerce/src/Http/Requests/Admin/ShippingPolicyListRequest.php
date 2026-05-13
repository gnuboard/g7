<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;

/**
 * 배송정책 목록 조회 요청
 */
class ShippingPolicyListRequest extends FormRequest
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
            // 정책명 검색
            'search' => ['nullable', 'string', 'max:200'],

            // 배송방법 (다중선택)
            'shipping_methods' => ['nullable', 'array'],
            'shipping_methods.*' => ['string', Rule::in(ShippingType::pluck('code')->toArray())],

            // 부과정책 (다중선택)
            'charge_policies' => ['nullable', 'array'],
            'charge_policies.*' => ['string', Rule::in(ChargePolicyEnum::values())],

            // 배송국가 (다중선택, Settings 기반 동적 국가)
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'max:10'],

            // 사용여부
            'is_active' => ['nullable', 'in:,true,false'],

            // 정렬 및 페이지네이션
            'sort_by' => ['nullable', 'in:id,name,is_active,sort_order,created_at,updated_at'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'search.string' => __('sirsoft-ecommerce::validation.list.search.string'),
            'search.max' => __('sirsoft-ecommerce::validation.list.search.max'),
            'shipping_methods.array' => __('sirsoft-ecommerce::validation.list.shipping_methods.array'),
            'shipping_methods.*.string' => __('sirsoft-ecommerce::validation.list.shipping_methods.string'),
            'shipping_methods.*.in' => __('sirsoft-ecommerce::validation.list.shipping_methods.in'),
            'charge_policies.array' => __('sirsoft-ecommerce::validation.list.charge_policies.array'),
            'charge_policies.*.string' => __('sirsoft-ecommerce::validation.list.charge_policies.string'),
            'charge_policies.*.in' => __('sirsoft-ecommerce::validation.list.charge_policies.in'),
            'countries.array' => __('sirsoft-ecommerce::validation.list.countries.array'),
            'countries.*.string' => __('sirsoft-ecommerce::validation.list.countries.string'),
            'countries.*.max' => __('sirsoft-ecommerce::validation.list.countries.max'),
            'is_active.in' => __('sirsoft-ecommerce::validation.list.is_active.in'),
            'sort_by.in' => __('sirsoft-ecommerce::validation.list.sort_by.in'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.list.sort_order.in'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.list.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.list.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.list.per_page.max'),
            'page.integer' => __('sirsoft-ecommerce::validation.list.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.list.page.min'),
        ];

        // 훅을 통한 validation messages 확장
        return HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.list_validation_messages', $messages, $this);
    }
}
