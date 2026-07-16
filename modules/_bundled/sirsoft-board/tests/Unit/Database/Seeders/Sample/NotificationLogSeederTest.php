<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Database\Seeders\Sample;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\NotificationLogSeeder;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 NotificationLogSeeder 통합 테스트.
 *
 * extension_identifier='sirsoft-board' 정의만 채워지는지 검증.
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
        $this->syncBoardNotificationDefinitions();

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

        $this->assertSame(100, NotificationLog::count());
    }

    public function test_seeder_only_seeds_board_scope(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $board = NotificationLog::query()->where('extension_identifier', 'sirsoft-board')->count();
        $other = NotificationLog::query()->where('extension_identifier', '!=', 'sirsoft-board')->count();

        $this->assertSame(100, $board, '게시판 영역만 채워야 합니다');
        $this->assertSame(0, $other, '코어/타 모듈 영역은 채우지 않아야 합니다');
    }

    public function test_seeder_uses_only_board_definitions(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $boardTypes = NotificationDefinition::query()
            ->where('extension_identifier', 'sirsoft-board')
            ->pluck('type')
            ->all();

        foreach (NotificationLog::query()->get() as $log) {
            $this->assertContains($log->notification_type, $boardTypes);
        }
    }

    /**
     * module.php::getNotificationDefinitions() SSoT 기반으로 게시판 알림 정의 시드.
     */
    private function syncBoardNotificationDefinitions(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-board');
        if (! $module) {
            return;
        }

        $helper = app(NotificationSyncHelper::class);
        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-board';
            $definition = $helper->syncDefinition($data);
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }
    }
}
