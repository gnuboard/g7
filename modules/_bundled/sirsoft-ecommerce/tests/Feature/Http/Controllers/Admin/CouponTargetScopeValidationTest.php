<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 적용대상 미선택 저장 차단 (A16① / A17①)
 *
 * target_scope=products/categories 인데 include 대상이 0건이면 422 로 차단되는지 검증.
 * exclude 만 있으면 적용 대상 공집합이라 차단. all/미전송이면 비어도 통과(회귀 보존).
 */
class CouponTargetScopeValidationTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
            'sirsoft-ecommerce.promotion-coupon.update',
        ]);
    }

    /**
     * 쿠폰 생성 시 필요한 기본 데이터
     *
     * @param  array  $overrides  오버라이드할 속성
     */
    private function validCouponData(array $overrides = []): array
    {
        return array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 5000,
            'issue_method' => CouponIssueMethod::DIRECT->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 0,
            'valid_type' => 'period',
            'valid_from' => now()->format('Y-m-d'),
            'valid_to' => now()->addMonth()->format('Y-m-d'),
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
        ], $overrides);
    }

    /**
     * 쿠폰을 API 로 생성하고 ID 반환
     */
    private function createCouponViaApi(array $overrides = []): int
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData($overrides));
        $response->assertStatus(201);

        return $response->json('data.id');
    }

    private function postCoupon(array $overrides)
    {
        return $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData($overrides));
    }

    // ─────────────────────────────────────────────────────────
    // products scope (A16①)
    // ─────────────────────────────────────────────────────────

    /**
     * #1 store: scope=products + 상품 빈 배열 → 422
     */
    public function test_store_scope_products_empty_is_blocked(): void
    {
        $this->postCoupon([
            'target_scope' => CouponTargetScope::PRODUCTS->value,
            'products' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('products');
    }

    /**
     * #2 store: scope=products + exclude 만 → 422 (include 0건)
     */
    public function test_store_scope_products_exclude_only_is_blocked(): void
    {
        $product = Product::factory()->create();

        $this->postCoupon([
            'target_scope' => CouponTargetScope::PRODUCTS->value,
            'products' => [['id' => $product->id, 'type' => 'exclude']],
        ])->assertStatus(422)->assertJsonValidationErrors('products');
    }

    /**
     * #3 store: scope=products + include 1건 → 통과
     */
    public function test_store_scope_products_include_passes(): void
    {
        $product = Product::factory()->create();

        $this->postCoupon([
            'target_scope' => CouponTargetScope::PRODUCTS->value,
            'products' => [['id' => $product->id, 'type' => 'include']],
        ])->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────
    // categories scope (A17①)
    // ─────────────────────────────────────────────────────────

    private function createCategory(): Category
    {
        $category = Category::create([
            'name' => ['ko' => '카테고리', 'en' => 'Category'],
            'slug' => 'test-cat-'.uniqid(),
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
     * #4 store: scope=categories + 카테고리 빈 배열 → 422
     */
    public function test_store_scope_categories_empty_is_blocked(): void
    {
        $this->postCoupon([
            'target_scope' => CouponTargetScope::CATEGORIES->value,
            'categories' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('categories');
    }

    /**
     * #5 store: scope=categories + exclude 만 → 422
     */
    public function test_store_scope_categories_exclude_only_is_blocked(): void
    {
        $category = $this->createCategory();

        $this->postCoupon([
            'target_scope' => CouponTargetScope::CATEGORIES->value,
            'categories' => [['id' => $category->id, 'type' => 'exclude']],
        ])->assertStatus(422)->assertJsonValidationErrors('categories');
    }

    /**
     * #6 store: scope=categories + include 1건 → 통과
     */
    public function test_store_scope_categories_include_passes(): void
    {
        $category = $this->createCategory();

        $this->postCoupon([
            'target_scope' => CouponTargetScope::CATEGORIES->value,
            'categories' => [['id' => $category->id, 'type' => 'include']],
        ])->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────
    // 회귀 보존 (all / 미전송)
    // ─────────────────────────────────────────────────────────

    /**
     * #7 store: scope=all + 비어도 통과 (전체 적용 의도)
     */
    public function test_store_scope_all_empty_passes(): void
    {
        $this->postCoupon([
            'target_scope' => CouponTargetScope::ALL->value,
            'products' => [],
            'categories' => [],
        ])->assertStatus(201);
    }

    /**
     * #8 store: target_scope 미전송 → all 폴백 → 통과
     */
    public function test_store_scope_omitted_falls_back_to_all(): void
    {
        $data = $this->validCouponData();
        unset($data['target_scope']);

        $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data)
            ->assertStatus(201);
    }

    /**
     * #9 update: scope=products 로 변경 + 상품 미전송 → 422 차단
     */
    public function test_update_scope_products_without_products_is_blocked(): void
    {
        $couponId = $this->createCouponViaApi();

        $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'target_scope' => CouponTargetScope::PRODUCTS->value,
                'products' => [],
                'per_user_limit' => 0,
            ])->assertStatus(422)->assertJsonValidationErrors('products');
    }

    /**
     * #10 update: scope=products + include 1건 → 통과
     */
    public function test_update_scope_products_with_include_passes(): void
    {
        $couponId = $this->createCouponViaApi();
        $product = Product::factory()->create();

        $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'target_scope' => CouponTargetScope::PRODUCTS->value,
                'products' => [['id' => $product->id, 'type' => 'include']],
                'per_user_limit' => 0,
            ])->assertStatus(200);
    }
}
