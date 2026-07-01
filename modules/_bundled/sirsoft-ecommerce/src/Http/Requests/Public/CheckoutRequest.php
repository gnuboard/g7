<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Cart;

/**
 * 주문하기 (체크아웃) 요청
 *
 * 장바구니에서 선택한 아이템으로 임시 주문을 생성합니다.
 */
class CheckoutRequest extends FormRequest
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
        // item_ids(장바구니 경유) 또는 direct_items(바로 구매, 장바구니 미경유) 택일
        $isDirect = $this->has('direct_items');

        $rules = [
            'item_ids' => [Rule::requiredIf(! $isDirect), 'array', 'min:1'],
            'item_ids.*' => ['integer', Rule::exists(Cart::class, 'id')],
            'direct_items' => [Rule::requiredIf($isDirect), 'array', 'min:1'],
            'direct_items.*.product_id' => ['required_with:direct_items', 'integer'],
            'direct_items.*.option_values' => ['nullable', 'array'],
            'direct_items.*.quantity' => ['required_with:direct_items', 'integer', 'min:1'],
            // 바로구매 추가옵션 선택 (서버에서 value_id 기준 검증/가격 재조회)
            'direct_items.*.additional_option_selections' => ['nullable', 'array'],
            'direct_items.*.additional_option_selections.*.additional_option_id' => ['required_with:direct_items.*.additional_option_selections', 'integer'],
            'direct_items.*.additional_option_selections.*.value_id' => ['required_with:direct_items.*.additional_option_selections', 'integer'],
            // 직접입력 텍스트 (선택지의 allow_custom_text 여부·필수성은 서버에서 재검증)
            'direct_items.*.additional_option_selections.*.custom_text' => ['nullable', 'string', 'max:255'],
            'coupon_issue_ids' => 'nullable|array',
            'coupon_issue_ids.*' => 'integer',
            'use_points' => 'nullable|integer|min:0',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.checkout.validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'item_ids.required' => __('sirsoft-ecommerce::validation.checkout.item_ids_required'),
            'item_ids.array' => __('sirsoft-ecommerce::validation.checkout.item_ids_array'),
            'item_ids.min' => __('sirsoft-ecommerce::validation.checkout.item_ids_min'),
            'item_ids.*.exists' => __('sirsoft-ecommerce::validation.cart.item_not_found'),
            'use_points.integer' => __('sirsoft-ecommerce::validation.checkout.use_points_integer'),
            'use_points.min' => __('sirsoft-ecommerce::validation.checkout.use_points_min'),
        ];
    }
}
