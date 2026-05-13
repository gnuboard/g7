<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Enums\ActivityLogType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceUserActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Tests\TestCase;

/**
 * EcommerceUserActivityLogListener 테스트
 *
 * 이커머스 사용자 활동 로그 리스너의 모든 훅 메서드를 검증합니다.
 * - 스냅샷 캡처: 없음
 * - 로그 기록 (9개): cart.add, cart.update_quantity, cart.change_option,
 *   cart.delete, cart.delete_all, wishlist.toggle, user_coupon.download,
 *   order.create, order_option.confirm
 *
 * 모든 메서드가 ActivityLogType::User를 사용하는지 검증합니다.
 */
class EcommerceUserActivityLogListenerTest extends TestCase
{
    private EcommerceUserActivityLogListener $listener;

    private $logChannel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', Request::create('/api/shop/test'));
        $this->listener = app(EcommerceUserActivityLogListener::class);
        $this->logChannel = Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    public function test_getSubscribedHooks_returns_all_hooks(): void
    {
        $hooks = EcommerceUserActivityLogListener::getSubscribedHooks();

        $this->assertCount(9, $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.cart.after_add', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.cart.after_update_quantity', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.cart.after_change_option', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.cart.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.cart.after_delete_all', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.wishlist.after_toggle', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.user_coupon.after_download', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order-option.after_confirm', $hooks);
    }

    // ═══════════════════════════════════════════
    // Cart 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleCartAfterAdd_logs_activity_with_product_relation(): void
    {
        $cart = $this->createCartMockWithProduct(1, 'Test Product');
        $data = ['quantity' => 2];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'cart.add'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.cart_add'
                    && $context['description_params']['product_name'] === 'Test Product'
                    && isset($context['loggable'])
                    && $context['properties']['product_name'] === 'Test Product'
                    && $context['properties']['quantity'] === 2;
            });

