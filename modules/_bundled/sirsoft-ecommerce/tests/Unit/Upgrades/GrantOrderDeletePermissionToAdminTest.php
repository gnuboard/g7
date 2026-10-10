<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Enums\PermissionType;
use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * 1.3.0 업그레이드 — 「주문 삭제」 권한을 admin 역할에 붙이는 데이터 마이그레이션 테스트 (7.1.0 R4)
 *
 * 기존 설치본의 admin 역할이 새 권한을 갖게 되는지, 운영자가 직접 뺀 기록은 존중하는지,
 * 두 번 돌려도 같은 결과인지를 본다.
 */
class GrantOrderDeletePermissionToAdminTest extends ModuleTestCase
{
    private const PERMISSION = 'sirsoft-ecommerce.orders.delete';

    private object $migration;

    private UpgradeContext $context;

    /**
     * 마이그레이션과 업그레이드 컨텍스트(로그 수집)를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.0/migrations/15_GrantOrderDeletePermissionToAdmin.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\GrantOrderDeletePermissionToAdmin';
        $this->migration = new $class;

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
        $this->context = new UpgradeContext(fromVersion: '1.2.1', toVersion: '1.3.0', currentStep: '1.3.0');
        Log::clearResolvedInstance('log');
    }

    /**
     * 선언형 동기화가 만든 권한 행을 흉내 냅니다.
     *
     * @return Permission 주문 삭제 권한
     */
    private function permission(): Permission
    {
        return Permission::firstOrCreate(
            ['identifier' => self::PERMISSION],
            ['name' => ['ko' => '주문 삭제', 'en' => 'Delete Order'], 'type' => PermissionType::Admin],
        );
    }

    /**
     * admin 역할에 권한이 없으면 붙이고, 다시 돌려도 한 번만 붙어 있습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects order_delete_permission_upgrade_grants_admin
     */
    public function test_grants_the_permission_to_admin_once(): void
    {
        $permission = $this->permission();
        $admin = Role::where('identifier', 'admin')->firstOrFail();
        $admin->permissions()->detach($permission->id);

        $this->migration->run($this->context);
        $this->migration->run($this->context);

        $this->assertSame(1, $admin->permissions()->whereKey($permission->id)->count());
    }

    /**
     * 운영자가 admin 역할에서 이 권한을 직접 뺀 기록이 있으면 붙이지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects order_delete_permission_upgrade_respects_override
     */
    public function test_respects_operator_override(): void
    {
        $permission = $this->permission();
        $admin = Role::where('identifier', 'admin')->firstOrFail();
        $admin->permissions()->detach($permission->id);
        $admin->forceFill(['user_overrides' => [self::PERMISSION]])->save();

        $this->migration->run($this->context);

        $this->assertFalse($admin->permissions()->whereKey($permission->id)->exists());
    }
}
