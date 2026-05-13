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
