<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Console;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\ModuleSettingsInterface;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\ModuleSettingsService;
use Carbon\Carbon;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
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
        $this->app->forgetInstance(ModuleSettingsService::class);

        // sirsoft-ecommerce 는 전용 EcommerceSettingsService 가 자동 discover 되어
        // ModuleSettingsService::get 에서 먼저 위임됨. 이 서비스를 모듈 설정 mock 으로 교체.
        $mockEcommerceSettings = Mockery::mock(EcommerceSettingsService::class)->makePartial();
        $mockEcommerceSettings->shouldReceive('getSetting')
            ->andReturnUsing(function (string $key, mixed $default = null) {
                return array_key_exists($key, $this->moduleSettings)
                    ? $this->moduleSettings[$key]
                    : $default;
            });
        $this->app->instance(EcommerceSettingsService::class, $mockEcommerceSettings);
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

    public function test_cancels_expired_dbank_manual_deposit_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);

        // 만료된 무통장입금(수동 입금확인) 결제 (DBANK 메서드 + deposit_due_at 사용)
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
            'deposit_due_at' => Carbon::now()->subDay(), // 1일 전 만료
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 계좌이체(BANK)는 입금 기한 만료 자동취소 대상이 아니다.
     *
     * 자동취소 대상은 가상계좌(VBANK)와 무통장입금(DBANK)뿐이다.
     * 과거 쿼리가 DBANK 대신 BANK 를 매칭해 무통장입금 주문이
     * 자동취소에서 누락되던 회귀를 차단한다 (주문 442).
     */
    public function test_does_not_cancel_expired_bank_transfer_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);

        // 계좌이체(BANK)는 입금 기한 만료 자동취소 대상이 아님
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::BANK,
            'deposit_due_at' => Carbon::now()->subDay(),
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
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

    /**
     * 결제창까지 갔으나 성립하지 않은 주문(PG 카드 등)도 경과 후 정리한다.
     *
     * 이 부류는 입금 기한이라는 개념이 없어 종전에는 어떤 정리 주체도 없었다. 브라우저 리턴
     * 콜백이 주문 상태를 바꾸지 않게 되면서 승인 거절분도 여기에 머무르므로, 경과 기준으로
     * 정리해 선차감 마일리지가 무기한 묶이지 않게 한다.
     */
    public function test_cancels_stale_pending_order_that_never_completed_payment(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'ordered_at' => Carbon::now()->subDays(2),
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD,
            'payment_status' => PaymentStatusEnum::READY,
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 아직 기한이 지나지 않은 주문대기 주문은 건드리지 않는다 — 구매자가 결제창을 열어 둔
     * 상태일 수 있으므로, 진행 중인 결제를 취소해 버리면 안 된다.
     */
    public function test_does_not_cancel_recent_pending_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'ordered_at' => Carbon::now()->subMinutes(5),
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD,
            'payment_status' => PaymentStatusEnum::READY,
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 승인 콜백과 경쟁해 이미 결제가 성립한 주문은 정리 대상이 아니다.
     */
    public function test_does_not_cancel_stale_pending_order_whose_payment_is_paid(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'ordered_at' => Carbon::now()->subDays(2),
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD,
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_at' => Carbon::now()->subDay(),
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 만료 기준을 0 으로 두면 주문대기 주문 정리를 끌 수 있다 (운영자 선택권).
     */
    public function test_pending_order_cleanup_can_be_disabled_by_setting(): void
    {
        $this->moduleSettings['order_settings.pending_order_expire_minutes'] = 0;

        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'ordered_at' => Carbon::now()->subDays(2),
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD,
            'payment_status' => PaymentStatusEnum::READY,
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 정리 시 선차감 마일리지가 복원된다 — 이것이 이 정리를 넓힌 이유다.
     *
     * 마일리지 차감 시점을 '주문할 때'로 설정한 상점에서 카드 승인이 거절되면, 종전에는
     * 콜백의 실패 처리가 복원했다. 그 경로가 위조 가능해 막힌 뒤로는 이 정리가 복원을 맡는다.
     */
    public function test_cancelling_stale_pending_order_restores_deducted_mileage(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'ordered_at' => Carbon::now()->subDays(2),
            'is_mileage_deducted' => true,
            'total_points_used_amount' => 500,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD,
            'payment_status' => PaymentStatusEnum::READY,
        ]);

        // 복원은 마일리지 리스너에 위임되므로, 복원이 일어났다는 신호는 이 훅의 발화다
        // (취소 서비스는 플래그를 되돌리지 않고 취소 레코드 기준으로 멱등성을 보장한다).
        $restoredAmounts = [];
        HookManager::addAction(
            'sirsoft-ecommerce.mileage.restore',
            function ($amount) use (&$restoredAmounts) {
                $restoredAmounts[] = $amount;
            },
            10,
            ['sync' => true],
        );

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertNotEmpty(
            $restoredAmounts,
            '정리된 주문의 선차감 마일리지 복원이 일어나지 않았습니다.'
        );
        $this->assertSame(500, (int) $restoredAmounts[0]);
    }
}
