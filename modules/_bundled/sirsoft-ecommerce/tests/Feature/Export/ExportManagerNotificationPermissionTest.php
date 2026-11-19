<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Enums\PermissionType;
use App\Extension\ModuleManager;
use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;
use Psr\Log\AbstractLogger;

/**
 * 기본 역할 「이커머스 관리자」가 사이트 알림(종 아이콘)을 볼 수 있는지 잠근다.
 *
 * 이 모듈은 내보내기 완료·실패, 새 주문, 상품 문의, 클레임 알림을 이 역할에게도 사이트 알림으로 보낸다. 그런데 알림 목록·읽지 않은
 * 수 API 는 코어 권한 `core.notifications.read`(읽음 처리는 `.update`)를 요구해, 이 역할만 가진 운영자는 받은 알림을 볼 수 없었다.
 * 새 설치는 설치 데이터 시더가, 기존 사이트는 1.4.0 업그레이드 단계가 그 두 권한을 붙인다. 운영자가 역할 관리에서 이 권한을
 * 직접 뺀 기록이 있으면 존중한다.
 */
class ExportManagerNotificationPermissionTest extends ExportTestCase
{
    /** 이 모듈의 기본 역할 */
    private const ROLE = 'sirsoft-ecommerce.manager';

    /** 알림을 보고 읽음 처리하는 코어 권한 */
    private const PERMISSIONS = ['core.notifications.read', 'core.notifications.update'];

    /** 설치 데이터 시더 */
    private const SEEDER = 'Modules\\Sirsoft\\Ecommerce\\Database\\Seeders\\ManagerNotificationPermissionSeeder';

    /** 1.4.0 업그레이드 데이터 마이그레이션 */
    private const MIGRATION = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_4_0\\Migrations\\GrantNotificationPermissionsToManager';

    /**
     * 역할과 코어 알림 권한 행을 준비하고, 역할에서 그 권한을 떼어 둡니다(1.3.x 설치본과 같은 상태).
     *
     * @return Role 이커머스 관리자 역할
     */
    private function managerWithoutNotificationPermissions(): Role
    {
        $role = Role::firstOrCreate(
            ['identifier' => self::ROLE],
            ['name' => ['ko' => '이커머스 관리자', 'en' => 'Ecommerce Manager'], 'is_active' => true],
        );

        foreach (self::PERMISSIONS as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin],
            );
            $role->permissions()->detach($permission->id);
        }

        $role->forceFill(['user_overrides' => null])->save();

        return $role->refresh();
    }

    /**
     * 역할이 가진 코어 알림 권한 식별자
     *
     * @param  Role  $role  역할
     * @return array<int, string> 식별자 (정렬)
     */
    private function notificationPermissionsOf(Role $role): array
    {
        return $role->permissions()->whereIn('identifier', self::PERMISSIONS)->orderBy('identifier')->pluck('identifier')->all();
    }

    /** 업그레이드 컨텍스트 (테스트마다 한 번 만든다 — 로그 채널 가짜는 한 번만 건다) */
    private ?UpgradeContext $context = null;

    /**
     * 1.4.0 업그레이드 데이터 마이그레이션을 실행합니다(로그는 버린다).
     *
     * @return void 반환값 없음
     */
    private function runUpgradeMigration(): void
    {
        require_once dirname(__DIR__, 3).'/upgrades/data/1.4.0/migrations/01_GrantNotificationPermissionsToManager.php';

        $class = self::MIGRATION;
        (new $class)->run($this->upgradeContext());
    }

    /**
     * 업그레이드 컨텍스트를 돌려줍니다(처음 한 번만 만든다).
     *
     * @return UpgradeContext 컨텍스트
     */
    private function upgradeContext(): UpgradeContext
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $logger = new class extends AbstractLogger
        {
            /**
             * 로그를 버립니다.
             *
             * @param  mixed  $level  로그 수준
             * @param  string|\Stringable  $message  메시지
             * @param  array  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };
        Log::shouldReceive('channel')->once()->andReturn($logger);
        $this->context = new UpgradeContext(fromVersion: '1.3.1', toVersion: '1.4.0', currentStep: '1.4.0');
        Log::clearResolvedInstance('log');

        return $this->context;
    }

    /**
     * 새 설치 — 설치 데이터 시더 목록에 들어 있고, 돌리면 역할이 알림 조회·읽음 처리 권한을 갖는다(두 번 돌려도 한 번만).
     *
     * @return void 반환값 없음
     *
     * @scenario action=notify
     *
     * @effects manager_role_can_view_site_notifications
     */
    public function test_install_seeder_grants_notification_permissions(): void
    {
        $role = $this->managerWithoutNotificationPermissions();
        $module = app(ModuleManager::class)->getModule(self::IDENTIFIER);

        $this->assertContains(self::SEEDER, $module->getSeeders(), '설치 데이터 시더 목록에 없습니다.');

        $this->seed(self::SEEDER);
        $this->seed(self::SEEDER);

        $this->assertSame(self::PERMISSIONS, $this->notificationPermissionsOf($role));
        $this->assertSame(1, $role->permissions()->where('identifier', 'core.notifications.read')->count());
    }

    /**
     * 기존 사이트 — 1.4.0 업그레이드 단계가 같은 권한을 붙이고, 다시 돌려도 같은 결과다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=notify
     *
     * @effects manager_role_can_view_site_notifications
     */
    public function test_upgrade_grants_notification_permissions_once(): void
    {
        $role = $this->managerWithoutNotificationPermissions();

        $this->assertArrayHasKey('1.4.0', app(ModuleManager::class)->getModule(self::IDENTIFIER)->upgrades());

        $this->runUpgradeMigration();
        $this->runUpgradeMigration();

        $this->assertSame(self::PERMISSIONS, $this->notificationPermissionsOf($role));
    }

    /**
     * 운영자가 역할 관리에서 이 권한을 직접 조정한 기록이 있으면 시더도 업그레이드도 그 권한을 다시 붙이지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=notify
     *
     * @effects manager_role_can_view_site_notifications
     */
    public function test_operator_override_is_respected(): void
    {
        $role = $this->managerWithoutNotificationPermissions();
        $role->forceFill(['user_overrides' => ['core.notifications.update']])->save();

        $this->seed(self::SEEDER);
        $this->runUpgradeMigration();

        $this->assertSame(['core.notifications.read'], $this->notificationPermissionsOf($role->refresh()));
    }
}
