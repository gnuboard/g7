<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;

/**
 * 클래임 사유 생성 요청
 */
class StoreClaimReasonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ClaimReasonTypeEnum::values())],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique(ClaimReason::class, 'code')->where('type', $this->input('type', 'refund')),
            ],
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'fault_type' => ['required', 'string', Rule::in(ClaimReasonFaultTypeEnum::values())],
            'is_user_selectable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        return [
            'type' => __('sirsoft-ecommerce::validation.attributes.claim_reason_type'),
            'code' => __('sirsoft-ecommerce::validation.attributes.claim_reason_code'),
            'name' => __('sirsoft-ecommerce::validation.attributes.claim_reason_name'),
            'fault_type' => __('sirsoft-ecommerce::validation.attributes.claim_reason_fault_type'),
            'is_user_selectable' => __('sirsoft-ecommerce::validation.attributes.claim_reason_is_user_selectable'),
            'is_active' => __('sirsoft-ecommerce::validation.attributes.claim_reason_is_active'),
            'sort_order' => __('sirsoft-ecommerce::validation.attributes.claim_reason_sort_order'),
        ];
    }
}
