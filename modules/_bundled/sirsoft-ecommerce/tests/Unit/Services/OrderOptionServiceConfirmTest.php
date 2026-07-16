<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 주문옵션 구매확정(CONFIRMED) 전이 대칭성 테스트 (이슈 #61)
 *
 * 관리자 상태변경 경로(changeStatusWithQuantity / bulkChangeStatusWithQuantity)로
 * CONFIRMED 전이 시, 유저 셀프 확정(OrderService::confirmOption)과 동일하게
 *   1) 옵션 confirmed_at 기록 (전체수량 / 분할 양쪽)
 *   2) order-option.after_confirm 훅 발화 (즉시적립·구매확정 활동로그 트리거)
 *   3) delay=0 즉시 적립 + 멱등
 *   4) 부모 주문 헤더 confirmed_at 기록
 * 이 발생하는지 검증한다. delivered_at 회귀 가드도 함께 둔다.
 */
class OrderOptionServiceConfirmTest extends ModuleTestCase
{
    private OrderOptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderOptionService::class);
        $this->writeMileageSettings();
    }

    protected function tearDown(): void
    {
        $file = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/mileage.json');
        if (file_exists($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    /**
     * 마일리지 설정 파일을 작성합니다. (trigger=confirmed, delay 기본 0)
     *
     * @param  array  $overrides  덮어쓸 값
     */
    private function writeMileageSettings(array $overrides = []): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $settings = array_merge([
            'enabled' => true,
            'default_earn_rate' => 1,
            'earn_trigger' => 'confirmed',
            'earn_delay_days' => 0,
            'currency_rules' => [['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0]],
            'expiry_enabled' => true,
            'expiry_days' => 365,
            'expiry_notification_enabled' => true,
            'expiry_notification_days_before' => 7,
        ], $overrides);

        file_put_contents($path.'/mileage.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 배송완료 상태 옵션을 가진 주문을 생성합니다. (CONFIRMED 전이 전제 상태)
     *
     * @param  int  $quantity  옵션 수량
     * @param  int|null  $userId  주문자 (마일리지 적립 대상, null 이면 게스트)
     * @return Order
     */
    private function makeDeliveredOrder(int $quantity = 1, ?int $userId = null): Order
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::DELIVERED,
            'user_id' => $userId,
            'currency' => 'KRW',
        ]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::DELIVERED,
            'delivered_at' => now()->subDay(),
            'quantity' => $quantity,
            'subtotal_earned_points_amount' => 500,
        ]);

        return $order->fresh();
    }

    /**
     * 전체수량 CONFIRMED 전이 시 옵션 confirmed_at 이 기록된다.
     *
     * @scenario transition_path=change_with_quantity, target_status=confirmed, quantity=full
     *
     * @effects admin_full_quantity_confirm_stamps_confirmed_at
     */
    public function test_full_quantity_confirmed_stamps_confirmed_at(): void
    {
        $order = $this->makeDeliveredOrder(2);
        $option = $order->options->first();

        $this->service->changeStatusWithQuantity($option, OrderStatusEnum::CONFIRMED, $option->quantity);

        $fresh = $option->fresh();
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $fresh->option_status);
        $this->assertNotNull($fresh->confirmed_at, '전체수량 구매확정 시 confirmed_at 이 기록되어야 함');
        $this->assertEqualsWithDelta(now()->timestamp, $fresh->confirmed_at->timestamp, 5);
    }

    /**
     * 부분수량 CONFIRMED 분할 전이 시 분할 레코드에 confirmed_at 이 기록된다.
     * (원본이 DELIVERED 였으면 분할 레코드는 delivered_at 계승 + confirmed_at 신규를 함께 보유)
     *
     * @scenario transition_path=change_with_quantity, target_status=confirmed, quantity=partial
     *
     * @effects admin_split_confirm_stamps_confirmed_at
     */
    public function test_partial_quantity_confirmed_stamps_confirmed_at_on_split(): void
    {
        $order = $this->makeDeliveredOrder(5);
        $option = $order->options->first();

        $result = $this->service->changeStatusWithQuantity($option, OrderStatusEnum::CONFIRMED, 2);

        $split = $result['split'];
        $this->assertNotNull($split, '부분수량 전이는 분할 레코드를 생성해야 함');
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $split->option_status);
        $this->assertNotNull($split->confirmed_at, '분할 CONFIRMED 레코드에 confirmed_at 이 기록되어야 함');
        $this->assertEqualsWithDelta(now()->timestamp, $split->confirmed_at->timestamp, 5);
        // 원본에서 계승한 delivered_at 은 정상(배송완료·구매확정 둘 다 실제 발생한 사건)
        $this->assertNotNull($split->delivered_at, '분할 레코드는 원본의 delivered_at 을 계승한다');
    }

    /**
     * CONFIRMED 전이 시 order-option.after_confirm 훅이 발화되고,
     * 인자로 CONFIRMED 를 보유한 (병합 후) 생존 레코드가 전달된다.
     *
     * @scenario transition_path=change_with_quantity, target_status=confirmed, quantity=full
     *
     * @effects admin_confirm_fires_after_confirm_hook
     */
    public function test_confirmed_transition_fires_after_confirm_hook(): void
    {
        $captured = [];
        $cb = function ($order, $option) use (&$captured) {
            $captured[] = $option;
        };
        HookManager::addAction('sirsoft-ecommerce.order-option.after_confirm', $cb, 10);

        $order = $this->makeDeliveredOrder(1);
        $option = $order->options->first();

        $this->service->changeStatusWithQuantity($option, OrderStatusEnum::CONFIRMED, $option->quantity);

        HookManager::removeAction('sirsoft-ecommerce.order-option.after_confirm', $cb);

        $this->assertCount(1, $captured, 'CONFIRMED 전이 시 after_confirm 훅이 1회 발화되어야 함');
        $this->assertInstanceOf(OrderOption::class, $captured[0]);
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $captured[0]->option_status);
    }

    /**
     * 분할 CONFIRMED 전이 시 after_confirm 훅 인자는 CONFIRMED 를 보유한 분할 레코드다.
     *
     * @scenario transition_path=change_with_quantity, target_status=confirmed, quantity=partial
     *
     * @effects admin_split_confirm_fires_after_confirm_hook_with_split
     */
    public function test_partial_confirmed_transition_fires_after_confirm_hook_with_split(): void
    {
        $captured = [];
        $cb = function ($order, $option) use (&$captured) {
            $captured[] = $option->id;
        };
        HookManager::addAction('sirsoft-ecommerce.order-option.after_confirm', $cb, 10);

        $order = $this->makeDeliveredOrder(5);
        $option = $order->options->first();

        $result = $this->service->changeStatusWithQuantity($option, OrderStatusEnum::CONFIRMED, 2);

        HookManager::removeAction('sirsoft-ecommerce.order-option.after_confirm', $cb);

        $this->assertCount(1, $captured);
        $this->assertEquals($result['split']->id, $captured[0], 'after_confirm 은 CONFIRMED 를 보유한 분할 레코드를 넘겨야 함');
    }

    /**
     * trigger=confirmed & delay=0 설정에서 관리자 CONFIRMED 전이 시 마일리지가 즉시 적립되고 멱등하다.
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=confirmed, earn_delay_days=0
     *
     * @effects admin_confirm_earns_mileage_immediately_when_delay_zero
     */
    public function test_confirmed_transition_earns_mileage_immediately_when_delay_zero(): void
    {
        $user = User::factory()->create();
        $order = $this->makeDeliveredOrder(1, $user->id);
        $option = $order->options->first();

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => $option->quantity]],
            OrderStatusEnum::CONFIRMED
        );

        $this->assertSame(1, MileageTransaction::where('order_option_id', $option->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)->count(),
            '즉시 적립(delay=0) 이 1건 발생해야 함');

        // 스케줄러가 뒤이어 돌아도 whereNotExists(EARN_TYPES) 멱등 가드로 중복 적립 없음
        Artisan::call('sirsoft-ecommerce:earn-mileage');
        $this->assertSame(1, MileageTransaction::where('order_option_id', $option->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)->count(),
            '즉시적립 후 스케줄러가 이중 적립하지 않아야 함');
    }

    /**
     * 전체 옵션이 CONFIRMED 로 확정되면 부모 주문 헤더에도 confirmed_at 이 기록된다.
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=confirmed, order_fully_confirmed=true
     *
     * @effects admin_full_confirm_stamps_order_header_confirmed_at
     */
    public function test_full_confirm_stamps_order_header_confirmed_at(): void
    {
        $order = $this->makeDeliveredOrder(1);
        $option = $order->options->first();

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => $option->quantity]],
            OrderStatusEnum::CONFIRMED
        );

        $freshOrder = $order->fresh();
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $freshOrder->order_status);
        $this->assertNotNull($freshOrder->confirmed_at, '주문 전체 확정 시 주문 헤더 confirmed_at 이 기록되어야 함');
    }

    /**
     * delivered_at 회귀 가드: 전체수량 DELIVERED 전이 시 delivered_at 이 기록된다.
     *
     * @scenario transition_path=change_with_quantity, target_status=delivered, quantity=full
     *
     * @effects admin_full_quantity_delivered_stamps_delivered_at
     */
    public function test_full_quantity_delivered_stamps_delivered_at(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::SHIPPING]);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::SHIPPING,
            'quantity' => 1,
        ]);

        $this->service->changeStatusWithQuantity($option->fresh(), OrderStatusEnum::DELIVERED, 1);

        $fresh = $option->fresh();
        $this->assertEquals(OrderStatusEnum::DELIVERED, $fresh->option_status);
        $this->assertNotNull($fresh->delivered_at, '배송완료 전이 시 delivered_at 이 기록되어야 함');
    }
}
