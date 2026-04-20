<?php

namespace Tests\Feature\Module;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\ModuleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

class ModuleRolePermissionTest extends TestCase
{
    use ProtectsExtensionDirectories;
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    /** @var bool 테스트 전에 활성 디렉토리가 이미 존재했는지 */
    private bool $boardExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        $activePath = base_path('modules/sirsoft-board');
        $bundledPath = base_path('modules/_bundled/sirsoft-board');

        // 이전 세션에서 남은 좀비 디렉토리 정리 (module.php 없는 잔존 디렉토리)
        if (File::isDirectory($activePath) && ! File::exists($activePath.'/module.php')) {
            File::deleteDirectory($activePath);
        }

        // 활성 디렉토리 존재 여부 판정
        $this->boardExistedBefore = File::isDirectory($activePath)
            && File::exists($activePath.'/module.php');

        // 미설치 시 _bundled에서 복사 (테스트 실행을 위한 준비)
        if (! $this->boardExistedBefore && File::isDirectory($bundledPath)) {
            File::copyDirectory($bundledPath, $activePath);
        }

        // 확장 디렉토리 보호 활성화 (이후 모든 deleteDirectory 호출 차단)
        $this->setUpExtensionProtection();

        $this->moduleManager = app(ModuleManager::class);
        $this->moduleManager->loadModules();

