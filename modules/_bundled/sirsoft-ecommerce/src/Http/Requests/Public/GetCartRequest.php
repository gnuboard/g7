<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 장바구니 조회 요청
 *
 * 선택된 상품 ID 목록을 받아 해당 상품만 계산에 포함합니다.
 */
class GetCartRequest extends FormRequest
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
            'selected_ids' => 'nullable|array',
            'selected_ids.*' => 'integer|min:1',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.cart.get_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'selected_ids.array' => __('sirsoft-ecommerce::validation.cart.selected_ids_array'),
            'selected_ids.*.integer' => __('sirsoft-ecommerce::validation.cart.selected_ids_integer'),
            'selected_ids.*.min' => __('sirsoft-ecommerce::validation.cart.selected_ids_min'),
        ];
    }
}
