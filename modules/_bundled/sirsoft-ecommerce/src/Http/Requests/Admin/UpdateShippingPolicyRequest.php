<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;

/**
 * 배송정책 수정 요청
 */
class UpdateShippingPolicyRequest extends StoreShippingPolicyRequest
{
    /**
     * 유효성 검사 규칙
     *
     * StoreShippingPolicyRequest의 규칙을 상속하며, 훅 필터명만 변경합니다.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // 훅을 통한 validation rules 확장 (update 전용)
        return HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.update_validation_rules', $rules, $this);
    }
}
