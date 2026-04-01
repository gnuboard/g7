<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Models\Coupon;

/**
 * 쿠폰 일괄 상태 변경 요청
 */
class BulkUpdateCouponStatusRequest extends FormRequest
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
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => ['required', 'integer', Rule::exists(Coupon::class, 'id')],
            'issue_status' => 'required|string|in:'.implode(',', CouponIssueStatus::values()),
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ecommerce::validation.coupon.ids_required'),
            'ids.min' => __('sirsoft-ecommerce::validation.coupon.ids_min'),
            'issue_status.required' => __('sirsoft-ecommerce::validation.coupon.issue_status_required'),
            'issue_status.in' => __('sirsoft-ecommerce::validation.coupon.issue_status_invalid'),
        ];
    }
}
