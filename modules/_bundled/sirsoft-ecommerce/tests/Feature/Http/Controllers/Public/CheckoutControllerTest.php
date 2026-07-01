<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

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
use Modules\Sirsoft\Ecommerce\Models\Cart;
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
     * 국가별로 서로 다른 고정 배송비를 가진 배송정책을 생성합니다.
     *
     * 배송국가 차등 배송비 회귀 테스트용 — KR/US 두 country_setting 을 둡니다.
     *
     * @param  int  $krFee  KR 고정 배송비
     * @param  int  $usFee  US 고정 배송비
     * @return array{product: Product, option: ProductOption, policy: ShippingPolicy}
     */
    protected function createProductWithCountryFees(int $krFee, int $usFee): array
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '국가별 배송정책', 'en' => 'Per-Country Policy'],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => $krFee,
            'is_active' => true,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'US',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => $usFee,
            'is_active' => true,
        ]);

        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $policy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 100,
        ]);

        return ['product' => $product, 'option' => $option, 'policy' => $policy->load('countrySettings')];
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
    // 배송국가 차등 배송비 (country_code → 계산 반영) 회귀
    // ========================================

    /**
     * GET /checkout 에 country_code=US 만 전달해도(우편번호 없이) US 배송비가 계산되어야 합니다.
     *
     * 회귀: 기존에는 show() 가 zipcode/region 이 있을 때만 ShippingAddress 를 생성해
     * country_code 단독 전달이 무시되고 KR 폴백(KR 배송비)이 적용되었습니다.
     * 배송비 "미리보기"는 우편번호 없이 배송국가만으로도 해당 국가 배송비를 보여줘야 합니다.
     */
    public function test_show_applies_destination_country_fee_when_only_country_code_given(): void
    {
        // Given: KR=3000, US=2000 인 배송정책 + 그 상품을 담은 임시 주문
        $user = $this->createUser();
        $data = $this->createProductWithCountryFees(krFee: 3000, usFee: 2000);
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ])->assertStatus(201);

        // When: 우편번호 없이 country_code=US 만 전달해 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/checkout?country_code=US');

        // Then: US 배송비(2000)가 적용되어야 함 (KR 폴백 3000 이면 회귀)
        $response->assertStatus(200);
        $response->assertJsonPath('data.calculation.summary.total_shipping', 2000);
        $response->assertJsonPath(
            'data.calculation.items.0.applied_shipping_policy.country_code',
            'US'
        );
    }

    /**
     * country_code 미전달 시에는 기본 국가(KR) 배송비가 적용됩니다 (기존 동작 보존).
     */
    public function test_show_defaults_to_kr_fee_when_no_country_code(): void
    {
        $user = $this->createUser();
        $data = $this->createProductWithCountryFees(krFee: 3000, usFee: 2000);
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ])->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/checkout');

        $response->assertStatus(200);
        $response->assertJsonPath('data.calculation.summary.total_shipping', 3000);
    }

    /**
     * PUT /checkout 에 country_code=US 를 전달하면 US 배송비로 재계산되어야 합니다.
     *
     * 주문서에서 배송국가 Select 를 바꾸는 경로(업데이트 재계산)의 백엔드 계약.
     */
    public function test_update_recalculates_with_destination_country_fee(): void
    {
        $user = $this->createUser();
        $data = $this->createProductWithCountryFees(krFee: 3000, usFee: 2000);
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ])->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'country_code' => 'US',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.calculation.summary.total_shipping', 2000);
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

    // ========================================
    // 구매수량 한도 초과 체크아웃 차단 (A25 — 모달 데이터 계약)
    // ========================================

    /**
     * 최대 구매수량 초과 체크아웃 시 unavailable_items 에 모달 표시용 필드가 모두 포함됩니다.
     */
    public function test_checkout_max_qty_unavailable_item_includes_modal_fields(): void
    {
        // Given: 최대 3개 상품, 장바구니 6개
        $user = $this->createUser();
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
            'min_purchase_qty' => 1,
            'max_purchase_qty' => 3,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 100,
        ]);
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($option)
            ->create(['quantity' => 6]);

        // When: 체크아웃 생성 시도
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ]);

        // Then: 4xx + max_qty 사유 + 모달이 쓰는 name/limit/requested 포함
        $response->assertStatus(400);
        $response->assertJsonPath('errors.code', 'cart_unavailable');
        $this->assertTrue($response->json('errors.has_max_qty_issue'));

        $item = $response->json('errors.unavailable_items.0');
        $this->assertSame('max_qty', $item['reason']);
        $this->assertSame(3, $item['limit']);
        $this->assertSame(6, $item['requested']);
        $this->assertNotEmpty($item['name']);
        // 사용자용 구체 메시지(errors.message)도 한도/요청 치환
        $this->assertStringContainsString('3', $response->json('errors.message'));
    }

    // ========================================
    // 바로 구매 (direct_items) — 장바구니 미경유
    // ========================================

    /**
     * direct_items 로 체크아웃하면 임시 주문이 생성되고 장바구니에는 행이 만들어지지 않습니다.
     */
    public function test_direct_items_checkout_creates_temp_order_without_cart_row(): void
    {
        // Given: 회원 + 판매중 상품(장바구니 비어 있음)
        $user = $this->createUser();
        $data = $this->createProductWithOption();

        // When: 바로 구매 (direct_items)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'direct_items' => [
                    ['product_id' => $data['product']->id, 'quantity' => 2],
                ],
            ]);

        // Then: 201 + 임시주문 생성, 장바구니 행은 0건 (오염 없음)
        $response->assertStatus(201);
        $this->assertSame(0, Cart::where('user_id', $user->id)->count());
    }

    /**
     * 바로 구매는 장바구니 기존 수량과 합산하지 않고 이번 선택 수량만으로 한도를 판정합니다.
     */
    public function test_direct_items_quantity_limit_excludes_existing_cart(): void
    {
        // Given: 최대 3개 상품 + 장바구니에 이미 2개 담겨 있음
        $user = $this->createUser();
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
            'min_purchase_qty' => 1,
            'max_purchase_qty' => 3,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 100,
        ]);
        CartFactory::new()->forUser($user)->forOption($option)->create(['quantity' => 2]);

        // When: 바로 구매로 3개 선택 (장바구니 2개와 합산하면 5개지만, 합산하지 않아야 함)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'direct_items' => [
                    ['product_id' => $product->id, 'quantity' => 3],
                ],
            ]);

        // Then: 201 통과 (이번 선택 3개 == 한도 3개), 장바구니 2개는 그대로 유지
        $response->assertStatus(201);
        $this->assertSame(2, (int) Cart::where('user_id', $user->id)->sum('quantity'));
    }

    /**
     * 바로 구매도 구매수량 한도 자체는 동일하게 적용됩니다 (이번 선택이 한도 초과면 차단).
     */
    public function test_direct_items_blocks_when_selection_exceeds_max(): void
    {
        // Given: 최대 3개 상품
        $user = $this->createUser();
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
            'min_purchase_qty' => 1,
            'max_purchase_qty' => 3,
        ]);
        ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        // When: 바로 구매로 5개 선택 (한도 3 초과)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'direct_items' => [
                    ['product_id' => $product->id, 'quantity' => 5],
                ],
            ]);

        // Then: 4xx 차단 + max_qty 사유
        $response->assertStatus(400);
        $response->assertJsonPath('errors.code', 'cart_unavailable');
        $this->assertTrue($response->json('errors.has_max_qty_issue'));
    }

    // ========================================
    // U14/MP06: 만료 임시주문 404 + 검증 표면화
    // ========================================

    /**
     * 만료/미존재 임시주문 update → 404 (한국어 로케일).
     *
     * 기존 i18n 문자열 매칭(str_contains 'temp_order_not_found')은 번역문이 한글이라
     * 항상 false → 500 이었다. 타입 분기로 항상 404 를 반환해야 한다.
     */
    public function test_update_nonexistent_temp_order_returns_404_ko(): void
    {
        app()->setLocale('ko');
        $user = $this->createUser();

        // 임시주문을 만들지 않은 상태에서 update 시도
        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'use_points' => 0,
            ]);

        $response->assertStatus(404);
    }

    /**
     * 만료/미존재 임시주문 update → 404 (영어 로케일 — 번역문 매칭 회귀 방지).
     */
    public function test_update_nonexistent_temp_order_returns_404_en(): void
    {
        app()->setLocale('en');
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'use_points' => 0,
            ]);

        $response->assertStatus(404);
    }

    /**
     * 미존재 임시주문 destroy → 404.
     */
    public function test_destroy_nonexistent_temp_order_returns_404(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/modules/sirsoft-ecommerce/checkout');

        $response->assertStatus(404);
    }

    /**
     * 미존재 임시주문 extend → 404.
     */
    public function test_extend_nonexistent_temp_order_returns_404(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout/extend');

        $response->assertStatus(404);
    }

    /**
     * update 응답에 validation_errors 키가 소프트 표면화된다(정상 시 빈 배열).
     */
    public function test_update_response_surfaces_validation_errors_key(): void
    {
        $user = $this->createUser();
        $data = $this->createProductWithOption();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($data['option'])
            ->create(['quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ])->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'use_points' => 0,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['validation_errors']]);
    }
}
