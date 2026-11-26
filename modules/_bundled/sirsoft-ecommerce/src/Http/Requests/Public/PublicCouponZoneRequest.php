<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 공개 쿠폰존 목록 요청
 *
 * 비로그인 방문자도 부른다. 쪽 번호와 쪽당 개수(1~48)만 받는다.
 */
class PublicCouponZoneRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (공개 목록)
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
        $rules = [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:48',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.coupon.public_zone_validation_rules', $rules, $this);
    }
}
