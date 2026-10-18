<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Console;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\ModuleSettingsInterface;
use App\Models\User;
use App\Services\ModuleSettingsService;
use Carbon\Carbon;
use Mockery;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ModuleInterface + ModuleSettingsInterface 결합 스텁
 * (두 인터페이스가 getSettingsDefaultsPath()를 공유하므로 intersection mock 불가 → abstract class 사용)
 */
abstract class CancelPendingPaymentOrdersModuleStub implements ModuleInterface, ModuleSettingsInterface {}

/**
 * 미결제 주문 만료 자동 취소 커맨드 테스트
 *
 * 입금기한 부류(가상계좌·무통장입금)와 주문대기 부류(결제창까지 갔으나 승인되지 않은 주문)를
 * 함께 다룬다. 취소 대상 주문에는 `attachOption()` 으로 상품 행을 붙이고, 마일리지 복원은
 * `BuildsClaimAdjustmentOrders` 로 실제 계산 경로를 거친 주문으로 검증한다.
 * 시나리오 SSoT: tests/scenarios/pending-order-expiry-cleanup.yaml
 */
class CancelPendingPaymentOrdersCommandTest extends ModuleTestCase
{
    use BuildsClaimAdjustmentOrders;

    /**
     * 테스트용 모듈 설정값
     */
    private array $moduleSettings = [];

    /**
     * 자동 취소를 켠 모듈 설정으로 테스트를 시작합니다.
     *
     * `module_setting()` 이 읽는 설정 저장소를 테스트용 배열로 바꿔, 각 테스트가 설정값을 직접 조작할 수 있게 한다.
     *
     * @return void 반환값 없음
     */
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
     * module_setting() 헬퍼가 사용하는 ModuleManagerInterface 와 이커머스 설정 서비스를 mock 합니다.
     *
     * 두 경로 모두 `$this->moduleSettings` 배열을 읽으므로, 테스트 중 배열을 바꾸면 다음 커맨드 실행이 그 값을 본다.
     *
     * @return void 반환값 없음
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

    /**
     * 정리 커맨드가 등록되어 있고 dry-run 으로 실행하면 성공 종료하는지 확인합니다.
     *
     * @scenario run_mode=dry_run
     *
     * @effects cleanup_command_is_registered
     *
     * @return void 반환값 없음
     */
    public function test_command_exists(): void
    {
        $this->artisan('sirsoft-ecommerce:cancel-pending-orders --dry-run')
            ->assertSuccessful();
    }

    /**
     * 입금 기한이 지난 가상계좌 주문은 취소됩니다.
     *
     * 자동취소는 주문 전체를 취소 신청서로 접수하므로 `attachOption()` 으로 상품 행을 붙여 실제 주문 모양을 맞춘다.
     *
     * @scenario order_class=deposit_due_vbank, elapsed=past_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects expired_vbank_order_is_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_cancels_expired_vbank_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);
        $this->attachOption($order);

        // 만료된 가상계좌 결제
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::VBANK,
            // 입금 전이다 — 팩토리 기본값(입금완료)이면 「입금이 기록된 결제 전 주문」(S3-D7)이 되어 환불신청서로 간다.
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'vbank_due_at' => Carbon::now()->subDay(), // 1일 전 만료
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 입금 기한(deposit_due_at)이 지난 무통장입금 주문은 취소됩니다.
     *
     * 상품 행은 `attachOption()` 으로 붙인다 — 상품 행이 없는 주문은 「상품 행 0건 클레임 금지」 가드에 걸려 접수되지 않는다.
     *
     * @scenario order_class=deposit_due_dbank, elapsed=past_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects expired_dbank_order_is_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_cancels_expired_dbank_manual_deposit_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);
        $this->attachOption($order);

        // 만료된 무통장입금(수동 입금확인) 결제 (DBANK 메서드 + deposit_due_at 사용)
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
            // 입금 전이다 — 팩토리 기본값(입금완료)이면 「입금이 기록된 결제 전 주문」(S3-D7)이 되어 환불신청서로 간다.
            'payment_status' => PaymentStatusEnum::READY,
            'deposit_due_at' => Carbon::now()->subDay(), // 1일 전 만료
        ]);

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 계좌이체(BANK)는 입금 기한 만료 자동취소 대상이 아닙니다.
     *
     * 자동취소 대상은 가상계좌(VBANK)와 무통장입금(DBANK)뿐이다.
     * 과거 쿼리가 DBANK 대신 BANK 를 매칭해 무통장입금 주문이
     * 자동취소에서 누락되던 회귀를 차단한다 (주문 442).
     *
     * @scenario order_class=bank_transfer, elapsed=past_threshold, run_mode=apply
     *
     * @effects bank_transfer_order_is_not_a_deposit_due_class
     *
     * @return void 반환값 없음
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

    /**
     * 입금 기한이 아직 남은 가상계좌 주문은 건드리지 않습니다.
     *
     * @scenario order_class=deposit_due_vbank, elapsed=within_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects non_expired_order_is_left_alone
     *
     * @return void 반환값 없음
     */
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

