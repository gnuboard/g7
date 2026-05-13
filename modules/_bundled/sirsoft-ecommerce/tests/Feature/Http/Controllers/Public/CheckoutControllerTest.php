<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CheckoutController Feature 테스트
 *
 * 체크아웃 API를 테스트합니다.
 */
class CheckoutControllerTest extends ModuleTestCase
{
    /**
     * 테스트용 배송정책을 생성합니다.
     *
     * @return ShippingPolicy
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
     * 테스트용 상품과 옵션을 생성합니다.
     *
     * @return array{product: Product, option: ProductOption}
     */
    protected function createProductWithOption(): array
    {
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 100,
        ]);

        return ['product' => $product, 'option' => $option];
    }

    /**
     * 테스트용 쿠폰을 생성합니다.
     *
     * @param array $overrides 오버라이드할 속성
     * @return Coupon
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
     * @param Coupon $coupon 쿠폰
     * @param int $userId 사용자 ID
     * @param array $overrides 오버라이드할 속성
     * @return CouponIssue
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
    // 체크아웃 생성 테스트 (store)
    // ========================================

    /**
     * 인증된 사용자가 체크아웃을 생성할 수 있습니다.
     */
    public function test_authenticated_user_can_create_checkout(): void
    {
        // Given: 인증된 사용자와 장바구니 아이템
        $user = $this->createUser();
        $data = $this->createProductWithOption();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 2]);

        // When: 체크아웃 생성
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        // Then: 201 Created 및 임시 주문 정보 반환
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'temp_order_id',
                'calculation',
                'expires_at',
            ],
        ]);
    }

    /**
     * 비회원도 체크아웃을 생성할 수 있습니다.
     */
    public function test_guest_can_create_checkout(): void
    {
        // Given: 비회원 장바구니 아이템
        $data = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('a', 32);
        $cart = CartFactory::new()
            ->forOption($data['option'])
            ->create([
                'user_id' => null,
                'cart_key' => $cartKey,
                'quantity' => 1,
            ]);

        // When: 체크아웃 생성
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/checkout', [
            'item_ids' => [$cart->id],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 201 Created
        $response->assertStatus(201);
    }

    // ========================================
    // 체크아웃 조회 테스트 (show) - available_coupons 응답 확인
    // ========================================

    /**
     * 인증된 사용자가 체크아웃을 조회하면 available_coupons가 포함됩니다.
     */
    public function test_authenticated_user_checkout_includes_available_coupons(): void
    {
        // Given: 인증된 사용자, 장바구니, 임시 주문, 쿠폰
        $user = $this->createUser();
        $data = $this->createProductWithOption();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        // 체크아웃 생성
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        // 쿠폰 발급
        $coupon = $this->createCoupon();
        $this->createCouponIssue($coupon, $user->id);

        // When: 체크아웃 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/checkout');

        // Then: available_coupons가 포함됨
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'temp_order_id',
                'items',
                'calculation',
                'available_coupons',
                'mileage',
                'expires_at',
            ],
        ]);
    }

    /**
     * 비회원이 체크아웃을 조회하면 available_coupons가 빈 배열입니다.
     */
    public function test_guest_checkout_has_empty_available_coupons(): void
    {
        // Given: 비회원 장바구니와 임시 주문
        $data = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('b', 32);
        $cart = CartFactory::new()
            ->forOption($data['option'])
            ->create([
                'user_id' => null,
                'cart_key' => $cartKey,
                'quantity' => 1,
            ]);

        // 체크아웃 생성
        $this->postJson('/api/modules/sirsoft-ecommerce/checkout', [
            'item_ids' => [$cart->id],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // When: 체크아웃 조회
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/checkout', [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: available_coupons가 빈 배열
        $response->assertStatus(200);
        $response->assertJsonPath('data.available_coupons', []);
    }

    // ========================================
    // 체크아웃 업데이트 테스트 (update)
    // ========================================

    /**
     * 인증된 사용자가 체크아웃을 업데이트할 수 있습니다.
     */
    public function test_authenticated_user_can_update_checkout(): void
    {
        // Given: 인증된 사용자와 임시 주문
        $user = $this->createUser();
        $data = $this->createProductWithOption();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        // 체크아웃 생성
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        // When: 체크아웃 업데이트 (마일리지 사용)
        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'use_points' => 0,
                'coupon_issue_ids' => [],
            ]);

        // Then: 200 OK 및 재계산된 정보 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'temp_order_id',
                'calculation',
                'available_coupons',
                'mileage',
                'expires_at',
            ],
        ]);
    }

    /**
     * 업데이트 시에도 available_coupons가 포함됩니다.
     * available_coupons는 주문/배송비 쿠폰만 포함하며, min_order_amount 조건도 필터링됩니다.
     */
    public function test_update_checkout_includes_available_coupons(): void
    {
        // Given: 인증된 사용자, 임시 주문, 쿠폰
        $user = $this->createUser();
        $data = $this->createProductWithOption();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        // 체크아웃 생성
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        // 주문 쿠폰 발급 (min_order_amount = 0으로 설정하여 조건 충족)
        $orderCoupon = $this->createCoupon([
            'target_type' => CouponTargetType::ORDER_AMOUNT,
            'min_order_amount' => 0,
        ]);
        $this->createCouponIssue($orderCoupon, $user->id);

        // When: 체크아웃 업데이트
        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'use_points' => 0,
            ]);

        // Then: available_coupons가 포함되고 주문 쿠폰이 있음
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.available_coupons'));
    }

    // ========================================
    // 체크아웃 삭제 테스트 (destroy)
    // ========================================

    /**
     * 인증된 사용자가 체크아웃을 삭제할 수 있습니다.
     */
    public function test_authenticated_user_can_delete_checkout(): void
    {
        // Given: 인증된 사용자와 임시 주문
        $user = $this->createUser();
        $data = $this->createProductWithOption();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        // 체크아웃 생성
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        // When: 체크아웃 삭제
        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/modules/sirsoft-ecommerce/checkout');

        // Then: 200 OK
        $response->assertStatus(200);
    }

    // ========================================
    // 체크아웃 연장 테스트 (extend)
    // ========================================

    /**
     * 인증된 사용자가 체크아웃 만료 시간을 연장할 수 있습니다.
     */
    public function test_authenticated_user_can_extend_checkout(): void
    {
        // Given: 인증된 사용자와 임시 주문
        $user = $this->createUser();
        $data = $this->createProductWithOption();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        // 체크아웃 생성
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        // When: 체크아웃 연장
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout/extend');

        // Then: 200 OK 및 새로운 만료 시간 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'expires_at',
            ],
        ]);
    }
}
