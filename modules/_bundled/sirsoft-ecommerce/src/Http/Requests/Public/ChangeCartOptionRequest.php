<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 장바구니 옵션 변경 요청
 */
class ChangeCartOptionRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'product_option_id' => ['required', 'integer', Rule::exists(ProductOption::class, 'id')],
            'quantity' => 'required|integer|min:1|max:9999',
            // 추가옵션 재선택 (미전달 시 기존 선택 유지 — 서버에서 value_id 검증/가격 재조회)
            'additional_option_selections' => 'nullable|array',
            'additional_option_selections.*.additional_option_id' => 'required_with:additional_option_selections|integer',
            'additional_option_selections.*.value_id' => 'required_with:additional_option_selections|integer',
            // 직접입력 텍스트 (선택지의 allow_custom_text 여부·필수성은 서버에서 재검증)
            'additional_option_selections.*.custom_text' => 'nullable|string|max:255',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.cart.change_option_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'product_option_id.required' => __('sirsoft-ecommerce::validation.cart.option_id_required'),
            'product_option_id.exists' => __('sirsoft-ecommerce::validation.cart.option_not_found'),
            'quantity.required' => __('sirsoft-ecommerce::validation.cart.quantity_required'),
            'quantity.min' => __('sirsoft-ecommerce::validation.cart.quantity_min'),
            'quantity.max' => __('sirsoft-ecommerce::validation.cart.quantity_max'),
        ];
    }
}
