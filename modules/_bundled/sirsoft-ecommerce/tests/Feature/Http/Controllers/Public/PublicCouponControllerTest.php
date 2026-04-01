<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 쿠폰 API Feature 테스트
 *
 * 상품별 다운로드 가능 쿠폰 조회 API를 테스트합니다.
 */
class PublicCouponControllerTest extends ModuleTestCase
{
    /**
     * 다운로드 가능한 쿠폰을 생성합니다.
     *
     * @param array $overrides 오버라이드할 속성
     * @return Coupon
     */
    protected function createDownloadableCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 다운로드 쿠폰', 'en' => 'Test Download Coupon'],
            'description' => ['ko' => '테스트 쿠폰 설명', 'en' => 'Test coupon description'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 10000,
            'issue_method' => CouponIssueMethod::DOWNLOAD,
            'issue_condition' => CouponIssueCondition::MANUAL,
            'issue_status' => CouponIssueStatus::ISSUING,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL,
            'valid_type' => 'period',
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
            'issue_from' => Carbon::now()->subDay(),
            'issue_to' => Carbon::now()->addMonth(),
            'per_user_limit' => 1,
            'total_quantity' => 100,
            'issued_count' => 0,
        ], $overrides));
    }

    // ========================================
    // 상품별 다운로드 가능 쿠폰 조회 (downloadableCoupons)
    // ========================================

    /**
     * target_scope=all인 쿠폰은 모든 상품에서 조회됩니다.
     */
    public function test_downloadable_coupons_with_scope_all(): void
    {
        // Given: target_scope=all 쿠폰과 상품
        $product = Product::factory()->onSale()->create();
        $this->createDownloadableCoupon(['target_scope' => CouponTargetScope::ALL]);

        // When: 상품의 다운로드 가능 쿠폰 조회
        $response = $this->getJson("/api/modules/sirsoft-ecommerce/products/{$product->id}/downloadable-coupons");

        // Then: 쿠폰이 반환됨
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
    }

    /**
     * 해당 없는 상품은 빈 배열이 반환됩니다.
     */
    public function test_downloadable_coupons_empty_for_non_matching_product(): void
    {
        // Given: target_scope=products이고 특정 상품만 포함하는 쿠폰
        $product1 = Product::factory()->onSale()->create();
        $product2 = Product::factory()->onSale()->create();
        $coupon = $this->createDownloadableCoupon(['target_scope' => CouponTargetScope::PRODUCTS]);
        $coupon->products()->attach($product1->id, ['type' => 'include']);

        // When: 포함되지 않은 product2의 다운로드 가능 쿠폰 조회
        $response = $this->getJson("/api/modules/sirsoft-ecommerce/products/{$product2->id}/downloadable-coupons");

        // Then: 빈 배열
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEmpty($data);
    }

    /**
     * 인증 시 is_downloaded 정보가 포함됩니다.
     */
    public function test_downloadable_coupons_include_is_downloaded_when_authenticated(): void
    {
        // Given: 인증된 사용자가 이미 다운로드한 쿠폰
        $user = $this->createUser();
        $product = Product::factory()->onSale()->create();
        $coupon = $this->createDownloadableCoupon();
        CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'DL-TEST1234',
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addMonth(),
        ]);

        // When: 인증 상태로 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/modules/sirsoft-ecommerce/products/{$product->id}/downloadable-coupons");

        // Then: is_downloaded=true
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        $this->assertTrue($data[0]['is_downloaded']);
    }

    /**
     * 비인증 시 is_downloaded=false로 반환됩니다.
     */
    public function test_downloadable_coupons_is_downloaded_false_when_unauthenticated(): void
    {
        // Given: 다운로드 가능 쿠폰과 상품
        $product = Product::factory()->onSale()->create();
        $this->createDownloadableCoupon();

        // When: 비인증 상태로 조회
        $response = $this->getJson("/api/modules/sirsoft-ecommerce/products/{$product->id}/downloadable-coupons");

        // Then: is_downloaded=false
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        $this->assertFalse($data[0]['is_downloaded']);
    }
}
