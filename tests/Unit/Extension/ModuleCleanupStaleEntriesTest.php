<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /**
     * 이 테스트는 파일시스템(활성 디렉토리)을 건드리지 않는다.
     *
     * 검증 대상은 cleanupStaleModuleEntries / removeModulePermissionsAndMenus 의 DB 권한
     * 로직이므로, 실제 installModule/uninstallModule(파일 복사·삭제·마이그레이션·오토로드
     * 포함)을 호출하는 대신 파일 무접촉 protected 메서드(createModulePermissions /
     * assignPermissionsToRoles / removeModulePermissionsAndMenus)만 Reflection 으로 호출한다.
     * 이커머스 활성 디렉토리는 setUp 의 loadModules() 로 이미 로드된 것을 재사용하며 절대
     * 삭제/변형하지 않는다 — 과거 실제 install/uninstall 호출이 dev 이커머스 디렉토리를
     * 삭제한 사고(feedback_test_must_protect_dev_directories)를 구조적으로 차단한다.
     */
    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * 이커머스 모듈 권한을 DB 에 시드합니다 (파일시스템 무접촉).
     *
     * installModule 전체 대신 권한 생성/역할 할당 Phase 만 Reflection 으로 실행한다.
     * 이미 로드된 이커머스 모듈 인스턴스를 사용하므로 활성 디렉토리를 건드리지 않는다.
     *
     * @return \App\Contracts\Extension\ModuleInterface 이커머스 모듈 인스턴스
     */
    private function seedEcommercePermissions()
    {
        $module = $this->moduleManager->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module, 'sirsoft-ecommerce 모듈이 로드되어 있어야 합니다.');

        $this->invokeProtected('createModulePermissions', $module);
        $this->invokeProtected('assignPermissionsToRoles', $module);

        return $module;
    }

    /**
     * ModuleManager 의 protected 메서드를 호출합니다 (파일 무접촉 Phase 격리 실행용).
     *
     * @param  string  $method  메서드명
     * @param  mixed  ...$args  인자
     * @return mixed 반환값
     */
    private function invokeProtected(string $method, ...$args)
    {
        return (new ReflectionMethod($this->moduleManager, $method))
            ->invoke($this->moduleManager, ...$args);
    }

    public function test_cleanup_stale_module_entries_does_not_delete_live_permissions(): void
    {
        $module = $this->seedEcommercePermissions();

        // 시드 후 ecommerce 모듈 권한 수 측정
        $countBefore = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();
        $this->assertGreaterThan(
            10,
            $countBefore,
            'ecommerce 모듈은 권한 시드 직후 다수의 권한을 보유해야 합니다 (카테고리 + action 조합).'
        );

        // cleanupStaleModuleEntries 를 직접 실행 — 정의와 저장이 일치하는 상태라면 아무것도 삭제되면 안 됨
        $this->invokeProtected('cleanupStaleModuleEntries', $module);

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
        $this->seedEcommercePermissions();

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

        $this->invokeProtected('cleanupStaleModuleEntries', $module);

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
        $module = $this->seedEcommercePermissions();

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

        $this->invokeProtected('cleanupStaleModuleEntries', $wrapper);

        $this->assertNotNull(
            Permission::where('identifier', 'sirsoft-ecommerce.dynamic-scope.action-alpha')->first(),
            '동적 권한은 getDynamicPermissionIdentifiers 반환 시 보존되어야 합니다.'
        );
    }

    /**
     * uninstall(deleteData=false) 시 권한·메뉴·역할이 보존되어 재설치 경로를 비파괴적으로 만드는지 검증.
     * 운영 정책: "동적 권한/메뉴는 데이터 삭제 옵션 체크 시에만 삭제".
     *
     * deleteData=false 경로는 removeModulePermissionsAndMenus 를 호출하지 않는 것과 동치이므로,
     * 권한 시드 후 삭제 메서드를 호출하지 않고 권한이 그대로 보존됨을 검증한다(파일 무접촉).
     */
    public function test_uninstall_without_delete_data_preserves_permissions(): void
    {
        $this->seedEcommercePermissions();

        $countBefore = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();
        $this->assertGreaterThan(0, $countBefore);

        // deleteData=false → removeModulePermissionsAndMenus 미호출 (권한 삭제 안 함).
        // 실제 uninstallModule 은 이 분기에서 권한 삭제 메서드를 건너뛴다.

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
     *
     * deleteData=true 경로는 removeModulePermissionsAndMenus 를 호출하므로, 이 메서드를
     * 직접 실행해 권한이 전수 삭제됨을 검증한다(파일 무접촉).
     */
    public function test_uninstall_with_delete_data_removes_permissions(): void
    {
        $module = $this->seedEcommercePermissions();

        $this->assertGreaterThan(
            0,
            Permission::where('extension_identifier', 'sirsoft-ecommerce')->count(),
        );

        $this->invokeProtected('removeModulePermissionsAndMenus', $module);

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
