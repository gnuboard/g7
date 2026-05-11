<?php

namespace Tests\Feature\Extension;

use App\Enums\IdentityPolicySourceType;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityPolicy;
use App\Models\NotificationDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * ModuleManager / PluginManager 의 declaration sync protected 메서드가 declaration
 * 빈 배열 반환 시 기존 DB row 를 안전 보존하는지 검증.
 *
 * 결함 시나리오 (production):
 *   - spawn 자식 프로세스에서 fresh-load 된 Module 클래스가 PSR-4 의존성 누락으로 부분 로드
 *   - declaration 메서드(`getIdentityPolicies` / `getIdentityMessages` /
 *     `getNotificationDefinitions`) 가 throw 없이 빈 배열 반환
 *   - sync 메서드가 "선언 부재 = stale 정리" 로 해석해 cleanupStaleXxx 실행
 *   - 운영자 데이터 + 모듈 시드 데이터 모두 silent 삭제
 *
 * 안전 가드: declaration 빈 배열 + DB 에 기존 row 존재 → cleanup 차단 + warning 로그.
 * 운영자가 admin UI 에서 직접 정의를 비우는 경로(별도 API) 와 declaration getter 의 빈 배열은
 * 의미가 다르다. declaration 은 코드 계약이므로 빈 배열 = "이 모듈은 해당 영역을 사용 안 함" —
 * 그 경우 첫 install 시점부터 row 가 없을 것이라는 전제가 성립.
 */
class ModuleManagerSyncCleanupGuardTest extends TestCase
{
    use RefreshDatabase;

    // ============================================================
    // ModuleManager
    // ============================================================

    public function test_module_identity_policies_cleanup_blocked_when_declaration_empty_and_db_has_rows(): void
    {
        $module = $this->makeFakeModule(['policies' => []]);

        // 사전 상태: DB 에 운영자/이전 시드의 정책 row 존재
        IdentityPolicy::create([
            'key' => 'fake-module.preserve.policy',
            'scope' => 'hook',
            'target' => 'fake-module.preserve.before_action',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'source_type' => IdentityPolicySourceType::Module->value,
            'source_identifier' => 'fake-module',
            'applies_to' => 'admin',
            'fail_mode' => 'block',
            'priority' => 100,
        ]);

        $this->invokeProtectedSync(app(ModuleManager::class), 'syncModuleIdentityPolicies', $module);

        $this->assertDatabaseHas('identity_policies', [
            'key' => 'fake-module.preserve.policy',
            'source_identifier' => 'fake-module',
        ]);
    }

