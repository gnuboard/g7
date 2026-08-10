<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 장바구니 수량 변경 요청
 */
class UpdateCartQuantityRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $maxQuantity = (int) config('sirsoft-ecommerce.cart.max_quantity', 99);

        $rules = [
            // 장바구니 수량 상한은 config('sirsoft-ecommerce.cart.max_quantity') 가 SSoT
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$maxQuantity],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.cart.update_quantity_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            'quantity.required' => __('sirsoft-ecommerce::validation.cart.quantity_required'),
            'quantity.min' => __('sirsoft-ecommerce::validation.cart.quantity_min'),
            'quantity.max' => __('sirsoft-ecommerce::validation.cart.quantity_max'),
        ];
    }
}
