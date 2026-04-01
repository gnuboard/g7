<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 per_user_limit 검증 테스트
 *
 * per_user_limit 필드의 검증 규칙 변경을 검증합니다.
 * - 0: 무제한 (회원당 발급 제한 없음)
 * - 1 이상: 제한 (회원당 N회까지)
 */
class CouponPerUserLimitTest extends ModuleTestCase
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
     * @param array $overrides 오버라이드할 속성
     * @return array
     */
    private function validCouponData(array $overrides = []): array
    {
        return array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트 설명', 'en' => 'Test description'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 10,
            'min_order_amount' => 1000,
            'discount_max_amount' => 5000,
            'issue_method' => CouponIssueMethod::AUTO->value,
            'issue_condition' => CouponIssueCondition::FIRST_PURCHASE->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'total_quantity' => 100,
            'per_user_limit' => 1,
            'valid_type' => 'period',
            'valid_from' => now()->format('Y-m-d'),
            'valid_to' => now()->addMonth()->format('Y-m-d'),
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────
    // per_user_limit=0 (무제한) 테스트
    // ─────────────────────────────────────────────────────────

    /**
     * per_user_limit=0(무제한)으로 쿠폰을 생성할 수 있습니다.
     */
    public function test_store_coupon_with_per_user_limit_zero(): void
    {
        $data = $this->validCouponData(['per_user_limit' => 0]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_promotion_coupons', [
            'per_user_limit' => 0,
        ]);
    }

    /**
     * per_user_limit=양수로 쿠폰을 생성할 수 있습니다.
     */
    public function test_store_coupon_with_per_user_limit_positive(): void
    {
        $data = $this->validCouponData(['per_user_limit' => 3]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_promotion_coupons', [
            'per_user_limit' => 3,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // per_user_limit 검증 실패 테스트
    // ─────────────────────────────────────────────────────────

    /**
     * per_user_limit가 누락되면 검증 실패합니다.
     */
    public function test_store_coupon_fails_without_per_user_limit(): void
    {
        $data = $this->validCouponData();
        unset($data['per_user_limit']);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('per_user_limit');
    }

    /**
     * per_user_limit가 null이면 검증 실패합니다.
     */
    public function test_store_coupon_fails_with_null_per_user_limit(): void
    {
        $data = $this->validCouponData(['per_user_limit' => null]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('per_user_limit');
    }

    /**
     * per_user_limit가 음수이면 검증 실패합니다.
     */
    public function test_store_coupon_fails_with_negative_per_user_limit(): void
    {
        $data = $this->validCouponData(['per_user_limit' => -1]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('per_user_limit');
    }
}
