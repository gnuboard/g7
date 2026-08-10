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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ModuleInterface + ModuleSettingsInterface 결합 스텁 (타입 안전성 테스트 전용)
 */
abstract class OrderProcessingTypeSafetyModuleStub implements ModuleInterface, ModuleSettingsInterface {}

/**
 * 입금기한 산정의 설정값 타입 안전성 회귀 테스트
 *
 * auto_cancel_days 가 문자열("3")·빈 문자열·null·범위 밖 값으로 저장되어 있어도
 * 무통장입금(vbank/dbank) 주문 생성이 Carbon TypeError 없이 성공해야 한다.
 *
 * 배경: Carbon 3.x 의 rawAddUnit(int|float $value) 는 strict_types 파일에서 호출되므로
 * 숫자 문자열이 강제변환되지 않고 TypeError 를 던진다. 관리자 환경설정을 한 번만
 * 저장해도 HTML number 입력의 문자열 값이 그대로 영속되어 전 무통장입금 주문이 500 실패했다.
 */
class OrderProcessingDueAtTypeSafetyTest extends ModuleTestCase
{
    protected OrderProcessingService $service;

    private array $moduleSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleSettings = [];
        $this->mockModuleSetting();
        $this->service = app(OrderProcessingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 설정 조회를 스텁으로 대체합니다 (저장 타입을 그대로 반환 — 정규화 계층 우회).
     */
    private function mockModuleSetting(): void
    {
        $mockModule = $this->createMock(OrderProcessingTypeSafetyModuleStub::class);
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
        $m->invoke($this->service, $order, $method, '홍길동', $dbankInfo, $this->makeCalculationResult(), []);
    }

    private function dbankInfo(): array
    {
        return ['bank_code' => '004', 'account_number' => '123', 'account_holder' => '홍길동'];
    }

    /**
     * 정상 정수로 저장된 경우 (회귀 기준선).
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function intValueProvider(): array
    {
        return [
            '정수 3 (기본값과 동일)' => [3, 3],
            '정수 5' => [5, 5],
        ];
    }

    /**
     * 숫자 문자열로 저장된 경우 — 크래시 원인이던 형태 + 상한 클램프.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function numericStringValueProvider(): array
    {
        return [
            '숫자 문자열 "3"' => ['3', 3],
            '앞자리 0 문자열 "05"' => ['05', 5],
            '상한 초과 "999"' => ['999', 30],
        ];
    }

    /**
     * 사용할 수 없는 값 — 전부 기본값(3)으로 되돌아가야 한다.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function degenerateValueProvider(): array
    {
        return [
            '빈 문자열' => ['', 3],
            'null' => [null, 3],
            '0' => [0, 3],
            '문자열 "0"' => ['0', 3],
            '음수 문자열 "-5"' => ['-5', 3],
            '비숫자 문자열' => ['abc', 3],
        ];
    }

    /**
     * vbank_due_at 을 검증합니다.
     *
     * @param  mixed  $stored  저장 형태 그대로의 설정값
     * @param  int  $expectedDays  기대 입금기한(일)
     */
    private function assertVbankDueDays(mixed $stored, int $expectedDays): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 21, 12, 0, 0));
        $this->moduleSettings = ['order_settings.auto_cancel_days' => $stored];
        $order = Order::factory()->create();

        $this->invokeCreatePayment($order, 'vbank');

        $payment = $order->payment()->first();
        $this->assertNotNull($payment->vbank_due_at);
        $this->assertSame(
            Carbon::now()->addDays($expectedDays)->toDateString(),
            Carbon::parse($payment->vbank_due_at)->toDateString(),
        );
    }

    /**
     * deposit_due_at 을 검증합니다.
     *
     * @param  mixed  $stored  저장 형태 그대로의 설정값
     * @param  int  $expectedDays  기대 입금기한(일)
     */
    private function assertDbankDueDays(mixed $stored, int $expectedDays): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 21, 12, 0, 0));
        $this->moduleSettings = ['order_settings.auto_cancel_days' => $stored];
        $order = Order::factory()->create();

        $this->invokeCreatePayment($order, 'dbank', $this->dbankInfo());

        $payment = $order->payment()->first();
        $this->assertNotNull($payment->deposit_due_at);
        $this->assertSame(
            Carbon::now()->addDays($expectedDays)->toDateString(),
            Carbon::parse($payment->deposit_due_at)->toDateString(),
        );
    }

    /**
     * @scenario payment_method=vbank, setting_value_type=int
     *
     * @effects vbank_due_at_survives_string_setting
     */
    #[DataProvider('intValueProvider')]
    public function test_vbank_due_at_with_int_setting(mixed $stored, int $expectedDays): void
    {
        $this->assertVbankDueDays($stored, $expectedDays);
    }

    /**
     * @scenario payment_method=vbank, setting_value_type=numeric_string
     *
     * @effects vbank_due_at_survives_string_setting, due_days_clamped_into_allowed_range
     */
    #[DataProvider('numericStringValueProvider')]
    public function test_vbank_due_at_with_numeric_string_setting(mixed $stored, int $expectedDays): void
    {
        $this->assertVbankDueDays($stored, $expectedDays);
    }

    /**
     * @scenario payment_method=vbank, setting_value_type=degenerate
     *
     * @effects vbank_due_at_survives_string_setting, due_days_clamped_into_allowed_range
     */
    #[DataProvider('degenerateValueProvider')]
    public function test_vbank_due_at_with_unusable_setting(mixed $stored, int $expectedDays): void
    {
        $this->assertVbankDueDays($stored, $expectedDays);
    }

    /**
     * @scenario payment_method=dbank, setting_value_type=int
     *
     * @effects deposit_due_at_survives_string_setting
     */
    #[DataProvider('intValueProvider')]
    public function test_dbank_due_at_with_int_setting(mixed $stored, int $expectedDays): void
    {
        $this->assertDbankDueDays($stored, $expectedDays);
    }

    /**
     * @scenario payment_method=dbank, setting_value_type=numeric_string
     *
     * @effects deposit_due_at_survives_string_setting, due_days_clamped_into_allowed_range
     */
    #[DataProvider('numericStringValueProvider')]
    public function test_dbank_due_at_with_numeric_string_setting(mixed $stored, int $expectedDays): void
    {
        $this->assertDbankDueDays($stored, $expectedDays);
    }

    /**
     * @scenario payment_method=dbank, setting_value_type=degenerate
     *
     * @effects deposit_due_at_survives_string_setting, due_days_clamped_into_allowed_range
     */
    #[DataProvider('degenerateValueProvider')]
    public function test_dbank_due_at_with_unusable_setting(mixed $stored, int $expectedDays): void
    {
        $this->assertDbankDueDays($stored, $expectedDays);
    }

    /**
     * 문자열 설정에서도 클라이언트가 보낸 due_days 는 여전히 무시되어야 한다 (E6 비회귀).
     *
     * @effects client_due_days_is_ignored
     */
    public function test_dbank_still_ignores_client_due_days_when_setting_is_string(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 21, 12, 0, 0));
        $this->moduleSettings = ['order_settings.auto_cancel_days' => '3'];
        $order = Order::factory()->create();

        // 클라이언트가 문자열로 보내도(정수와 동일하게) 무시되어야 한다.
        $this->invokeCreatePayment($order, 'dbank', $this->dbankInfo() + ['due_days' => '5']);

        $payment = $order->payment()->first();
        $this->assertSame(
            Carbon::now()->addDays(3)->toDateString(),
            Carbon::parse($payment->deposit_due_at)->toDateString(),
        );
    }

    /**
     * 설정 키 자체가 없는 환경(신규 설치·구버전 설정 파일)에서도 기본값으로 동작해야 한다.
     *
     * 값이 null 인 경우와 달리, 키가 아예 없으면 조회가 default 인자로 폴백한다 —
     * 별도 경로이므로 따로 고정한다.
     *
     * @scenario payment_method=dbank, setting_value_type=degenerate
     *
     * @effects deposit_due_at_survives_string_setting, due_days_clamped_into_allowed_range
     */
    public function test_dbank_due_at_falls_back_when_setting_key_is_absent(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 21, 12, 0, 0));
        $this->moduleSettings = [];  // 키 자체가 없음
        $order = Order::factory()->create();

        $this->invokeCreatePayment($order, 'dbank', $this->dbankInfo());

        $payment = $order->payment()->first();
        $this->assertSame(
            Carbon::now()->addDays(3)->toDateString(),
            Carbon::parse($payment->deposit_due_at)->toDateString(),
        );
    }

    /**
     * vbank 도 동일하게 설정 키 부재 시 기본값으로 동작한다.
     *
     * @scenario payment_method=vbank, setting_value_type=degenerate
     *
     * @effects vbank_due_at_survives_string_setting, due_days_clamped_into_allowed_range
     */
    public function test_vbank_due_at_falls_back_when_setting_key_is_absent(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 21, 12, 0, 0));
        $this->moduleSettings = [];  // 키 자체가 없음
        $order = Order::factory()->create();

        $this->invokeCreatePayment($order, 'vbank');

        $payment = $order->payment()->first();
        $this->assertSame(
            Carbon::now()->addDays(3)->toDateString(),
            Carbon::parse($payment->vbank_due_at)->toDateString(),
        );
    }
}
