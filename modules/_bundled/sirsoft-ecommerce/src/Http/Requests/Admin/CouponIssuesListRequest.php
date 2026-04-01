<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;

/**
 * 쿠폰 발급 내역 조회 요청
 */
class CouponIssuesListRequest extends FormRequest
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
            'user_id' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')],
            'status' => 'nullable|string|in:'.implode(',', CouponIssueRecordStatus::values()),
            'per_page' => 'nullable|integer|min:1|max:100',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.coupon.issues_list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'user_id.integer' => __('sirsoft-ecommerce::validation.coupon_issues.user_id_integer'),
            'user_id.exists' => __('sirsoft-ecommerce::validation.coupon_issues.user_id_exists'),
            'status.in' => __('sirsoft-ecommerce::validation.coupon_issues.status_in'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.coupon_issues.per_page_integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.coupon_issues.per_page_min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.coupon_issues.per_page_max'),
        ];
    }
}