    public function test_module_identity_messages_cleanup_blocked_when_declaration_empty_and_db_has_rows(): void
    {
        $module = $this->makeFakeModule(['messages' => []]);

        IdentityMessageDefinition::create([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'fake_purpose',
            'name' => ['ko' => '보존', 'en' => 'Preserve'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'channels' => ['mail'],
            'variables' => [],
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
        ]);

        $this->invokeProtectedSync(app(ModuleManager::class), 'syncModuleIdentityMessages', $module);

        $this->assertDatabaseHas('identity_message_definitions', [
            'extension_identifier' => 'fake-module',
            'scope_value' => 'fake_purpose',
        ]);
    }

    public function test_module_menus_cleanup_blocked_when_declaration_empty_and_db_has_rows(): void
    {
        $module = $this->makeFakeModule(['menus' => []]);

        \App\Models\Menu::create([
            'slug' => 'fake-module-preserve-menu',
            'name' => ['ko' => '보존 메뉴', 'en' => 'Preserve Menu'],
            'extension_type' => \App\Enums\ExtensionOwnerType::Module->value,
            'extension_identifier' => 'fake-module',
            'is_active' => true,
            'order' => 0,
        ]);

        $this->invokeProtectedSync(app(ModuleManager::class), 'cleanupStaleModuleEntries', $module);

        $this->assertDatabaseHas('menus', [
            'slug' => 'fake-module-preserve-menu',
            'extension_identifier' => 'fake-module',
        ]);
    }

    public function test_module_notification_definitions_cleanup_blocked_when_declaration_empty_and_db_has_rows(): void
    {
        $module = $this->makeFakeModule(['notifications' => []]);

        NotificationDefinition::create([
            'type' => 'fake-module.preserve_event',
            'hook_prefix' => 'fake-module',
            'name' => ['ko' => '보존 이벤트', 'en' => 'Preserve Event'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'enabled' => true,
        ]);

        $this->invokeProtectedSync(app(ModuleManager::class), 'syncModuleNotificationDefinitions', $module);

        $this->assertDatabaseHas('notification_definitions', [
            'type' => 'fake-module.preserve_event',
            'extension_identifier' => 'fake-module',
        ]);
    }

    // ============================================================
    // PluginManager
    // ============================================================

    public function test_plugin_identity_policies_cleanup_blocked_when_declaration_empty_and_db_has_rows(): void
    {
        $plugin = $this->makeFakePlugin(['policies' => []]);

        IdentityPolicy::create([
            'key' => 'fake-plugin.preserve.policy',
            'scope' => 'hook',
            'target' => 'fake-plugin.preserve.before_action',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'source_type' => IdentityPolicySourceType::Plugin->value,
            'source_identifier' => 'fake-plugin',
            'applies_to' => 'admin',
            'fail_mode' => 'block',
            'priority' => 100,
        ]);

        $this->invokeProtectedSync(app(PluginManager::class), 'syncPluginIdentityPolicies', $plugin);

        $this->assertDatabaseHas('identity_policies', [
            'key' => 'fake-plugin.preserve.policy',
            'source_identifier' => 'fake-plugin',
        ]);
    }

    public function test_plugin_identity_messages_cleanup_blocked_when_declaration_empty_and_db_has_rows(): void
    {
        $plugin = $this->makeFakePlugin(['messages' => []]);

        IdentityMessageDefinition::create([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'fake_plugin_purpose',
            'name' => ['ko' => '보존', 'en' => 'Preserve'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'channels' => ['mail'],
            'variables' => [],
            'extension_type' => 'plugin',
            'extension_identifier' => 'fake-plugin',
        ]);

        $this->invokeProtectedSync(app(PluginManager::class), 'syncPluginIdentityMessages', $plugin);

        $this->assertDatabaseHas('identity_message_definitions', [
            'extension_identifier' => 'fake-plugin',
            'scope_value' => 'fake_plugin_purpose',
        ]);
    }

    public function test_plugin_notification_definitions_cleanup_blocked_when_declaration_empty_and_db_has_rows(): void
    {
        $plugin = $this->makeFakePlugin(['notifications' => []]);

        NotificationDefinition::create([
            'type' => 'fake-plugin.preserve_event',
            'hook_prefix' => 'fake-plugin',
            'name' => ['ko' => '보존', 'en' => 'Preserve'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => 'plugin',
            'extension_identifier' => 'fake-plugin',
            'enabled' => true,
        ]);

        $this->invokeProtectedSync(app(PluginManager::class), 'syncPluginNotificationDefinitions', $plugin);

        $this->assertDatabaseHas('notification_definitions', [
            'type' => 'fake-plugin.preserve_event',
            'extension_identifier' => 'fake-plugin',
        ]);
    }

    // ============================================================
    // 정상 동작 보장 — declaration 비어있고 DB 도 비어있으면 정상 skip
    // ============================================================

    public function test_module_sync_skips_silently_when_both_declaration_and_db_empty(): void
    {
        $module = $this->makeFakeModule([]);

        $this->invokeProtectedSync(app(ModuleManager::class), 'syncModuleIdentityPolicies', $module);
        $this->invokeProtectedSync(app(ModuleManager::class), 'syncModuleNotificationDefinitions', $module);

        $this->assertDatabaseCount('identity_policies', 0);
        $this->assertDatabaseCount('notification_definitions', 0);
    }

    /**
     * declaration 이 정상 채워진 경우 cleanupStale 이 동작해 stale row 만 제거되는지 검증
     * (가드가 정상 케이스를 깨지 않음 검증).
     */
    public function test_module_identity_policies_normal_cleanup_still_works(): void
    {
        // 1차 시드 — 2개 정책
        IdentityPolicy::create([
            'key' => 'fake-module.keep.policy',
            'scope' => 'hook',
            'target' => 'fake-module.keep.before_action',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'source_type' => IdentityPolicySourceType::Module->value,
            'source_identifier' => 'fake-module',
            'applies_to' => 'admin',
            'fail_mode' => 'block',
            'priority' => 100,
        ]);
        IdentityPolicy::create([
            'key' => 'fake-module.stale.policy',
            'scope' => 'hook',
            'target' => 'fake-module.stale.before_action',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'source_type' => IdentityPolicySourceType::Module->value,
            'source_identifier' => 'fake-module',
            'applies_to' => 'admin',
            'fail_mode' => 'block',
            'priority' => 100,
        ]);

        // 모듈 declaration: keep 만 있음 (stale 은 없음)
        $module = $this->makeFakeModule(['policies' => [
            [
                'key' => 'fake-module.keep.policy',
                'scope' => 'hook',
                'target' => 'fake-module.keep.before_action',
                'purpose' => 'sensitive_action',
                'enabled' => false,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
        ]]);

        $this->invokeProtectedSync(app(ModuleManager::class), 'syncModuleIdentityPolicies', $module);

        $this->assertDatabaseHas('identity_policies', ['key' => 'fake-module.keep.policy']);
        $this->assertDatabaseMissing('identity_policies', ['key' => 'fake-module.stale.policy']);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function makeFakeModule(array $declarations): AbstractModule
    {
        $module = new class($declarations) extends AbstractModule
        {
            public function __construct(private array $data)
            {
            }

            public function getName(): string|array
            {
                return 'Fake Module';
            }

            public function getDescription(): string|array
            {
                return 'Test';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getIdentityPolicies(): array
            {
                return $this->data['policies'] ?? [];
            }

            public function getIdentityMessages(): array
            {
                return $this->data['messages'] ?? [];
            }

            public function getNotificationDefinitions(): array
            {
                return $this->data['notifications'] ?? [];
            }

            public function getAdminMenus(): array
            {
                return $this->data['menus'] ?? [];
            }

            public function getPermissions(): array
            {
                return $this->data['permissions'] ?? [];
            }
        };

        $this->setIdentifier($module, AbstractModule::class, 'fake-module');

        return $module;
    }

    private function makeFakePlugin(array $declarations): AbstractPlugin
    {
        $plugin = new class($declarations) extends AbstractPlugin
        {
            public function __construct(private array $data)
            {
            }

            public function getName(): string|array
            {
                return 'Fake Plugin';
            }

            public function getDescription(): string|array
            {
                return 'Test';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getIdentityPolicies(): array
            {
                return $this->data['policies'] ?? [];
            }

            public function getIdentityMessages(): array
            {
                return $this->data['messages'] ?? [];
            }

            public function getNotificationDefinitions(): array
            {
                return $this->data['notifications'] ?? [];
            }
        };

        $this->setIdentifier($plugin, AbstractPlugin::class, 'fake-plugin');

        return $plugin;
    }

    private function setIdentifier(object $extension, string $abstractClass, string $identifier): void
    {
        $reflection = new ReflectionClass($abstractClass);
        if (! $reflection->hasProperty('identifier')) {
            return;
        }
        $prop = $reflection->getProperty('identifier');
        $prop->setAccessible(true);
        $prop->setValue($extension, $identifier);
    }

    private function invokeProtectedSync(object $manager, string $method, object $extension): void
    {
        $reflection = new ReflectionClass($manager);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        $m->invoke($manager, $extension);
    }
}
