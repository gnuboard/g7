<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CouponController::issues() Feature 테스트
 *
 * 쿠폰 발급 내역 조회 API 엔드포인트 테스트
 * Issue #74: CouponIssuesListRequest의 Rule::exists(User::class, 'id') 검증
 */
class CouponIssuesListTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
        ]);
    }

    /**
     * 테스트용 쿠폰 생성 헬퍼
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
            'max_discount_amount' => null,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'target_scope' => CouponTargetScope::ALL->value,
            'max_issues' => 100,
            'max_issues_per_user' => 1,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * 테스트용 쿠폰 발급 내역 생성 헬퍼
     *
     * @param Coupon $coupon 쿠폰
     * @param User $user 사용자
     * @param array $attributes 오버라이드할 속성
     * @return CouponIssue
     */
    private function createCouponIssue(Coupon $coupon, User $user, array $attributes = []): CouponIssue
    {
        return CouponIssue::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE->value,
            'issued_at' => now(),
        ], $attributes));
    }

    // ────────────────────────────────────────────────────────
    // 목록 조회 테스트
    // ────────────────────────────────────────────────────────

    /**
     * 쿠폰 발급 내역 조회 성공 테스트
     */
    public function test_issues_returns_paginated_list(): void
    {
        $coupon = $this->createCoupon();
        $user = $this->createUser();
        $this->createCouponIssue($coupon, $user);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issues");

        $response->assertStatus(200);
    }

    /**
     * user_id 필터 검증 - 존재하는 사용자 ID 필터링
     * (Rule::exists(User::class, 'id') 검증)
     */
    public function test_issues_filters_by_valid_user_id(): void
    {
        $coupon = $this->createCoupon();
        $user1 = $this->createUser();
        $user2 = $this->createUser();
        $this->createCouponIssue($coupon, $user1);
        $this->createCouponIssue($coupon, $user2);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issues?user_id={$user1->id}");

        $response->assertStatus(200);
    }

    /**
     * 존재하지 않는 user_id로 필터링 시 검증 실패 테스트
     * (Rule::exists(User::class, 'id')가 올바르게 동작하는지 검증)
     */
    public function test_issues_fails_with_nonexistent_user_id(): void
    {
        $coupon = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issues?user_id=99999");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    /**
     * status 필터 검증
     */
    public function test_issues_filters_by_status(): void
    {
        $coupon = $this->createCoupon();
        $user = $this->createUser();
        $this->createCouponIssue($coupon, $user, [
            'status' => CouponIssueRecordStatus::AVAILABLE->value,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issues?status=" . CouponIssueRecordStatus::AVAILABLE->value);

        $response->assertStatus(200);
    }

    /**
     * per_page 파라미터 검증 - 범위 초과
     */
    public function test_issues_fails_with_invalid_per_page(): void
    {
        $coupon = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issues?per_page=200");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['per_page']);
    }

    // ────────────────────────────────────────────────────────
    // 인증 테스트
    // ────────────────────────────────────────────────────────

    /**
     * 미인증 사용자 접근 거부 테스트
     */
    public function test_unauthenticated_user_gets_401(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons/1/issues');
        $response->assertStatus(401);
    }
}
