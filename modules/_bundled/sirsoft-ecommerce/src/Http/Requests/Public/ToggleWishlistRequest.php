<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 찜 토글 요청
 */
class ToggleWishlistRequest extends FormRequest
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
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.wishlist.toggle_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'product_id.required' => __('sirsoft-ecommerce::validation.wishlist.product_id_required'),
            'product_id.exists' => __('sirsoft-ecommerce::validation.wishlist.product_not_found'),
        ];
    }
}
