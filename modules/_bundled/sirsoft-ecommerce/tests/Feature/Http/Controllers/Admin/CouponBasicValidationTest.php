<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 기본정보 검증 — 최소주문금액 빈값 정규화 (A14 §1-b)
 *
 * min_order_amount/discount_max_amount/total_quantity 를 빈 문자열로 저장 시
 * cast 오류(NOT NULL 제약/500) 없이 정상 저장되는지 검증합니다. min_order_amount 는
 * NOT NULL default 0 컬럼이라 빈값 → 0("최소금액 없음") 정규화, discount_max_amount/
 * total_quantity 는 nullable 이라 빈값 → null 정규화입니다.
 */
class CouponBasicValidationTest extends ModuleTestCase
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
            'description' => ['ko' => '테스트 설명', 'en' => 'Test description'],
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
     * 생성: 최소주문금액 빈 문자열 → 저장 오류 없이 0 정규화 (NOT NULL default 0 컬럼)
     */
    public function test_store_empty_min_order_amount_normalizes_to_zero(): void
    {
        $data = $this->validCouponData([
            'min_order_amount' => '',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);

        $coupon = Coupon::find($response->json('data.id'));
        $this->assertEquals(0, $coupon->min_order_amount);
    }

    /**
     * 생성: 최소주문금액 null → 0 정규화 (NOT NULL default 0)
     */
    public function test_store_null_min_order_amount_is_valid(): void
    {
        $data = $this->validCouponData([
            'min_order_amount' => null,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
        $this->assertEquals(0, Coupon::find($response->json('data.id'))->min_order_amount);
    }

    /**
     * 생성: discount_max_amount/total_quantity 빈 문자열 → null 정규화 (회귀 방지)
     */
    public function test_store_empty_nullable_numerics_normalize_to_null(): void
    {
        $data = $this->validCouponData([
            'discount_max_amount' => '',
            'total_quantity' => '',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);

        $coupon = Coupon::find($response->json('data.id'));
        $this->assertNull($coupon->discount_max_amount);
        $this->assertNull($coupon->total_quantity);
    }

    /**
     * 생성: 정상 입력 비파괴 (min_order_amount=5000 유지)
     */
    public function test_store_valid_min_order_amount_preserved(): void
    {
        $data = $this->validCouponData([
            'min_order_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
        $this->assertEquals(5000, Coupon::find($response->json('data.id'))->min_order_amount);
    }

    /**
     * 수정: 최소주문금액 빈 문자열 → 저장 오류 없이 0 정규화
     */
    public function test_update_empty_min_order_amount_normalizes_to_zero(): void
    {
        $createResponse = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData());
        $createResponse->assertStatus(201);
        $couponId = $createResponse->json('data.id');

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'min_order_amount' => '',
                'per_user_limit' => 0,
            ]);

        $response->assertStatus(200);
        $this->assertEquals(0, Coupon::find($couponId)->min_order_amount);
    }
}
