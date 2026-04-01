<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 최대 사용 가능 마일리지 조회 요청
 */
class UserMileageMaxUsableRequest extends FormRequest
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
            'order_amount' => ['required', 'integer', 'min:0'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.mileage.max_usable_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order_amount.required' => __('sirsoft-ecommerce::validation.user_mileage.order_amount.required'),
            'order_amount.integer' => __('sirsoft-ecommerce::validation.user_mileage.order_amount.integer'),
            'order_amount.min' => __('sirsoft-ecommerce::validation.user_mileage.order_amount.min'),
        ];
    }
}
