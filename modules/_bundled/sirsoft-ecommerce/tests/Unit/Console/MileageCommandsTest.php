<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Console;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 스케줄러 커맨드 테스트 (earn/expire/reconcile)
 */
class MileageCommandsTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
     * 마일리지 설정 파일을 작성합니다.
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
     * earn-mileage: 구매확정 + 적립 ledger 부재 옵션을 적립하고 재실행 멱등.
     */
    public function test_earn_mileage_command_earns_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        $option = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED,
            'confirmed_at' => now()->subDay(),
            'subtotal_earned_points_amount' => 500,
        ]);

        Artisan::call('sirsoft-ecommerce:earn-mileage');

        $this->assertSame(1, MileageTransaction::where('order_option_id', $option->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)->count());

        // 재실행 멱등 — 추가 적립 없음
        Artisan::call('sirsoft-ecommerce:earn-mileage');
        $this->assertSame(1, MileageTransaction::where('order_option_id', $option->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)->count());
    }

    /**
     * earn-mileage: 지연일 미경과 옵션은 적립하지 않는다.
     */
    public function test_earn_mileage_command_skips_within_delay(): void
    {
        $this->writeMileageSettings(['earn_delay_days' => 3]);
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        $option = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED,
            'confirmed_at' => now()->subDay(), // 지연 3일 미경과
            'subtotal_earned_points_amount' => 500,
        ]);

        Artisan::call('sirsoft-ecommerce:earn-mileage');

        $this->assertDatabaseMissing('ecommerce_mileage_transactions', ['order_option_id' => $option->id]);
    }

    /**
     * expire-mileage: 만료 lot 소멸 + 재실행 멱등.
     */
    public function test_expire_mileage_command_expires_due_lots(): void
    {
        $user = User::factory()->create();
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 400, 'remaining_amount' => 400, 'balance_after' => 400, 'expires_at' => now()->subDay(),
        ]);

        Artisan::call('sirsoft-ecommerce:expire-mileage');

        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'user_id' => $user->id,
            'type' => MileageTransactionTypeEnum::EXPIRED->value,
        ]);

        $expiredCount = MileageTransaction::where('type', MileageTransactionTypeEnum::EXPIRED->value)->count();
        Artisan::call('sirsoft-ecommerce:expire-mileage');
        $this->assertSame($expiredCount, MileageTransaction::where('type', MileageTransactionTypeEnum::EXPIRED->value)->count());
    }

    /**
     * reconcile: 임의 오염된 캐시를 원장 기준으로 재산출.
     */
    public function test_reconcile_command_heals_drifted_cache(): void
    {
        $user = User::factory()->create();
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
        ]);
        // 캐시 오염
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'KRW', 'available' => 9999]);

        Artisan::call('sirsoft-ecommerce:reconcile-mileage-balance');

        $balance = MileageBalance::where('user_id', $user->id)->where('currency', 'KRW')->first();
        $this->assertSame(1000.0, (float) $balance->available);
    }

    /**
     * 마일리지 비활성 시 커맨드는 조기 종료.
     */
    public function test_commands_skip_when_disabled(): void
    {
        $this->writeMileageSettings(['enabled' => false]);
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED,
            'confirmed_at' => now()->subDay(),
            'subtotal_earned_points_amount' => 500,
        ]);

        $exit = Artisan::call('sirsoft-ecommerce:earn-mileage');

        $this->assertSame(0, $exit);
        $this->assertSame(0, MileageTransaction::count());
    }
}
