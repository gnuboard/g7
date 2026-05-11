<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CartWithCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 장바구니 서비스 Unit 테스트
 */
class CartServiceTest extends ModuleTestCase
{
    protected CartService $service;

    protected $mockCartRepository;

    protected $mockProductOptionRepository;

    protected $mockCalculationService;

    protected function setUp(): void
    {
        parent::setUp();

        // Unit 테스트는 Mock 모델(DB 미저장) 사용. Hook listener 가 큐 워커에서
        // 모델을 deserialize 할 때 find($id) 가 null 을 반환하여 TypeError 발생.
        // Queue::fake() 로 listener job 실행을 차단하여 Service 의 비즈니스 로직만 검증.
        Queue::fake();

        $this->mockCartRepository = Mockery::mock(CartRepositoryInterface::class);
        $this->mockProductOptionRepository = Mockery::mock(ProductOptionRepositoryInterface::class);
        $this->mockCalculationService = Mockery::mock(OrderCalculationService::class);

        $this->service = new CartService(
            $this->mockCartRepository,
            $this->mockProductOptionRepository,
            $this->mockCalculationService
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * private 메서드를 호출합니다.
     *
     * @param  object  $object  대상 객체
     * @param  string  $methodName  메서드 이름
     * @param  array  $args  메서드 인자
     * @return mixed
     */
    protected function invokePrivateMethod(object $object, string $methodName, array $args = []): mixed
    {
        $method = new ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }

    /**
     * CartService의 addToCart private 메서드를 호출합니다.
     *
     * @param  array  $data  장바구니 데이터
     * @return Cart
     */
    protected function callAddToCart(array $data): Cart
    {
        return $this->invokePrivateMethod($this->service, 'addToCart', [$data]);
    }

    // ========================================
    // issueCartKey() 테스트
    // ========================================

    public function test_issue_cart_key_returns_valid_key(): void
    {
        // Given: 중복 키 없음
        $this->mockCartRepository
            ->shouldReceive('existsByCartKey')
            ->once()
            ->andReturn(false);

        // When
        $cartKey = $this->service->issueCartKey();

        // Then
        $this->assertStringStartsWith('ck_', $cartKey);
        $this->assertEquals(35, strlen($cartKey)); // 'ck_' + 32자 = 35자
    }

    public function test_issue_cart_key_returns_unique_keys(): void
    {
        // Given: 중복 키 없음
        $this->mockCartRepository
            ->shouldReceive('existsByCartKey')
            ->twice()
            ->andReturn(false);

        // When
        $key1 = $this->service->issueCartKey();
        $key2 = $this->service->issueCartKey();

        // Then
        $this->assertNotEquals($key1, $key2);
    }

    public function test_issue_cart_key_regenerates_on_duplicate(): void
    {
        // Given: 첫 번째 키는 중복, 두 번째 키는 유일
        $this->mockCartRepository
            ->shouldReceive('existsByCartKey')
            ->andReturn(true, false);

        // When
        $cartKey = $this->service->issueCartKey();

        // Then: 최종적으로 유효한 키 반환
        $this->assertStringStartsWith('ck_', $cartKey);
        $this->assertEquals(35, strlen($cartKey));
    }

    // ========================================
    // getCart() 테스트
    // ========================================

    public function test_get_cart_returns_items_for_user(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->forUser($user)->forOption($option)->create();
        $items = new Collection([$cart]);

        $this->mockCartRepository
            ->shouldReceive('findByUserId')
            ->with($user->id)
            ->once()
            ->andReturn($items);

        // When
        $result = $this->service->getCart($user->id, null);

        // Then
        $this->assertCount(1, $result);
        $this->assertEquals($cart->id, $result->first()->id);
    }

    public function test_get_cart_returns_items_for_guest(): void
    {
        // Given
        $cartKey = 'ck_test_guest_key';
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->withCartKey($cartKey)->forOption($option)->create();
        $items = new Collection([$cart]);

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyWithoutUser')
            ->with($cartKey)
            ->once()
            ->andReturn($items);

        // When
        $result = $this->service->getCart(null, $cartKey);

        // Then
        $this->assertCount(1, $result);
        $this->assertEquals($cartKey, $result->first()->cart_key);
    }

    public function test_get_cart_returns_empty_collection_without_credentials(): void
    {
        // When
        $result = $this->service->getCart(null, null);

        // Then
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    // ========================================
    // getCartWithCalculation() 테스트
    // ========================================

    public function test_get_cart_with_calculation_returns_items_and_calculation(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->forUser($user)->forOption($option)->create([
            'quantity' => 2,
        ]);
        $items = new Collection([$cart]);

        $this->mockCartRepository
            ->shouldReceive('findByUserId')
            ->with($user->id)
            ->once()
            ->andReturn($items);

        $calculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 30000,
                productCouponDiscount: 0,
                codeDiscount: 0,
                orderCouponDiscount: 0,
                totalDiscount: 0,
                baseShippingTotal: 3000,
                extraShippingTotal: 0,
                totalShipping: 3000,
                shippingDiscount: 0,
                taxableAmount: 30000,
                taxFreeAmount: 0,
                pointsEarning: 300,
                pointsUsed: 0,
                paymentAmount: 33000,
                finalAmount: 33000
            )
        );

        $this->mockCalculationService
            ->shouldReceive('calculate')
            ->once()
            ->andReturn($calculationResult);

        // When
        $result = $this->service->getCartWithCalculation($user->id, null);

        // Then
        $this->assertInstanceOf(CartWithCalculationResult::class, $result);
        $this->assertCount(1, $result->items);
        $this->assertEquals(33000, $result->calculation->summary->finalAmount);
    }

    public function test_get_cart_with_calculation_for_guest(): void
    {
        // Given
        $cartKey = 'ck_test_guest_calculation';
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->withCartKey($cartKey)->forOption($option)->create();
        $items = new Collection([$cart]);

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyWithoutUser')
            ->with($cartKey)
            ->once()
            ->andReturn($items);

        $calculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 15000,
                productCouponDiscount: 0,
                codeDiscount: 0,
                orderCouponDiscount: 0,
                totalDiscount: 0,
                baseShippingTotal: 3000,
                extraShippingTotal: 0,
                totalShipping: 3000,
                shippingDiscount: 0,
                taxableAmount: 15000,
                taxFreeAmount: 0,
                pointsEarning: 150,
                pointsUsed: 0,
                paymentAmount: 18000,
                finalAmount: 18000
            )
        );

