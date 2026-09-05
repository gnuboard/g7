<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CartController Feature 테스트
 *
 * FormRequest 검증 및 API 응답을 테스트합니다.
 */
class CartControllerTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ko');
        $this->withHeaders(['Accept-Language' => 'ko']);
    }

    /**
     * 테스트용 배송정책을 생성합니다.
     */
    protected function createShippingPolicy(): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FREE,
            'base_fee' => 0,
            'free_threshold' => null,
            'ranges' => null,
            'extra_fee_enabled' => false,
            'extra_fee_settings' => null,
            'extra_fee_multiply' => false,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
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
     * 국가별로 서로 다른 고정 배송비를 가진 배송정책 + 상품을 생성합니다.
     *
     * @param  int  $krFee  KR 고정 배송비
     * @param  int  $usFee  US 고정 배송비
     * @return array{product: Product, option: ProductOption}
     */
    protected function createProductWithCountryFees(int $krFee, int $usFee): array
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '국가별 배송정책', 'en' => 'Per-Country Policy'],
            'is_active' => true,
            'is_default' => false,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR', 'shipping_method' => 'parcel', 'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FIXED, 'base_fee' => $krFee, 'is_active' => true,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'US', 'shipping_method' => 'parcel', 'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FIXED, 'base_fee' => $usFee, 'is_active' => true,
        ]);

        $product = ProductFactory::new()->onSale()->create(['shipping_policy_id' => $policy->id]);
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        return ['product' => $product, 'option' => $option];
    }

    /**
     * 해외배송 가능 국가(KR/US)를 활성화합니다.
     */
    protected function enableIntlShipping(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.international_shipping_enabled', true);
        $settings->setSetting('shipping.default_country', 'KR');
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국'], 'is_active' => true],
            ['code' => 'US', 'name' => ['ko' => '미국'], 'is_active' => true],
        ]);
    }

    // ========================================
    // 배송국가 차등 배송비 (선택 국가 → 장바구니 배송비 계산) 회귀
    // ========================================

    /**
     * 선택 배송국가(X-Shipping-Country: US)가 장바구니 배송비 계산에 반영되어야 합니다.
     *
     * 회귀: CartService 가 CalculationInput 에 shippingAddress 를 전달하지 않아
     * 장바구니 배송비가 항상 KR 로 계산되던 버그. selected_shipping_country 표시(US)와
     * 실제 배송비 계산 국가가 일치해야 한다.
     */
    public function test_cart_applies_selected_shipping_country_fee(): void
    {
        $this->enableIntlShipping();
        $data = $this->createProductWithCountryFees(krFee: 3000, usFee: 2000);
        $cartKey = 'ck_'.str_repeat('e', 32);

        $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [
                ['product_option_id' => $data['option']->id, 'quantity' => 1],
            ],
        ], ['X-Cart-Key' => $cartKey])->assertStatus(201);

        // 비회원 헤더로 US 선택 → 장바구니 배송비 US(2000)
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/cart', [
            'X-Cart-Key' => $cartKey,
            'X-Shipping-Country' => 'US',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.calculation.summary.total_shipping', 2000);
        $response->assertJsonPath('data.selected_shipping_country', 'US');
    }

    /**
     * 배송국가 헤더 미전달 시에는 기본 국가(KR) 배송비가 적용됩니다.
     */
    public function test_cart_defaults_to_kr_fee_without_shipping_country_header(): void
    {
        $this->enableIntlShipping();
        $data = $this->createProductWithCountryFees(krFee: 3000, usFee: 2000);
        $cartKey = 'ck_'.str_repeat('f', 32);

        $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [
                ['product_option_id' => $data['option']->id, 'quantity' => 1],
            ],
        ], ['X-Cart-Key' => $cartKey])->assertStatus(201);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/cart', [
            'X-Cart-Key' => $cartKey,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.calculation.summary.total_shipping', 3000);
    }

    // ========================================
    // #84 존재하지 않는 옵션 담기 시도 테스트
    // ========================================

    /**
     * #84 존재하지 않는 상품 ID로 장바구니 담기 시도 시 422 에러를 반환합니다.
     */
    public function test_add_to_cart_returns_422_for_non_existent_product(): void
    {
        // Given: 존재하지 않는 상품 ID
        $data = $this->createProductWithOption();

        // When: 존재하지 않는 상품 ID로 장바구니 담기 시도
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => 99999, // 존재하지 않는 상품 ID
            'items' => [
                ['quantity' => 1],
            ],
        ], [
            'X-Cart-Key' => 'ck_'.str_repeat('a', 32),
        ]);

        // Then: 422 Validation Error 반환
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['product_id']);
    }

    // ========================================
    // #90 수량 변경 테스트
    // ========================================

    /**
     * 수량 변경 성공 시 전체 장바구니 목록과 계산 결과를 반환합니다.
     *
     * refetch 없이 프론트엔드에서 바로 상태를 업데이트할 수 있도록
     * items, item_count, calculation을 포함한 응답을 반환합니다.
     */
    public function test_update_quantity_returns_full_cart_with_calculation(): void
    {
        // Given: 장바구니에 아이템이 존재
        $data = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('d', 32);

        // 장바구니에 아이템 추가
        $addResponse = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [
                ['product_option_id' => $data['option']->id, 'quantity' => 2],
            ],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);
        $addResponse->assertStatus(201);
        $cartId = $addResponse->json('data.items.0.id');

        // When: 수량을 5로 변경
        $response = $this->patchJson("/api/modules/sirsoft-ecommerce/cart/{$cartId}/quantity", [
            'quantity' => 5,
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 200 OK와 함께 전체 장바구니 데이터 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'items',
                'item_count',
                'calculation' => [
                    'items',
                    'summary',
                    'promotions',
                ],
            ],
        ]);

        // 변경된 수량 확인
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals(5, $items[0]['quantity']);

        // item_count 확인
        $this->assertEquals(1, $response->json('data.item_count'));
    }

    /**
     * 수량 변경 응답은 장바구니 조회 응답과 같은 키 집합이어야 합니다.
     *
     * 화면은 수량 변경 응답으로 장바구니 데이터소스를 **통째로 교체**한다(refetch 제거).
     * 그래서 조회 응답에만 있는 키는 수량을 한 번 바꾸는 순간 사라진다. 특히
     * `has_unshippable_items` 는 배송 불가 상품이 담겼을 때 주문 버튼을 잠그는 값이라,
     * 사라지면 그 잠금이 조용히 풀린다 — 오류도 경고도 남지 않는다.
     */
    public function test_update_quantity_response_matches_cart_query_shape(): void
    {
        // Given: 장바구니에 아이템이 존재
        $data = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('e', 32);

        $addResponse = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [
                ['product_option_id' => $data['option']->id, 'quantity' => 2],
            ],
        ], ['X-Cart-Key' => $cartKey]);
        $addResponse->assertStatus(201);
        $cartId = $addResponse->json('data.items.0.id');

        // When: 조회와 수량 변경을 각각 호출
        $queryResponse = $this->getJson('/api/modules/sirsoft-ecommerce/cart', ['X-Cart-Key' => $cartKey]);
        $quantityResponse = $this->patchJson(
            "/api/modules/sirsoft-ecommerce/cart/{$cartId}/quantity",
            ['quantity' => 3],
            ['X-Cart-Key' => $cartKey]
        );

        $queryResponse->assertStatus(200);
        $quantityResponse->assertStatus(200);

        // Then: 조회 응답의 키가 하나도 빠지지 않는다
        $queryKeys = array_keys($queryResponse->json('data'));
        $quantityKeys = array_keys($quantityResponse->json('data'));

        $this->assertSame(
            [],
            array_values(array_diff($queryKeys, $quantityKeys)),
            '수량 변경 응답에 장바구니 조회 응답의 키가 빠져 있습니다 — 화면이 데이터소스를 통째로 교체하므로 그 값들이 사라집니다.'
        );
    }

    /**
     * #90 수량을 0으로 변경 시도 시 422 에러를 반환합니다.
     */
    public function test_update_quantity_returns_422_for_zero_quantity(): void
    {
        // Given: 장바구니에 아이템이 존재
        $data = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('b', 32);

        // 장바구니에 아이템 추가
        $addResponse = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [
                ['product_option_id' => $data['option']->id, 'quantity' => 2],
            ],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);
        $addResponse->assertStatus(201);
        $cartId = $addResponse->json('data.items.0.id');

        // When: 수량을 0으로 변경 시도
        $response = $this->patchJson("/api/modules/sirsoft-ecommerce/cart/{$cartId}/quantity", [
            'quantity' => 0,
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 422 Validation Error 반환
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * #90 음수 수량으로 변경 시도 시 422 에러를 반환합니다.
     */
    public function test_update_quantity_returns_422_for_negative_quantity(): void
    {
        // Given: 장바구니에 아이템이 존재
        $data = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('c', 32);

        // 장바구니에 아이템 추가
        $addResponse = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [
                ['product_option_id' => $data['option']->id, 'quantity' => 2],
            ],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);
        $addResponse->assertStatus(201);
        $cartId = $addResponse->json('data.items.0.id');

        // When: 음수 수량으로 변경 시도
        $response = $this->patchJson("/api/modules/sirsoft-ecommerce/cart/{$cartId}/quantity", [
            'quantity' => -1,
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 422 Validation Error 반환
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    // ========================================
    // 일괄 담기 (bulk add) 테스트
    // ========================================

    /**
     * 옵션이 있는 상품을 여러 옵션 조합으로 일괄 담기 성공 시 201과 cart_count를 반환합니다.
     */
    public function test_bulk_add_with_options_returns_201_with_cart_count(): void
    {
        // Given: 상품과 두 개의 옵션
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option1 = ProductOptionFactory::new()->forProduct($product)->create([
            'option_values' => ['색상' => '빨강', '사이즈' => 'L'],
            'stock_quantity' => 100,
        ]);
        $option2 = ProductOptionFactory::new()->forProduct($product)->create([
            'option_values' => ['색상' => '파랑', '사이즈' => 'M'],
            'stock_quantity' => 100,
        ]);
        $cartKey = 'ck_'.str_repeat('e', 32);

        // When: 두 옵션 조합을 한 번에 담기
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [
                ['product_option_id' => $option1->id, 'quantity' => 2],
                ['product_option_id' => $option2->id, 'quantity' => 1],
            ],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 201 Created 반환 + cart_count 포함
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'items',
                'cart_count',
            ],
        ]);
        $this->assertEquals(2, $response->json('data.cart_count'));
    }

    /**
     * 옵션이 없는 상품 일괄 담기 성공 시 기본 옵션으로 담깁니다.
     */
    public function test_bulk_add_without_options_uses_default_option(): void
    {
        // Given: 옵션 없는 상품 (기본 옵션만 존재)
        $data = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('f', 32);

        // When: product_option_id 없이 담기
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [
                ['quantity' => 3],
            ],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 201 Created
        $response->assertStatus(201);
        $this->assertEquals(1, $response->json('data.cart_count'));
    }

    /**
     * 다른 상품에 속한 product_option_id 를 주입하면 담기가 거부되고 장바구니에 반영되지 않습니다.
     *
     * 회귀: 옵션 식별을 product_option_id 기반으로 전환하면서, 요청한 상품(product_id)의 옵션
     * 집합(getByProductId) 안에서만 옵션 ID 를 조회해야 한다. 타 상품 옵션 ID 주입 시 거부되어야
     * 서버가 임의 옵션(다른 상품/가격/재고)을 장바구니에 담는 위변조를 차단한다.
     */
    public function test_bulk_add_rejects_option_id_from_another_product(): void
    {
        // Given: 각각 옵션을 가진 두 상품
        $target = $this->createProductWithOption();
        $foreign = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('h', 32);

        // When: target 상품에 foreign 상품의 옵션 ID 를 실어 담기 시도
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $target['product']->id,
            'items' => [
                ['product_option_id' => $foreign['option']->id, 'quantity' => 1],
            ],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 담기 실패(비-201) + 장바구니에 어떤 행도 생성되지 않음
        $this->assertNotSame(201, $response->getStatusCode());
        $this->assertSame(0, Cart::where('cart_key', $cartKey)->count());
    }

    /**
     * 일괄 담기 시 items가 비어있으면 422를 반환합니다.
     */
    public function test_bulk_add_returns_422_for_empty_items(): void
    {
        // Given: 상품 존재
        $data = $this->createProductWithOption();
        $cartKey = 'ck_'.str_repeat('g', 32);

        // When: items 비어있는 상태로 요청
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 422 Validation Error
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
    }

    /**
     * 일괄 담기 시 product_id 누락 시 422를 반환합니다.
     */
    public function test_bulk_add_returns_422_for_missing_product_id(): void
    {
        $cartKey = 'ck_'.str_repeat('h', 32);

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'items' => [['quantity' => 1]],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['product_id']);
    }

    // ========================================
    // Section 7.16: cart_key 검증 테스트 (2개)
    // ========================================

    /**
     * 테스트 #111: X-Cart-Key 헤더 누락 시 비회원 조회 → 에러
     *
     * 비회원이 X-Cart-Key 헤더 없이 장바구니 조회 시 400 Bad Request 반환
     */
    public function test_get_cart_returns_error_without_cart_key_header_for_guest(): void
    {
        // When: X-Cart-Key 헤더 없이 비회원으로 장바구니 조회
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/cart');

        // Then: 400 Bad Request 반환 (cart_key 필수)
        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
        ]);
    }

    /**
     * 테스트 #112: 잘못된 cart_key 형식 → 에러
     *
     * 올바르지 않은 형식의 cart_key로 요청 시 400 Bad Request 반환
     * (형식: /^ck_[a-zA-Z0-9]{32}$/)
     */
    public function test_get_cart_returns_error_for_invalid_cart_key_format(): void
    {
        // When: 잘못된 형식의 cart_key로 장바구니 조회
        $invalidCartKeys = [
            'invalid_key',           // ck_ 접두사 없음
            'ck_tooshort',           // 32자 미만
            'ck_'.str_repeat('a', 31),  // 31자 (1자 부족)
            'ck_'.str_repeat('a', 33),  // 33자 (1자 초과)
            'ck_'.str_repeat('!', 32),  // 특수문자 포함
        ];

        foreach ($invalidCartKeys as $invalidKey) {
            $response = $this->getJson('/api/modules/sirsoft-ecommerce/cart', [
                'X-Cart-Key' => $invalidKey,
            ]);

            // Then: 400 Bad Request 반환 (잘못된 cart_key 형식)
            $response->assertStatus(400, "Failed for cart_key: {$invalidKey}");
            $response->assertJson([
                'success' => false,
            ], "Failed JSON assertion for cart_key: {$invalidKey}");
        }
    }

    // ========================================
    // U13②/U4: 판매불가 상품 담기 4xx 매핑 테스트
    // ========================================

    /**
     * 판매중지 상품 담기 시 generic 500 이 아닌 422(cart_unavailable) 로 차단됩니다.
     */
    public function test_add_to_cart_blocks_suspended_product_with_422(): void
    {
        // Given: 판매중지 상품
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->suspended()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 100,
        ]);

        $cartKey = 'ck_'.str_repeat('e', 32);

        // When: 담기 시도
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [
                ['product_option_id' => $option->id, 'quantity' => 1],
            ],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 422 + 판매상태 사유 (500 아님)
        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'cart_unavailable');
        $this->assertTrue($response->json('errors.has_status_issue'));
        // 사용자용 구체 안내(errors.message)가 generic 과 다른 판매상태 안내여야 한다
        $this->assertNotEmpty($response->json('errors.message'));
        $this->assertStringContainsString(
            __('sirsoft-ecommerce::exceptions.product_unavailable'),
            $response->json('errors.message')
        );
    }

    /**
     * 최대 구매수량 초과 담기 시 errors.message 에 한도/요청 수량 구체 안내가 포함됩니다.
     */
    public function test_add_to_cart_max_qty_response_includes_specific_message(): void
    {
        // Given: 최대 3개 상품 (옵션 재고 충분)
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
            'min_purchase_qty' => 1,
            'max_purchase_qty' => 3,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 100,
        ]);

        $cartKey = 'ck_'.str_repeat('f', 32);

        // When: 한도(3) 초과(6) 담기 시도
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [
                ['product_option_id' => $option->id, 'quantity' => 6],
            ],
        ], [
            'X-Cart-Key' => $cartKey,
        ]);

        // Then: 422 + max_qty 사유 + 한도(3)/요청(6) 치환된 구체 메시지
        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'cart_unavailable');
        $this->assertTrue($response->json('errors.has_max_qty_issue'));
        $message = $response->json('errors.message');
        $this->assertNotEmpty($message);
        $this->assertStringContainsString('3', $message);
        $this->assertStringContainsString('6', $message);
    }

    // ========================================
    // MP07 §1 — 담기/삭제 액션 500 → 4xx (U9/U8)
    // ========================================

    /**
     * 재고 0(품절) 옵션 담기 → generic 500 이 아닌 422(cart_unavailable, 재고 사유).
     */
    public function test_add_to_cart_out_of_stock_returns_422_not_500(): void
    {
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 0, // 품절
        ]);

        $cartKey = 'ck_'.str_repeat('g', 32);

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [
                ['product_option_id' => $option->id, 'quantity' => 1],
            ],
        ], ['X-Cart-Key' => $cartKey]);

        // Then: 422 (500 아님) + 재고 사유
        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'cart_unavailable');
        $this->assertTrue($response->json('errors.has_stock_issue'));
        $this->assertNotEmpty($response->json('errors.message'));
    }

    /**
     * 요청 수량 > 재고 담기 → 422 + 재고 부족 구체 메시지(요청/가용 치환).
     */
    public function test_add_to_cart_exceeding_stock_returns_422_with_substitution(): void
    {
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 3,
        ]);

        $cartKey = 'ck_'.str_repeat('h', 32);

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [
                ['product_option_id' => $option->id, 'quantity' => 5],
            ],
        ], ['X-Cart-Key' => $cartKey]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'cart_unavailable');
        $this->assertTrue($response->json('errors.has_stock_issue'));
        $message = $response->json('errors.message');
        $this->assertStringContainsString('5', $message); // 요청
        $this->assertStringContainsString('3', $message); // 가용
    }

    /**
     * 존재하지 않는 장바구니 항목 삭제 → generic 500 이 아닌 404.
     */
    public function test_destroy_nonexistent_item_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->deleteJson('/api/modules/sirsoft-ecommerce/cart/999999');

        $response->assertStatus(404);
    }

    /**
     * 타인의 장바구니 항목 삭제 시도 → generic 500 이 아닌 403.
     */
    public function test_destroy_others_item_returns_403(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 10,
        ]);
        $cart = CartFactory::new()->forUser($owner)->forProduct($product)->forOption($option)->create();

        // other 사용자가 owner 의 항목을 삭제 시도
        $response = $this->actingAs($other)
            ->deleteJson("/api/modules/sirsoft-ecommerce/cart/{$cart->id}");

        $response->assertStatus(403);
    }

    /**
     * 정상 삭제는 200 (회귀 보호 — 4xx 분기가 정상 경로를 막지 않음).
     */
    public function test_destroy_own_item_returns_200(): void
    {
        $user = User::factory()->create();

        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 10,
        ]);
        $cart = CartFactory::new()->forUser($user)->forProduct($product)->forOption($option)->create();

        $response = $this->actingAs($user)
            ->deleteJson("/api/modules/sirsoft-ecommerce/cart/{$cart->id}");

        $response->assertStatus(200);
    }

    /**
     * 선택 삭제 응답 메시지의 :deleted_count placeholder 치환 (U12)
     */
    public function test_destroy_multiple_replaces_deleted_count_placeholder(): void
    {
        $user = User::factory()->create();
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 50]);

        $cart1 = CartFactory::new()->forUser($user)->forProduct($product)->forOption($option)->create();
        $cart2 = CartFactory::new()->forUser($user)->forProduct($product)->forOption($option)->create();

        $response = $this->actingAs($user)
            ->deleteJson('/api/modules/sirsoft-ecommerce/cart', ['ids' => [$cart1->id, $cart2->id]]);

        $response->assertStatus(200);
        // placeholder 가 치환되어 개수가 들어가고 raw key 가 노출되지 않아야 한다
        $response->assertJsonPath('message', fn ($m) => is_string($m)
            && str_contains($m, '2')
            && ! str_contains($m, ':deleted_count'));
    }

    /**
     * 전체 삭제 응답 메시지의 :deleted_count placeholder 치환 (U12)
     */
    public function test_destroy_all_replaces_deleted_count_placeholder(): void
    {
        $user = User::factory()->create();
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 50]);

        CartFactory::new()->forUser($user)->forProduct($product)->forOption($option)->create();
        CartFactory::new()->forUser($user)->forProduct($product)->forOption($option)->create();
        CartFactory::new()->forUser($user)->forProduct($product)->forOption($option)->create();

        $response = $this->actingAs($user)
            ->deleteJson('/api/modules/sirsoft-ecommerce/cart/all');

        $response->assertStatus(200);
        $response->assertJsonPath('message', fn ($m) => is_string($m)
            && str_contains($m, '3')
            && ! str_contains($m, ':deleted_count'));
    }

    /**
     * 추가옵션 그룹 + 선택지 1개를 가진 상품을 생성합니다.
     *
     * @param  int  $priceAdjustment  선택지 추가금
     * @param  bool  $allowCustomText  직접입력 허용 여부
     * @return array{product: Product, option: ProductOption, value: ProductAdditionalOptionValue}
     */
    protected function createProductWithAdditionalOption(int $priceAdjustment = 5000, bool $allowCustomText = false): array
    {
        $shippingPolicy = $this->createShippingPolicy();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
            'selling_price' => 10000,
            'list_price' => 10000,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 100,
            'price_adjustment' => 0,
        ]);

        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '포장 방식', 'en' => 'Packaging'],
            'is_required' => false,
            'sort_order' => 0,
        ]);

        $value = ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '선물 포장', 'en' => 'Gift Box'],
            'price_adjustment' => $priceAdjustment,
            'is_default' => false,
            'is_active' => true,
            'allow_custom_text' => $allowCustomText,
            'sort_order' => 0,
        ]);

        return ['product' => $product, 'option' => $option, 'value' => $value];
    }

    /**
     * 담기 응답의 아이템은 목록 조회와 동일하게 추가옵션·소계를 실어야 한다.
     *
     * 회귀: 담기 응답은 `product`/`productOption` 만 적재해 `CartItemResource` 의
     * `relationLoaded('additionalOptions')` 가드에 걸렸다. 그래서 선택이 저장되었는데도
     * `additional_options` 가 빈 배열, `additional_options_total` 이 0, `subtotal` 이
     * 추가금을 뺀 값으로 나갔다. 오류가 아니라 값만 조용히 빠지므로, 담기 응답만 읽는
     * 소비자는 잘못된 금액을 그대로 표시하게 된다.
     *
     * @scenario surface=cart,observation=response_shape
     *
     * @effects store_response_matches_index_response, additional_option_amount_is_included_in_subtotal
     */
    public function test_store_response_includes_additional_options_and_subtotal(): void
    {
        $data = $this->createProductWithAdditionalOption(priceAdjustment: 5000, allowCustomText: true);
        $cartKey = 'ck_'.str_repeat('j', 32);

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [[
                'product_option_id' => $data['option']->id,
                'quantity' => 2,
                'additional_option_selections' => [[
                    'additional_option_id' => $data['value']->additional_option_id,
                    'value_id' => $data['value']->id,
                    'custom_text' => '각인 문구',
                ]],
            ]],
        ], ['X-Cart-Key' => $cartKey]);

        $response->assertStatus(201);

        $item = $response->json('data.items.0');

        $this->assertCount(1, $item['additional_options'], '담기 응답에 선택한 추가옵션이 실려야 한다');
        $this->assertSame($data['value']->id, $item['additional_options'][0]['value_id']);
        $this->assertSame('각인 문구', $item['additional_options'][0]['custom_text']);
        $this->assertSame(5000, $item['additional_options_total']);

        // 소계 = (옵션 판매가 10,000 + 추가옵션 5,000) × 수량 2
        $this->assertSame(30000, $item['subtotal']);
    }

    /**
     * 담기 응답의 아이템은 목록 조회 응답과 같은 값을 가져야 한다.
     *
     * 위 테스트가 "담기 응답이 맞다" 를 고정한다면, 이 테스트는 두 엔드포인트가 서로
     * 어긋나지 않음을 고정한다. 한쪽 적재 목록만 바뀌어도 red 가 된다.
     *
     * @scenario surface=cart,observation=response_shape
     *
     * @effects store_response_matches_index_response
     */
    public function test_store_response_item_matches_index_response_item(): void
    {
        $data = $this->createProductWithAdditionalOption(priceAdjustment: 3000);
        $cartKey = 'ck_'.str_repeat('k', 32);

        $storeResponse = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [[
                'product_option_id' => $data['option']->id,
                'quantity' => 1,
                'additional_option_selections' => [[
                    'additional_option_id' => $data['value']->additional_option_id,
                    'value_id' => $data['value']->id,
                ]],
            ]],
        ], ['X-Cart-Key' => $cartKey]);

        $storeResponse->assertStatus(201);
        $stored = $storeResponse->json('data.items.0');

        $indexResponse = $this->getJson('/api/modules/sirsoft-ecommerce/cart', ['X-Cart-Key' => $cartKey]);
        $indexResponse->assertStatus(200);
        $listed = $indexResponse->json('data.items.0');

        foreach (['additional_options', 'additional_options_total', 'subtotal', 'product'] as $key) {
            $this->assertSame(
                $listed[$key],
                $stored[$key],
                "담기 응답과 목록 응답의 `{$key}` 가 어긋나면 안 된다"
            );
        }
    }

    /**
     * 옵션 변경 응답의 아이템도 추가옵션·소계를 실어야 한다.
     *
     * 담기와 같은 원인(적재 목록 누락)이 옵션 변경 응답에도 있었다. 이쪽은 변경된 단건을
     * 그대로 돌려주는 자리라 소비자가 응답만으로 행을 갱신하면 금액이 어긋난다.
     *
     * @scenario surface=cart,observation=response_shape
     *
     * @effects change_option_response_carries_additional_options
     */
    public function test_change_option_response_includes_additional_options_and_subtotal(): void
    {
        $data = $this->createProductWithAdditionalOption(priceAdjustment: 4000);
        $cartKey = 'ck_'.str_repeat('l', 32);

        $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [['product_option_id' => $data['option']->id, 'quantity' => 1]],
        ], ['X-Cart-Key' => $cartKey])->assertStatus(201);

        $cartId = Cart::where('cart_key', $cartKey)->value('id');

        $response = $this->putJson("/api/modules/sirsoft-ecommerce/cart/{$cartId}/option", [
            'product_option_id' => $data['option']->id,
            'quantity' => 3,
            'additional_option_selections' => [[
                'additional_option_id' => $data['value']->additional_option_id,
                'value_id' => $data['value']->id,
            ]],
        ], ['X-Cart-Key' => $cartKey]);

        $response->assertStatus(200);

        $item = $response->json('data.item');

        $this->assertCount(1, $item['additional_options'], '옵션 변경 응답에 추가옵션이 실려야 한다');
        $this->assertSame(4000, $item['additional_options_total']);
        $this->assertSame(42000, $item['subtotal']); // (10,000 + 4,000) × 3
    }

    /**
     * 장바구니 조회는 상품 이미지의 필요한 컬럼만 읽는다.
     *
     * 장바구니는 상품당 대표 이미지 1장의 URL 만 쓰는데, 종전에는 그 상품의 이미지 행을
     * 전 컬럼으로 불러왔다. 대표 지정이 없을 때 첫 이미지로 폴백하는 동작은 그대로 유지되므로
     * 관계는 두고 컬럼만 좁힌다.
     *
     * @scenario surface=cart,observation=query_shape
     *
     * @effects list_response_shape_is_unchanged, image_columns_stay_sufficient_for_thumbnail_assembly
     */
    public function test_cart_loads_only_needed_product_image_columns(): void
    {
        $data = $this->createProductWithCountryFees(krFee: 3000, usFee: 2000);
        $cartKey = 'ck_'.str_repeat('i', 32);

        $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [
                ['product_option_id' => $data['option']->id, 'quantity' => 1],
            ],
        ], ['X-Cart-Key' => $cartKey])->assertStatus(201);

        $selects = [];
        DB::listen(function ($query) use (&$selects) {
            if (str_contains($query->sql, 'g7_ecommerce_product_images')) {
                $selects[] = $query->sql;
            }
        });

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/cart', ['X-Cart-Key' => $cartKey]);
        $response->assertStatus(200);

        $this->assertNotEmpty($selects, '상품 이미지 조회 쿼리가 있어야 한다');

        foreach ($selects as $sql) {
            $this->assertStringNotContainsString('select *', $sql, '장바구니는 이미지 전 컬럼을 읽으면 안 된다');
            $this->assertStringContainsString('`hash`', $sql, 'download_url 생성에 필요한 hash 는 유지되어야 한다');
            $this->assertStringContainsString('`is_thumbnail`', $sql, '대표 이미지 판정 컬럼은 유지되어야 한다');
        }
    }
}
