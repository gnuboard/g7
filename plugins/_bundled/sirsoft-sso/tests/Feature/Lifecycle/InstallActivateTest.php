<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Lifecycle;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\PluginManager;
use App\Models\Menu;
use Plugins\Sirsoft\Sso\Bootstrap\Commands;
use Plugins\Sirsoft\Sso\Bootstrap\Container;
use Plugins\Sirsoft\Sso\Bootstrap\Listeners;
use Plugins\Sirsoft\Sso\Bootstrap\Schedules;
use Plugins\Sirsoft\Sso\Bootstrap\Tables;
use Plugins\Sirsoft\Sso\Plugin;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 플러그인 선언과 생애주기 — 이음매 파일 전부 존재, 선언(권한, 역할, 메뉴, 훅, 표) 모양, 활성화·삭제의 메뉴 동기화.
 *
 * @since 1.0.0
 */
class InstallActivateTest extends PluginTestCase
{
    /**
     * 선언 읽기용 플러그인 인스턴스.
     *
     * @return Plugin 플러그인
     */
    private function plugin(): Plugin
    {
        return new Plugin(['identifier' => 'sirsoft-sso', 'vendor' => 'sirsoft', 'version' => '1.0.0']);
    }

    /**
     * 이음매 파일이 모두 있고 읽히는지 확인한다.
     *
     * @scenario seams=all
     *
     * @effects seam_files_exist_and_load
     *
     * @return void 반환값 없음
     */
    public function test_every_seam_file_exists_and_loads(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [
            'src/Bootstrap/Listeners/CoreFlow.php', 'src/Bootstrap/Listeners/Oidc.php', 'src/Bootstrap/Listeners/Saml.php', 'src/Bootstrap/Listeners/Ldap.php',
            'src/Bootstrap/Listeners/Provisioning.php', 'src/Bootstrap/Listeners/Policy.php', 'src/Bootstrap/Listeners/Admin.php',
            'src/Bootstrap/Commands/Core.php', 'src/Bootstrap/Commands/Saml.php', 'src/Bootstrap/Commands/Ldap.php', 'src/Bootstrap/Commands/Provisioning.php', 'src/Bootstrap/Commands/Policy.php',
            'src/Bootstrap/Schedules/Provisioning.php', 'src/Bootstrap/Schedules/Maintenance.php',
            'src/Bootstrap/Tables/Core.php', 'src/Bootstrap/Tables/Provisioning.php', 'src/Bootstrap/Tables/Policy.php',
            'src/Bootstrap/Container/Core.php', 'src/Bootstrap/Container/Saml.php', 'src/Bootstrap/Container/Ldap.php', 'src/Bootstrap/Container/Provisioning.php', 'src/Bootstrap/Container/Policy.php',
            'src/Bootstrap/SettingsSchema.php', 'src/Bootstrap/Notifications.php',
            'src/routes/web/login.php', 'src/routes/web/oidc.php', 'src/routes/web/saml.php', 'src/routes/web/logout.php', 'src/routes/web/backchannel.php', 'src/routes/web/mock.php', 'src/routes/web/mock_saml.php',
            'src/routes/api/flow.php', 'src/routes/api/me.php', 'src/routes/api/admin_idps.php', 'src/routes/api/admin_policy.php', 'src/routes/api/admin_accounts.php', 'src/routes/api/admin_logs.php', 'src/routes/api/scim.php',
            'resources/js/handlers/flow/index.ts', 'resources/js/handlers/policy/index.ts', 'resources/js/handlers/admin/index.ts',
        ];
        foreach (['ko', 'en'] as $locale) {
            foreach (['messages', 'validation', 'exceptions', 'oidc', 'saml', 'ldap', 'scim', 'provisioning', 'policy', 'admin', 'activity_log'] as $file) {
                $files[] = "lang/{$locale}/{$file}.php";
            }
        }

        foreach ($files as $file) {
            $this->assertFileExists($root.'/'.$file);
        }

        $this->assertIsArray(Commands::all());
        $this->assertIsArray(Schedules::all());
        $this->assertIsArray(Container::repositories());
        // 영역 표(프로비저닝, 정책)의 자식 먼저 순서는 각 영역 시험이 본다 — 여기서는 기반 표가 맨 뒤인지만 본다.
        $tables = Tables::all();
        $this->assertSame(['sso_login_attempts', 'sso_accounts', 'sso_identity_providers'], array_slice($tables, -3), '기반 표는 맨 뒤(삭제 순서 — 자식 표가 먼저)');
        $this->assertSame(count($tables), count(array_unique($tables)));
    }

