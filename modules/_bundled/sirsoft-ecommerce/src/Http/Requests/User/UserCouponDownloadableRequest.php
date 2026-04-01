<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 다운로드 가능 쿠폰 조회 요청
 */
class UserCouponDownloadableRequest extends FormRequest
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.coupon.user_downloadable_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' => __('sirsoft-ecommerce::validation.user_coupon.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.user_coupon.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.user_coupon.per_page.max'),
        ];
    }
}
