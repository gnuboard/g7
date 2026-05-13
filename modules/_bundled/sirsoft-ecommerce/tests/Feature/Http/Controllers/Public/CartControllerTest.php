<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
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
     *
     * @return ShippingPolicy
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
                ['option_values' => $data['option']->getLocalizedOptionValues(), 'quantity' => 2],
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
                ['option_values' => $data['option']->getLocalizedOptionValues(), 'quantity' => 2],
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
                ['option_values' => $data['option']->getLocalizedOptionValues(), 'quantity' => 2],
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
                ['option_values' => ['색상' => '빨강', '사이즈' => 'L'], 'quantity' => 2],
                ['option_values' => ['색상' => '파랑', '사이즈' => 'M'], 'quantity' => 1],
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

        // When: option_values 없이 담기
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
}
