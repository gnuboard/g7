<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;

/**
 * 추가배송비 템플릿 수정 요청 검증
 */
class ExtraFeeTemplateUpdateRequest extends FormRequest
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
        $id = $this->route('id');

        return [
            'zipcode' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9]+(-[0-9]+)?$/',
                Rule::unique(ExtraFeeTemplate::class, 'zipcode')->ignore($id),
            ],
            'fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'region' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
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
            'zipcode.required' => __('sirsoft-ecommerce::validation.extra_fee_template.zipcode_required'),
            'zipcode.unique' => __('sirsoft-ecommerce::validation.extra_fee_template.zipcode_unique'),
            'zipcode.max' => __('sirsoft-ecommerce::validation.extra_fee_template.zipcode_max'),
            'fee.required' => __('sirsoft-ecommerce::validation.extra_fee_template.fee_required'),
            'fee.numeric' => __('sirsoft-ecommerce::validation.extra_fee_template.fee_numeric'),
            'fee.min' => __('sirsoft-ecommerce::validation.extra_fee_template.fee_min'),
            'zipcode.regex' => __('sirsoft-ecommerce::validation.extra_fee_template.zipcode_format'),
        ];
    }
}
