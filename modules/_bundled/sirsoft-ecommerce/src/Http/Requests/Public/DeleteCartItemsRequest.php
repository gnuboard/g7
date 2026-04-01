<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Cart;

/**
 * 장바구니 선택 삭제 요청
 */
class DeleteCartItemsRequest extends FormRequest
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
            'ids' => 'required|array|min:1',
            'ids.*' => ['integer', Rule::exists(Cart::class, 'id')],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.cart.delete_items_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ecommerce::validation.cart.ids_required'),
            'ids.array' => __('sirsoft-ecommerce::validation.cart.ids_array'),
            'ids.min' => __('sirsoft-ecommerce::validation.cart.ids_min'),
            'ids.*.exists' => __('sirsoft-ecommerce::validation.cart.item_not_found'),
        ];
    }
}
