<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\TempOrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 임시 주문 서비스 Unit 테스트
 */
class TempOrderServiceTest extends ModuleTestCase
{
    protected TempOrderService $service;

    protected $mockTempOrderRepository;

    protected $mockCartRepository;

    protected $mockCouponIssueRepository;

    protected $mockCalculationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTempOrderRepository = Mockery::mock(TempOrderRepositoryInterface::class);
        $this->mockCartRepository = Mockery::mock(CartRepositoryInterface::class);
        $this->mockCouponIssueRepository = Mockery::mock(CouponIssueRepositoryInterface::class);
        $this->mockCalculationService = Mockery::mock(OrderCalculationService::class);

        $this->service = new TempOrderService(
            $this->mockTempOrderRepository,
            $this->mockCartRepository,
            $this->mockCouponIssueRepository,
            $this->mockCalculationService
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // createTempOrder() 테스트
    // ========================================

    public function test_create_temp_order_creates_order_for_user(): void
    {
        // Given
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->forUser($user)->forOption($option)->create();

        $cartItems = new Collection([$cart]);

        $calculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 30000,
                couponDiscount: 0,
                codeDiscount: 0,
                orderDiscount: 0,
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

        $tempOrder = new TempOrder([
            'id' => 1,
            'user_id' => $user->id,
            'items' => [],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => [],
                    'order_coupon_issue_id' => null,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 0,
                'shipping_address' => null,
            ],
            'calculation_result' => $calculationResult->toArray(),
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        $this->mockTempOrderRepository
            ->shouldReceive('upsert')
            ->once()
            ->andReturn($tempOrder);

        // When
        $result = $this->service->createTempOrder($cartItems, $user->id, null);

        // Then
        $this->assertEquals($user->id, $result->user_id);
    }

    public function test_create_temp_order_throws_exception_for_empty_cart(): void
    {
        // Given
        $cartItems = new Collection();

        // Then
        $this->expectException(\Exception::class);

        // When
        $this->service->createTempOrder($cartItems, 1, null);
    }

    public function test_create_temp_order_creates_order_for_guest(): void
    {
        // Given
        $cartKey = 'ck_test_guest_key';
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $cart = CartFactory::new()->withCartKey($cartKey)->forOption($option)->create();

        $cartItems = new Collection([$cart]);

        $calculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 15000,
                couponDiscount: 0,
                codeDiscount: 0,
                orderDiscount: 0,
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

        $tempOrder = new TempOrder([
            'id' => 2,
            'cart_key' => $cartKey,
            'user_id' => null,
            'items' => [],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => [],
                    'order_coupon_issue_id' => null,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 0,
                'shipping_address' => null,
            ],
            'calculation_result' => $calculationResult->toArray(),
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        $this->mockTempOrderRepository
            ->shouldReceive('upsert')
            ->once()
            ->andReturn($tempOrder);

        // When
        $result = $this->service->createTempOrder($cartItems, null, $cartKey);

        // Then
        $this->assertEquals($cartKey, $result->cart_key);
        $this->assertNull($result->user_id);
    }

    // ========================================
    // getTempOrder() 테스트
    // ========================================

    public function test_get_temp_order_returns_valid_order(): void
    {
        // Given
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()->forUser($user)->create();

        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with($user->id, null)
            ->once()
            ->andReturn($tempOrder);

        // When
        $result = $this->service->getTempOrder($user->id, null);

        // Then
        $this->assertNotNull($result);
        $this->assertEquals($tempOrder->id, $result->id);
    }

    public function test_get_temp_order_returns_null_for_non_existent(): void
    {
        // Given
        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with(99999, null)
            ->once()
            ->andReturn(null);

        // When
        $result = $this->service->getTempOrder(99999, null);

        // Then
        $this->assertNull($result);
    }

    // ========================================
    // updateTempOrder() 테스트
    // ========================================

    public function test_update_temp_order_recalculates_with_new_promotions(): void
    {
        // Given
        $user = User::factory()->create();
        $existingTempOrder = TempOrderFactory::new()->forUser($user)->create([
            'items' => [
                ['cart_id' => 1, 'product_id' => 1, 'product_option_id' => 1, 'quantity' => 2],
            ],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => [],
                    'order_coupon_issue_id' => null,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 0,
                'shipping_address' => null,
            ],
        ]);

        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with($user->id, null)
            ->once()
            ->andReturn($existingTempOrder);

        // 쿠폰 소유권 검증 mock
        $this->mockCouponIssueRepository
            ->shouldReceive('findByIdsForUser')
            ->andReturn(new Collection());

        $newCalculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 30000,
                couponDiscount: 3000,
                codeDiscount: 0,
                orderDiscount: 0,
                totalDiscount: 3000,
                baseShippingTotal: 0,
                extraShippingTotal: 0,
                totalShipping: 0,
                shippingDiscount: 0,
                taxableAmount: 27000,
                taxFreeAmount: 0,
                pointsEarning: 270,
                pointsUsed: 0,
                paymentAmount: 27000,
                finalAmount: 27000
            )
        );

        $this->mockCalculationService
            ->shouldReceive('calculate')
            ->once()
            ->andReturn($newCalculationResult);

        $updatedTempOrder = clone $existingTempOrder;
        $updatedTempOrder->calculation_input = [
            'promotions' => [
                'item_coupons' => [],
                'order_coupon_issue_id' => null,
                'shipping_coupon_issue_id' => null,
            ],
            'use_points' => 0,
            'shipping_address' => null,
        ];
        $updatedTempOrder->calculation_result = $newCalculationResult->toArray();

        $this->mockTempOrderRepository
            ->shouldReceive('update')
            ->once()
            ->andReturn($updatedTempOrder);

        // When
        $promotions = [
            'item_coupons' => [],
            'order_coupon_issue_id' => null,
            'shipping_coupon_issue_id' => null,
        ];
        $result = $this->service->updateTempOrder($user->id, null, $promotions, 0);

        // Then
        $this->assertNotNull($result);
        $this->assertEquals(0, $result->getUsedPoints());
    }

