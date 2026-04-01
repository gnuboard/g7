<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CouponResource 카테고리 path 필드 테스트
 *
 * 쿠폰 상세 조회 시 included_categories/excluded_categories에
 * 로케일 브레드크럼 경로(path)가 포함되는지 검증
 */
class CouponResourceCategoryPathTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 한국어 로케일로 설정 (getLocalizedName 검증)
        app()->setLocale('ko');

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
            'sirsoft-ecommerce.promotion-coupon.update',
        ]);
    }

    /**
     * 테스트용 카테고리 트리 생성
     *
     * @return array{root: Category, child: Category, grandchild: Category}
     */
    private function createCategoryTree(): array
    {
        $root = Category::create([
            'name' => ['ko' => '의류', 'en' => 'Clothing'],
            'slug' => 'test-clothing-' . uniqid(),
            'is_active' => true,
            'sort_order' => 0,
            'depth' => 0,
            'path' => '0', // 임시 — 생성 후 갱신
        ]);
        $root->generatePath();
        $root->save();

        $child = Category::create([
            'name' => ['ko' => '남성', 'en' => 'Men'],
            'slug' => 'test-men-' . uniqid(),
            'parent_id' => $root->id,
            'is_active' => true,
            'sort_order' => 0,
            'depth' => 1,
            'path' => '0', // 임시 — 생성 후 갱신
        ]);
        $child->generatePath();
        $child->save();

        $grandchild = Category::create([
            'name' => ['ko' => '티셔츠', 'en' => 'T-Shirts'],
            'slug' => 'test-tshirts-' . uniqid(),
            'parent_id' => $child->id,
            'is_active' => true,
            'sort_order' => 0,
            'depth' => 2,
            'path' => '0', // 임시 — 생성 후 갱신
        ]);
        $grandchild->generatePath();
        $grandchild->save();

        return compact('root', 'child', 'grandchild');
    }

    /**
     * 테스트용 쿠폰 생성
     *
     * @param array $attributes 오버라이드할 속성
     * @return Coupon
     */
    private function createCoupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'code' => 'TEST' . uniqid(),
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'target_scope' => CouponTargetScope::CATEGORIES->value,
            'max_issues' => 100,
            'max_issues_per_user' => 1,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'is_active' => true,
        ], $attributes));
    }

    // ─────────────────────────────────────────────────────────

    /**
     * included_categories에 로케일 브레드크럼 path가 포함되는지 검증
     *
     * @return void
     */
    public function test_included_categories_have_localized_breadcrumb_path(): void
    {
        $categories = $this->createCategoryTree();
        $coupon = $this->createCoupon();

        // 3뎁스 카테고리를 포함 카테고리로 연결
        $coupon->categories()->attach($categories['grandchild']->id, ['type' => 'include']);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}");

        $response->assertOk();

        $includedCategories = $response->json('data.included_categories');

        $this->assertNotEmpty($includedCategories);
        $this->assertCount(1, $includedCategories);

        $category = $includedCategories[0];

        // path 필드가 존재하는지 확인
        $this->assertArrayHasKey('path', $category);

        // 로케일 브레드크럼 형식: "› " 구분자로 전체 뎁스 경로
        $this->assertStringContainsString(' › ', $category['path']);

        // 3뎁스이므로 구분자가 2개 있어야 함
        $this->assertEquals(2, substr_count($category['path'], ' › '));

        // name 필드가 비어있지 않은지 확인 (로케일에 따라 한국어 또는 영어)
        $this->assertNotEmpty($category['name']);

        // products_count 필드 존재 확인
        $this->assertArrayHasKey('products_count', $category);
    }

    /**
     * excluded_categories에도 동일한 path 형식 적용 검증
     *
     * @return void
     */
    public function test_excluded_categories_have_localized_breadcrumb_path(): void
    {
        $categories = $this->createCategoryTree();
        $coupon = $this->createCoupon();

        // 2뎁스 카테고리를 제외 카테고리로 연결
        $coupon->categories()->attach($categories['child']->id, ['type' => 'exclude']);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}");

        $response->assertOk();

        $excludedCategories = $response->json('data.excluded_categories');

        $this->assertNotEmpty($excludedCategories);

        $category = $excludedCategories[0];

        // 2뎁스 경로: 구분자가 1개
        $this->assertStringContainsString(' › ', $category['path']);
        $this->assertEquals(1, substr_count($category['path'], ' › '));
        $this->assertNotEmpty($category['name']);
    }

    /**
     * 루트 카테고리는 path가 이름만 표시되는지 검증
     *
     * @return void
     */
    public function test_root_category_path_shows_only_name(): void
    {
        $categories = $this->createCategoryTree();
        $coupon = $this->createCoupon();

        // 루트 카테고리를 포함으로 연결
        $coupon->categories()->attach($categories['root']->id, ['type' => 'include']);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}");

        $response->assertOk();

        $includedCategories = $response->json('data.included_categories');
        $category = $includedCategories[0];

        // 루트 카테고리는 구분자 없이 이름만
        $this->assertNotEmpty($category['path']);
        $this->assertStringNotContainsString('›', $category['path']);
    }
}
