<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ModuleManager::cleanupStaleModuleEntries() 회귀 테스트.
 *
 * 회귀 방지 대상: 모듈 정의의 권한은 `action` 필드로 식별되고, DB 저장 시
 * `{moduleIdentifier}.{category.identifier}.{action}` 포맷으로 기록된다.
 * cleanupStaleModuleEntries 가 동일 포맷으로 expected 목록을 만들지 않으면
 * 모든 모듈 권한이 "stale" 로 오판되어 전수 삭제되는 치명적 회귀가 발생한다.
 *
 * (참고) beta.2 번들 일괄 업데이트 시 sirsoft-board / sirsoft-ecommerce 등
 * 모듈 권한이 대부분 삭제된 실제 프로덕션 증상을 재현·차단한다.
 */
class ModuleCleanupStaleEntriesTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    private bool $ecommerceExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        $modulesPath = base_path('modules');
        $activePath = $modulesPath.'/sirsoft-ecommerce';
        if (File::isDirectory($activePath) && ! File::exists($activePath.'/module.php')) {
            File::deleteDirectory($activePath);
        }
        $this->ecommerceExistedBefore = File::isDirectory($activePath);

        $this->moduleManager = app(ModuleManager::class);
        $this->moduleManager->loadModules();

        Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '시스템 관리자', 'en' => 'System Administrator'],
            'description' => ['ko' => '관리자', 'en' => 'Administrator'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
        Role::create([
            'identifier' => 'manager',
            'name' => ['ko' => '매니저', 'en' => 'Manager'],
            'description' => ['ko' => '매니저', 'en' => 'Manager'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        User::factory()->create(['email' => 'test@test.com']);
    }

    protected function tearDown(): void
    {
        if (! $this->ecommerceExistedBefore) {
            $activePath = base_path('modules/sirsoft-ecommerce');
            if (File::isDirectory($activePath)) {
                File::deleteDirectory($activePath);
            }
        }

        parent::tearDown();
    }

    public function test_cleanup_stale_module_entries_does_not_delete_live_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        $module = $this->moduleManager->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module);

        // 설치 후 ecommerce 모듈 권한 수 측정
        $countBefore = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();
        $this->assertGreaterThan(
            10,
            $countBefore,
            'ecommerce 모듈은 install 직후 다수의 권한을 보유해야 합니다 (카테고리 + action 조합).'
        );

        // cleanupStaleModuleEntries 를 직접 실행 — 정의와 저장이 일치하는 상태라면 아무것도 삭제되면 안 됨
        $method = new ReflectionMethod($this->moduleManager, 'cleanupStaleModuleEntries');
        $method->invoke($this->moduleManager, $module);

        $countAfter = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();

        $this->assertSame(
            $countBefore,
            $countAfter,
            'cleanupStaleModuleEntries 는 정의된 권한을 삭제하지 않아야 합니다. '
            ."before={$countBefore}, after={$countAfter} (손실={$this->lossCount($countBefore, $countAfter)})"
        );
    }

    public function test_cleanup_stale_module_entries_removes_only_genuinely_stale_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        // 인위적으로 stale 권한 1건 삽입 (정의에 없는 identifier)
        Permission::create([
            'identifier' => 'sirsoft-ecommerce.removed-category.obsolete-action',
            'name' => ['ko' => '제거된 권한', 'en' => 'Removed permission'],
            'description' => ['ko' => '제거된 권한', 'en' => 'Removed permission'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-ecommerce',
            'type' => \App\Enums\PermissionType::Admin,
            'order' => 999,
            'parent_id' => null,
        ]);

        $module = $this->moduleManager->getModule('sirsoft-ecommerce');
        $liveCountBefore = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->where('identifier', '!=', 'sirsoft-ecommerce.removed-category.obsolete-action')
            ->count();

        $method = new ReflectionMethod($this->moduleManager, 'cleanupStaleModuleEntries');
        $method->invoke($this->moduleManager, $module);

        // stale 1건만 삭제되고 나머지는 보존되어야 함
        $this->assertNull(
            Permission::where('identifier', 'sirsoft-ecommerce.removed-category.obsolete-action')->first(),
            'stale 권한은 삭제되어야 합니다.'
        );

        $liveCountAfter = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();

        $this->assertSame(
            $liveCountBefore,
            $liveCountAfter,
            '정의된 권한은 전부 보존되어야 합니다. '
            ."before={$liveCountBefore}, after={$liveCountAfter}"
        );
    }

    /**
     * 동적 권한(getDynamicPermissionIdentifiers override) 이 stale cleanup 대상에서 보존되는지 검증.
     *
     * 회귀 시나리오: sirsoft-board 처럼 런타임에 Permission::updateOrCreate 로 추가된 권한이
     * 모듈 업데이트 시점의 stale cleanup 에서 "정적 정의에 없다"는 이유로 전수 삭제되는 버그.
     */
    public function test_cleanup_preserves_dynamic_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');
        $module = $this->moduleManager->getModule('sirsoft-ecommerce');

        // 런타임 동적 권한 모사: 정적 정의에 없는 식별자를 삽입
        Permission::create([
            'identifier' => 'sirsoft-ecommerce.dynamic-scope.action-alpha',
            'name' => ['ko' => '동적', 'en' => 'Dynamic'],
            'description' => ['ko' => '동적', 'en' => 'Dynamic'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-ecommerce',
            'type' => \App\Enums\PermissionType::Admin,
            'order' => 900,
            'parent_id' => null,
        ]);

        // Mockery partial mock: 실제 모듈 동작 유지 + getDynamicPermissionIdentifiers 만 override
        $wrapper = \Mockery::mock($module)->makePartial();
        $wrapper->shouldReceive('getDynamicPermissionIdentifiers')
            ->andReturn(['sirsoft-ecommerce.dynamic-scope.action-alpha']);

        $method = new ReflectionMethod($this->moduleManager, 'cleanupStaleModuleEntries');
        $method->invoke($this->moduleManager, $wrapper);

        $this->assertNotNull(
            Permission::where('identifier', 'sirsoft-ecommerce.dynamic-scope.action-alpha')->first(),
            '동적 권한은 getDynamicPermissionIdentifiers 반환 시 보존되어야 합니다.'
        );
    }

    /**
     * uninstall(deleteData=false) 시 권한·메뉴·역할이 보존되어 재설치 경로를 비파괴적으로 만드는지 검증.
     * 운영 정책: "동적 권한/메뉴는 데이터 삭제 옵션 체크 시에만 삭제".
     */
    public function test_uninstall_without_delete_data_preserves_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        $countBefore = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();
        $this->assertGreaterThan(0, $countBefore);

        $this->moduleManager->uninstallModule('sirsoft-ecommerce', deleteData: false);

        $countAfter = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();

        $this->assertSame(
            $countBefore,
            $countAfter,
            'deleteData=false 시 권한은 보존되어야 합니다.'
        );
    }

    /**
     * uninstall(deleteData=true) 시 권한이 삭제되는지 (기존 동작 유지) 검증.
     */
    public function test_uninstall_with_delete_data_removes_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        $this->assertGreaterThan(
            0,
            Permission::where('extension_identifier', 'sirsoft-ecommerce')->count(),
        );

        $this->moduleManager->uninstallModule('sirsoft-ecommerce', deleteData: true);

        $this->assertSame(
            0,
            Permission::where('extension_identifier', 'sirsoft-ecommerce')->count(),
            'deleteData=true 시 권한은 전수 삭제되어야 합니다.'
        );
    }

    /**
     * updateComposerAutoload 가 cleanupStaleModuleEntries 보다 먼저 호출되는 순서를 검증.
     *
     * 회귀 시나리오: 동적 hook(getDynamicPermissionIdentifiers 등)이 모듈 클래스(예: Board 모델)를
     * 참조할 때, 현재 프로세스의 PSR-4 매핑이 갱신되기 전에 cleanup 이 실행되면 "Class not found"
     * 가 발생한다. 본 테스트는 ModuleManager::updateModule 의 소스 구조적 순서를 검증한다.
     */
    public function test_update_module_calls_autoload_before_cleanup(): void
    {
        $source = file_get_contents(base_path('app/Extension/ModuleManager.php'));

        $start = strpos($source, 'public function updateModule(');
        $this->assertNotFalse($start, 'updateModule 메서드를 찾을 수 없습니다.');
        // 다음 메서드(visibility 무관) 시작 지점을 본문 종료로 사용
        $end = false;
        foreach (['public function ', 'protected function ', 'private function '] as $kw) {
            $pos = strpos($source, $kw, $start + 1);
            if ($pos !== false && ($end === false || $pos < $end)) {
                $end = $pos;
            }
        }
        $this->assertNotFalse($end, '다음 메서드를 찾지 못해 본문 범위 결정 실패');
        $body = substr($source, $start, $end - $start);

        $autoloadPos = strpos($body, '$this->extensionManager->updateComposerAutoload();');
        // updateModule 은 cleanupStaleModuleEntries 를 직접 호출하거나 syncDeclarativeArtifacts
        // 위임으로 호출한다 (위임이 내부적으로 cleanup 실행). 어느 형태든 해당 호출이
        // updateComposerAutoload 이후에 위치해야 한다.
        $cleanupPos = strpos($body, '$this->cleanupStaleModuleEntries(');
        if ($cleanupPos === false) {
            $cleanupPos = strpos($body, '$this->syncDeclarativeArtifacts(');
        }

        $this->assertNotFalse($autoloadPos, 'updateComposerAutoload 호출을 찾을 수 없습니다.');
        $this->assertNotFalse($cleanupPos, 'cleanupStaleModuleEntries 또는 syncDeclarativeArtifacts 호출을 찾을 수 없습니다.');
        $this->assertLessThan(
            $cleanupPos,
            $autoloadPos,
            'updateComposerAutoload 는 cleanupStale (또는 syncDeclarativeArtifacts 위임) 보다 먼저 호출되어야 한다. '
            .'(동적 hook 의 모듈 클래스 autoload 보장)'
        );
    }

    public function test_update_plugin_calls_autoload_before_cleanup(): void
    {
        $source = file_get_contents(base_path('app/Extension/PluginManager.php'));

        $start = strpos($source, 'public function updatePlugin(');
        $this->assertNotFalse($start, 'updatePlugin 메서드를 찾을 수 없습니다.');
        // 다음 메서드(visibility 무관) 시작 지점을 본문 종료로 사용
        $end = false;
        foreach (['public function ', 'protected function ', 'private function '] as $kw) {
            $pos = strpos($source, $kw, $start + 1);
            if ($pos !== false && ($end === false || $pos < $end)) {
                $end = $pos;
            }
        }
        $this->assertNotFalse($end, '다음 메서드를 찾지 못해 본문 범위 결정 실패');
        $body = substr($source, $start, $end - $start);

        $autoloadPos = strpos($body, '$this->extensionManager->updateComposerAutoload();');
        $cleanupPos = strpos($body, '$this->cleanupStalePluginEntries(');
        if ($cleanupPos === false) {
            $cleanupPos = strpos($body, '$this->syncDeclarativeArtifacts(');
        }

        $this->assertNotFalse($autoloadPos);
        $this->assertNotFalse($cleanupPos);
        $this->assertLessThan(
            $cleanupPos,
            $autoloadPos,
            'updateComposerAutoload 는 cleanupStale (또는 syncDeclarativeArtifacts 위임) 보다 먼저 호출되어야 한다.'
        );
    }

    private function lossCount(int $before, int $after): int
    {
        return max(0, $before - $after);
    }
}