    public function test_update_temp_order_throws_exception_when_not_found(): void
    {
        // Given
        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with(99999, null)
            ->once()
            ->andReturn(null);

        // Then
        $this->expectException(\Exception::class);

        // When
        $promotions = [
            'item_coupons' => [],
            'order_coupon_issue_id' => null,
            'shipping_coupon_issue_id' => null,
        ];
        $this->service->updateTempOrder(99999, null, $promotions, 0);
    }

    public function test_update_temp_order_preserves_existing_promotions_when_not_sent(): void
    {
        // Given: 기존 temp_order에 쿠폰이 적용된 상태
        $user = User::factory()->create();
        $existingTempOrder = TempOrderFactory::new()->forUser($user)->create([
            'items' => [
                ['cart_id' => 1, 'product_id' => 1, 'product_option_id' => 1, 'quantity' => 2],
            ],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => ['1529' => ['9130']],
                    'order_coupon_issue_id' => 10375,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 500,
                'shipping_address' => ['country_code' => 'KR', 'zipcode' => '13479'],
            ],
        ]);

        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with($user->id, null)
            ->once()
            ->andReturn($existingTempOrder);

        $newCalculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 30000,
                couponDiscount: 3000,
                codeDiscount: 0,
                orderDiscount: 0,
                totalDiscount: 3000,
                baseShippingTotal: 0,
                extraShippingTotal: 0,
                totalShipping: 0,
                shippingDiscount: 0,
                taxableAmount: 27000,
                taxFreeAmount: 0,
                pointsEarning: 270,
                pointsUsed: 500,
                paymentAmount: 26500,
                finalAmount: 26500
            )
        );

        $this->mockCalculationService
            ->shouldReceive('calculate')
            ->once()
            ->withArgs(function ($input) {
                // 기존 쿠폰이 보존되어 재계산에 전달되는지 검증
                return ! empty($input->itemCoupons)
                    && $input->usePoints === 500;
            })
            ->andReturn($newCalculationResult);

        // 쿠폰 소유권 검증 mock (기존 쿠폰 재검증)
        $this->mockCouponIssueRepository
            ->shouldReceive('findByIdsForUser')
            ->andReturn(new Collection());

        $updatedTempOrder = clone $existingTempOrder;
        $updatedTempOrder->calculation_result = $newCalculationResult->toArray();

        $this->mockTempOrderRepository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function ($tempOrder, $updateData) {
                // calculation_input.promotions에 기존 쿠폰이 보존되는지 검증
                $promotions = $updateData['calculation_input']['promotions'];

                return ! empty($promotions['item_coupons'])
                    && $promotions['order_coupon_issue_id'] === 10375
                    && $updateData['calculation_input']['use_points'] === 500;
            })
            ->andReturn($updatedTempOrder);

        // When: 빈 promotions 전달 (배송 주소만 변경하는 경우)
        $result = $this->service->updateTempOrder(
            userId: $user->id,
            cartKey: null,
            promotions: [],       // 프로모션 필드 미전송
            usePoints: null,      // 마일리지 미전송
            shippingAddress: new \Modules\Sirsoft\Ecommerce\DTO\ShippingAddress(
                countryCode: 'KR',
                zipcode: '06234'
            )
        );

        // Then
        $this->assertNotNull($result);
    }

    public function test_update_temp_order_overrides_promotions_when_explicitly_sent(): void
    {
        // Given: 기존 temp_order에 쿠폰이 적용된 상태
        $user = User::factory()->create();
        $existingTempOrder = TempOrderFactory::new()->forUser($user)->create([
            'items' => [
                ['cart_id' => 1, 'product_id' => 1, 'product_option_id' => 1, 'quantity' => 2],
            ],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => ['1529' => ['9130']],
                    'order_coupon_issue_id' => 10375,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 500,
                'shipping_address' => null,
            ],
        ]);

        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with($user->id, null)
            ->once()
            ->andReturn($existingTempOrder);

        $newCalculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 30000,
                couponDiscount: 0,
                codeDiscount: 0,
                orderDiscount: 0,
                totalDiscount: 0,
                baseShippingTotal: 0,
                extraShippingTotal: 0,
                totalShipping: 0,
                shippingDiscount: 0,
                taxableAmount: 30000,
                taxFreeAmount: 0,
                pointsEarning: 300,
                pointsUsed: 0,
                paymentAmount: 30000,
                finalAmount: 30000
            )
        );

        $this->mockCalculationService
            ->shouldReceive('calculate')
            ->once()
            ->withArgs(function ($input) {
                // 쿠폰이 명시적으로 제거되었는지 검증
                return empty($input->itemCoupons)
                    && $input->usePoints === 0;
            })
            ->andReturn($newCalculationResult);

        $this->mockCouponIssueRepository
            ->shouldReceive('findByIdsForUser')
            ->andReturn(new Collection());

        $updatedTempOrder = clone $existingTempOrder;
        $updatedTempOrder->calculation_result = $newCalculationResult->toArray();

        $this->mockTempOrderRepository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function ($tempOrder, $updateData) {
                // 명시적으로 빈 프로모션이 저장되는지 검증
                $promotions = $updateData['calculation_input']['promotions'];

                return empty($promotions['item_coupons'])
                    && $promotions['order_coupon_issue_id'] === null
                    && $updateData['calculation_input']['use_points'] === 0;
            })
            ->andReturn($updatedTempOrder);

        // When: 명시적으로 빈 쿠폰 전달 (사용자가 쿠폰을 제거한 경우)
        $promotions = [
            'item_coupons' => [],
            'order_coupon_issue_id' => null,
            'shipping_coupon_issue_id' => null,
        ];
        $result = $this->service->updateTempOrder(
            userId: $user->id,
            cartKey: null,
            promotions: $promotions,
            usePoints: 0           // 명시적 0
        );

        // Then
        $this->assertNotNull($result);
    }

    public function test_update_temp_order_preserves_partial_promotions(): void
    {
        // Given: 기존 temp_order에 상품 쿠폰 + 주문 쿠폰 적용 상태
        $user = User::factory()->create();
        $existingTempOrder = TempOrderFactory::new()->forUser($user)->create([
            'items' => [
                ['cart_id' => 1, 'product_id' => 1, 'product_option_id' => 1, 'quantity' => 1],
            ],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => ['1529' => ['9130']],
                    'order_coupon_issue_id' => 10375,
                    'shipping_coupon_issue_id' => 200,
                ],
                'use_points' => 1000,
                'shipping_address' => null,
            ],
        ]);

        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with($user->id, null)
            ->once()
            ->andReturn($existingTempOrder);

        $newCalculationResult = new OrderCalculationResult(
            items: [],
            summary: new Summary(
                subtotal: 20000,
                couponDiscount: 0,
                codeDiscount: 0,
                orderDiscount: 0,
                totalDiscount: 0,
                baseShippingTotal: 0,
                extraShippingTotal: 0,
                totalShipping: 0,
                shippingDiscount: 0,
                taxableAmount: 20000,
                taxFreeAmount: 0,
                pointsEarning: 200,
                pointsUsed: 1000,
                paymentAmount: 19000,
                finalAmount: 19000
            )
        );

        $this->mockCalculationService
            ->shouldReceive('calculate')
            ->once()
            ->withArgs(function ($input) {
                // item_coupons만 변경, order_coupon은 기존 유지, points도 기존 유지
                return empty($input->itemCoupons)
                    && $input->usePoints === 1000;
            })
            ->andReturn($newCalculationResult);

        $this->mockCouponIssueRepository
            ->shouldReceive('findByIdsForUser')
            ->andReturn(new Collection());

        $updatedTempOrder = clone $existingTempOrder;
        $updatedTempOrder->calculation_result = $newCalculationResult->toArray();

        $this->mockTempOrderRepository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function ($tempOrder, $updateData) {
                $promotions = $updateData['calculation_input']['promotions'];

                // item_coupons만 빈 배열로 변경, 나머지는 기존 값 유지
                return empty($promotions['item_coupons'])
                    && $promotions['order_coupon_issue_id'] === 10375
                    && $promotions['shipping_coupon_issue_id'] === 200
                    && $updateData['calculation_input']['use_points'] === 1000;
            })
            ->andReturn($updatedTempOrder);

        // When: item_coupons만 명시적으로 전달 (상품 쿠폰만 제거)
        $result = $this->service->updateTempOrder(
            userId: $user->id,
            cartKey: null,
            promotions: ['item_coupons' => []], // item_coupons만 전송, 나머지 미전송
            usePoints: null                      // 마일리지 미전송 → 기존 유지
        );

        // Then
        $this->assertNotNull($result);
    }

    // ========================================
    // deleteTempOrder() 테스트
    // ========================================

    public function test_delete_temp_order_deletes_existing_order(): void
    {
        // Given
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()->forUser($user)->create();

        $this->mockTempOrderRepository
            ->shouldReceive('findByUserOrCartKey')
            ->with($user->id, null)
            ->once()
            ->andReturn($tempOrder);

        $this->mockTempOrderRepository
            ->shouldReceive('delete')
            ->with($tempOrder)
            ->once()
            ->andReturn(true);

        // When
        $result = $this->service->deleteTempOrder($user->id, null);

        // Then
        $this->assertTrue($result);
    }

    public function test_delete_temp_order_returns_false_when_not_found(): void
    {
        // Given
        $this->mockTempOrderRepository
            ->shouldReceive('findByUserOrCartKey')
            ->with(99999, null)
            ->once()
            ->andReturn(null);

        // When
        $result = $this->service->deleteTempOrder(99999, null);

        // Then
        $this->assertFalse($result);
    }

    // ========================================
    // cleanupExpiredTempOrders() 테스트
    // ========================================

    public function test_cleanup_expired_temp_orders_deletes_expired(): void
    {
        // Given
        $this->mockTempOrderRepository
            ->shouldReceive('deleteExpired')
            ->once()
            ->andReturn(5);

        // When
        $result = $this->service->cleanupExpiredTempOrders();

        // Then
        $this->assertEquals(5, $result);
    }

    // ========================================
    // hasTempOrder() 테스트
    // ========================================

    public function test_has_temp_order_returns_true_when_exists(): void
    {
        // Given
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()->forUser($user)->create();

        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with($user->id, null)
            ->once()
            ->andReturn($tempOrder);

        // When
        $result = $this->service->hasTempOrder($user->id, null);

        // Then
        $this->assertTrue($result);
    }

    public function test_has_temp_order_returns_false_when_not_exists(): void
    {
        // Given
        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with(99999, null)
            ->once()
            ->andReturn(null);

        // When
        $result = $this->service->hasTempOrder(99999, null);

        // Then
        $this->assertFalse($result);
    }

    // ========================================
    // extendExpiration() 테스트
    // ========================================

    public function test_extend_expiration_extends_valid_order(): void
    {
        // Given
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()->forUser($user)->create();

        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with($user->id, null)
            ->once()
            ->andReturn($tempOrder);

        $extendedOrder = clone $tempOrder;
        $extendedOrder->expires_at = Carbon::now()->addMinutes(30);

        $this->mockTempOrderRepository
            ->shouldReceive('update')
            ->once()
            ->andReturn($extendedOrder);

        // When
        $result = $this->service->extendExpiration($user->id, null);

        // Then
        $this->assertNotNull($result);
        $this->assertTrue($result->expires_at->greaterThan(Carbon::now()));
    }

    public function test_extend_expiration_returns_null_when_not_exists(): void
    {
        // Given
        $this->mockTempOrderRepository
            ->shouldReceive('findValidByUserOrCartKey')
            ->with(99999, null)
            ->once()
            ->andReturn(null);

        // When
        $result = $this->service->extendExpiration(99999, null);

        // Then
        $this->assertNull($result);
    }
}