    /**
     * 입금 기한이 지났어도 이미 결제완료된 주문은 취소하지 않습니다.
     *
     * @scenario order_class=deposit_due_vbank, elapsed=past_threshold, payment_status=paid, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects already_paid_deposit_due_order_is_left_alone
     *
     * @return void 반환값 없음
     */
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

    /**
     * dry-run 은 대상을 찾기만 하고 주문 상태를 바꾸지 않습니다.
     *
     * @scenario order_class=deposit_due_vbank, elapsed=past_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=dry_run
     *
     * @effects dry_run_mutates_nothing
     *
     * @return void 반환값 없음
     */
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

    /**
     * --limit 옵션만큼만 취소합니다.
     *
     * 만료 주문 5건에 `--limit=2` 를 주면 2건만 취소 상태가 된다. 각 주문에는 `attachOption()` 으로 상품 행을 붙인다.
     *
     * @scenario order_class=deposit_due_vbank, elapsed=past_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects limit_option_is_respected
     *
     * @return void 반환값 없음
     */
    public function test_respects_limit_option(): void
    {
        $user = User::factory()->create();

        // 5개 만료 주문 생성
        for ($i = 0; $i < 5; $i++) {
            $order = Order::factory()->create([
                'user_id' => $user->id,
                'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            ]);
            $this->attachOption($order);

            OrderPayment::factory()->create([
                'order_id' => $order->id,
                'payment_method' => PaymentMethodEnum::VBANK,
                'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
                'vbank_due_at' => Carbon::now()->subDay(),
            ]);
        }

        // 2개만 처리
        $this->artisan('sirsoft-ecommerce:cancel-pending-orders --limit=2')
            ->assertSuccessful();

        $cancelledCount = Order::where('order_status', OrderStatusEnum::CANCELLED)->count();
        $this->assertEquals(2, $cancelledCount);
    }

    /**
     * 자동 취소 설정을 끄면 만료 주문이 있어도 실행 전체를 건너뜁니다.
     *
     * @scenario order_class=deposit_due_vbank, elapsed=past_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects disabled_toggle_skips_the_whole_run
     *
     * @return void 반환값 없음
     */
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

    /**
     * 정리할 주문이 없으면 안내 문구를 출력하고 성공 종료합니다.
     *
     * @scenario run_mode=apply
     *
     * @effects empty_run_reports_no_orders
     *
     * @return void 반환값 없음
     */
    public function test_outputs_no_orders_message_when_empty(): void
    {
        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->expectsOutput('처리할 만료 주문이 없습니다.')
            ->assertSuccessful();
    }

    /**
     * 결제창까지 갔으나 성립하지 않은 주문(PG 카드 등)도 경과 후 정리합니다.
     *
     * 이 부류는 입금 기한이라는 개념이 없어 종전에는 어떤 정리 주체도 없었다. 브라우저 리턴
     * 콜백이 주문 상태를 바꾸지 않게 되면서 승인 거절분도 여기에 머무르므로, 경과 기준으로
     * 정리해 선차감 마일리지가 무기한 묶이지 않게 한다.
     *
     * @scenario order_class=pending_order_never_paid, elapsed=past_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects stale_pending_order_is_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_cancels_stale_pending_order_that_never_completed_payment(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'ordered_at' => Carbon::now()->subDays(2),
        ]);
        $this->attachOption($order);

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
     * 아직 기준 시간이 지나지 않은 주문대기 주문은 건드리지 않습니다.
     *
     * 구매자가 결제창을 열어 둔 상태일 수 있으므로, 진행 중인 결제를 취소해 버리면 안 된다.
     *
     * @scenario order_class=pending_order_never_paid, elapsed=within_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects recent_pending_order_is_left_alone
     *
     * @return void 반환값 없음
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
     * 승인 콜백과 경쟁해 이미 결제가 성립한 주문은 정리 대상이 아닙니다.
     *
     * @scenario order_class=pending_order_never_paid, elapsed=past_threshold, payment_status=paid, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects paid_pending_order_is_left_alone
     *
     * @return void 반환값 없음
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
     * 만료 기준을 0 으로 두면 주문대기 주문 정리를 끌 수 있습니다 (운영자 선택권).
     *
     * @scenario order_class=pending_order_never_paid, elapsed=past_threshold, payment_status=ready_or_failed, expire_minutes_setting=zero, run_mode=apply
     *
     * @effects zero_setting_disables_pending_class_only
     *
     * @return void 반환값 없음
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
     * 미결제 주문을 정리하면 선차감 마일리지가 복원됩니다 — 이것이 이 정리를 넓힌 이유입니다.
     *
     * 마일리지 차감 시점을 '주문할 때'로 설정한 상점에서 카드 승인이 거절되면, 종전에는
     * 콜백의 실패 처리가 복원했다. 그 경로가 위조 가능해 막힌 뒤로는 이 정리가 복원을 맡는다.
     *
     * 돌려줄 몫은 취소 계산이 주문 스냅샷·상품 행 배분에서 찾으므로, 주문은
     * `BuildsClaimAdjustmentOrders` 의 실제 계산 경로(마일리지 500 사용)로 조립한다.
     * 복원은 가짜 리스너가 받은 금액이 아니라 실제 원장(`order_cancel_restore` 행)과 잔액으로 단언한다(T20).
     *
     * @scenario order_class=pending_order_never_paid, elapsed=past_threshold, payment_status=ready_or_failed, expire_minutes_setting=positive, run_mode=apply
     *
     * @effects stale_pending_order_is_cancelled, deducted_mileage_is_restored_on_cleanup
     *
     * @return void 반환값 없음
     */
    public function test_cancelling_stale_pending_order_restores_deducted_mileage(): void
    {
        // 돌려줄 마일리지 몫은 취소 계산이 주문 스냅샷·상품 행 배분에서 찾는다. 손으로 박은 금액은
        // 재계산과 어긋나 0 이 나오므로, 주문 생성과 같은 계산 경로로 조립한다.
        $policy = $this->createClaimShippingPolicy();
        [$product, $option] = $this->createClaimProduct(10000, $policy);
        $order = $this->createClaimOrder(
            new CalculationInput(
                items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
                usePoints: 500,
            ),
            [
                'order_status' => OrderStatusEnum::PENDING_ORDER,
                'ordered_at' => Carbon::now()->subDays(2),
                'is_mileage_deducted' => true,
                'total_paid_amount' => 0,
                'total_due_amount' => 0,
            ],
            [
                'payment_method' => PaymentMethodEnum::CARD,
                'payment_status' => PaymentStatusEnum::READY,
                'paid_amount_local' => 0,
                'paid_amount_base' => 0,
                'paid_at' => null,
            ],
        );
        $order->options()->update(['option_status' => OrderStatusEnum::PENDING_ORDER->value]);
        $this->assertSame(500, (int) $order->total_points_used_amount, '픽스처가 마일리지 500 을 쓰는 주문이 아니다');

        // 실제 차감 원장을 만든다 — 적립 500 → 주문 사용 500(FIFO). 잔액 0 에서 출발한다.
        $userId = (int) $order->user_id;
        MileageTransaction::create([
            'user_id' => $userId, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 500, 'remaining_amount' => 500, 'balance_after' => 500, 'expires_at' => now()->addYear(),
        ]);
        app(UserMileageService::class)->deductFifo($userId, 500, 'KRW', $order);

        // 가짜 리스너 없이 모듈이 등록한 실제 MileageTransactionListener 가 복원한다(T20)
        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);

        $restores = MileageTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value)
            ->get();
        $this->assertCount(1, $restores, '정리된 주문의 선차감 마일리지 복원 원장이 한 건 생겨야 합니다.');
        $this->assertSame(500.0, (float) $restores->first()->amount);
        $this->assertNotNull($restores->first()->order_cancel_id, '복원은 취소 ID 멱등 축에 묶인다');
        // 회원 잔액이 사용 전(500)으로 돌아왔다
        $this->assertSame(500.0, (float) MileageTransaction::query()->where('user_id', $userId)->where('currency', 'KRW')->sum('amount'));
    }

    /**
     * 주문에 상품 행 하나를 붙입니다.
     *
     * 자동취소는 주문 전체를 취소 신청서로 접수하는데, 상품 행이 없는 주문은 「상품 행 0건 클레임 금지」
     * 가드에 걸려 접수되지 않는다. 실제 주문은 언제나 상품 행을 가지므로 픽스처도 그 모양을 따른다.
     *
     * @param  Order  $order  대상 주문
     * @return void 반환값 없음
     */
    private function attachOption(Order $order): void
    {
        OrderOption::factory()->forOrder($order)->create([
            'option_status' => $order->order_status,
        ]);
    }
}
