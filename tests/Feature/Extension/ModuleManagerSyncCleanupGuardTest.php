<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionOwnerType;
use App\Enums\IdentityPolicySourceType;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\IdentityPolicy;
use App\Models\Menu;
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
 *   - declaration 메서드(`getIdentityPolicies` /
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

    /**
     * 모듈의 본인인증 정책 선언이 빈 배열이어도 DB 에 남은 그 모듈 소유 정책 row 를 지우지 않는다.
     *
     * @scenario declaration_state=empty_with_db_rows
     *
     * @effects module_identity_policies_preserved_when_declaration_empty
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모듈의 관리자 메뉴 선언이 빈 배열이어도 stale 정리(cleanupStaleModuleEntries)가 그 모듈 소유 메뉴 row 를 지우지 않는다.
     *
     * @scenario declaration_state=empty_with_db_rows
     *
     * @effects module_menus_preserved_when_declaration_empty
     *
     * @return void 반환값 없음
     */
    public function test_module_menus_cleanup_blocked_when_declaration_empty_and_db_has_rows(): void
    {
        $module = $this->makeFakeModule(['menus' => []]);

        Menu::create([
            'slug' => 'fake-module-preserve-menu',
            'name' => ['ko' => '보존 메뉴', 'en' => 'Preserve Menu'],
            'extension_type' => ExtensionOwnerType::Module->value,
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

    /**
     * 모듈의 알림 정의 선언이 빈 배열이어도 DB 에 남은 그 모듈 소유 알림 정의 row 를 지우지 않는다.
     *
     * @scenario declaration_state=empty_with_db_rows
     *
     * @effects module_notification_definitions_preserved_when_declaration_empty
     *
     * @return void 반환값 없음
     */
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

    /**
     * 플러그인의 본인인증 정책 선언이 빈 배열이어도 DB 에 남은 그 플러그인 소유 정책 row 를 지우지 않는다.
     *
     * @scenario declaration_state=empty_with_db_rows
     *
     * @effects plugin_identity_policies_preserved_when_declaration_empty
     *
     * @return void 반환값 없음
     */
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

    /**
     * 플러그인의 알림 정의 선언이 빈 배열이어도 DB 에 남은 그 플러그인 소유 알림 정의 row 를 지우지 않는다.
     *
     * @scenario declaration_state=empty_with_db_rows
     *
     * @effects plugin_notification_definitions_preserved_when_declaration_empty
     *
     * @return void 반환값 없음
     */
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

    /**
     * 선언도 DB 도 비어 있으면 정책·알림 정의 동기화가 예외 없이 끝나고 row 를 하나도 만들지 않는다.
     *
     * @scenario declaration_state=empty_without_db_rows
     *
     * @effects sync_skips_silently_when_declaration_and_db_both_empty
     *
     * @return void 반환값 없음
     */
    public function test_module_sync_skips_silently_when_both_declaration_and_db_empty(): void
    {
        $module = $this->makeFakeModule([]);

        $this->invokeProtectedSync(app(ModuleManager::class), 'syncModuleIdentityPolicies', $module);
        $this->invokeProtectedSync(app(ModuleManager::class), 'syncModuleNotificationDefinitions', $module);

        $this->assertDatabaseCount('identity_policies', 0);
        $this->assertDatabaseCount('notification_definitions', 0);
    }

    /**
     * 선언이 일부 정책만 담고 있으면 선언에 없는 stale 정책 row 만 지우고 선언된 정책은 남긴다
     * (빈 선언 가드가 정상 정리 경로를 막지 않음).
     *
     * @scenario declaration_state=partial_with_stale_rows
     *
     * @effects stale_rows_removed_when_declaration_is_partial
     *
     * @return void 반환값 없음
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

    /**
     * 선언 getter 가 주어진 배열을 그대로 돌려주는 가짜 모듈을 만듭니다 (식별자 fake-module).
     *
     * @param  array<string, array>  $declarations  영역별 선언 (policies·messages·notifications·menus·permissions)
     * @return AbstractModule 가짜 모듈
     */
    private function makeFakeModule(array $declarations): AbstractModule
    {
        $module = new class($declarations) extends AbstractModule
        {
            /**
             * 영역별 선언 배열을 보관합니다.
             *
             * @param  array<string, array>  $data  영역별 선언
             */
            public function __construct(private array $data) {}

            /**
             * 가짜 확장 이름을 돌려줍니다.
             *
             * @return string|array 이름
             */
            public function getName(): string|array
            {
                return 'Fake Module';
            }

            /**
             * 가짜 확장 설명을 돌려줍니다.
             *
             * @return string|array 설명
             */
            public function getDescription(): string|array
            {
                return 'Test';
            }

            /**
             * 가짜 확장 버전을 돌려줍니다.
             *
             * @return string 버전
             */
            public function getVersion(): string
            {
                return '1.0.0';
            }

            /**
             * 본인인증 정책 선언을 돌려줍니다.
             *
             * @return array 정책 선언 (미지정 시 빈 배열)
             */
            public function getIdentityPolicies(): array
            {
                return $this->data['policies'] ?? [];
            }

            /**
             * (호환층) 옛 본인인증 메시지 선언을 돌려줍니다.
             *
             * @return array 메시지 선언 (미지정 시 빈 배열)
             */
            public function getIdentityMessages(): array
            {
                return $this->data['messages'] ?? [];
            }

            /**
             * 알림 정의 선언을 돌려줍니다.
             *
             * @return array 알림 정의 선언 (미지정 시 빈 배열)
             */
            public function getNotificationDefinitions(): array
            {
                return $this->data['notifications'] ?? [];
            }

            /**
             * 관리자 메뉴 선언을 돌려줍니다.
             *
             * @return array 메뉴 선언 (미지정 시 빈 배열)
             */
            public function getAdminMenus(): array
            {
                return $this->data['menus'] ?? [];
            }

            /**
             * 권한 선언을 돌려줍니다.
             *
             * @return array 권한 선언 (미지정 시 빈 배열)
             */
            public function getPermissions(): array
            {
                return $this->data['permissions'] ?? [];
            }
        };

        $this->setIdentifier($module, AbstractModule::class, 'fake-module');

        return $module;
    }

    /**
     * 선언 getter 가 주어진 배열을 그대로 돌려주는 가짜 플러그인을 만듭니다 (식별자 fake-plugin).
     *
     * @param  array<string, array>  $declarations  영역별 선언 (policies·messages·notifications)
     * @return AbstractPlugin 가짜 플러그인
     */
    private function makeFakePlugin(array $declarations): AbstractPlugin
    {
        $plugin = new class($declarations) extends AbstractPlugin
        {
            /**
             * 영역별 선언 배열을 보관합니다.
             *
             * @param  array<string, array>  $data  영역별 선언
             */
            public function __construct(private array $data) {}

            /**
             * 가짜 확장 이름을 돌려줍니다.
             *
             * @return string|array 이름
             */
            public function getName(): string|array
            {
                return 'Fake Plugin';
            }

            /**
             * 가짜 확장 설명을 돌려줍니다.
             *
             * @return string|array 설명
             */
            public function getDescription(): string|array
            {
                return 'Test';
            }

            /**
             * 가짜 확장 버전을 돌려줍니다.
             *
             * @return string 버전
             */
            public function getVersion(): string
            {
                return '1.0.0';
            }

            /**
             * 본인인증 정책 선언을 돌려줍니다.
             *
             * @return array 정책 선언 (미지정 시 빈 배열)
             */
            public function getIdentityPolicies(): array
            {
                return $this->data['policies'] ?? [];
            }

            /**
             * (호환층) 옛 본인인증 메시지 선언을 돌려줍니다.
             *
             * @return array 메시지 선언 (미지정 시 빈 배열)
             */
            public function getIdentityMessages(): array
            {
                return $this->data['messages'] ?? [];
            }

            /**
             * 알림 정의 선언을 돌려줍니다.
             *
             * @return array 알림 정의 선언 (미지정 시 빈 배열)
             */
            public function getNotificationDefinitions(): array
            {
                return $this->data['notifications'] ?? [];
            }
        };

        $this->setIdentifier($plugin, AbstractPlugin::class, 'fake-plugin');

        return $plugin;
    }

    /**
     * 추상 확장 클래스의 identifier 속성에 식별자를 주입합니다 (속성이 없으면 아무것도 하지 않음).
     *
     * @param  object  $extension  대상 확장 인스턴스
     * @param  string  $abstractClass  identifier 속성을 선언한 추상 클래스
     * @param  string  $identifier  주입할 식별자
     * @return void 반환값 없음
     */
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

    /**
     * Manager 의 protected 동기화 메서드를 리플렉션으로 호출합니다.
     *
     * @param  object  $manager  ModuleManager 또는 PluginManager
     * @param  string  $method  호출할 메서드 이름
     * @param  object  $extension  동기화 대상 확장
     * @return void 반환값 없음
     */
    private function invokeProtectedSync(object $manager, string $method, object $extension): void
    {
        $reflection = new ReflectionClass($manager);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        $m->invoke($manager, $extension);
    }
}