        // 기본 admin role 생성
        Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '시스템 관리자', 'en' => 'System Administrator'],
            'description' => ['ko' => '모든 권한을 가진 관리자', 'en' => 'Administrator with all permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        // manager role 생성 (sirsoft-board 권한에서 참조)
        Role::create([
            'identifier' => 'manager',
            'name' => ['ko' => '매니저', 'en' => 'Manager'],
            'description' => ['ko' => '관리 권한을 가진 매니저', 'en' => 'Manager with management permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        // 테스트용 사용자 생성
        User::factory()->create(['email' => 'admin@test.com']);
    }

    protected function tearDown(): void
    {
        // 확장 디렉토리 보호 해제 (원래 File 파사드 복원)
        $this->tearDownExtensionProtection();

        // 테스트에서 생성한 활성 디렉토리만 정리 (기존 설치분은 건드리지 않음)
        if (! $this->boardExistedBefore) {
            $activePath = base_path('modules/sirsoft-board');
            if (File::isDirectory($activePath)) {
                File::deleteDirectory($activePath);
            }
        }

        parent::tearDown();
    }

    /**
     * 모듈 설치 시 권한이 생성되는지 테스트
     *
     * 계층형 구조로 권한이 생성됨:
     * - 1레벨: 모듈 노드 (sirsoft-board)
     * - 2레벨: 카테고리 노드 (sirsoft-board.boards, sirsoft-board.settings, sirsoft-board.reports)
     * - 3레벨: 개별 권한 (boards: read/create/update/delete, settings: read/update, reports: view/manage)
     */
    public function test_module_installation_creates_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 권한이 생성되었는지 확인 (계층형 구조: 모듈 1 + 카테고리 3 + 개별 권한 8 = 12개)
        $permissions = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')->get();
        $this->assertCount(12, $permissions);

        $identifiers = $permissions->pluck('identifier')->toArray();
        // 1레벨: 모듈 노드
        $this->assertContains('sirsoft-board', $identifiers);
        // 2레벨: 카테고리 노드
        $this->assertContains('sirsoft-board.boards', $identifiers);
        $this->assertContains('sirsoft-board.settings', $identifiers);
        $this->assertContains('sirsoft-board.reports', $identifiers);
        // 3레벨: 개별 권한 - boards
        $this->assertContains('sirsoft-board.boards.read', $identifiers);
        $this->assertContains('sirsoft-board.boards.create', $identifiers);
        $this->assertContains('sirsoft-board.boards.update', $identifiers);
        $this->assertContains('sirsoft-board.boards.delete', $identifiers);
        // 3레벨: 개별 권한 - settings
        $this->assertContains('sirsoft-board.settings.read', $identifiers);
        $this->assertContains('sirsoft-board.settings.update', $identifiers);
        // 3레벨: 개별 권한 - reports
        $this->assertContains('sirsoft-board.reports.view', $identifiers);
        $this->assertContains('sirsoft-board.reports.manage', $identifiers);
    }

    /**
     * 모듈 설치 시 권한이 지정된 role에 할당되는지 테스트
     */
    public function test_module_installation_assigns_permissions_to_roles(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // admin role에 모든 개별 권한(8개)이 할당되었는지 확인 (boards 4 + settings 2 + reports 2)
        $adminRole = Role::where('identifier', 'admin')->first();
        $adminPermissions = $adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->get();
        $this->assertCount(8, $adminPermissions);

        // manager role에 할당된 권한 확인 (boards: read, create, update + reports: view = 4개)
        $managerRole = Role::where('identifier', 'manager')->first();
        $managerPermissions = $managerRole->permissions()->where('extension_identifier', 'sirsoft-board')->get();
        $this->assertCount(4, $managerPermissions);

        $managerIdentifiers = $managerPermissions->pluck('identifier')->toArray();
        $this->assertContains('sirsoft-board.boards.read', $managerIdentifiers);
        $this->assertContains('sirsoft-board.boards.create', $managerIdentifiers);
        $this->assertContains('sirsoft-board.boards.update', $managerIdentifiers);
        $this->assertContains('sirsoft-board.reports.view', $managerIdentifiers);
    }

    /**
     * 기존 role에 새 권한 추가 시 기존 권한이 유지되는지 테스트
     */
    public function test_existing_role_permissions_are_preserved(): void
    {
        // admin role에 기존 권한 추가
        $adminRole = Role::where('identifier', 'admin')->first();
        $existingPermission = Permission::create([
            'identifier' => 'core.test.permission',
            'name' => ['ko' => '테스트 권한', 'en' => 'Test Permission'],
            'description' => ['ko' => '테스트용 권한', 'en' => 'Test permission'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);
        $adminRole->permissions()->attach($existingPermission->id);

        // 모듈 설치
        $this->moduleManager->installModule('sirsoft-board');

        // 기존 권한이 유지되는지 확인
        $adminRole->refresh();
        $this->assertTrue($adminRole->permissions()->where('identifier', 'core.test.permission')->exists());

        // 새 권한도 추가되었는지 확인
        $this->assertTrue($adminRole->permissions()->where('identifier', 'sirsoft-board.boards.read')->exists());
    }

    /**
     * 모듈 제거 시 권한이 role에서 분리되는지 테스트
     */
    public function test_module_uninstallation_detaches_permissions_from_roles(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // admin role에 권한이 있는지 확인
        $adminRole = Role::where('identifier', 'admin')->first();
        $this->assertTrue($adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->exists());

        // 모듈 제거
        $this->moduleManager->uninstallModule('sirsoft-board');

        // 권한이 분리되었는지 확인
        $adminRole->refresh();
        $this->assertFalse($adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->exists());
    }

    /**
     * 모듈 제거 시 권한이 삭제되는지 테스트
     */
    public function test_module_uninstallation_deletes_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 권한이 존재하는지 확인
        $this->assertTrue(Permission::where('extension_identifier', 'sirsoft-board')->exists());

        // 모듈 제거
        $this->moduleManager->uninstallModule('sirsoft-board');

        // 권한이 삭제되었는지 확인
        $this->assertFalse(Permission::where('extension_identifier', 'sirsoft-board')->exists());
    }

    /**
     * 시스템 role은 모듈 제거 시에도 삭제되지 않는지 테스트
     */
    public function test_system_roles_are_not_deleted_on_uninstall(): void
    {
        $this->moduleManager->installModule('sirsoft-board');
        $this->moduleManager->uninstallModule('sirsoft-board');

        // 시스템 admin role은 삭제되지 않음
        $this->assertTrue(Role::where('identifier', 'admin')->exists());
    }

    /**
     * getPermissions() 메서드가 roles 필드를 포함하는지 테스트
     *
     * 계층형 구조에서 개별 권한(3레벨)에 roles 필드가 있는지 확인
     */
    public function test_get_permissions_includes_roles_field(): void
    {
        $this->moduleManager->installModule('sirsoft-board');
        $module = $this->moduleManager->getModule('sirsoft-board');
        $permissionConfig = $module->getPermissions();

        $this->assertIsArray($permissionConfig);
        $this->assertArrayHasKey('categories', $permissionConfig);
        $this->assertNotEmpty($permissionConfig['categories']);

        // 계층형 구조에서 개별 권한 검증
        foreach ($permissionConfig['categories'] as $category) {
            $this->assertArrayHasKey('identifier', $category);
            $this->assertArrayHasKey('name', $category);
            $this->assertArrayHasKey('description', $category);
            $this->assertArrayHasKey('permissions', $category);

            foreach ($category['permissions'] as $permission) {
                $this->assertArrayHasKey('action', $permission);
                $this->assertArrayHasKey('name', $permission);
                $this->assertArrayHasKey('description', $permission);
                $this->assertArrayHasKey('roles', $permission);
                $this->assertIsArray($permission['roles']);
            }
        }

        // boards 카테고리의 권한 확인
        $boardsCategory = collect($permissionConfig['categories'])->firstWhere('identifier', 'boards');
        $this->assertNotNull($boardsCategory);

        // read 권한은 admin과 manager 둘 다에게 할당
        $readPermission = collect($boardsCategory['permissions'])->firstWhere('action', 'read');
        $this->assertContains('admin', $readPermission['roles']);
        $this->assertContains('manager', $readPermission['roles']);

        // delete 권한은 admin에게만 할당
        $deletePermission = collect($boardsCategory['permissions'])->firstWhere('action', 'delete');
        $this->assertContains('admin', $deletePermission['roles']);
        $this->assertNotContains('manager', $deletePermission['roles']);
    }

    /**
     * roles 필드가 없는 권한은 role에 할당되지 않는지 테스트
     *
     * 계층형 구조에서 개별 권한(3레벨)만 role에 할당됨.
     * 모듈 노드(1레벨)와 카테고리 노드(2레벨)는 할당되지 않음.
     */
    public function test_permissions_without_roles_field_are_not_assigned(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 계층형 구조에서 개별 권한(3레벨)만 role에 할당됨
        // 모듈의 getPermissions()에서 정의된 개별 권한 8개만 할당되어야 함
        $adminRole = Role::where('identifier', 'admin')->first();

        // admin role에 할당된 모듈 권한 개수 확인 (개별 권한 8개)
        $assignedPermissions = $adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->count();
        $this->assertEquals(8, $assignedPermissions);

        // 전체 모듈 권한 개수 확인 (모듈 1 + 카테고리 3 + 개별 8 = 12개)
        $totalModulePermissions = Permission::where('extension_identifier', 'sirsoft-board')->count();
        $this->assertEquals(12, $totalModulePermissions);

        // 개별 권한만 role에 할당되었는지 확인
        $assignedIdentifiers = $adminRole->permissions()
            ->where('extension_identifier', 'sirsoft-board')
            ->pluck('identifier')
            ->toArray();

        $this->assertContains('sirsoft-board.boards.read', $assignedIdentifiers);
        $this->assertContains('sirsoft-board.boards.create', $assignedIdentifiers);
        $this->assertContains('sirsoft-board.boards.update', $assignedIdentifiers);
        $this->assertContains('sirsoft-board.boards.delete', $assignedIdentifiers);
        $this->assertContains('sirsoft-board.settings.read', $assignedIdentifiers);
        $this->assertContains('sirsoft-board.settings.update', $assignedIdentifiers);
        $this->assertContains('sirsoft-board.reports.view', $assignedIdentifiers);
        $this->assertContains('sirsoft-board.reports.manage', $assignedIdentifiers);

        // 모듈 노드와 카테고리 노드는 할당되지 않음
        $this->assertNotContains('sirsoft-board', $assignedIdentifiers);
        $this->assertNotContains('sirsoft-board.boards', $assignedIdentifiers);
        $this->assertNotContains('sirsoft-board.settings', $assignedIdentifiers);
        $this->assertNotContains('sirsoft-board.reports', $assignedIdentifiers);
    }
}
