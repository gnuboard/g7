<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin\Concerns;

use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;

/**
 * 쿠폰 적용대상(상품/카테고리) 미선택 저장 차단 검증 trait
 *
 * target_scope 가 products/categories 인데 include 타입 대상이 0건이면 검증 오류를 추가합니다.
 * (exclude 만 있으면 적용 대상 공집합이라 무의미하므로 차단)
 */
trait ValidatesCouponTargetScope
{
    /**
     * target_scope 기준 적용대상 선택 필수 검증을 등록합니다.
     *
     * @param  Validator  $validator  검증기 인스턴스
     */
    protected function validateTargetScopeSelection(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $scope = $this->input('target_scope', CouponTargetScope::ALL->value);

            if ($scope === CouponTargetScope::PRODUCTS->value
                && ! $this->hasIncludeEntry($this->input('products', []))) {
                $validator->errors()->add(
                    'products',
                    __('sirsoft-ecommerce::validation.coupon.target_products_required')
                );
            }

            if ($scope === CouponTargetScope::CATEGORIES->value
                && ! $this->hasIncludeEntry($this->input('categories', []))) {
                $validator->errors()->add(
                    'categories',
                    __('sirsoft-ecommerce::validation.coupon.target_categories_required')
                );
            }
        });
    }

    /**
     * 배열에 type=include 항목이 1건 이상 있는지 확인합니다.
     *
     * @param  mixed  $entries  products/categories 입력 배열
     * @return bool include 항목 존재 여부
     */
    private function hasIncludeEntry(mixed $entries): bool
    {
        if (! is_array($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'include') {
                return true;
            }
        }

        return false;
    }
}
