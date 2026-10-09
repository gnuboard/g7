<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Console;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 스케줄러 적립은 트리거 상태를 지난 상품도 잡는다 (F4)
 *
 * 적립 대상 조회가 `option_status = 트리거` 만 보아, 「배송완료 + 지연 N일」 설정에서 지연 중에 구매확정된 상품은 배송완료가
 * 아니라서 스케줄러가 영영 적립하지 않았다. 즉시 적립이 실패한 뒤 상품이 다음 단계로 넘어간 경우도 같았다.
 *
 * 시나리오 매니페스트: `tests/scenarios/delayed-earn-past-trigger.yaml`
 */
class DelayedEarnPastTriggerTest extends ModuleTestCase
{
    /**
     * 마일리지 설정 파일을 씁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
        file_put_contents($path.'/mileage.json', json_encode([
            'enabled' => true,
            'default_earn_rate' => 1,
            'earn_trigger' => 'delivered',
            'earn_delay_days' => 3,
            'currency_rules' => [['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0]],
            'expiry_enabled' => true,
            'expiry_days' => 365,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 마일리지 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $file = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/mileage.json');
        if (file_exists($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    /**
     * 배송완료 트리거 + 지연 3일에서, 1일차에 구매확정된 상품도 4일 뒤 스케줄러가 적립한다.
     *
     * @scenario status=confirmed_during_delay
     *
     * @effects scheduler_earns_options_past_the_trigger_status
     *
     * @return void 반환값 없음
     */
    public function test_option_confirmed_during_the_delay_is_earned(): void
    {
        $order = Order::factory()->create(['user_id' => User::factory()->create()->id, 'currency' => 'KRW']);
        $option = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED,
            'delivered_at' => now()->subDays(4),
            'confirmed_at' => now()->subDays(3),
            'subtotal_earned_points_amount' => 500,
        ]);

        Artisan::call('sirsoft-ecommerce:earn-mileage');

        $this->assertSame(1, MileageTransaction::where('order_option_id', $option->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)->count(), '지연 중 구매확정된 상품이 적립되지 않았다');
    }

    /**
     * 트리거 이전 상태(배송중)·취소된 상품은 여전히 대상이 아니다.
     *
     * @scenario status=not_reached
     *
     * @effects scheduler_earns_options_past_the_trigger_status
     *
     * @return void 반환값 없음
     */
    public function test_options_before_the_trigger_or_cancelled_are_not_earned(): void
    {
        $order = Order::factory()->create(['user_id' => User::factory()->create()->id, 'currency' => 'KRW']);
        $shipping = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::SHIPPING,
            'delivered_at' => now()->subDays(4),
            'subtotal_earned_points_amount' => 500,
        ]);
        $cancelled = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CANCELLED,
            'delivered_at' => now()->subDays(4),
            'subtotal_earned_points_amount' => 500,
        ]);

        Artisan::call('sirsoft-ecommerce:earn-mileage');

        $this->assertSame(0, MileageTransaction::whereIn('order_option_id', [$shipping->id, $cancelled->id])->count());
    }
}
