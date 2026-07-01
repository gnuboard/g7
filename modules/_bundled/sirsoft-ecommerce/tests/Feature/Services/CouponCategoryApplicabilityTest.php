<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 카테고리 쿠폰 적용가능 판정 — 카테고리 외 상품 차단 (A17③)
 *
 * target_scope=categories 쿠폰이 카테고리 교집합 기준으로 정확히 적용 판정되는지 검증.
 */
class CouponCategoryApplicabilityTest extends ModuleTestCase
{
    private UserCouponService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UserCouponService::class);
    }

    private function createCategory(): Category
    {
        $category = Category::create([
            'name' => ['ko' => '카테고리', 'en' => 'Category'],
            'slug' => 'cat-'.uniqid(),
            'is_active' => true,
            'sort_order' => 0,
            'depth' => 0,
            'path' => '0',
        ]);
        $category->generatePath();
        $category->save();

        return $category;
    }

    /**
     * 쿠폰 생성 + 카테고리 동기화
     *
     * @param  array<int, array{id:int, type:string}>  $categories
     */
    private function createCoupon(string $scope, array $categories = []): Coupon
    {
        $coupon = Coupon::create([
            'name' => ['ko' => '쿠폰', 'en' => 'Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_method' => CouponIssueMethod::DOWNLOAD->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 0,
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
            'is_combinable' => true,
            'target_scope' => $scope,
        ]);

        foreach ($categories as $cat) {
            $coupon->categories()->attach($cat['id'], ['type' => $cat['type']]);
        }

        return $coupon->fresh(['includedCategories', 'excludedCategories']);
    }

    /**
     * #1 categories scope, 상품 카테고리가 include 와 교집합 → true
     */
    public function test_applicable_when_category_in_include(): void
    {
        $cat = $this->createCategory();
        $coupon = $this->createCoupon(CouponTargetScope::CATEGORIES->value, [['id' => $cat->id, 'type' => 'include']]);

        $this->assertTrue($this->service->isCouponApplicableToProduct($coupon, 999, [$cat->id]));
    }

    /**
     * #2 categories scope, 상품 카테고리가 include 와 교집합 없음 → false (수정 전 true=버그)
     */
    public function test_not_applicable_when_category_not_in_include(): void
    {
        $included = $this->createCategory();
        $other = $this->createCategory();
        $coupon = $this->createCoupon(CouponTargetScope::CATEGORIES->value, [['id' => $included->id, 'type' => 'include']]);

        $this->assertFalse($this->service->isCouponApplicableToProduct($coupon, 999, [$other->id]));
    }

    /**
     * #3 categories scope, exclude 교집합 → false
     */
    public function test_not_applicable_when_category_in_exclude(): void
    {
        $cat = $this->createCategory();
        $coupon = $this->createCoupon(CouponTargetScope::CATEGORIES->value, [
            ['id' => $cat->id, 'type' => 'include'],
            ['id' => $cat->id, 'type' => 'exclude'],
        ]);

        $this->assertFalse($this->service->isCouponApplicableToProduct($coupon, 999, [$cat->id]));
    }

    /**
     * #4 categories scope, 상품 카테고리 없음 → false
     */
    public function test_not_applicable_when_product_has_no_categories(): void
    {
        $cat = $this->createCategory();
        $coupon = $this->createCoupon(CouponTargetScope::CATEGORIES->value, [['id' => $cat->id, 'type' => 'include']]);

        $this->assertFalse($this->service->isCouponApplicableToProduct($coupon, 999, []));
    }

    /**
     * #5 all scope → 항상 true (회귀 보존)
     */
    public function test_scope_all_always_applicable(): void
    {
        $coupon = $this->createCoupon(CouponTargetScope::ALL->value);

        $this->assertTrue($this->service->isCouponApplicableToProduct($coupon, 999, []));
    }

    /**
     * 통합: getProductCouponsGrouped 가 카테고리 외 상품에 쿠폰을 노출하지 않는다.
     */
    public function test_grouped_excludes_coupon_for_non_matching_category_product(): void
    {
        $included = $this->createCategory();
        $other = $this->createCategory();

        $matchingProduct = Product::factory()->create();
        $matchingProduct->categories()->attach($included->id);
        $nonMatchingProduct = Product::factory()->create();
        $nonMatchingProduct->categories()->attach($other->id);

        $coupon = $this->createCoupon(CouponTargetScope::CATEGORIES->value, [['id' => $included->id, 'type' => 'include']]);

        // 회원에게 쿠폰 발급(다운로드)
        $user = User::factory()->create();
        $this->service->downloadCoupon($user->id, $coupon->id);

        $grouped = $this->service->getProductCouponsGrouped(
            $user->id,
            [$matchingProduct->id, $nonMatchingProduct->id]
        );

        $this->assertNotEmpty($grouped[$matchingProduct->id], '매칭 카테고리 상품엔 쿠폰 노출');
        $this->assertEmpty($grouped[$nonMatchingProduct->id], '비매칭 카테고리 상품엔 쿠폰 미노출');
    }
}
