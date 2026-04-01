<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 추가배송비 템플릿 일괄 등록 요청 검증
 *
 * CSV/Excel 업로드를 통한 일괄 등록 시 사용
 */
class ExtraFeeTemplateBulkCreateRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.zipcode' => ['required', 'string', 'max:20', 'regex:/^[0-9]+(-[0-9]+)?$/'],
            'items.*.fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.region' => ['nullable', 'string', 'max:100'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 검증 메시지
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'items.required' => __('sirsoft-ecommerce::validation.extra_fee_template.items_required'),
            'items.array' => __('sirsoft-ecommerce::validation.extra_fee_template.items_array'),
            'items.min' => __('sirsoft-ecommerce::validation.extra_fee_template.items_min'),
            'items.max' => __('sirsoft-ecommerce::validation.extra_fee_template.items_max'),
            'items.*.zipcode.required' => __('sirsoft-ecommerce::validation.extra_fee_template.item_zipcode_required'),
            'items.*.zipcode.max' => __('sirsoft-ecommerce::validation.extra_fee_template.zipcode_max'),
            'items.*.fee.required' => __('sirsoft-ecommerce::validation.extra_fee_template.item_fee_required'),
            'items.*.fee.numeric' => __('sirsoft-ecommerce::validation.extra_fee_template.fee_numeric'),
            'items.*.fee.min' => __('sirsoft-ecommerce::validation.extra_fee_template.fee_min'),
            'items.*.zipcode.regex' => __('sirsoft-ecommerce::validation.extra_fee_template.zipcode_format'),
        ];
    }
}
