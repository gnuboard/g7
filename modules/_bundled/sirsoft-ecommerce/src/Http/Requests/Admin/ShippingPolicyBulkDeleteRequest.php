<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;

/**
 * 배송정책 일괄 삭제 요청
 */
class ShippingPolicyBulkDeleteRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists(ShippingPolicy::class, 'id')],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.bulk_delete_validation_rules', $rules, $this);
    }

    /**
     * 에러 메시지
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ecommerce::validation.shipping_policy.ids_required'),
            'ids.array' => __('sirsoft-ecommerce::validation.shipping_policy.ids_array'),
            'ids.min' => __('sirsoft-ecommerce::validation.shipping_policy.ids_min'),
            'ids.*.integer' => __('sirsoft-ecommerce::validation.shipping_policy.id_integer'),
            'ids.*.exists' => __('sirsoft-ecommerce::validation.shipping_policy.id_exists'),
        ];
    }
}
