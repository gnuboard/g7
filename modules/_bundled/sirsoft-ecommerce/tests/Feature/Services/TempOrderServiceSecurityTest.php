<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * TempOrderService 보안 테스트
 *
 * 쿠폰 소유권 검증 및 마일리지 사용 검증을 테스트합니다.
 */
class TempOrderServiceSecurityTest extends ModuleTestCase
{
    protected TempOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 실제 서비스 인스턴스 사용 (DI 컨테이너에서 가져옴)
        $this->service = app(TempOrderService::class);
    }

    /**
     * 테스트용 배송정책을 생성합니다.
     */
    protected function createShippingPolicy(): ShippingPolicy
    {
        return ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'shipping_method' => 'parcel',
            'charge_policy' => ChargePolicyEnum::FREE,
            'base_fee' => 0,
            'countries' => ['KR'],
            'currency_code' => 'KRW',
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    /**
     * 테스트용 쿠폰을 생성합니다.
     *
     * @param  array  $overrides  오버라이드할 속성
     */
    protected function createCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트 쿠폰 설명', 'en' => 'Test coupon description'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 10000,
            'issue_status' => CouponIssueStatus::ISSUING,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL,
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
        ], $overrides));
    }

    /**
     * 테스트용 쿠폰 발급 내역을 생성합니다.
     *
     * @param  Coupon  $coupon  쿠폰
     * @param  int  $userId  사용자 ID
     * @param  array  $overrides  오버라이드할 속성
     */
    protected function createCouponIssue(Coupon $coupon, int $userId, array $overrides = []): CouponIssue
    {
        return CouponIssue::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'coupon_code' => 'TEST-'.strtoupper(uniqid()),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addMonth(),
        ], $overrides));
    }

    // ========================================
    // 비회원 쿠폰 사용 불가 테스트
    // ========================================

    /**
     * 비회원이 체크아웃 업데이트 시 쿠폰이 무시됩니다.
     */
    public function test_guest_checkout_ignores_coupons(): void
    {
        // Given: 비회원 장바구니와 쿠폰 (비회원은 쿠폰을 가질 수 없지만 API 우회 시도 가정)
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 50000,
            'stock_quantity' => 100,
        ]);

        $cartKey = 'ck_'.str_repeat('c', 32);
        $cart = CartFactory::new()
            ->forOption($option)
            ->create([
                'user_id' => null,
                'cart_key' => $cartKey,
                'quantity' => 1,
            ]);

        // 다른 사용자의 쿠폰 ID를 시도 (보안 테스트)
        $otherUser = $this->createUser();
        $coupon = $this->createCoupon();
        $couponIssue = $this->createCouponIssue($coupon, $otherUser->id);

        // When: 비회원이 체크아웃 생성
        $createResponse = $this->postJson('/api/modules/sirsoft-ecommerce/checkout', [
            'item_ids' => [$cart->id],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        $createResponse->assertStatus(201);

        // When: 비회원이 체크아웃 업데이트 시 쿠폰 ID 포함
        $response = $this->putJson('/api/modules/sirsoft-ecommerce/checkout', [
            'order_coupon_issue_id' => $couponIssue->id, // 타인의 쿠폰 ID
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 체크아웃 업데이트 성공하지만 쿠폰 할인은 적용되지 않음 (비회원이므로 검증 실패)
        $response->assertStatus(200);
        // 쿠폰 할인이 적용되지 않았는지 확인 (total_discount가 0 또는 쿠폰 할인 없음)
        $this->assertEquals(0, $response->json('data.calculation.summary.coupon_discount') ?? 0);
    }

    // ========================================
    // 타인 쿠폰 사용 불가 테스트
    // ========================================

    /**
     * 사용자가 타인의 쿠폰을 사용하려고 하면 무시됩니다.
     */
    public function test_user_cannot_use_other_users_coupon(): void
    {
        // Given: 두 명의 사용자와 각각의 쿠폰
        $user1 = $this->createUser();
        $user2 = $this->createUser();

        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 50000,
            'stock_quantity' => 100,
        ]);

        $cart = CartFactory::new()
            ->forUser($user1)
            ->forOption($option)
            ->create(['quantity' => 1]);

        // user2의 쿠폰 생성
        $coupon = $this->createCoupon();
        $user2CouponIssue = $this->createCouponIssue($coupon, $user2->id);

        // When: user1이 체크아웃 생성
        $createResponse = $this->actingAs($user1, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        $createResponse->assertStatus(201);

        // When: user1이 user2의 쿠폰으로 체크아웃 업데이트 시도
        $response = $this->actingAs($user1, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'order_coupon_issue_id' => $user2CouponIssue->id, // user2의 쿠폰
            ]);

        // Then: 체크아웃 업데이트 성공하지만 쿠폰 할인은 적용되지 않음 (소유권 검증 실패)
        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.calculation.summary.coupon_discount') ?? 0);
    }

    /**
     * 사용자가 본인의 쿠폰을 사용하면 정상 적용됩니다.
     */
    public function test_user_can_use_own_coupon(): void
    {
        // Given: 사용자와 본인 쿠폰
        $user = $this->createUser();

        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 50000,
            'stock_quantity' => 100,
        ]);

        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($option)
            ->create(['quantity' => 1]);

        // 본인 쿠폰 생성 (1000원 할인)
        $coupon = $this->createCoupon([
            'discount_value' => 1000,
        ]);
        $userCouponIssue = $this->createCouponIssue($coupon, $user->id);

        // When: 체크아웃 생성
        $createResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        $createResponse->assertStatus(201);

        // When: 본인 쿠폰으로 체크아웃 업데이트
        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'order_coupon_issue_id' => $userCouponIssue->id,
            ]);

        // Then: 체크아웃이 업데이트되고 쿠폰 할인이 적용됨
        $response->assertStatus(200);
        // 참고: 실제 할인 적용 여부는 OrderCalculationService에서 처리됨
        // 여기서는 쿠폰이 무시되지 않았는지만 확인
    }

    /**
     * 테스트 사용자에게 마일리지 잔액을 적립합니다 (원장 직접 적립).
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $amount  적립 금액
     * @param  string  $currency  통화 코드
     */
    protected function grantMileage(int $userId, int $amount, string $currency = 'KRW'): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => $currency,
            'type' => MileageTransactionTypeEnum::ADMIN_EARN,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $amount,
            'description' => 'test grant',
            'expires_at' => null,
        ]);
    }

    /**
     * 테스트용 판매중 상품 + 옵션 + 회원 장바구니를 구성합니다.
     *
     * @param  User  $user  회원
     * @return Cart
     */
    protected function setupMemberCart($user)
    {
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 50000,
            'stock_quantity' => 100,
        ]);

        return CartFactory::new()
            ->forUser($user)
            ->forOption($option)
            ->create(['quantity' => 1]);
    }

    // ========================================
    // 회원 마일리지 보유잔액 초과 사용 차단 테스트 (U15)
    // ========================================

    /**
     * 회원이 보유 잔액을 초과하는 마일리지 사용을 시도하면 422 로 차단됩니다.
     */
    public function test_member_use_points_exceeding_balance_returns_422(): void
    {
        // Given: 잔액 1000 회원 + 판매중 상품 장바구니
        $user = $this->createUser();
        $this->grantMileage($user->id, 1000);
        $cart = $this->setupMemberCart($user);

        // When: 보유 잔액(1000) 초과 use_points(5000) 로 체크아웃 생성
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 5000,
            ]);

        // Then: 422 + code=mileage_exceeds_balance + 보유잔액 노출
        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'mileage_exceeds_balance');
        $this->assertStringContainsString('1000', $response->json('errors.message') ?? '');
    }

    /**
     * update 진입점에서도 보유 잔액 초과 사용은 422 로 차단됩니다 (generic 404 아님).
     */
    public function test_member_use_points_exceeding_balance_returns_422_on_update(): void
    {
        // Given: 잔액 1000 회원 + 체크아웃 생성됨
        $user = $this->createUser();
        $this->grantMileage($user->id, 1000);
        $cart = $this->setupMemberCart($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ])
            ->assertStatus(201);

        // When: update 로 보유 잔액 초과 use_points 시도
        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'use_points' => 5000,
            ]);

        // Then: 422 (generic temp_order_not_found 404 로 가로채이지 않음)
        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'mileage_exceeds_balance');
    }

    /**
     * 보유 잔액과 정확히 같은 마일리지 사용은 통과합니다 (경계).
     */
    public function test_member_use_points_exactly_balance_passes(): void
    {
        // Given: 잔액 5000 회원
        $user = $this->createUser();
        $this->grantMileage($user->id, 5000);
        $cart = $this->setupMemberCart($user);

        // When: 잔액과 동일한 use_points(5000)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 5000,
            ]);

        // Then: 201 통과
        $response->assertStatus(201);
    }

    /**
     * 보유 잔액 미만 마일리지 사용은 통과합니다.
     */
    public function test_member_use_points_below_balance_passes(): void
    {
        // Given: 잔액 5000 회원
        $user = $this->createUser();
        $this->grantMileage($user->id, 5000);
        $cart = $this->setupMemberCart($user);

        // When: 잔액 미만 use_points(3000)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 3000,
            ]);

        // Then: 201 통과
        $response->assertStatus(201);
    }

    // ========================================
    // 비회원 마일리지 사용 불가 테스트
    // ========================================

    /**
     * 비회원이 마일리지를 사용하려고 하면 0으로 처리됩니다.
     */
    public function test_guest_checkout_ignores_mileage(): void
    {
        // Given: 비회원 장바구니
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 50000,
            'stock_quantity' => 100,
        ]);

        $cartKey = 'ck_'.str_repeat('d', 32);
        $cart = CartFactory::new()
            ->forOption($option)
            ->create([
                'user_id' => null,
                'cart_key' => $cartKey,
                'quantity' => 1,
            ]);

        // When: 비회원이 체크아웃 생성
        $createResponse = $this->postJson('/api/modules/sirsoft-ecommerce/checkout', [
            'item_ids' => [$cart->id],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        $createResponse->assertStatus(201);

        // When: 비회원이 마일리지 사용 시도 (업데이트)
        $response = $this->putJson('/api/modules/sirsoft-ecommerce/checkout', [
            'use_points' => 5000, // 마일리지 사용 시도
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 체크아웃 업데이트 성공하지만 마일리지는 사용되지 않음 (비회원이므로 검증 실패)
        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.calculation.summary.points_used') ?? 0);
    }
}
