<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;

/**
 * 추가배송비 템플릿 일괄 사용여부 변경 요청 검증
 */
class ExtraFeeTemplateBulkToggleActiveRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', Rule::exists(ExtraFeeTemplate::class, 'id')],
            'is_active' => ['required', 'boolean'],
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
            'ids.required' => __('sirsoft-ecommerce::validation.extra_fee_template.ids_required'),
            'ids.array' => __('sirsoft-ecommerce::validation.extra_fee_template.ids_array'),
            'ids.min' => __('sirsoft-ecommerce::validation.extra_fee_template.ids_min'),
            'ids.*.exists' => __('sirsoft-ecommerce::validation.extra_fee_template.id_not_found'),
            'is_active.required' => __('sirsoft-ecommerce::validation.extra_fee_template.is_active_required'),
            'is_active.boolean' => __('sirsoft-ecommerce::validation.extra_fee_template.is_active_boolean'),
        ];
    }
}
