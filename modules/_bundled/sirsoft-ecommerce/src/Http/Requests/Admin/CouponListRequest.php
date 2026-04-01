<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;

/**
 * 쿠폰 목록 조회 요청
 */
class CouponListRequest extends FormRequest
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
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|string|in:created_at,name,discount_value,issued_count',
            'sort_order' => 'nullable|string|in:asc,desc',
            'search_field' => 'nullable|string|in:all,name,description,created_by',
            'search_keyword' => 'nullable|string|max:255',
            'target_type' => 'nullable|string|in:all,'.implode(',', CouponTargetType::values()),
            'discount_type' => 'nullable|string|in:all,'.implode(',', CouponDiscountType::values()),
            'issue_status' => 'nullable|string|in:all,'.implode(',', CouponIssueStatus::values()),
            'issue_method' => 'nullable|string|in:all,'.implode(',', CouponIssueMethod::values()),
            'issue_condition' => 'nullable|string|in:all,'.implode(',', CouponIssueCondition::values()),
            'min_benefit_amount' => 'nullable|numeric|min:0',
            'max_benefit_amount' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'created_start_date' => 'nullable|date',
            'created_end_date' => 'nullable|date|after_or_equal:created_start_date',
            'valid_start_date' => 'nullable|date',
            'valid_end_date' => 'nullable|date|after_or_equal:valid_start_date',
            'issue_start_date' => 'nullable|date',
            'issue_end_date' => 'nullable|date|after_or_equal:issue_start_date',
            'created_by' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => __('sirsoft-ecommerce::validation.list.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.list.page.min'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.list.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.list.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.list.per_page.max'),
            'sort_by.string' => __('sirsoft-ecommerce::validation.list.sort_by.string'),
            'sort_by.in' => __('sirsoft-ecommerce::validation.list.sort_by.in'),
            'sort_order.string' => __('sirsoft-ecommerce::validation.list.sort_order.string'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.list.sort_order.in'),
            'search_field.string' => __('sirsoft-ecommerce::validation.list.search_field.string'),
            'search_field.in' => __('sirsoft-ecommerce::validation.list.search_field.in'),
            'search_keyword.string' => __('sirsoft-ecommerce::validation.list.search_keyword.string'),
            'search_keyword.max' => __('sirsoft-ecommerce::validation.list.search_keyword.max'),
            'target_type.string' => __('sirsoft-ecommerce::validation.list.target_type.string'),
            'target_type.in' => __('sirsoft-ecommerce::validation.list.target_type.in'),
            'discount_type.string' => __('sirsoft-ecommerce::validation.list.discount_type.string'),
            'discount_type.in' => __('sirsoft-ecommerce::validation.list.discount_type.in'),
            'issue_status.string' => __('sirsoft-ecommerce::validation.list.issue_status.string'),
            'issue_status.in' => __('sirsoft-ecommerce::validation.list.issue_status.in'),
            'issue_method.string' => __('sirsoft-ecommerce::validation.list.issue_method.string'),
            'issue_method.in' => __('sirsoft-ecommerce::validation.list.issue_method.in'),
            'issue_condition.string' => __('sirsoft-ecommerce::validation.list.issue_condition.string'),
            'issue_condition.in' => __('sirsoft-ecommerce::validation.list.issue_condition.in'),
            'min_benefit_amount.numeric' => __('sirsoft-ecommerce::validation.list.min_benefit_amount.numeric'),
            'min_benefit_amount.min' => __('sirsoft-ecommerce::validation.list.min_benefit_amount.min'),
            'max_benefit_amount.numeric' => __('sirsoft-ecommerce::validation.list.max_benefit_amount.numeric'),
            'max_benefit_amount.min' => __('sirsoft-ecommerce::validation.list.max_benefit_amount.min'),
            'min_order_amount.numeric' => __('sirsoft-ecommerce::validation.list.min_order_amount.numeric'),
            'min_order_amount.min' => __('sirsoft-ecommerce::validation.list.min_order_amount.min'),
            'created_start_date.date' => __('sirsoft-ecommerce::validation.list.created_start_date.date'),
            'created_end_date.date' => __('sirsoft-ecommerce::validation.list.created_end_date.date'),
            'created_end_date.after_or_equal' => __('sirsoft-ecommerce::validation.list.created_end_date.after_or_equal'),
            'valid_start_date.date' => __('sirsoft-ecommerce::validation.list.valid_start_date.date'),
            'valid_end_date.date' => __('sirsoft-ecommerce::validation.list.valid_end_date.date'),
            'valid_end_date.after_or_equal' => __('sirsoft-ecommerce::validation.list.valid_end_date.after_or_equal'),
            'issue_start_date.date' => __('sirsoft-ecommerce::validation.list.issue_start_date.date'),
            'issue_end_date.date' => __('sirsoft-ecommerce::validation.list.issue_end_date.date'),
            'issue_end_date.after_or_equal' => __('sirsoft-ecommerce::validation.list.issue_end_date.after_or_equal'),
        ];
    }
}
