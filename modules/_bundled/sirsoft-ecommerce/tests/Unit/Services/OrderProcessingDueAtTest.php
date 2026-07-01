<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\ModuleSettingsInterface;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Carbon;
use Mockery;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\PromotionsSummary;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ModuleInterface + ModuleSettingsInterface 결합 스텁
 */
abstract class OrderProcessingDueAtModuleStub implements ModuleInterface, ModuleSettingsInterface {}

/**
 * 입금 대기 due_at 산정 단일화 테스트 (A3)
 *
 * vbank_due_at / deposit_due_at 산정 출처가 단일 SSoT auto_cancel_days 로 통일되었는지,
 * dbank 의 per-order due_days 명시 전달분이 우선순위를 유지하는지 검증.
 */
class OrderProcessingDueAtTest extends ModuleTestCase
{
    protected OrderProcessingService $service;

    private array $moduleSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleSettings = [
            'order_settings.auto_cancel_days' => 5,
        ];
        $this->mockModuleSetting();
        $this->service = app(OrderProcessingService::class);
    }

    private function mockModuleSetting(): void
    {
        $mockModule = $this->createMock(OrderProcessingDueAtModuleStub::class);
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
        $this->app->forgetInstance(ModuleSettingsService::class);

        $mockEcommerceSettings = Mockery::mock(EcommerceSettingsService::class)->makePartial();
        $mockEcommerceSettings->shouldReceive('getSetting')
            ->andReturnUsing(function (string $key, mixed $default = null) {
                return array_key_exists($key, $this->moduleSettings)
                    ? $this->moduleSettings[$key]
                    : $default;
            });
        $this->app->instance(EcommerceSettingsService::class, $mockEcommerceSettings);
    }

    private function makeCalculationResult(int $finalAmount = 50000): OrderCalculationResult
    {
        $summary = new Summary(
            subtotal: $finalAmount,
            totalDiscount: 0,
            productCouponDiscount: 0,
            codeDiscount: 0,
            totalShipping: 0,
            taxableAmount: $finalAmount,
            taxFreeAmount: 0,
            pointsUsed: 0,
            pointsEarning: 0,
            paymentAmount: $finalAmount,
            finalAmount: $finalAmount,
        );

        return new OrderCalculationResult(
            items: [],
            summary: $summary,
            promotions: new PromotionsSummary,
            validationErrors: [],
        );
    }

    private function invokeCreatePayment(Order $order, string $method, ?array $dbankInfo = null): void
    {
        $reflection = new \ReflectionClass($this->service);
        $m = $reflection->getMethod('createOrderPayment');
        $m->setAccessible(true);
        $m->invoke($this->service, $order, $method, '홍길동', $dbankInfo, $this->makeCalculationResult(), []);
    }

    public function test_vbank_due_at_uses_auto_cancel_days(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 21, 12, 0, 0));
        $order = Order::factory()->create();

        $this->invokeCreatePayment($order, 'vbank');

        $payment = $order->payment()->first();
        $this->assertNotNull($payment->vbank_due_at);
        $this->assertSame(
            Carbon::now()->addDays(5)->toDateString(),
            Carbon::parse($payment->vbank_due_at)->toDateString(),
        );

        Carbon::setTestNow();
    }

    public function test_dbank_due_at_uses_auto_cancel_days_when_no_per_order_override(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 21, 12, 0, 0));
        $order = Order::factory()->create();

        // per-order due_days 미지정 → auto_cancel_days(5) 사용
        $this->invokeCreatePayment($order, 'dbank', ['bank_code' => '004', 'account_number' => '123', 'account_holder' => '홍길동']);

        $payment = $order->payment()->first();
        $this->assertNotNull($payment->deposit_due_at);
        $this->assertSame(
            Carbon::now()->addDays(5)->toDateString(),
            Carbon::parse($payment->deposit_due_at)->toDateString(),
        );

        Carbon::setTestNow();
    }

    public function test_dbank_per_order_due_days_takes_priority(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 21, 12, 0, 0));
        $order = Order::factory()->create();

        // per-order due_days=10 명시 → auto_cancel_days(5)보다 우선
        $this->invokeCreatePayment($order, 'dbank', [
            'bank_code' => '004', 'account_number' => '123', 'account_holder' => '홍길동', 'due_days' => 10,
        ]);

        $payment = $order->payment()->first();
        $this->assertSame(
            Carbon::now()->addDays(10)->toDateString(),
            Carbon::parse($payment->deposit_due_at)->toDateString(),
        );

        Carbon::setTestNow();
    }
}
