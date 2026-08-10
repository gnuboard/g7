<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 부분수량 분할 시 적립 포인트 안분 절사 테스트
 *
 * 부분취소·부분 구매확정은 주문옵션 레코드를 분할하고 금액을 비율 안분한다. 적립 포인트만은
 * 원장이 정수로 확정하는 값인데 안분이 `round(..., 2)` 였다 — 3개 중 1개를 분할하면
 * 781 × (1/3) = 260.33 처럼 **원장에 존재할 수 없는 소수점 포인트**가 옵션 행에 남았고,
 * 그 값이 곧 적립 목표액(`earnForOrderOption` 의 target)이 되어 실제 지급 가능한 정수
 * 포인트와 어긋났다.
 *
 * 안분에도 주문 시점 절사 기준을 적용하고, 잔여분은 `원본 − 분할` 로 두어 총액을 보존한다.
 */
class OrderOptionSplitEarnedPointsRoundingTest extends ModuleTestCase
{
    private OrderOptionService $service;

    private string $settingsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrderOptionService::class);
        $this->settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (! is_dir($this->settingsPath)) {
            mkdir($this->settingsPath, 0755, true);
        }

        file_put_contents($this->settingsPath.'/mileage.json', json_encode([
            'enabled' => true,
            'default_earn_rate' => 1,
            'earn_trigger' => 'confirmed',
            // 분할 자체를 관측하기 위해 적립 트리거는 뒤로 미룬다 (분할 → 적립 순서 간섭 배제)
            'earn_delay_days' => 30,
            'currency_rules' => [[
                'currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1,
                'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0,
            ]],
            'expiry_enabled' => true,
            'expiry_days' => 365,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        $file = $this->settingsPath.'/mileage.json';
        if (file_exists($file)) {
            unlink($file);
        }

        parent::tearDown();
    }

    /**
     * 적립 포인트가 실린 배송완료 주문을 만듭니다.
     *
     * @param  int  $quantity  옵션 수량
     * @param  float  $earnedPoints  옵션 적립 포인트
     * @param  array|null  $mileagePolicySnapshot  주문 시점 마일리지 정책 스냅샷
     * @return Order 생성된 주문
     */
    private function makeOrder(int $quantity, float $earnedPoints, ?array $mileagePolicySnapshot = null): Order
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::DELIVERED,
            'currency' => 'KRW',
            'mileage_policy_snapshot' => $mileagePolicySnapshot,
        ]);

        OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::DELIVERED,
            'delivered_at' => now()->subDay(),
            'quantity' => $quantity,
            'subtotal_earned_points_amount' => $earnedPoints,
        ]);

        return $order->fresh();
    }

    #[Test]
    public function 분할된_적립_포인트에_소수점이_남지_않는다(): void
    {
        // 781점 / 3개 → 1개 분할 시 260.333... 점
        $order = $this->makeOrder(3, 781);
        $option = $order->options->first();

        $this->service->changeStatusWithQuantity($option, OrderStatusEnum::CANCELLED, 1);

        $points = Order::find($order->id)->options
            ->pluck('subtotal_earned_points_amount')
            ->map(fn ($v) => (float) $v);

        foreach ($points as $value) {
            $this->assertSame(
                (float) (int) $value,
                $value,
                "분할 안분 결과에 소수점 포인트가 남았다 ({$value}) — 원장은 정수 포인트만 기록한다"
            );
        }
    }

    #[Test]
    public function 분할해도_적립_총액이_보존된다(): void
    {
        $order = $this->makeOrder(3, 781);
        $option = $order->options->first();

        $this->service->changeStatusWithQuantity($option, OrderStatusEnum::CANCELLED, 1);

        $total = Order::find($order->id)->options->sum(fn ($o) => (float) $o->subtotal_earned_points_amount);

        $this->assertSame(781.0, $total, '분할 전후 적립 총액이 달라졌다 — 잔여분은 원본 − 분할이어야 한다');
    }

    #[Test]
    public function 안분도_주문_시점_절사_기준을_따른다(): void
    {
        // 주문 시점 기준: 10점 올림. 781 × (1/3) = 260.33 → 270
        $order = $this->makeOrder(3, 781, [
            'usable' => true,
            'currency' => 'KRW',
            'rule' => [
                MileageRounding::UNIT_KEY => '10',
                MileageRounding::METHOD_KEY => 'ceil',
            ],
        ]);
        $option = $order->options->first();

        $this->service->changeStatusWithQuantity($option, OrderStatusEnum::CANCELLED, 1);

        $fresh = Order::find($order->id)->options;
        $split = $fresh->firstWhere('option_status', OrderStatusEnum::CANCELLED);
        $remaining = $fresh->firstWhere('option_status', OrderStatusEnum::DELIVERED);

        $this->assertSame(270.0, (float) $split->subtotal_earned_points_amount);
        $this->assertSame(511.0, (float) $remaining->subtotal_earned_points_amount, '잔여분 = 원본 − 분할');
    }

    #[Test]
    public function 스냅샷이_없는_구형_주문은_1점_버림으로_안분된다(): void
    {
        // 이 기능 이전 주문에는 절사 기준이 없다 — 폴백이 정수화를 보장해야 한다.
        $order = $this->makeOrder(3, 781, null);
        $option = $order->options->first();

        $this->service->changeStatusWithQuantity($option, OrderStatusEnum::CANCELLED, 1);

        $fresh = Order::find($order->id)->options;
        $split = $fresh->firstWhere('option_status', OrderStatusEnum::CANCELLED);
        $remaining = $fresh->firstWhere('option_status', OrderStatusEnum::DELIVERED);

        $this->assertSame(260.0, (float) $split->subtotal_earned_points_amount);
        $this->assertSame(521.0, (float) $remaining->subtotal_earned_points_amount);
    }
}