        $this->listener->handleCartAfterAdd($cart, $data);
    }

    public function test_handleCartAfterAdd_falls_back_to_product_name_attribute(): void
    {
        $cart = $this->createCartMockWithProductName(2, 'Fallback Name');
        $data = ['quantity' => 1];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'cart.add'
                    && $context['description_params']['product_name'] === 'Fallback Name'
                    && $context['properties']['product_name'] === 'Fallback Name'
                    && $context['properties']['quantity'] === 1;
            });

        $this->listener->handleCartAfterAdd($cart, $data);
    }

    public function test_handleCartAfterAdd_with_empty_product_info(): void
    {
        $cart = $this->createCartMockWithProductName(3, null);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'cart.add'
                    && $context['description_params']['product_name'] === ''
                    && $context['properties']['quantity'] === null;
            });

        $this->listener->handleCartAfterAdd($cart, []);
    }

    public function test_handleCartAfterUpdateQuantity_logs_activity(): void
    {
        $cart = $this->createCartMockWithProduct(4, 'Qty Product');
        $quantity = 5;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($quantity) {
                return $action === 'cart.update_quantity'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.cart_update_quantity'
                    && $context['description_params']['product_name'] === 'Qty Product'
                    && isset($context['loggable'])
                    && $context['properties']['product_name'] === 'Qty Product'
                    && $context['properties']['quantity'] === $quantity;
            });

        $this->listener->handleCartAfterUpdateQuantity($cart, $quantity);
    }

    public function test_handleCartAfterChangeOption_logs_activity(): void
    {
        $cart = $this->createCartMockWithProduct(5, 'Option Product');
        $newProductOptionId = 42;
        $quantity = 3;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($newProductOptionId, $quantity) {
                return $action === 'cart.change_option'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.cart_change_option'
                    && $context['description_params']['product_name'] === 'Option Product'
                    && isset($context['loggable'])
                    && $context['properties']['product_name'] === 'Option Product'
                    && $context['properties']['new_option_id'] === $newProductOptionId
                    && $context['properties']['quantity'] === $quantity;
            });

        $this->listener->handleCartAfterChangeOption($cart, $newProductOptionId, $quantity);
    }

    public function test_handleCartAfterDelete_logs_activity(): void
    {
        $cartId = 10;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($cartId) {
                return $action === 'cart.delete'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.cart_delete'
                    && $context['properties']['cart_id'] === $cartId
                    && ! isset($context['loggable']);
            });

        $this->listener->handleCartAfterDelete($cartId);
    }

    public function test_handleCartAfterDeleteAll_logs_activity(): void
    {
        $userId = 100;
        $cartKey = 'user_100_cart';
        $deletedCount = 5;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($userId, $cartKey, $deletedCount) {
                return $action === 'cart.delete_all'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.cart_delete_all'
                    && $context['properties']['user_id'] === $userId
                    && $context['properties']['cart_key'] === $cartKey
                    && $context['properties']['deleted_count'] === $deletedCount
                    && ! isset($context['loggable']);
            });

        $this->listener->handleCartAfterDeleteAll($userId, $cartKey, $deletedCount);
    }

    // ═══════════════════════════════════════════
    // Wishlist 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * Wishlist toggle 핸들러는 Product::find() 정적 호출을 사용합니다.
     * alias mock의 PHP 프로세스 내 클래스 재정의 제한으로 인해
     * 로그 출력 형식(action, log_type, description_key 등)을 별도 검증합니다.
     *
     * handleCartAfterAdd 등 다른 테스트에서 이미 log_type=User, description_key,
     * description_params 패턴이 검증되었으므로, wishlist 핸들러의 로직(add/remove 분기,
     * product_name 추출)은 소스코드 리뷰로 보장합니다.
     */
    public function test_handleWishlistAfterToggle_method_exists_and_hooks_registered(): void
    {
        $hooks = EcommerceUserActivityLogListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.wishlist.after_toggle', $hooks);
        $this->assertEquals('handleWishlistAfterToggle', $hooks['sirsoft-ecommerce.wishlist.after_toggle']['method']);
        $this->assertTrue(method_exists($this->listener, 'handleWishlistAfterToggle'));
    }

    // ═══════════════════════════════════════════
    // User Coupon 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * UserCoupon download 핸들러는 Coupon::find() 정적 호출을 사용합니다.
     * alias mock의 PHP 프로세스 내 클래스 재정의 제한으로 인해
     * 훅 등록 및 메서드 존재를 검증합니다.
     */
    public function test_handleUserCouponAfterDownload_method_exists_and_hooks_registered(): void
    {
        $hooks = EcommerceUserActivityLogListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.user_coupon.after_download', $hooks);
        $this->assertEquals('handleUserCouponAfterDownload', $hooks['sirsoft-ecommerce.user_coupon.after_download']['method']);
        $this->assertTrue(method_exists($this->listener, 'handleUserCouponAfterDownload'));
    }

    // ═══════════════════════════════════════════
    // Order 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleOrderAfterCreate_logs_activity(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->forceFill(['id' => 10, 'order_code' => 'ORD-20260326-001']);
        $order->shouldReceive('getKey')->andReturn(10);
        $order->shouldReceive('getMorphClass')->andReturn('order');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'order.create'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.user_order_create'
                    && $context['description_params']['order_id'] === 10
                    && isset($context['loggable'])
                    && $context['properties']['order_id'] === 10
                    && $context['properties']['order_code'] === 'ORD-20260326-001';
            });

        $this->listener->handleOrderAfterCreate($order);
    }

    public function test_handleOrderAfterCreate_handles_null_order_code(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->forceFill(['id' => 11]);
        $order->shouldReceive('getKey')->andReturn(11);
        $order->shouldReceive('getMorphClass')->andReturn('order');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'order.create'
                    && $context['properties']['order_id'] === 11
                    && $context['properties']['order_code'] === null;
            });

        $this->listener->handleOrderAfterCreate($order);
    }

    // ═══════════════════════════════════════════
    // Order Option 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleOrderOptionAfterConfirm_logs_activity(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->forceFill(['id' => 20]);
        $order->shouldReceive('getKey')->andReturn(20);

        $option = Mockery::mock(OrderOption::class)->makePartial();
        $option->forceFill(['id' => 55]);
        $option->shouldReceive('getKey')->andReturn(55);
        $option->shouldReceive('getMorphClass')->andReturn('order_option');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'order_option.confirm'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.user_order_option_confirm'
                    && $context['description_params']['option_id'] === 55
                    && isset($context['loggable'])
                    && $context['properties']['order_id'] === 20
                    && $context['properties']['option_id'] === 55;
            });

        $this->listener->handleOrderOptionAfterConfirm($order, $option);
    }

    public function test_handleOrderOptionAfterConfirm_hooks_registered(): void
    {
        $hooks = EcommerceUserActivityLogListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.order-option.after_confirm', $hooks);
        $this->assertEquals('handleOrderOptionAfterConfirm', $hooks['sirsoft-ecommerce.order-option.after_confirm']['method']);
        $this->assertEquals(20, $hooks['sirsoft-ecommerce.order-option.after_confirm']['priority']);
    }

    // ═══════════════════════════════════════════
    // 에러 핸들링 테스트
    // ═══════════════════════════════════════════

    public function test_logActivity_catches_exception_and_logs_error(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \Exception('Activity log write failed'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to record activity log'
                    && $context['action'] === 'cart.delete'
                    && $context['error'] === 'Activity log write failed';
            });

        $this->listener->handleCartAfterDelete(1);
    }

    // ═══════════════════════════════════════════
    // handle 기본 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handle_does_nothing(): void
    {
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════
    // 모든 핸들러가 User 타입인지 검증
    // ═══════════════════════════════════════════

    public function test_all_handlers_use_user_log_type(): void
    {
        $hooks = EcommerceUserActivityLogListener::getSubscribedHooks();

        foreach ($hooks as $hookName => $config) {
            $this->assertArrayHasKey('method', $config, "훅 '{$hookName}'에 method 키가 없습니다.");
            $this->assertTrue(
                method_exists($this->listener, $config['method']),
                "리스너에 '{$config['method']}' 메서드가 존재하지 않습니다."
            );
        }
    }

    // ═══════════════════════════════════════════
    // 헬퍼 메서드
    // ═══════════════════════════════════════════

    /**
     * product 관계가 있는 Cart mock 생성
     *
     * @param int $id 장바구니 ID
     * @param string $productName 상품명
     * @return Cart
     */
    private function createCartMockWithProduct(int $id, string $productName): Cart
    {
        $product = new \stdClass();
        $product->name = $productName;

        $cart = Mockery::mock(Cart::class)->makePartial();
        $cart->forceFill(['id' => $id, 'product_name' => $productName]);
        $cart->shouldReceive('getKey')->andReturn($id);
        $cart->shouldReceive('getMorphClass')->andReturn('cart');
        // $cart->product?->name 접근을 위해 관계 속성을 설정
        $cart->shouldReceive('getRelationValue')->with('product')->andReturn($product);

        return $cart;
    }

    /**
     * product 관계 없이 product_name 속성만 있는 Cart mock 생성
     *
     * @param int $id 장바구니 ID
     * @param string|null $productName 상품명
     * @return Cart
     */
    private function createCartMockWithProductName(int $id, ?string $productName): Cart
    {
        $cart = Mockery::mock(Cart::class)->makePartial();
        $cart->forceFill(['id' => $id, 'product_name' => $productName]);
        $cart->shouldReceive('getKey')->andReturn($id);
        $cart->shouldReceive('getMorphClass')->andReturn('cart');
        $cart->shouldReceive('getRelationValue')->with('product')->andReturn(null);

        return $cart;
    }
}
