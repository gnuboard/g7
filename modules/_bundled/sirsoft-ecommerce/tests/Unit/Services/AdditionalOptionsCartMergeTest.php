<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 추가옵션 장바구니 합산 키 분기 테스트
 *
 * DB 백엔드 통합 테스트로, 합산 판정에 추가옵션 선택 해시가 포함되는지 검증합니다.
 * - 같은 옵션 + 같은 추가옵션 → 수량 합산 (1행)
 * - 같은 옵션 + 다른 추가옵션 → 별개 행 (2행) (D3)
 * - 타상품/비활성 value_id → 422 (D12)
 * - 필수 그룹 미선택 → 422 (D9/D12)
 */
class AdditionalOptionsCartMergeTest extends ModuleTestCase
{
    protected CartService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 합산/생성 로직만 검증 — listener 큐 job 차단
        Queue::fake();

        $this->service = app(CartService::class);
    }

    /**
     * addToCart private 메서드 호출 헬퍼.
     *
     * @param  array  $data  담기 데이터
     * @return Cart 생성/수정된 장바구니
     */
    protected function callAddToCart(array $data): Cart
    {
        $method = new ReflectionMethod(CartService::class, 'addToCart');
        $method->setAccessible(true);

        return $method->invoke($this->service, $data);
    }

    /**
     * 추가옵션 그룹 + 선택지를 생성합니다.
     *
     * @param  Product  $product  상품
     * @param  int  $priceAdjustment  추가금
     * @param  bool  $isRequired  필수 여부
     * @param  bool  $isActive  활성 여부
     * @param  bool  $allowCustomText  직접입력 허용 여부
     * @return ProductAdditionalOptionValue 생성된 선택지
     */
    protected function createValue(Product $product, int $priceAdjustment, bool $isRequired = false, bool $isActive = true, bool $allowCustomText = false): ProductAdditionalOptionValue
    {
        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인', 'en' => 'Engraving'],
            'is_required' => $isRequired,
            'sort_order' => 0,
        ]);

        return ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '추가', 'en' => 'Add'],
            'price_adjustment' => $priceAdjustment,
            'is_default' => false,
            'is_active' => $isActive,
            'allow_custom_text' => $allowCustomText,
            'sort_order' => 0,
        ]);
    }

    /**
     * 상품/옵션을 생성합니다.
     *
     * @return array{0: Product, 1: ProductOption}
     */
    protected function createProductWithOption(): array
    {
        $product = ProductFactory::new()->create(['selling_price' => 10000, 'list_price' => 10000]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    public function test_same_option_same_selection_combines_quantity(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        $value = $this->createValue($product, 5000);

        $selections = [['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id]];

        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 2,
            'additional_option_selections' => $selections,
        ]);
        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'additional_option_selections' => $selections,
        ]);

        $rows = Cart::where('user_id', $user->id)->where('product_option_id', $option->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(5, $rows->first()->quantity);
    }

    public function test_same_option_different_selection_creates_separate_rows(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        $valueA = $this->createValue($product, 5000);
        $valueB = $this->createValue($product, 3000);

        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [['additional_option_id' => $valueA->additional_option_id, 'value_id' => $valueA->id]],
        ]);
        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [['additional_option_id' => $valueB->additional_option_id, 'value_id' => $valueB->id]],
        ]);

        $rows = Cart::where('user_id', $user->id)->where('product_option_id', $option->id)->get();
        $this->assertCount(2, $rows);
    }

    public function test_no_selection_combines_with_existing_no_selection(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();

        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
        ]);
        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 4,
        ]);

        $rows = Cart::where('user_id', $user->id)->where('product_option_id', $option->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(5, $rows->first()->quantity);
    }

    public function test_invalid_value_id_rejected(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        [$otherProduct] = $this->createProductWithOption();
        $otherValue = $this->createValue($otherProduct, 5000);

        $this->expectException(CartUnavailableException::class);

        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [['additional_option_id' => $otherValue->additional_option_id, 'value_id' => $otherValue->id]],
        ]);
    }

    public function test_required_group_unselected_rejected(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        // 필수 그룹 생성 (선택 안 함)
        $this->createValue($product, 5000, isRequired: true);

        $this->expectException(CartUnavailableException::class);

        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            // additional_option_selections 미전달 → 필수 미선택
        ]);
    }

    public function test_persisted_selection_is_normalized_single_per_group(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        $value = $this->createValue($product, 5000);

        $cart = $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id]],
        ]);

        $stored = $cart->fresh()->additional_option_selections;
        $this->assertCount(1, $stored);
        $this->assertEquals($value->id, $stored[0]['value_id']);
        $this->assertEquals($value->additional_option_id, $stored[0]['additional_option_id']);
    }

    public function test_custom_text_required_only_when_group_is_required_and_empty_rejected(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        // 필수 그룹 + 직접입력 허용 선택지 → 빈 custom_text 차단 (Q-E1)
        $value = $this->createValue($product, 5000, isRequired: true, allowCustomText: true);

        $this->expectException(CartUnavailableException::class);

        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [[
                'additional_option_id' => $value->additional_option_id,
                'value_id' => $value->id,
                'custom_text' => '   ',
            ]],
        ]);
    }

    public function test_custom_text_optional_when_group_is_not_required_empty_passes(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        // 비필수 그룹 + 직접입력 허용 선택지 → 빈 custom_text 통과 (필수 그룹만 입력 강제)
        $value = $this->createValue($product, 5000, isRequired: false, allowCustomText: true);

        $cart = $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [[
                'additional_option_id' => $value->additional_option_id,
                'value_id' => $value->id,
                'custom_text' => '',
            ]],
        ]);

        // 차단 없이 담겨야 함
        $this->assertNotNull($cart->fresh());
        $stored = $cart->fresh()->additional_option_selections;
        $this->assertEquals($value->id, $stored[0]['value_id']);
    }

    public function test_custom_text_is_preserved_when_provided(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        $value = $this->createValue($product, 5000, allowCustomText: true);

        $cart = $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [[
                'additional_option_id' => $value->additional_option_id,
                'value_id' => $value->id,
                'custom_text' => '홍길동',
            ]],
        ]);

        $stored = $cart->fresh()->additional_option_selections;
        $this->assertSame('홍길동', $stored[0]['custom_text']);
    }

    public function test_custom_text_dropped_when_value_does_not_allow(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        // allow_custom_text=false (기본) — custom_text 전송돼도 드롭 (E4)
        $value = $this->createValue($product, 5000);

        $cart = $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [[
                'additional_option_id' => $value->additional_option_id,
                'value_id' => $value->id,
                'custom_text' => '무시되어야함',
            ]],
        ]);

        $stored = $cart->fresh()->additional_option_selections;
        $this->assertArrayNotHasKey('custom_text', $stored[0]);
    }

    public function test_same_value_different_custom_text_creates_separate_rows(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption();
        $value = $this->createValue($product, 5000, allowCustomText: true);

        // 같은 (옵션+선택지) 라도 각인 문구가 다르면 별개 행 (E5/D3)
        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [[
                'additional_option_id' => $value->additional_option_id,
                'value_id' => $value->id,
                'custom_text' => '홍길동',
            ]],
        ]);
        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [[
                'additional_option_id' => $value->additional_option_id,
                'value_id' => $value->id,
                'custom_text' => '김철수',
            ]],
        ]);

        $rows = Cart::where('user_id', $user->id)->where('product_option_id', $option->id)->get();
        $this->assertCount(2, $rows);
    }
}
