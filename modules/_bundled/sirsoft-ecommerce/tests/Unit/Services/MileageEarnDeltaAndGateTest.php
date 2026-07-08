<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 적립 델타 멱등 + enabled 게이트 통일 테스트 (검수 후속 2·3)
 *
 * 후속-2: 수량을 나눠 구매확정 → 병합 시 목표 적립액(옵션 subtotal_earned)과
 *         기적립 purchase_earn 합계의 델타만큼 추가 적립되어 총액이 정확해야 한다.
 *         (건수 멱등이 아니라 금액 델타 멱등, 방식 A = 옵션 기존 lot 증액.)
 * 후속-3: mileage.enabled=OFF 면 즉시적립(delay=0)도 발생하지 않아야 한다
 *         (지연적립이 스케줄러 게이트로 막히는 것과 미지급 기준으로 통일).
 */
class MileageEarnDeltaAndGateTest extends ModuleTestCase
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
     * 배송완료 상태 옵션(적립예정 있음)을 가진 회원 주문을 생성합니다.
     *
     * @param  int  $quantity  옵션 수량
     * @param  float  $earnPoints  적립예정 포인트 소계
     * @return Order
     */
    private function makeDeliveredOrder(int $quantity, float $earnPoints): Order
    {
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::DELIVERED,
            'user_id' => $user->id,
            'currency' => 'KRW',
        ]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::DELIVERED,
            'delivered_at' => now()->subDay(),
            'quantity' => $quantity,
            'subtotal_earned_points_amount' => $earnPoints,
        ]);

        return $order->fresh();
    }

    /**
     * 해당 주문의 구매적립(purchase_earn) 총액을 반환합니다.
     *
     * @param  int  $orderId  주문 ID
     * @return float
     */
    private function purchaseEarnTotal(int $orderId): float
    {
        return (float) MileageTransaction::where('order_id', $orderId)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->sum('amount');
    }

    // ---- 후속-2: 델타 멱등 ----

    /**
     * 수량 2(적립예정 200P) 를 1개씩 두 번 구매확정하면 총 200P 가 적립된다.
     * (두 번째 확정이 첫 분할 레코드로 병합되어도 델타 100P 가 추가 적립되어야 함)
     *
     * @effects admin_split_then_merge_confirm_earns_full_delta
     */
    public function test_split_then_merge_confirm_earns_full_amount(): void
    {
        $order = $this->makeDeliveredOrder(2, 200);
        $option = $order->options->first();

        // 1차: 1개만 구매확정 → 분할 + 100P 적립
        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => 1]],
            OrderStatusEnum::CONFIRMED
        );
        $this->assertEqualsWithDelta(100.0, $this->purchaseEarnTotal($order->id), 0.5, '1차 확정 후 100P 적립되어야 함');

        // 2차: 나머지 1개 구매확정 → 병합 + 델타 100P 추가 적립
        $remaining = $order->options()
            ->whereIn('option_status', [OrderStatusEnum::DELIVERED->value])
            ->first();
        $this->assertNotNull($remaining, '잔여(배송완료) 레코드가 남아 있어야 함');

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $remaining->id, 'quantity' => 1]],
            OrderStatusEnum::CONFIRMED
        );

        $this->assertEqualsWithDelta(200.0, $this->purchaseEarnTotal($order->id), 0.5, '나눠 확정 총합은 200P 여야 함');
    }

    /**
     * 3분할(수량 3, 300P)을 1개씩 세 번 확정하면 누적 300P.
     *
     * @effects admin_three_way_split_confirm_accumulates
     */
    public function test_three_way_split_confirm_accumulates_to_full(): void
    {
        $order = $this->makeDeliveredOrder(3, 300);

        for ($i = 0; $i < 3; $i++) {
            $target = $order->fresh()->options()
                ->where('option_status', OrderStatusEnum::DELIVERED->value)
                ->first();
            $this->assertNotNull($target, "{$i}회차 확정 대상(배송완료)이 있어야 함");
            $this->service->bulkChangeStatusWithQuantity(
                [['option_id' => $target->id, 'quantity' => 1]],
                OrderStatusEnum::CONFIRMED
            );
        }

        $this->assertEqualsWithDelta(300.0, $this->purchaseEarnTotal($order->id), 0.5, '3분할 누적 300P');
    }

    /**
     * 전량 확정(분할 없음) 후 스케줄러/재확정 재실행해도 델타=0 이라 중복 적립 없음.
     *
     * @effects admin_full_confirm_delta_idempotent
     */
    public function test_full_confirm_is_delta_idempotent(): void
    {
        $order = $this->makeDeliveredOrder(1, 150);
        $option = $order->options->first();

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => 1]],
            OrderStatusEnum::CONFIRMED
        );
        $this->assertEqualsWithDelta(150.0, $this->purchaseEarnTotal($order->id), 0.5);

        // 스케줄러 재실행 → 델타 0 → 추가 없음
        Artisan::call('sirsoft-ecommerce:earn-mileage');
        $this->assertEqualsWithDelta(150.0, $this->purchaseEarnTotal($order->id), 0.5, '재실행 시 중복 적립 없어야 함');
    }

    // ---- 후속-3: enabled 게이트 미지급 통일 ----

    /**
     * enabled=OFF + delay=0: 관리자 CONFIRMED 전이 시 즉시적립이 발생하지 않는다.
     *
     * @effects admin_confirm_no_earn_when_disabled_immediate
     */
    public function test_no_immediate_earn_when_mileage_disabled(): void
    {
        $this->writeMileageSettings(['enabled' => false, 'earn_delay_days' => 0]);
        $order = $this->makeDeliveredOrder(1, 120);
        $option = $order->options->first();

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => 1]],
            OrderStatusEnum::CONFIRMED
        );

        $this->assertSame(0.0, $this->purchaseEarnTotal($order->id), '기능 OFF 면 즉시적립도 발생하지 않아야 함');
        // 전이 자체는 성공 (confirmed_at 기록)
        $this->assertNotNull($option->fresh()->confirmed_at, '기능 OFF 여도 구매확정 전이·시점 기록은 정상');
    }

    /**
     * enabled=ON 대조군: delay=0 즉시적립 정상 발생 (회귀 대조).
     *
     * @effects admin_confirm_earns_when_enabled_immediate
     */
    public function test_immediate_earn_when_mileage_enabled(): void
    {
        $order = $this->makeDeliveredOrder(1, 120);
        $option = $order->options->first();

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => 1]],
            OrderStatusEnum::CONFIRMED
        );

        $this->assertEqualsWithDelta(120.0, $this->purchaseEarnTotal($order->id), 0.5, '기능 ON 이면 즉시적립 정상');
    }
}
