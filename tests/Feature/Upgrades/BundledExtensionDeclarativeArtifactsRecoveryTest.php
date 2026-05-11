<?php

namespace Tests\Feature\Upgrades;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\UpgradeContext;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * `Upgrade_7_0_0_beta_4` 의 모듈/플러그인 IDV/메시지/알림 정의 사후 보정 회귀.
 *
 * 결함 시나리오 (production 로그 + DB 조회 결과로 입증):
 *   - beta.3 → beta.4 업그레이드 후 `identity_policies` 테이블에 `core` source 9건만 존재하고
 *     모듈/플러그인 source 정책이 0건 (`SELECT source_type, source_identifier, COUNT(*) FROM
 *     identity_policies GROUP BY source_type, source_identifier;` → 1행, core/core/9).
 *
 * 근본 원인 두 축:
 *   1. 부모 메모리 stale — `BundledExtensionUpdatePrompt` 가 `core:update` 부모(beta.3)
 *      프로세스에서 실행되어 `$moduleManager->updateModule(...)` 호출 시 beta.3 메모리의
 *      ModuleManager 코드 사용. beta.3 의 ModuleManager 에는 `syncModuleIdentityPolicies`
 *      등이 부재 (`@since 7.0.0-beta.4`) → 사용자가 yes 를 선택해도 시드되지 않음.
 *   2. 활성 dir 에 신규 declaration 부재 — beta.3 출시본의 활성 dir module.php 에는
 *      IDV/메시지/알림 declaration 메서드(getIdentityPolicies 등) 자체가 도입되지 않았음.
 *      따라서 활성 dir 을 fresh-load 해도 declaration count 0 → 시드 안 됨.
 *
 * 수정: `Upgrade_7_0_0_beta_4` (spawn 자식, beta.4 메모리) 가 `resyncAllActiveDeclarativeArtifacts`
 * 호출. 해당 메서드는 _bundled 디렉토리(이번 코어 업그레이드로 출하된 NEW 코드) 의
 * module.php/plugin.php 를 fresh-load 하므로 활성 dir 의 OLD 코드(declaration 부재) 와
 * 무관하게 NEW declaration 을 시드. 멱등.
 *
 * 본 테스트는 _bundled fresh-load 경로를 진짜로 검증하기 위해 임시 sandbox 디렉토리에
 * fake module/plugin 의 진입점 파일을 생성하고, ModuleManager/PluginManager 의
 * `bundledModulesPath`/`bundledPluginsPath` 프로퍼티를 sandbox 로 교체한다.
 */
class BundledExtensionDeclarativeArtifactsRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private object $upgrade;

    private UpgradeContext $context;

    private string $sandboxModulesPath;

    private string $sandboxPluginsPath;

    private ?string $originalBundledModulesPath = null;

    private ?string $originalBundledPluginsPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('upgrades/Upgrade_7_0_0_beta_4.php');

        $class = 'App\\Upgrades\\Upgrade_7_0_0_beta_4';
        $this->upgrade = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '7.0.0-beta.3',
            toVersion: '7.0.0-beta.4',
            currentStep: '7.0.0-beta.4',
        );

        // 임시 sandbox 디렉토리 — 실제 _bundled 트리를 오염시키지 않기 위함.
        $this->sandboxModulesPath = storage_path('framework/testing/issue326-bundled-modules-'.uniqid());
        $this->sandboxPluginsPath = storage_path('framework/testing/issue326-bundled-plugins-'.uniqid());
        File::ensureDirectoryExists($this->sandboxModulesPath);
        File::ensureDirectoryExists($this->sandboxPluginsPath);

        $this->swapBundledPath(ModuleManager::class, 'bundledModulesPath', $this->sandboxModulesPath, $this->originalBundledModulesPath);
        $this->swapBundledPath(PluginManager::class, 'bundledPluginsPath', $this->sandboxPluginsPath, $this->originalBundledPluginsPath);
    }

    protected function tearDown(): void
    {
        $this->swapBundledPath(ModuleManager::class, 'bundledModulesPath', $this->originalBundledModulesPath, $unused1);
        $this->swapBundledPath(PluginManager::class, 'bundledPluginsPath', $this->originalBundledPluginsPath, $unused2);

        File::deleteDirectory($this->sandboxModulesPath);
        File::deleteDirectory($this->sandboxPluginsPath);

        parent::tearDown();
    }

    /**
     * 활성 모듈이 _bundled 에 선언한 IDV 정책이 step 호출 후 DB 에 시드되는지 검증.
     *
     * step 호출 전: 정책 0건. step 호출 후: 모듈 선언 정책이 source_type=module 로 저장.
     */
    public function test_active_module_identity_policies_are_seeded_by_recovery_step(): void
    {
        $this->registerFakeModule(
            identifier: 'fake-recoverymodule',
            policies: [
                [
                    'key' => 'fake-recoverymodule.action.delete',
                    'scope' => 'hook',
                    'target' => 'fake-recoverymodule.action.before_delete',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 5,
                    'enabled' => false,
                    'applies_to' => 'admin',
                    'fail_mode' => 'block',
                ],
                [
                    'key' => 'fake-recoverymodule.action.publish',
                    'scope' => 'hook',
                    'target' => 'fake-recoverymodule.action.before_publish',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 0,
                    'enabled' => false,
                    'applies_to' => 'self',
                    'fail_mode' => 'block',
                ],
            ],
        );

        // pre-condition: 정책이 DB에 없음 (production 결함 상태 재현)
        $this->assertDatabaseMissing('identity_policies', [
            'source_type' => 'module',
            'source_identifier' => 'fake-recoverymodule',
        ]);

        $this->invokeRecover();

        $this->assertDatabaseHas('identity_policies', [
            'key' => 'fake-recoverymodule.action.delete',
            'source_type' => 'module',
            'source_identifier' => 'fake-recoverymodule',
            'purpose' => 'sensitive_action',
        ]);
        $this->assertDatabaseHas('identity_policies', [
            'key' => 'fake-recoverymodule.action.publish',
            'source_type' => 'module',
            'source_identifier' => 'fake-recoverymodule',
            'applies_to' => 'self',
        ]);
    }

    /**
     * 활성 플러그인이 _bundled 에 선언한 IDV 정책이 step 호출 후 DB 에 시드되는지 검증.
     */
    public function test_active_plugin_identity_policies_are_seeded_by_recovery_step(): void
    {
        $this->registerFakePlugin(
            identifier: 'fake-recoveryplugin',
            policies: [
                [
                    'key' => 'fake-recoveryplugin.payment.cancel',
                    'scope' => 'hook',
                    'target' => 'fake-recoveryplugin.payment.before_cancel',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 0,
                    'enabled' => false,
                    'applies_to' => 'admin',
                    'fail_mode' => 'block',
                ],
            ],
        );

        $this->assertDatabaseMissing('identity_policies', [
            'source_type' => 'plugin',
            'source_identifier' => 'fake-recoveryplugin',
        ]);

        $this->invokeRecover();

        $this->assertDatabaseHas('identity_policies', [
            'key' => 'fake-recoveryplugin.payment.cancel',
            'source_type' => 'plugin',
            'source_identifier' => 'fake-recoveryplugin',
        ]);
    }

    /**
     * 멱등: 정상 환경(이미 시드됨)에서 재실행해도 user_overrides 가 보존되어야 함.
     */
    public function test_recovery_is_idempotent_and_preserves_user_overrides(): void
    {
        $this->registerFakeModule(
            identifier: 'fake-idempotentmodule',
            policies: [[
                'key' => 'fake-idempotentmodule.toggle',
                'scope' => 'hook',
                'target' => 'fake-idempotentmodule.before_action',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => false,
                'applies_to' => 'both',
                'fail_mode' => 'block',
            ]],
        );

        // 1차 호출 — 시드
        $this->invokeRecover();

        // 운영자가 enabled + grace_minutes 변경 + user_overrides 마킹
        \App\Models\IdentityPolicy::where('key', 'fake-idempotentmodule.toggle')->update([
            'enabled' => true,
            'grace_minutes' => 30,
            'user_overrides' => ['enabled', 'grace_minutes'],
        ]);

        // 2차 호출 — 멱등 (선언 기본값으로 덮어쓰지 않아야 함)
        $this->invokeRecover();

        $row = \App\Models\IdentityPolicy::where('key', 'fake-idempotentmodule.toggle')->first();
        $this->assertNotNull($row, '1차 시드 후 정책 row 가 존재해야 함');
        $this->assertTrue((bool) $row->enabled, 'user_overrides 의 enabled 가 보존되어야 함');
        $this->assertSame(30, (int) $row->grace_minutes, 'user_overrides 의 grace_minutes 가 보존되어야 함');
    }

    /**
     * 활성 모듈을 DB(modules 테이블) 에 등록하고 sandbox _bundled 에 module.php 진입점을 생성.
     *
     * 본 테스트의 sandbox 는 ModuleManager::$bundledModulesPath 를 임시 경로로 교체했으므로
     * 여기에 module.php 를 두면 `resyncAllActiveDeclarativeArtifacts()` 의 fresh-load 가 실제로
     * 동작한다 (in-memory 인스턴스 inject 우회 — production 흐름과 일치).
     */
    private function registerFakeModule(string $identifier, array $policies): void
    {
        Module::query()->updateOrCreate(
            ['identifier' => $identifier],
            [
                'vendor' => explode('-', $identifier, 2)[0] ?? 'fake',
                'name' => json_encode(['ko' => 'Fake', 'en' => 'Fake']),
                'description' => json_encode(['ko' => 'fake', 'en' => 'fake']),
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
            ],
        );
        ModuleManager::invalidateModuleStatusCache();

        $namespace = $this->namespaceFromIdentifier($identifier);
        $moduleDir = $this->sandboxModulesPath.DIRECTORY_SEPARATOR.$identifier;
        File::ensureDirectoryExists($moduleDir);

        $policiesPhp = var_export($policies, true);
        $contents = <<<PHP
<?php

namespace Modules\\{$namespace};

use App\\Extension\\AbstractModule;

class Module extends AbstractModule
{
    public function getName(): string|array
    {
        return 'Fake Recovery Module';
    }

    public function getDescription(): string|array
    {
        return 'fake';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getIdentityPolicies(): array
    {
        return {$policiesPhp};
    }
}

PHP;

        File::put($moduleDir.DIRECTORY_SEPARATOR.'module.php', $contents);
    }

    private function registerFakePlugin(string $identifier, array $policies): void
    {
        Plugin::query()->updateOrCreate(
            ['identifier' => $identifier],
            [
                'vendor' => explode('-', $identifier, 2)[0] ?? 'fake',
                'name' => json_encode(['ko' => 'Fake', 'en' => 'Fake']),
                'description' => json_encode(['ko' => 'fake', 'en' => 'fake']),
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
            ],
        );
        PluginManager::invalidatePluginStatusCache();

        $namespace = $this->namespaceFromIdentifier($identifier);
        $pluginDir = $this->sandboxPluginsPath.DIRECTORY_SEPARATOR.$identifier;
        File::ensureDirectoryExists($pluginDir);

        $policiesPhp = var_export($policies, true);
        $contents = <<<PHP
<?php

namespace Plugins\\{$namespace};

use App\\Extension\\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function getName(): string|array
    {
        return 'Fake Recovery Plugin';
    }

    public function getDescription(): string|array
    {
        return 'fake';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getIdentityPolicies(): array
    {
        return {$policiesPhp};
    }
}

PHP;

        File::put($pluginDir.DIRECTORY_SEPARATOR.'plugin.php', $contents);
    }

    private function namespaceFromIdentifier(string $identifier): string
    {
        $parts = array_map(
            fn (string $part): string => str_replace(' ', '', ucwords(str_replace('_', ' ', $part))),
            explode('-', $identifier),
        );

        return implode('\\', $parts);
    }

    private function swapBundledPath(string $managerClass, string $property, ?string $newValue, ?string &$captured): void
    {
        $manager = app($managerClass);
        $ref = new ReflectionClass($manager);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        $captured = $prop->getValue($manager);
        if ($newValue !== null) {
            $prop->setValue($manager, $newValue);
        }
    }

    private function invokeRecover(): void
    {
        $reflection = new ReflectionClass($this->upgrade);
        $method = $reflection->getMethod('resyncBundledExtensionDeclarativeArtifacts');
        $method->setAccessible(true);
        $method->invoke($this->upgrade, $this->context);
    }
}
