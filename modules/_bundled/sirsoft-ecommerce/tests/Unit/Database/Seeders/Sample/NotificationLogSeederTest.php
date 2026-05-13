<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders\Sample;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\NotificationLogSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 NotificationLogSeeder 통합 테스트.
 *
 * extension_identifier='sirsoft-ecommerce' 정의만 채워지는지 검증.
 */
class NotificationLogSeederTest extends ModuleTestCase
{
    private function bootstrap(): void
    {
        // 테스트 격리: 다른 테스트 클래스가 트랜잭션 외부에서 남긴 잔여 데이터 정리.
        NotificationLog::query()->delete();
        NotificationDefinition::query()->delete();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationDefinitionSeeder::class);
        $this->syncEcommerceNotificationDefinitions();

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        User::factory()
            ->count(15)
            ->create()
            ->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));
    }

    public function test_seeder_creates_default_count(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $this->assertSame(100, NotificationLog::count(), '기본 100건이 생성되어야 합니다');
    }

    public function test_seeder_only_seeds_ecommerce_scope(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $ecommerce = NotificationLog::query()
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();
        $other = NotificationLog::query()
            ->where('extension_identifier', '!=', 'sirsoft-ecommerce')
            ->count();

        $this->assertSame(100, $ecommerce, '이커머스 영역만 채워야 합니다');
        $this->assertSame(0, $other, '코어/타 모듈 영역은 채우지 않아야 합니다');
    }

    public function test_seeder_uses_only_ecommerce_definitions(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $ecommerceTypes = NotificationDefinition::query()
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->pluck('type')
            ->all();

        foreach (NotificationLog::query()->get() as $log) {
            $this->assertContains(
                $log->notification_type,
                $ecommerceTypes,
                "type={$log->notification_type} 는 이커머스 정의여야 합니다",
            );
        }
    }

    public function test_seeder_respects_count_option(): void
    {
        $this->bootstrap();

        $seeder = $this->app->make(NotificationLogSeeder::class);
        $seeder->setSeederCounts(['ecommerce_notification_logs' => 25]);
        $seeder->setCommand($this->createMockCommand());
        $seeder->run();

        $this->assertSame(25, NotificationLog::count());
    }

    /**
     * 시더 run() 내부의 $this->command->info() 호출용 더미 커맨드.
     *
     * @return \Illuminate\Console\Command 더미 커맨드 인스턴스
     */
    private function createMockCommand(): \Illuminate\Console\Command
    {
        return new class extends \Illuminate\Console\Command
        {
            protected $signature = 'test:dummy';

            public function info($string, $verbosity = null): void {}

            public function warn($string, $verbosity = null): void {}
        };
    }

    /**
     * module.php::getNotificationDefinitions() SSoT 기반으로 이커머스 알림 정의 시드.
     */
    private function syncEcommerceNotificationDefinitions(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        if (! $module) {
            return;
        }

        $helper = app(NotificationSyncHelper::class);
        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-ecommerce';
            $definition = $helper->syncDefinition($data);
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }
    }
}
