<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Console;

use App\Extension\HookManager;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 소멸 예고 명령(notify-expiring-mileage) 이 알림 훅에 넘기는 소멸일 테스트
 *
 * 소멸일은 알림 변수(`expires_date`)로 그대로 쓰인다 — 사이트 시간대 날짜여야 한다(#160 D1).
 */
class NotifyExpiringMileageCommandTest extends ModuleTestCase
{
    /**
     * 마일리지 설정 파일을 쓰고 시각을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeMileageSettings();
    }

    /**
     * 설정 파일과 고정 시각을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $file = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/mileage.json');
        if (file_exists($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    /**
     * 마일리지 설정 파일을 작성합니다.
     *
     * @return void 반환값 없음
     */
    private function writeMileageSettings(): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        file_put_contents($path.'/mileage.json', json_encode([
            'enabled' => true,
            'currency_rules' => [['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0]],
            'expiry_enabled' => true,
            'expiry_days' => 365,
            'expiry_notification_enabled' => true,
            'expiry_notification_days_before' => 7,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * UTC 12-30 15:00 에 소멸하는 적립분은 서울 날짜 12-31 로 알린다.
     *
     * @scenario surface=mileage_expires_date
     *
     * @effects notification_dates_use_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_expires_date_hook_argument_is_the_site_date(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        Carbon::setTestNow(Carbon::parse('2026-12-28 00:00:00', 'UTC'));

        $user = User::factory()->create();
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 300, 'remaining_amount' => 300, 'balance_after' => 300,
            'expires_at' => Carbon::parse('2026-12-30 15:00:00', 'UTC'),
        ]);
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'KRW', 'available' => 300]);

        $captured = [];
        HookManager::addAction('sirsoft-ecommerce.mileage.notify_expiring', function ($u, $amount, $currency, $expiresDate) use (&$captured) {
            $captured[] = $expiresDate;
        }, 1);

        Artisan::call('sirsoft-ecommerce:notify-expiring-mileage');

        $this->assertSame(['2026-12-31'], $captured);
    }
}
