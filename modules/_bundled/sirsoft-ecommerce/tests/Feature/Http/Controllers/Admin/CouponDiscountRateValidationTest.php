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
 * 쿠폰 할인율(rate) 검증 테스트
 *
 * discount_type이 'rate'일 때 discount_value가 1~100 범위인지 검증합니다.
 * discount_type이 'fixed'일 때는 min:0만 적용됩니다.
 */
class CouponDiscountRateValidationTest extends ModuleTestCase
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

    // ─────────────────────────────────────────────────────────
    // 정액(fixed) 할인 테스트
    // ─────────────────────────────────────────────────────────

    /**
     * 정액 할인: discount_value=0 허용
     */
    public function test_store_fixed_discount_value_zero_is_valid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 0,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
    }

    /**
     * 정액 할인: 음수 금액 거부
     */
    public function test_store_fixed_discount_negative_is_invalid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => -100,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    /**
     * 정액 할인: 큰 금액 허용 (상한 없음)
     */
    public function test_store_fixed_discount_large_value_is_valid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 50000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────
    // 정률(rate) 할인 - 생성 테스트
    // ─────────────────────────────────────────────────────────

    /**
     * 정률 할인: discount_value=50 (정상 범위)
     */
    public function test_store_rate_discount_value_50_is_valid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 50,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
    }

    /**
     * 정률 할인: discount_value=1 (최솟값 경계)
     */
    public function test_store_rate_discount_value_1_is_valid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 1,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
    }

    /**
     * 정률 할인: discount_value=100 (최댓값 경계)
     */
    public function test_store_rate_discount_value_100_is_valid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 100,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
    }

    /**
     * 정률 할인: discount_value=0 거부 (최솟값 미달)
     */
    public function test_store_rate_discount_value_zero_is_invalid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 0,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    /**
     * 정률 할인: discount_value=101 거부 (최댓값 초과)
     */
    public function test_store_rate_discount_value_101_is_invalid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 101,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    /**
     * 정률 할인: 음수값 거부
     */
    public function test_store_rate_discount_negative_is_invalid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => -5,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    /**
     * 정률 할인: 3000% 거부 (시안 사례)
     */
    public function test_store_rate_discount_value_3000_is_invalid(): void
    {
        $data = $this->validCouponData([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 3000,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    // ─────────────────────────────────────────────────────────
    // 정률(rate) 할인 - 수정 테스트
    // ─────────────────────────────────────────────────────────

    /**
     * API를 통해 쿠폰을 생성하고 ID를 반환합니다.
     *
     * @param array $overrides 오버라이드할 속성
     * @return int 생성된 쿠폰 ID
     */
    private function createCouponViaApi(array $overrides = []): int
    {
        $data = $this->validCouponData($overrides);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);

        return $response->json('data.id');
    }

    /**
     * 수정: 정률 할인 정상 범위 허용
     */
    public function test_update_rate_discount_value_50_is_valid(): void
    {
        $couponId = $this->createCouponViaApi([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 10,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'discount_type' => CouponDiscountType::RATE->value,
                'discount_value' => 50,
                'per_user_limit' => 0,
            ]);

        $response->assertStatus(200);
    }

    /**
     * 수정: 정률 할인 101% 거부
     */
    public function test_update_rate_discount_value_101_is_invalid(): void
    {
        $couponId = $this->createCouponViaApi([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 10,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'discount_type' => CouponDiscountType::RATE->value,
                'discount_value' => 101,
                'per_user_limit' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    /**
     * 수정: 정률 할인 0% 거부
     */
    public function test_update_rate_discount_value_zero_is_invalid(): void
    {
        $couponId = $this->createCouponViaApi([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 10,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'discount_type' => CouponDiscountType::RATE->value,
                'discount_value' => 0,
                'per_user_limit' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    /**
     * 수정: 정액으로 변경 시 큰 금액 허용
     */
    public function test_update_change_to_fixed_allows_large_value(): void
    {
        $couponId = $this->createCouponViaApi([
            'discount_type' => CouponDiscountType::RATE->value,
            'discount_value' => 10,
            'discount_max_amount' => 5000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'discount_type' => CouponDiscountType::FIXED->value,
                'discount_value' => 50000,
                'per_user_limit' => 0,
            ]);

        $response->assertStatus(200);
    }
}