    /**
     * 등록 리스너가 모두 있고 훅 리스너 계약을 구현하는지 확인한다.
     *
     * @scenario declarations=listeners
     *
     * @effects listeners_implement_contract
     *
     * @return void 반환값 없음
     */
    public function test_listeners_exist_and_implement_contract(): void
    {
        $this->assertNotEmpty(Listeners::all());
        foreach ($this->plugin()->getHookListeners() as $class) {
            $this->assertTrue(class_exists($class), $class);
            $this->assertContains(HookListenerInterface::class, class_implements($class));
        }
    }

    /**
     * 권한, 역할, 훅, 설정 스키마 선언 모양을 확인한다.
     *
     * @scenario declarations=permissions|roles|hooks|settings
     *
     * @effects declared_shape
     *
     * @return void 반환값 없음
     */
    public function test_declarations(): void
    {
        $plugin = $this->plugin();

        $actions = [];
        foreach ($plugin->getPermissions()['categories'] as $category) {
            foreach ($category['permissions'] as $permission) {
                $actions[] = $category['identifier'].'.'.$permission['action'];
                $this->assertSame('admin', $permission['type']);
            }
        }
        $this->assertSame(['settings.view', 'settings.update', 'accounts.view', 'accounts.unlink', 'accounts.provision', 'logs.view'], $actions);
        $this->assertSame('sirsoft-sso.manager', $plugin->getRoles()[0]['identifier']);
        $this->assertSame([], $plugin->getSettingsSchema(), '정책은 코어 설정 경로로 저장하지 않는다');

        foreach ($plugin->getHooks() as $name => $hook) {
            $this->assertStringStartsWith('sirsoft-sso.', $name);
            $this->assertSame(['ko', 'en', 'ja'], array_keys($hook['description']), $name);
        }
    }

    /**
     * 활성화가 메뉴를 맞추고 비활성은 보존하며, 플러그인의 uninstall() 도 메뉴를 남기는지 확인한다.
     *
     * uninstall() 은 데이터 보존 제거에서도 불리므로 메뉴를 지우지 않는다 — 메뉴는 「데이터도 함께 삭제」 제거에서
     * 코어(PluginManager)가 지운다(코어 7.2.0). 코어 쪽 계약은 tests/Feature/Extension/PluginUninstallKeepsMenusTest.php.
     *
     * @scenario lifecycle=activate|uninstall
     *
     * @effects menus_synced, plugin_uninstall_hook_keeps_menus
     *
     * @return void 반환값 없음
     */
    public function test_activate_syncs_menus_and_uninstall_hook_keeps_menus(): void
    {
        $plugin = app(PluginManager::class)->getPlugin('sirsoft-sso') ?? $this->plugin();

        $this->assertTrue($plugin->activate());
        $slugs = Menu::query()->where('extension_type', ExtensionOwnerType::Plugin->value)->where('extension_identifier', 'sirsoft-sso')->pluck('slug')->all();
        sort($slugs);
        $this->assertSame(['sirsoft-sso', 'sirsoft-sso-accounts', 'sirsoft-sso-logs'], $slugs);

        $this->assertTrue($plugin->activate(), '다시 켜도 중복 없음');
        $this->assertSame(3, Menu::query()->where('extension_identifier', 'sirsoft-sso')->count());

        $this->assertTrue($plugin->deactivate());
        $this->assertSame(3, Menu::query()->where('extension_identifier', 'sirsoft-sso')->count(), '비활성은 메뉴 보존');

        $this->assertTrue($plugin->uninstall());
        $this->assertSame(3, Menu::query()->where('extension_identifier', 'sirsoft-sso')->count(), '데이터 보존 제거에서도 불리는 훅이라 메뉴를 남긴다');
    }
}
