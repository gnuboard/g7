<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Console;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\ModuleSettingsInterface;
use App\Models\User;
use Carbon\Carbon;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ModuleInterface + ModuleSettingsInterface 결합 스텁
 * (두 인터페이스가 getSettingsDefaultsPath()를 공유하므로 intersection mock 불가 → abstract class 사용)
 */
abstract class CancelPendingPaymentOrdersModuleStub implements ModuleInterface, ModuleSettingsInterface {}

/**
 * 입금 기한 만료 주문 자동 취소 커맨드 테스트
 */
class CancelPendingPaymentOrdersCommandTest extends ModuleTestCase
{
    /**
     * 테스트용 모듈 설정값
     */
    private array $moduleSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 자동 취소 기능 활성화 (module_setting() mock)
        $this->moduleSettings = [
            'order_settings.auto_cancel_expired' => true,
        ];

        $this->mockModuleSetting();
    }

    /**
     * module_setting() 헬퍼가 사용하는 ModuleManagerInterface를 mock합니다.
     */
    private function mockModuleSetting(): void
    {
        $mockModule = $this->createMock(CancelPendingPaymentOrdersModuleStub::class);
        $mockModule->method('getSetting')
            ->willReturnCallback(function (string $key, mixed $default = null) {
                return array_key_exists($key, $this->moduleSettings)
                    ? $this->moduleSettings[$key]
                    : $default;
            });

        $mockModuleManager = $this->createMock(ModuleManagerInterface::class);
        $mockModuleManager->method('getModule')
            ->with('sirsoft-ecommerce')
            ->willReturn($mockModule);

        $this->app->instance(ModuleManagerInterface::class, $mockModuleManager);

        // ModuleSettingsService 는 ModuleManagerInterface 를 생성 시점에 주입받으므로
        // instance 교체 후 기존 service 인스턴스를 forget 해 다음 resolve 에서 새 mock 사용
        $this->app->forgetInstance(\App\Services\ModuleSettingsService::class);

        // sirsoft-ecommerce 는 전용 EcommerceSettingsService 가 자동 discover 되어
        // ModuleSettingsService::get 에서 먼저 위임됨. 이 서비스를 모듈 설정 mock 으로 교체.
        $mockEcommerceSettings = Mockery::mock(\Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService::class)->makePartial();
        $mockEcommerceSettings->shouldReceive('getSetting')
            ->andReturnUsing(function (string $key, mixed $default = null) {
                return array_key_exists($key, $this->moduleSettings)
                    ? $this->moduleSettings[$key]
                    : $default;
            });
        $this->app->instance(\Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService::class, $mockEcommerceSettings);
    }

    public function test_command_exists(): void
    {
        $this->artisan('sirsoft-ecommerce:cancel-pending-orders --dry-run')
            ->assertSuccessful();
    }

    public function test_cancels_expired_vbank_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);

        // 만료된 가상계좌 결제
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::VBANK,
            'vbank_due_at' => Carbon::now()->subDay(), // 1일 전 만료
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    public function test_cancels_expired_manual_bank_transfer_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);

        // 만료된 수동 무통장입금 결제 (BANK 메서드 + deposit_due_at 사용)
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::BANK,
            'deposit_due_at' => Carbon::now()->subDay(), // 1일 전 만료
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    public function test_does_not_cancel_non_expired_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);

        // 아직 만료되지 않은 가상계좌
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::VBANK,
            'vbank_due_at' => Carbon::now()->addDays(2), // 2일 후 만료
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
    }

    public function test_does_not_cancel_paid_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE, // 이미 결제됨
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::VBANK,
            'vbank_due_at' => Carbon::now()->subDay(),
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_dry_run_does_not_cancel(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::VBANK,
            'vbank_due_at' => Carbon::now()->subDay(),
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders --dry-run')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
    }

    public function test_respects_limit_option(): void
    {
        $user = User::factory()->create();

        // 5개 만료 주문 생성
        for ($i = 0; $i < 5; $i++) {
            $order = Order::factory()->create([
                'user_id' => $user->id,
                'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            ]);

            OrderPayment::factory()->create([
                'order_id' => $order->id,
                'payment_method' => PaymentMethodEnum::VBANK,
                'vbank_due_at' => Carbon::now()->subDay(),
            ]);
        }

        // 2개만 처리
        $this->artisan('sirsoft-ecommerce:cancel-pending-orders --limit=2')
            ->assertSuccessful();

        $cancelledCount = Order::where('order_status', OrderStatusEnum::CANCELLED)->count();
        $this->assertEquals(2, $cancelledCount);
    }

    public function test_disabled_when_config_is_false(): void
    {
        $this->moduleSettings['order_settings.auto_cancel_expired'] = false;
        $this->mockModuleSetting();

        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::VBANK,
            'vbank_due_at' => Carbon::now()->subDay(),
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
    }

    public function test_outputs_no_orders_message_when_empty(): void
    {
        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->expectsOutput('처리할 만료 주문이 없습니다.')
            ->assertSuccessful();
    }
}
