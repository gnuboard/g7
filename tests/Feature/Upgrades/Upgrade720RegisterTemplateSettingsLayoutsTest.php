<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TemplateLayout;
use App\Services\CoreUpdateService;
use App\Upgrades\Data\V7_2_0\Migrations\RegisterTemplateSettingsLayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 7.2.0 업그레이드 — 템플릿 설정 화면 등록 스텝 테스트 (#434)
 */
class Upgrade720RegisterTemplateSettingsLayoutsTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 테스트가 만든 픽스처를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanUpTemplateSettingsFixtures();

        parent::tearDown();
    }

    /**
     * 켜진 관리자 템플릿에 설치된 템플릿 설정 화면을 등록하고, 두 번 돌려도 행이 늘지 않는다(멱등).
     *
     * @scenario case=upgrade
     *
     * @effects upgrade_registers_settings_layouts
     *
     * @return void 반환값 없음
     */
    public function test_registers_settings_layouts_idempotently(): void
    {
        require_once base_path('upgrades/data/7.2.0/migrations/06_RegisterTemplateSettingsLayouts.php');

        $host = $this->makeSettingsTemplate('qa434-uphost', 'admin', 'active');
        $this->makeSettingsTemplate('qa434-upuser', 'user', 'inactive');
        $context = new UpgradeContext('7.1.4', '7.2.0');
        $migration = new RegisterTemplateSettingsLayouts;

        $migration->run($context);
        $migration->run($context);

        $this->assertSame(1, TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-upuser.template_settings')->count());
        $this->assertSame(1, TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-uphost.template_settings')->count());
    }

    /**
     * 「템플릿 설정」 권한은 코어 선언 재동기화로 생긴다(별도 스텝 불필요).
     *
     * @scenario case=upgrade
     *
     * @effects upgrade_registers_settings_layouts
     *
     * @return void 반환값 없음
     */
    public function test_permission_comes_from_core_declaration_resync(): void
    {
        $leaves = collect(config('core.permissions.categories', []))->firstWhere('identifier', 'core.templates')['permissions'] ?? [];

        $this->assertContains('core.templates.settings', array_column($leaves, 'identifier'));
    }

    /**
     * 업그레이드 스텝이 부르는 코어 선언 재동기화(`reloadCoreConfigAndResync`)를 돌리면 「템플릿 설정」 권한 행이 생기고
     * 관리자(admin) 역할이 그 권한을 갖는다 — 권한이 없던 7.1 설치본에서 출발.
     *
     * @scenario case=upgrade
     *
     * @effects upgrade_registers_settings_layouts
     *
     * @return void 반환값 없음
     */
    public function test_core_resync_creates_permission_and_grants_admin_role(): void
    {
        Permission::where('identifier', 'core.templates.settings')->get()->each(function (Permission $permission) {
            $permission->roles()->detach();
            $permission->delete();
        });
        $this->assertFalse(Permission::where('identifier', 'core.templates.settings')->exists(), '전제 — 권한이 없는 설치본');

        app(CoreUpdateService::class)->reloadCoreConfigAndResync();

        $permission = Permission::where('identifier', 'core.templates.settings')->first();
        $this->assertNotNull($permission, '재동기화가 권한을 만든다');
        $this->assertSame('core.templates', $permission->parent?->identifier);

        $admin = Role::where('identifier', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->permissions()->where('identifier', 'core.templates.settings')->exists(), '관리자 역할이 템플릿 설정 권한을 갖는다');
    }
}