        $this->mockCalculationService
            ->shouldReceive('calculate')
            ->once()
            ->andReturn($calculationResult);

        // When
        $result = $this->service->getCartWithCalculation(null, $cartKey);

        // Then
        $this->assertInstanceOf(CartWithCalculationResult::class, $result);
        $this->assertEquals(18000, $result->calculation->summary->finalAmount);
    }

    public function test_get_cart_with_calculation_returns_empty_for_empty_cart(): void
    {
        // Given
        $user = User::factory()->create();
        $emptyItems = new Collection;

        $this->mockCartRepository
            ->shouldReceive('findByUserId')
            ->with($user->id)
            ->once()
            ->andReturn($emptyItems);

        $emptyCalculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 0,
                productCouponDiscount: 0,
                codeDiscount: 0,
                orderCouponDiscount: 0,
                totalDiscount: 0,
                baseShippingTotal: 0,
                extraShippingTotal: 0,
                totalShipping: 0,
                shippingDiscount: 0,
                taxableAmount: 0,
                taxFreeAmount: 0,
                pointsEarning: 0,
                pointsUsed: 0,
                paymentAmount: 0,
                finalAmount: 0
            )
        );

        $this->mockCalculationService
            ->shouldReceive('calculate')
            ->once()
            ->andReturn($emptyCalculationResult);

        // When
        $result = $this->service->getCartWithCalculation($user->id, null);

        // Then
        $this->assertTrue($result->isEmpty());
        $this->assertEquals(0, $result->count());
        $this->assertEquals(0, $result->calculation->summary->finalAmount);
    }

    public function test_get_cart_with_calculation_passes_coupons_and_points(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->forUser($user)->forOption($option)->create();
        $items = new Collection([$cart]);
        $couponIds = [1, 2];
        $usePoints = 1000;

        $this->mockCartRepository
            ->shouldReceive('findByUserId')
            ->with($user->id)
            ->once()
            ->andReturn($items);

        $calculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 30000,
                productCouponDiscount: 5000,
                codeDiscount: 0,
                orderCouponDiscount: 0,
                totalDiscount: 5000,
                baseShippingTotal: 0,
                extraShippingTotal: 0,
                totalShipping: 0,
                shippingDiscount: 0,
                taxableAmount: 25000,
                taxFreeAmount: 0,
                pointsEarning: 250,
                pointsUsed: 1000,
                paymentAmount: 24000,
                finalAmount: 24000
            )
        );

        $this->mockCalculationService
            ->shouldReceive('calculate')
            ->withArgs(function ($input) use ($couponIds, $usePoints) {
                return $input->couponIssueIds === $couponIds
                    && $input->usePoints === $usePoints;
            })
            ->once()
            ->andReturn($calculationResult);

        // When
        $result = $this->service->getCartWithCalculation($user->id, null, $couponIds, $usePoints);

        // Then
        $this->assertEquals(5000, $result->calculation->summary->productCouponDiscount);
        $this->assertEquals(1000, $result->calculation->summary->pointsUsed);
        $this->assertEquals(24000, $result->calculation->summary->finalAmount);
    }

    // ========================================
    // addToCart() 테스트
    // ========================================

    public function test_add_to_cart_creates_new_item(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        $data = [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
        ];

        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $option->id)
            ->once()
            ->andReturn(null);

        // Mock stock validation
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->once()
            ->andReturn($option);

        $newCart = new Cart($data);
        $newCart->id = 1;

        $this->mockCartRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($newCart);

        // When
        $result = $this->callAddToCart($data);

        // Then
        $this->assertEquals($user->id, $result->user_id);
        $this->assertEquals($option->id, $result->product_option_id);
    }

    public function test_add_to_cart_increases_quantity_for_existing_item(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $existingCart = CartFactory::new()->forUser($user)->forOption($option)->create([
            'quantity' => 2,
        ]);

        $data = [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
        ];

        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $option->id)
            ->once()
            ->andReturn($existingCart);

        // Mock stock validation
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->once()
            ->andReturn($option);

        $updatedCart = clone $existingCart;
        $updatedCart->quantity = 5; // 2 + 3

        $this->mockCartRepository
            ->shouldReceive('update')
            ->once()
            ->andReturn($updatedCart);

        // When
        $result = $this->callAddToCart($data);

        // Then
        $this->assertEquals(5, $result->quantity);
    }

    public function test_add_to_cart_for_guest(): void
    {
        // Given
        $cartKey = 'ck_guest_add';
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        $data = [
            'cart_key' => $cartKey,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
        ];

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyAndOption')
            ->with($cartKey, $option->id)
            ->once()
            ->andReturn(null);

        // Mock stock validation
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->once()
            ->andReturn($option);

        $newCart = new Cart($data);
        $newCart->id = 1;

        $this->mockCartRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($newCart);

        // When
        $result = $this->callAddToCart($data);

        // Then
        $this->assertEquals($cartKey, $result->cart_key);
    }

    // ========================================
    // updateQuantity() 테스트
    // ========================================

    public function test_update_quantity_updates_quantity(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $cart = CartFactory::new()->forUser($user)->forOption($option)->create([
            'quantity' => 1,
        ]);

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cart->id)
            ->once()
            ->andReturn($cart);

        // Mock stock validation
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->once()
            ->andReturn($option);

        $updatedCart = clone $cart;
        $updatedCart->quantity = 5;

        $this->mockCartRepository
            ->shouldReceive('update')
            ->once()
            ->andReturn($updatedCart);

        // When
        $result = $this->service->updateQuantity($cart->id, 5, $user->id, null);

        // Then
        $this->assertEquals(5, $result->quantity);
    }

    public function test_update_quantity_throws_exception_for_not_found(): void
    {
        // Given
        $this->mockCartRepository
            ->shouldReceive('find')
            ->with(99999)
            ->once()
            ->andReturn(null);

        // Then
        $this->expectException(\Exception::class);

        // When
        $this->service->updateQuantity(99999, 5, 1, null);
    }

    public function test_update_quantity_throws_exception_for_unauthorized(): void
    {
        // Given
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->forUser($user)->forOption($option)->create();

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cart->id)
            ->once()
            ->andReturn($cart);

        // Then
        $this->expectException(\Exception::class);

        // When
        $this->service->updateQuantity($cart->id, 5, $otherUser->id, null);
    }

    // ========================================
    // deleteItem() 테스트
    // ========================================

    public function test_delete_item_deletes_cart_item(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->forUser($user)->forOption($option)->create();

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cart->id)
            ->once()
            ->andReturn($cart);

        $this->mockCartRepository
            ->shouldReceive('delete')
            ->with($cart)
            ->once()
            ->andReturn(true);

        // When
        $result = $this->service->deleteItem($cart->id, $user->id, null);

        // Then
        $this->assertTrue($result);
    }

    // ========================================
    // deleteItems() 테스트
    // ========================================

    public function test_delete_items_deletes_multiple_items(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option1 = ProductOptionFactory::new()->forProduct($product)->create();
        $option2 = ProductOptionFactory::new()->forProduct($product)->create();
        $cart1 = CartFactory::new()->forUser($user)->forOption($option1)->create();
        $cart2 = CartFactory::new()->forUser($user)->forOption($option2)->create();

        $ids = [$cart1->id, $cart2->id];
        $items = new Collection([$cart1, $cart2]);

        $this->mockCartRepository
            ->shouldReceive('findByIds')
            ->with($ids)
            ->once()
            ->andReturn($items);

        $this->mockCartRepository
            ->shouldReceive('deleteByIds')
            ->once()
            ->andReturn(2);

        // When
        $result = $this->service->deleteItems($ids, $user->id, null);

        // Then
        $this->assertEquals(2, $result);
    }

    // ========================================
    // mergeGuestCartToUser() 테스트
    // ========================================

    public function test_merge_guest_cart_to_user_merges_items(): void
    {
        // Given
        $user = User::factory()->create();
        $cartKey = 'ck_merge_test';
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $guestCart = CartFactory::new()->withCartKey($cartKey)->forOption($option)->create([
            'quantity' => 2,
        ]);

        $guestItems = new Collection([$guestCart]);

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyWithoutUser')
            ->with($cartKey)
            ->once()
            ->andReturn($guestItems);

        // Mock stock check in mergeGuestCartToUser
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->andReturn($option);

        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $option->id)
            ->once()
            ->andReturn(null);

        $this->mockCartRepository
            ->shouldReceive('update')
            ->once()
            ->andReturn($guestCart);

        // When
        $result = $this->service->mergeGuestCartToUser($cartKey, $user->id);

        // Then
        $this->assertEquals(1, $result);
    }

    public function test_merge_guest_cart_combines_quantities_for_same_option(): void
    {
        // Given
        $user = User::factory()->create();
        $cartKey = 'ck_merge_combine';
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        $guestCart = CartFactory::new()->withCartKey($cartKey)->forOption($option)->create([
            'quantity' => 2,
        ]);
        $userCart = CartFactory::new()->forUser($user)->forOption($option)->create([
            'quantity' => 3,
        ]);

        $guestItems = new Collection([$guestCart]);

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyWithoutUser')
            ->with($cartKey)
            ->once()
            ->andReturn($guestItems);

        // Mock stock check in mergeGuestCartToUser
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->andReturn($option);

        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $option->id)
            ->once()
            ->andReturn($userCart);

        $combinedCart = clone $userCart;
        $combinedCart->quantity = 5;

        $this->mockCartRepository
            ->shouldReceive('update')
            ->with($userCart, Mockery::any())
            ->once()
            ->andReturn($combinedCart);

        $this->mockCartRepository
            ->shouldReceive('delete')
            ->with($guestCart)
            ->once();

        // When
        $result = $this->service->mergeGuestCartToUser($cartKey, $user->id);

        // Then
        $this->assertEquals(1, $result);
    }

    // ========================================
    // getItemCount() 테스트
    // ========================================

    public function test_get_item_count_returns_count(): void
    {
        // Given
        $user = User::factory()->create();

        $this->mockCartRepository
            ->shouldReceive('countItems')
            ->with($user->id, null)
            ->once()
            ->andReturn(5);

        // When
        $result = $this->service->getItemCount($user->id, null);

        // Then
        $this->assertEquals(5, $result);
    }

    // ========================================
    // 동일 상품 다른 옵션 담기 테스트
    // ========================================

    /**
     * 테스트 #86: 동일 상품 다른 옵션 담기
     *
     * 입력: 상품A-옵션1 존재, 상품A-옵션2 담기
     * 기대: 별도 Cart 레코드 생성 (총 2개)
     */
    public function test_add_to_cart_creates_separate_record_for_different_option(): void
    {
        // Given
        $cartKey = 'ck_test123456789012345678901234';
        $product = ProductFactory::new()->create();
        $option1 = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $option2 = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        // 기존 장바구니 아이템 (옵션1)
        $existingCart = CartFactory::new()->create([
            'cart_key' => $cartKey,
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $option1->id,
            'quantity' => 2,
        ]);

        // 새 옵션(옵션2)에 대해서는 기존 아이템 없음
        $this->mockCartRepository
            ->shouldReceive('findByCartKeyAndOption')
            ->with($cartKey, $option2->id)
            ->once()
            ->andReturn(null);

        // Mock stock validation
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option2->id)
            ->once()
            ->andReturn($option2);

        // 새 아이템 생성
        $newCart = CartFactory::new()->create([
            'cart_key' => $cartKey,
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $option2->id,
            'quantity' => 1,
        ]);

        $this->mockCartRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($newCart);

        // When
        $result = $this->callAddToCart([
            'cart_key' => $cartKey,
            'product_id' => $product->id,
            'product_option_id' => $option2->id,
            'quantity' => 1,
        ]);

        // Then
        $this->assertEquals($option2->id, $result->product_option_id);
        $this->assertEquals(1, $result->quantity);
    }

    // ========================================
    // 옵션 변경 테스트 (#92, #93, #94)
    // ========================================

    /**
     * 테스트 #92: 옵션만 변경
     *
     * 입력: PUT /cart/{id}, product_option_id: 새옵션, quantity: 기존
     * 기대: 옵션 ID 업데이트
     */
    public function test_change_option_updates_option_only(): void
    {
        // Given
        $cartKey = 'ck_test123456789012345678901234';
        $product = ProductFactory::new()->create();
        $option1 = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $option2 = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        $cart = CartFactory::new()->create([
            'cart_key' => $cartKey,
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $option1->id,
            'quantity' => 2,
        ]);

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cart->id)
            ->once()
            ->andReturn($cart);

        // Mock validateSameProduct and validateStock
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option2->id)
            ->andReturn($option2);

        // 새 옵션이 이미 장바구니에 없음
        $this->mockCartRepository
            ->shouldReceive('findByCartKeyAndOption')
            ->with($cartKey, $option2->id)
            ->once()
            ->andReturn(null);

        $updatedCart = clone $cart;
        $updatedCart->product_option_id = $option2->id;

        $this->mockCartRepository
            ->shouldReceive('update')
            ->with($cart, Mockery::on(function ($data) use ($option2) {
                return $data['product_option_id'] === $option2->id && $data['quantity'] === 2;
            }))
            ->once()
            ->andReturn($updatedCart);

        // When
        $result = $this->service->changeOption($cart->id, $option2->id, 2, null, $cartKey);

        // Then
        $this->assertEquals($option2->id, $result->product_option_id);
    }

    /**
     * 테스트 #93: 옵션+수량 동시 변경
     *
     * 입력: PUT /cart/{id}, product_option_id: 새옵션, quantity: 3
     * 기대: 옵션+수량 모두 업데이트
     */
    public function test_change_option_updates_option_and_quantity(): void
    {
        // Given
        $cartKey = 'ck_test123456789012345678901234';
        $product = ProductFactory::new()->create();
        $option1 = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $option2 = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        $cart = CartFactory::new()->create([
            'cart_key' => $cartKey,
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $option1->id,
            'quantity' => 1,
        ]);

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cart->id)
            ->once()
            ->andReturn($cart);

        // Mock validateSameProduct and validateStock
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option2->id)
            ->andReturn($option2);

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyAndOption')
            ->with($cartKey, $option2->id)
            ->once()
            ->andReturn(null);

        $updatedCart = clone $cart;
        $updatedCart->product_option_id = $option2->id;
        $updatedCart->quantity = 3;

        $this->mockCartRepository
            ->shouldReceive('update')
            ->with($cart, Mockery::on(function ($data) use ($option2) {
                return $data['product_option_id'] === $option2->id && $data['quantity'] === 3;
            }))
            ->once()
            ->andReturn($updatedCart);

        // When
        $result = $this->service->changeOption($cart->id, $option2->id, 3, null, $cartKey);

        // Then
        $this->assertEquals($option2->id, $result->product_option_id);
        $this->assertEquals(3, $result->quantity);
    }

    /**
     * 테스트 #94: 옵션 변경 시 기존 동일옵션 존재 - 합산
     *
     * 입력: 기존: 옵션A(2개), 옵션B(3개) → 옵션A를 옵션B로 변경
     * 기대: 옵션B: 5개, 옵션A 레코드 삭제
     *
     * 참고: changeOption에서 existingItem->fresh()를 호출하여 최신 데이터를 반환하므로
     *       update 호출 시 반환되는 객체의 quantity가 5인지 확인합니다.
     */
    public function test_change_option_merges_when_target_option_exists(): void
    {
        // Given
        $cartKey = 'ck_test123456789012345678901234';
        $product = ProductFactory::new()->create();
        $optionA = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $optionB = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        // 옵션A 장바구니 (변경 대상)
        $cartA = CartFactory::new()->create([
            'cart_key' => $cartKey,
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $optionA->id,
            'quantity' => 2,
        ]);

        // 옵션B 장바구니 (이미 존재) - Partial Mock으로 fresh() 동작 처리
        $cartB = Mockery::mock(Cart::class)->makePartial();
        $cartB->id = 999;
        $cartB->cart_key = $cartKey;
        $cartB->user_id = null;
        $cartB->product_id = $product->id;
        $cartB->product_option_id = $optionB->id;
        $cartB->quantity = 3;

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cartA->id)
            ->once()
            ->andReturn($cartA);

        // Mock validateSameProduct and validateStock
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($optionB->id)
            ->andReturn($optionB);

        // 옵션B가 이미 장바구니에 존재
        $this->mockCartRepository
            ->shouldReceive('findByCartKeyAndOption')
            ->with($cartKey, $optionB->id)
            ->once()
            ->andReturn($cartB);

        // update 결과로 quantity가 5인 객체 반환
        $mergedCart = Mockery::mock(Cart::class)->makePartial();
        $mergedCart->id = 999;
        $mergedCart->product_option_id = $optionB->id;
        $mergedCart->quantity = 5;

        $this->mockCartRepository
            ->shouldReceive('update')
            ->with($cartB, Mockery::on(function ($data) {
                return $data['quantity'] === 5; // 2 + 3 = 5
            }))
            ->once()
            ->andReturn($mergedCart);

        $this->mockCartRepository
            ->shouldReceive('delete')
            ->with($cartA)
            ->once();

        // existingItem->fresh() 호출 시 mergedCart 반환
        $cartB->shouldReceive('fresh')->once()->andReturn($mergedCart);

        // When
        $result = $this->service->changeOption($cartA->id, $optionB->id, 2, null, $cartKey);

        // Then
        $this->assertEquals($optionB->id, $result->product_option_id);
        $this->assertEquals(5, $result->quantity);
    }

    // ========================================
    // 비회원→회원 병합 (혼합) 테스트
    // ========================================

    /**
     * 테스트 #106: 비회원→회원 병합 (혼합)
     *
     * 입력: 비회원: 옵션A 2개, 옵션B 1개 / 회원: 옵션A 3개, 옵션C 2개
     * 기대: 옵션A 5개, 옵션B 1개, 옵션C 2개
     */
    public function test_merge_guest_cart_to_user_with_mixed_items(): void
    {
        // Given
        $user = User::factory()->create();
        $cartKey = 'ck_test123456789012345678901234';
        $product = ProductFactory::new()->create();
        $optionA = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $optionB = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $optionC = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        // 비회원 장바구니: 옵션A 2개, 옵션B 1개
        $guestCartA = CartFactory::new()->create([
            'cart_key' => $cartKey,
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $optionA->id,
            'quantity' => 2,
        ]);
        $guestCartB = CartFactory::new()->create([
            'cart_key' => $cartKey,
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $optionB->id,
            'quantity' => 1,
        ]);

        // 회원 장바구니: 옵션A 3개, 옵션C 2개
        $userCartA = CartFactory::new()->forUser($user)->create([
            'product_id' => $product->id,
            'product_option_id' => $optionA->id,
            'quantity' => 3,
        ]);
        $userCartC = CartFactory::new()->forUser($user)->create([
            'product_id' => $product->id,
            'product_option_id' => $optionC->id,
            'quantity' => 2,
        ]);

        $guestItems = new Collection([$guestCartA, $guestCartB]);

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyWithoutUser')
            ->with($cartKey)
            ->once()
            ->andReturn($guestItems);

        // Mock stock check in mergeGuestCartToUser
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($optionA->id)
            ->andReturn($optionA);
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($optionB->id)
            ->andReturn($optionB);

        // 옵션A: 회원 장바구니에 존재 → 합산
        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $optionA->id)
            ->once()
            ->andReturn($userCartA);

        $mergedCartA = clone $userCartA;
        $mergedCartA->quantity = 5;

        $this->mockCartRepository
            ->shouldReceive('update')
            ->with($userCartA, Mockery::on(function ($data) {
                return $data['quantity'] === 5;
            }))
            ->once()
            ->andReturn($mergedCartA);

        $this->mockCartRepository
            ->shouldReceive('delete')
            ->with($guestCartA)
            ->once();

        // 옵션B: 회원 장바구니에 없음 → user_id 업데이트
        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $optionB->id)
            ->once()
            ->andReturn(null);

        $updatedGuestCartB = clone $guestCartB;
        $updatedGuestCartB->user_id = $user->id;
        $updatedGuestCartB->cart_key = null;

        $this->mockCartRepository
            ->shouldReceive('update')
            ->with($guestCartB, Mockery::on(function ($data) use ($user) {
                // cart_key는 유지되므로 업데이트 데이터에 포함되지 않음
                return $data['user_id'] === $user->id && ! array_key_exists('cart_key', $data);
            }))
            ->once()
            ->andReturn($updatedGuestCartB);

        // When
        $result = $this->service->mergeGuestCartToUser($cartKey, $user->id);

        // Then: 2개 아이템 처리됨
        $this->assertEquals(2, $result);
    }

    // ========================================
    // #108 cart_key 없이 로그인 병합 테스트
    // ========================================

    /**
     * #108 빈 cart_key로 병합 시도 시 병합을 스킵하고 0을 반환합니다.
     *
     * 빈 cart_key (빈 문자열)로 mergeGuestCartToUser 호출 시
     * 비회원 장바구니가 없으므로 병합 없이 0을 반환해야 합니다.
     */
    public function test_merge_guest_cart_skips_when_cart_key_is_empty(): void
    {
        // Given: 빈 cart_key
        $user = User::factory()->create();
        $emptyCartKey = '';

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyWithoutUser')
            ->with($emptyCartKey)
            ->once()
            ->andReturn(new Collection);

        // When: 빈 cart_key로 병합 시도
        $result = $this->service->mergeGuestCartToUser($emptyCartKey, $user->id);

        // Then: 병합된 아이템 0개
        $this->assertEquals(0, $result);
    }

    /**
     * #108 존재하지 않는 cart_key로 병합 시도 시 병합을 스킵합니다.
     *
     * 존재하지 않는 cart_key로 mergeGuestCartToUser 호출 시
     * 비회원 장바구니가 없으므로 병합 없이 0을 반환해야 합니다.
     */
    public function test_merge_guest_cart_skips_when_cart_key_has_no_items(): void
    {
        // Given: 존재하지 않는 cart_key (아이템 없음)
        $user = User::factory()->create();
        $nonExistentCartKey = 'ck_nonexistent123456789012345678';

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyWithoutUser')
            ->with($nonExistentCartKey)
            ->once()
            ->andReturn(new Collection);

        // When: 해당 cart_key로 병합 시도
        $result = $this->service->mergeGuestCartToUser($nonExistentCartKey, $user->id);

        // Then: 병합된 아이템 0개, 기존 회원 장바구니 유지
        $this->assertEquals(0, $result);
    }

    // ========================================
    // Section 7.14: 재고 검증 테스트 (6개)
    // ========================================

    /**
     * 테스트 #83: 재고 초과 수량 담기 시도 → 예외
     *
     * 재고가 5개인 옵션에 10개를 담으려 하면 stock_exceeded 예외 발생
     */
    public function test_add_to_cart_throws_exception_when_stock_exceeded(): void
    {
        // Given: 재고 5개인 옵션
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 5,
        ]);

        // 기존 장바구니 없음
        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $option->id)
            ->once()
            ->andReturn(null);

        // Mock stock validation - 재고 5개 반환
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->once()
            ->andReturn($option);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-ecommerce::exceptions.stock_exceeded', [
            'available' => 5,
            'requested' => 10,
        ]));

        // When: 재고 초과 수량 담기 시도
        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 10, // 재고 5개 < 요청 10개
        ]);
    }

    /**
     * 테스트 #87: 합산 후 재고 초과 → 예외
     *
     * 이미 장바구니에 3개 있고, 재고가 5개인 상태에서 5개 추가 시도 → 예외
     * (3 + 5 = 8 > 5)
     */
    public function test_add_to_cart_throws_exception_when_combined_quantity_exceeds_stock(): void
    {
        // Given: 재고 5개인 옵션, 기존 장바구니에 3개
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 5,
        ]);

        $existingCart = CartFactory::new()->forUser($user)->forOption($option)->create([
            'quantity' => 3,
        ]);

        // 기존 장바구니 있음
        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $option->id)
            ->once()
            ->andReturn($existingCart);

        // Mock stock validation - 재고 5개 반환
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->once()
            ->andReturn($option);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-ecommerce::exceptions.stock_exceeded', [
            'available' => 5,
            'requested' => 8, // 3 + 5 = 8
        ]));

        // When: 추가 5개 담기 시도 (합산 8개 > 재고 5개)
        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 5,
        ]);
    }

    /**
     * 테스트 #88: 품절 상품 담기 시도 → 예외
     *
     * 재고가 0인 옵션을 담으려 하면 out_of_stock 예외 발생
     */
    public function test_add_to_cart_throws_exception_when_out_of_stock(): void
    {
        // Given: 재고 0개인 옵션 (품절)
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 0,
        ]);

        // 기존 장바구니 없음
        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $option->id)
            ->once()
            ->andReturn(null);

        // Mock stock validation - 재고 0개 반환
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->once()
            ->andReturn($option);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-ecommerce::exceptions.out_of_stock'));

        // When: 품절 상품 담기 시도
        $this->callAddToCart([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
        ]);
    }

    /**
     * 테스트 #91: 재고 초과 수량 변경 시도 → 예외
     *
     * 재고 5개인 옵션의 수량을 10개로 변경 시도 → 예외
     */
    public function test_update_quantity_throws_exception_when_stock_exceeded(): void
    {
        // Given: 재고 5개인 옵션
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 5,
        ]);

        $cart = CartFactory::new()->forUser($user)->forOption($option)->create([
            'quantity' => 2,
        ]);

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cart->id)
            ->once()
            ->andReturn($cart);

        // Mock stock validation - 재고 5개 반환
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->once()
            ->andReturn($option);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-ecommerce::exceptions.stock_exceeded', [
            'available' => 5,
            'requested' => 10,
        ]));

        // When: 수량 10개로 변경 시도 (재고 5개 < 요청 10개)
        $this->service->updateQuantity($cart->id, 10, $user->id, null);
    }

    /**
     * 테스트 #95: 옵션 변경 후 재고 초과 → 예외
     *
     * 새 옵션의 재고가 5개인데 기존 수량이 7개 → 예외
     */
    public function test_change_option_throws_exception_when_stock_exceeded(): void
    {
        // Given: 기존 옵션으로 7개 담김
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $oldOption = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 10,
        ]);
        $newOption = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 5,
        ]);

        $cart = CartFactory::new()->forUser($user)->forOption($oldOption)->create([
            'quantity' => 7,
        ]);

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cart->id)
            ->once()
            ->andReturn($cart);

        // Mock validateSameProduct and validateStock - newOption 반환
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($newOption->id)
            ->andReturn($newOption);

        // 새 옵션으로 기존에 담긴 것 없음
        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $newOption->id)
            ->once()
            ->andReturn(null);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-ecommerce::exceptions.stock_exceeded', [
            'available' => 5,
            'requested' => 7,
        ]));

        // When: 새 옵션으로 변경 시도 (재고 5개 < 기존 수량 7개)
        $this->service->changeOption($cart->id, $newOption->id, $cart->quantity, $user->id, null);
    }

    /**
     * 테스트 #96: 다른 상품 옵션으로 변경 시도 → 예외
     *
     * 상품A의 옵션을 상품B의 옵션으로 변경 시도 → 예외
     */
    public function test_change_option_throws_exception_for_different_product(): void
    {
        // Given: 상품A의 장바구니
        $user = User::factory()->create();
        $productA = ProductFactory::new()->create();
        $optionA = ProductOptionFactory::new()->forProduct($productA)->create(['stock_quantity' => 100]);

        // 상품B의 옵션
        $productB = ProductFactory::new()->create();
        $optionB = ProductOptionFactory::new()->forProduct($productB)->create(['stock_quantity' => 100]);

        $cart = CartFactory::new()->forUser($user)->forOption($optionA)->create([
            'product_id' => $productA->id,
        ]);

        $this->mockCartRepository
            ->shouldReceive('find')
            ->with($cart->id)
            ->once()
            ->andReturn($cart);

        // Mock validateSameProduct - 다른 상품의 옵션 반환
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($optionB->id)
            ->once()
            ->andReturn($optionB);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('sirsoft-ecommerce::exceptions.invalid_option_for_product'));

        // When: 다른 상품 옵션으로 변경 시도
        $this->service->changeOption($cart->id, $optionB->id, 1, $user->id, null);
    }

    // ========================================
    // Section 7.15: 장바구니 병합 재고 테스트 (1개)
    // ========================================

    /**
     * 테스트 #107: 병합 시 재고 초과 처리
     *
     * 비회원 장바구니 3개 + 회원 장바구니 4개 = 7개
     * 재고가 5개면 회원 장바구니 수량을 5개로 제한
     */
    public function test_merge_guest_cart_adjusts_quantity_when_stock_exceeded(): void
    {
        // Given: 재고 5개인 옵션
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 5,
        ]);

        // 비회원 장바구니: 3개
        $cartKey = 'ck_test1234567890123456789012345';
        $guestCart = CartFactory::new()->forOption($option)->create([
            'cart_key' => $cartKey,
            'user_id' => null,
            'quantity' => 3,
        ]);

        // 회원 장바구니: 4개
        $userCart = CartFactory::new()->forUser($user)->forOption($option)->create([
            'quantity' => 4,
        ]);

        $this->mockCartRepository
            ->shouldReceive('findByCartKeyWithoutUser')
            ->with($cartKey)
            ->once()
            ->andReturn(new Collection([$guestCart]));

        // Mock stock check in mergeGuestCartToUser - 재고 5개 반환
        $this->mockProductOptionRepository
            ->shouldReceive('findById')
            ->with($option->id)
            ->andReturn($option);

        // 회원 장바구니에 같은 옵션 있음
        $this->mockCartRepository
            ->shouldReceive('findByUserAndOption')
            ->with($user->id, $option->id)
            ->once()
            ->andReturn($userCart);

        // 기대: 수량을 재고(5개)로 조정하여 업데이트
        $updatedUserCart = clone $userCart;
        $updatedUserCart->quantity = 5; // 재고 한도

        $this->mockCartRepository
            ->shouldReceive('update')
            ->withArgs(function ($cart, $data) use ($userCart) {
                return $cart->id === $userCart->id && $data['quantity'] === 5;
            })
            ->once()
            ->andReturn($updatedUserCart);

        // 비회원 장바구니 삭제
        $this->mockCartRepository
            ->shouldReceive('delete')
            ->with($guestCart)
            ->once()
            ->andReturn(true);

        // When
        $result = $this->service->mergeGuestCartToUser($cartKey, $user->id);

        // Then: 1개 아이템 처리됨 (병합)
        $this->assertEquals(1, $result);
    }

    // ========================================
    // Section 7.17: 전체 삭제 테스트
    // ========================================

    /**
     * 테스트 #99-1: 회원 장바구니 전체 삭제
     *
     * 회원의 장바구니를 전체 삭제하면 해당 회원의 모든 아이템이 삭제됩니다.
     */
    public function test_delete_all_deletes_all_items_for_user(): void
    {
        // Given
        $user = User::factory()->create();

        $this->mockCartRepository
            ->shouldReceive('deleteByUserId')
            ->with($user->id)
            ->once()
            ->andReturn(5);

        // When
        $result = $this->service->deleteAll($user->id, null);

        // Then
        $this->assertEquals(5, $result);
    }

    /**
     * 테스트 #99-2: 비회원 장바구니 전체 삭제
     *
     * 비회원의 장바구니를 전체 삭제하면 해당 cart_key의 모든 아이템이 삭제됩니다.
     */
    public function test_delete_all_deletes_all_items_for_guest(): void
    {
        // Given
        $cartKey = 'ck_test123456789012345678901234';

        $this->mockCartRepository
            ->shouldReceive('deleteByCartKey')
            ->with($cartKey)
            ->once()
            ->andReturn(3);

        // When
        $result = $this->service->deleteAll(null, $cartKey);

        // Then
        $this->assertEquals(3, $result);
    }

    /**
     * 테스트 #99-3: 인증정보 없이 전체 삭제
     *
     * userId와 cartKey 모두 없으면 0을 반환합니다.
     */
    public function test_delete_all_returns_zero_without_credentials(): void
    {
        // When
        $result = $this->service->deleteAll(null, null);

        // Then
        $this->assertEquals(0, $result);
    }

    /**
     * 테스트 #99-4: 빈 장바구니 전체 삭제
     *
     * 장바구니가 비어있을 때 전체 삭제 호출 시 0을 반환합니다.
     */
    public function test_delete_all_returns_zero_for_empty_cart(): void
    {
        // Given
        $user = User::factory()->create();

        $this->mockCartRepository
            ->shouldReceive('deleteByUserId')
            ->with($user->id)
            ->once()
            ->andReturn(0);

        // When
        $result = $this->service->deleteAll($user->id, null);

        // Then
        $this->assertEquals(0, $result);
    }
}
