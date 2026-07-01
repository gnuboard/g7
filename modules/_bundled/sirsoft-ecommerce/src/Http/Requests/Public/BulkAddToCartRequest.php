<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 장바구니 일괄 담기 요청
 *
 * 하나의 상품에 대해 여러 옵션 조합을 한 번에 장바구니에 담습니다.
 */
class BulkAddToCartRequest extends FormRequest
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
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'items' => 'required|array|min:1',
            'items.*.option_values' => 'nullable|array',
            'items.*.quantity' => 'required|integer|min:1|max:9999',
            // 추가옵션 선택 (서버에서 value_id 기준 가격 재조회·검증 — 클라 가격 신뢰 금지)
            'items.*.additional_option_selections' => 'nullable|array',
            'items.*.additional_option_selections.*.additional_option_id' => 'required_with:items.*.additional_option_selections|integer',
            'items.*.additional_option_selections.*.value_id' => 'required_with:items.*.additional_option_selections|integer',
            // 직접입력 텍스트 (선택지의 allow_custom_text 여부·필수성은 서버에서 재검증)
            'items.*.additional_option_selections.*.custom_text' => 'nullable|string|max:255',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.cart.bulk_add_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'product_id.required' => __('sirsoft-ecommerce::validation.cart.product_id_required'),
            'product_id.exists' => __('sirsoft-ecommerce::validation.cart.product_not_found'),
            'items.required' => __('sirsoft-ecommerce::validation.cart.items_required'),
            'items.min' => __('sirsoft-ecommerce::validation.cart.items_min'),
            'items.*.quantity.required' => __('sirsoft-ecommerce::validation.cart.quantity_required'),
            'items.*.quantity.min' => __('sirsoft-ecommerce::validation.cart.quantity_min'),
            'items.*.quantity.max' => __('sirsoft-ecommerce::validation.cart.quantity_max'),
        ];
    }
}
