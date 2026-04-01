<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 추가배송비 템플릿 목록 조회 요청 검증
 */
class ExtraFeeTemplateListRequest extends FormRequest
{
    /**
     * 권한 검증
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'region' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'in:,true,false'],
            'sort_by' => ['nullable', 'in:id,zipcode,fee,region,is_active,created_at,updated_at'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.string' => __('sirsoft-ecommerce::validation.list.search.string'),
            'search.max' => __('sirsoft-ecommerce::validation.list.search.max'),
            'region.string' => __('sirsoft-ecommerce::validation.list.region.string'),
            'region.max' => __('sirsoft-ecommerce::validation.list.region.max'),
            'is_active.in' => __('sirsoft-ecommerce::validation.list.is_active.in'),
            'sort_by.in' => __('sirsoft-ecommerce::validation.list.sort_by.in'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.list.sort_order.in'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.list.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.list.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.list.per_page.max'),
            'page.integer' => __('sirsoft-ecommerce::validation.list.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.list.page.min'),
        ];
    }
}
