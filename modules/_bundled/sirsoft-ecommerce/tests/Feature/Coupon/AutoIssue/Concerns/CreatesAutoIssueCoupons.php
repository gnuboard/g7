<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;

/**
 * 자동발급 쿠폰 테스트 픽스처 (#952)
 */
trait CreatesAutoIssueCoupons
{
    /**
     * 자동발급 쿠폰을 만듭니다.
     *
     * @param  CouponIssueCondition  $condition  발급 조건
     * @param  array<string, mixed>  $overrides  덮을 속성
     * @return Coupon 쿠폰
     */
    protected function makeAutoCoupon(CouponIssueCondition $condition, array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '자동 쿠폰', 'en' => 'Auto Coupon'],
            'target_type' => CouponTargetType::ORDER_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 3000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'issue_method' => CouponIssueMethod::AUTO,
            'issue_condition' => $condition,
            'issue_status' => CouponIssueStatus::ISSUING,
            'per_user_limit' => 0,
            'total_quantity' => null,
            'issued_count' => 0,
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
        ], $overrides));
    }

    /**
     * 다운로드 쿠폰을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮을 속성
     * @return Coupon 쿠폰
     */
    protected function makeDownloadCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '다운로드 쿠폰', 'en' => 'Download Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'issue_method' => CouponIssueMethod::DOWNLOAD,
            'issue_condition' => CouponIssueCondition::MANUAL,
            'issue_status' => CouponIssueStatus::ISSUING,
            'per_user_limit' => 1,
            'total_quantity' => 100,
            'issued_count' => 0,
            'valid_type' => 'period',
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
        ], $overrides));
    }
}
