<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\UserMileageInfoListener;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * UserMileageInfoListener 테스트 (회원 화면 잔액 주입)
 */
class UserMileageInfoListenerTest extends ModuleTestCase
{
    private UserMileageInfoListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeMileageSettings();
        $this->listener = app(UserMileageInfoListener::class);
    }

    protected function tearDown(): void
    {
        // 운영 경로가 아닌 testing 격리 경로를 정리한다 (EcommerceSettingsService 가드와 동일 경로).
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
        // 운영 설정(storage/app/modules/...)을 오염시키지 않도록 testing 격리 경로를 사용한다
        // (EcommerceSettingsService::getStoragePath 의 testing 가드와 동일 경로).
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
        ], $overrides);

        file_put_contents($path.'/mileage.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 회원과 적립 lot 을 생성하고 캐시를 재계산합니다.
     *
     * @return User 생성된 회원
     */
    private function seedUserWithBalance(): User
    {
        $user = User::factory()->create();
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 5000,
            'remaining_amount' => 5000,
            'balance_after' => 5000,
            'expires_at' => Carbon::now()->addYear(),
        ]);
        app(MileageBalanceRepositoryInterface::class)->recalculateForUser($user->id);

        return $user;
    }

    public function test_injects_mileage_summary_into_user_data(): void
    {
        $user = $this->seedUserWithBalance();

        $result = $this->listener->injectMileageTotal(['uuid' => $user->uuid], $user);

        $this->assertArrayHasKey('ecommerce_mileage', $result);
        $this->assertTrue($result['ecommerce_mileage']['enabled']);
        $this->assertSame(5000.0, $result['ecommerce_mileage']['available']);
        $this->assertArrayHasKey('pending', $result['ecommerce_mileage']);
        $this->assertArrayHasKey('expiring_soon', $result['ecommerce_mileage']);
        $this->assertArrayHasKey('by_currency', $result['ecommerce_mileage']);
    }

    public function test_injects_zero_filled_summary_for_user_without_activity(): void
    {
        $user = User::factory()->create();

        $result = $this->listener->injectMileageTotal(['uuid' => $user->uuid], $user);

        $this->assertArrayHasKey('ecommerce_mileage', $result);
        $this->assertSame(0.0, $result['ecommerce_mileage']['available']);
        $this->assertSame(0.0, $result['ecommerce_mileage']['pending']);
    }

    public function test_injects_disabled_signal_when_mileage_disabled(): void
    {
        $this->writeMileageSettings(['enabled' => false]);
        $user = $this->seedUserWithBalance();

        $result = $this->listener->injectMileageTotal(['uuid' => $user->uuid], $user);

        // 비활성 시에도 키를 주입해 화면이 "데이터 없음"과 "비활성화"를 구별할 수 있어야 한다.
        $this->assertArrayHasKey('ecommerce_mileage', $result);
        $this->assertFalse($result['ecommerce_mileage']['enabled']);
        // 잔액 필드는 주입하지 않는다 (비활성 신호만).
        $this->assertArrayNotHasKey('available', $result['ecommerce_mileage']);
    }

    public function test_reads_cache_not_ledger_sum(): void
    {
        $user = User::factory()->create();

        $repository = $this->createMock(MileageBalanceRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('getCachedBalance')
            ->with($user->id)
            ->willReturn(['available' => 1200.0, 'pending' => 0.0, 'by_currency' => []]);

        $listener = new UserMileageInfoListener($repository, app(EcommerceSettingsService::class));

        $result = $listener->injectMileageTotal([], $user);

        $this->assertSame(1200.0, $result['ecommerce_mileage']['available']);
    }

    public function test_subscribes_to_filter_resource_data_as_filter_hook(): void
    {
        $hooks = UserMileageInfoListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.user.filter_resource_data', $hooks);
        $this->assertSame('filter', $hooks['core.user.filter_resource_data']['type']);
    }
}
