<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 사용 가능 쿠폰 조회 요청
 */
class UserCouponAvailableRequest extends FormRequest
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
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.coupon.user_available_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_ids.array' => __('sirsoft-ecommerce::validation.user_coupon.product_ids.array'),
            'product_ids.*.integer' => __('sirsoft-ecommerce::validation.user_coupon.product_ids_item.integer'),
        ];
    }
}
