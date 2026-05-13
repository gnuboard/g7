<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 쿠폰 수정 요청
 */
class UpdateCouponRequest extends FormRequest
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
            // 다국어 필드
            'name' => ['sometimes', 'required', 'array', new LocaleRequiredTranslatable(maxLength: 200)],
            'description' => ['nullable', 'array', new TranslatableField(maxLength: 1000)],

            // 기본 정보
            'target_type' => 'sometimes|required|string|in:'.implode(',', CouponTargetType::values()),
            'discount_type' => 'sometimes|required|string|in:'.implode(',', CouponDiscountType::values()),
            'discount_value' => $this->discountValueRules(),
            'discount_max_amount' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',

            // 발급 설정
            'issue_method' => 'sometimes|required|string|in:'.implode(',', CouponIssueMethod::values()),
            'issue_condition' => 'sometimes|required|string|in:'.implode(',', CouponIssueCondition::values()),
            'issue_status' => 'sometimes|required|string|in:'.implode(',', CouponIssueStatus::values()),
            'total_quantity' => 'nullable|integer|min:1',
            'per_user_limit' => 'required|integer|min:0',

            // 유효기간
            'valid_type' => 'sometimes|required|string|in:period,days_from_issue',
            'valid_days' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',

            // 발급기간
            'issue_from' => 'nullable|date',
            'issue_to' => 'nullable|date|after_or_equal:issue_from',

            // 중복 사용 및 적용 범위
            'is_combinable' => 'boolean',
            'target_scope' => 'nullable|string|in:'.implode(',', CouponTargetScope::values()),

            // 적용 상품/카테고리
            'products' => 'nullable|array',
            'products.*.id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'products.*.type' => 'required|string|in:include,exclude',

            'categories' => 'nullable|array',
            'categories.*.id' => ['required', 'integer', Rule::exists(Category::class, 'id')],
            'categories.*.type' => 'required|string|in:include,exclude',
        ];

        // 훅을 통한 동적 규칙 확장
        return HookManager::applyFilters('sirsoft-ecommerce.coupon.update_validation_rules', $rules, $this);
    }

    /**
     * 할인값 검증 규칙을 반환합니다.
     *
     * @return array
     */
    protected function discountValueRules(): array
    {
        if ($this->input('discount_type') === CouponDiscountType::RATE->value) {
            return ['sometimes', 'required', 'numeric', 'min:1', 'max:100'];
        }

        return ['sometimes', 'required', 'numeric', 'min:0'];
    }

    /**
     * 검증된 데이터에서 날짜 필드를 사이트 타임존 기준 UTC datetime으로 변환하여 반환합니다.
     *
     * @param  string|null  $key  특정 키만 반환
     * @param  mixed  $default  기본값
     * @return mixed
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null) {
            return $data;
        }

        // 유효기간: 날짜만 입력 (date) → 시작일 00:00:00, 종료일 23:59:59
        foreach (['valid_from'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = TimezoneHelper::fromSiteDateStartOfDay($data[$field]);
            }
        }

        foreach (['valid_to'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = TimezoneHelper::fromSiteDateEndOfDay($data[$field]);
            }
        }

        // 발급기간: 날짜+시간 입력 (datetime-local) → 사이트 타임존 그대로 UTC 변환
        foreach (['issue_from', 'issue_to'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = TimezoneHelper::fromSiteDateTime($data[$field]);
            }
        }

        return $data;
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.coupon.name_required'),
            'discount_value.min' => __('sirsoft-ecommerce::validation.coupon.discount_value_rate_min'),
            'discount_value.max' => __('sirsoft-ecommerce::validation.coupon.discount_value_rate_max'),
            'valid_to.after_or_equal' => __('sirsoft-ecommerce::validation.coupon.valid_to_after_from'),
        ];
    }
}
