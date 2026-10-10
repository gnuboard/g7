<?php

namespace Tests\Feature\Module;

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
 * 모듈 역할/권한 동기화 통합 테스트
 *
 * ExtensionRoleSyncHelper를 통한 실제 DB 기반 동기화를 검증합니다:
 * - Permission은 항상 확장 정의값으로 덮어쓰기
 * - user_overrides 배열로 유저 커스터마이징 보존
 * - DB 기반 diff로 권한 attach/detach
 * - stale 권한 정리
 */
class ModuleRolePermissionSyncTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    /** @var bool 활성 디렉토리가 테스트 전에 이미 존재했는지 (tearDown에서 정리 판단용) */
    private bool $boardExistedBefore = false;

    /**
     * 활성 디렉토리 잔존물을 정리하고 기본 시스템 역할·사용자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 이전 테스트에서 남은 잔존 디렉토리 정리
        $activePath = base_path('modules/sirsoft-board');
        if (File::isDirectory($activePath) && ! File::exists($activePath.'/module.php')) {
            File::deleteDirectory($activePath);
        }

        $this->boardExistedBefore = File::isDirectory($activePath);
        $this->moduleManager = app(ModuleManager::class);
        $this->moduleManager->loadModules();

        // 기본 시스템 역할 생성
        Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '시스템 관리자', 'en' => 'System Administrator'],
            'description' => ['ko' => '모든 권한을 가진 관리자', 'en' => 'Administrator with all permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

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

    /**
     * 이 시험이 만든 활성 디렉토리만 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        // 테스트에서 생성한 활성 디렉토리만 정리 (기존에 있었으면 건드리지 않음)
        if (! $this->boardExistedBefore) {
            $activePath = base_path('modules/sirsoft-board');
            if (File::isDirectory($activePath)) {
                File::deleteDirectory($activePath);
            }
        }

        parent::tearDown();
    }

    /**
     * ModuleManager의 protected 메서드를 호출하는 헬퍼
     *
     * @param  string  $methodName  메서드명
     * @param  array  $args  인자
     * @return mixed 호출한 메서드의 반환값
     */
    private function callProtectedMethod(string $methodName, array $args = []): mixed
    {
        $method = new ReflectionMethod($this->moduleManager, $methodName);

        return $method->invokeArgs($this->moduleManager, $args);
    }

    /**
     * 게시판 모듈의 권한 정의(module.php getPermissions())에서 기대 권한 수를 계산합니다.
     *
     * 게시판은 기능이 늘 때마다 권한 범주가 더해진다(본인인증 정책·대시보드·스크랩·반응 관리 등). 숫자를
     * 시험에 박아 두면 정의가 바뀔 때마다 동기화 로직이 멀쩡해도 시험이 깨지므로, 정의에서 직접 센다.
     *
     * @return array{total: int, admin: int, manager: int} 전체(모듈 루트 + 범주 + 리프)·admin 리프·manager 리프 수
     */
    private function expectedBoardPermissionCounts(): array
    {
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->assertNotNull($module, '게시판 모듈 인스턴스가 있어야 합니다.');

        $categories = $module->getPermissions()['categories'] ?? [];
        $this->assertNotEmpty($categories, '게시판 권한 정의에 범주가 있어야 합니다.');

        $total = 1 + count($categories);
        $admin = 0;
        $manager = 0;

        foreach ($categories as $category) {
            foreach ($category['permissions'] ?? [] as $leaf) {
                $total++;
                $roles = $leaf['roles'] ?? [];
                $admin += in_array('admin', $roles, true) ? 1 : 0;
                $manager += in_array('manager', $roles, true) ? 1 : 0;
            }
        }

        return ['total' => $total, 'admin' => $admin, 'manager' => $manager];
    }

    /**
     * 모듈 설치 시 권한이 생성되고 역할에 할당되는지 테스트
     *
     * @scenario case=fresh_install
     *
     * @effects permissions_created_from_definition, roles_assigned_from_definition
     *
     * @return void 반환값 없음
     */
    public function test_module_install_creates_permissions_and_assigns_to_roles(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $expected = $this->expectedBoardPermissionCounts();

        // 권한 생성 확인 (모듈 루트 1 + 카테고리 + 리프 — module.php 정의에서 계산)
        $permissions = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')->get();
        $this->assertCount($expected['total'], $permissions);

        // admin 역할에 할당된 sirsoft-board 권한 확인 (정의에서 admin 이 붙은 리프)
        $adminRole = Role::where('identifier', 'admin')->first();
        $adminPermissions = $adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->get();
        $this->assertCount($expected['admin'], $adminPermissions);

        // manager 역할에 할당된 sirsoft-board 권한 확인 (정의에서 manager 가 붙은 리프)
        $managerRole = Role::where('identifier', 'manager')->first();
        $managerPermissions = $managerRole->permissions()->where('extension_identifier', 'sirsoft-board')->get();
        $this->assertCount($expected['manager'], $managerPermissions);
    }

    /**
     * Permission은 항상 확장 정의값으로 덮어쓰기되는지 테스트
     *
     * @scenario case=reinstall
     *
     * @effects permission_overwritten_by_definition
     *
     * @return void 반환값 없음
     */
    public function test_reinstall_always_overwrites_permission(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $readPerm = Permission::where('identifier', 'sirsoft-board.boards.read')->first();
        $originalName = $readPerm->name;

        // 재설치 (업데이트 시뮬레이션)
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('createModulePermissions', [$module]);

        $readPerm->refresh();

        // 항상 확장 정의값으로 덮어씀
        $this->assertEquals($originalName, $readPerm->name);
    }

    /**
     * 최초 설치 시 역할에 user_overrides가 비어있는지 테스트
     *
     * @scenario case=fresh_install
     *
     * @effects no_user_overrides_on_fresh_install
     *
     * @return void 반환값 없음
     */
    public function test_no_user_overrides_on_fresh_install(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $adminRole = Role::where('identifier', 'admin')->first();
        $managerRole = Role::where('identifier', 'manager')->first();

        // 최초 설치 시 user_overrides는 비어있거나 null
        $this->assertTrue(
            empty($adminRole->user_overrides),
            'admin 역할의 user_overrides는 비어있어야 합니다'
        );

        $this->assertTrue(
            empty($managerRole->user_overrides),
            'manager 역할의 user_overrides는 비어있어야 합니다'
        );
    }

    /**
     * user_overrides에 개별 권한 식별자가 있으면 해당 권한만 보호되는지 테스트
     *
     * 시나리오:
     * 1. 모듈 설치 → admin에 정의상 admin 리프 권한 전부 할당
     * 2. 유저가 admin에서 boards.delete 해제 + user_overrides에 "sirsoft-board.boards.delete" 기록
     * 3. 재설치 (업데이트 시뮬레이션)
     * 4. boards.delete만 보호 (해제 상태 유지), 나머지 권한은 정상 동기화
     *
     * @scenario case=reinstall_with_user_override
     *
     * @effects overridden_permission_kept_detached
     *
     * @return void 반환값 없음
     */
    public function test_user_permission_override_preserved_on_reinstall(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 초기 상태 확인 (admin 에 정의상 admin 리프 전부 할당)
        $adminLeaves = $this->expectedBoardPermissionCounts()['admin'];
        $adminRole = Role::where('identifier', 'admin')->first();
        $this->assertCount($adminLeaves, $adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->get());

        // 유저가 admin에서 boards.delete 권한 해제
        $deletePerm = Permission::where('identifier', 'sirsoft-board.boards.delete')->first();
        $adminRole->permissions()->detach($deletePerm->id);
        $this->assertCount($adminLeaves - 1, $adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->get());

        // user_overrides에 개별 권한 식별자 기록 (실제로는 Listener가 수행)
        $adminRole->update(['user_overrides' => ['sirsoft-board.boards.delete']]);

        // 재설치 (업데이트 시뮬레이션)
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('assignPermissionsToRoles', [$module]);

        // sirsoft-board.boards.delete만 보호 → 해제 상태 유지 → 하나 빠진 수 유지
        $adminRole->refresh();
        $this->assertCount(
            $adminLeaves - 1,
            $adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->get(),
            'user_overrides에 개별 권한 식별자가 있으면 해당 권한만 보호되어야 합니다'
        );

        // boards.delete는 여전히 해제 상태
        $this->assertFalse(
            $adminRole->permissions()->where('identifier', 'sirsoft-board.boards.delete')->exists(),
            '보호된 권한(boards.delete)은 해제 상태가 유지되어야 합니다'
        );
    }

    /**
     * user_overrides에 없는 권한은 재설치 시 정상 동기화되는지 테스트
     *
     * 시나리오:
     * 1. 모듈 설치 → admin에 정의상 admin 리프 권한 전부 할당
     * 2. 유저가 boards.delete 해제 + user_overrides에 기록
     * 3. 유저가 boards.read도 해제 (하지만 user_overrides에 미기록 — 가상 시나리오)
     * 4. 재설치 → boards.delete는 보호, boards.read는 비보호이므로 다시 attach
     *
     * @scenario case=reinstall_with_user_override
     *
     * @effects overridden_permission_kept_detached, non_overridden_permission_reattached
     *
     * @return void 반환값 없음
     */
    public function test_non_overridden_permissions_synced_normally(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $adminLeaves = $this->expectedBoardPermissionCounts()['admin'];
        $adminRole = Role::where('identifier', 'admin')->first();

        // 유저가 boards.delete 해제 + user_overrides 기록
        $deletePerm = Permission::where('identifier', 'sirsoft-board.boards.delete')->first();
        $adminRole->permissions()->detach($deletePerm->id);
        $adminRole->update(['user_overrides' => ['sirsoft-board.boards.delete']]);

        // boards.read도 해제 (user_overrides 미기록)
        $readPerm = Permission::where('identifier', 'sirsoft-board.boards.read')->first();
        $adminRole->permissions()->detach($readPerm->id);

        // admin 리프 중 2개 해제
        $this->assertCount($adminLeaves - 2, $adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->get());

        // 재설치
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('assignPermissionsToRoles', [$module]);

        $adminRole->refresh();

        // boards.read는 비보호 → 다시 attach → 하나만 빠진 수
        $this->assertTrue(
            $adminRole->permissions()->where('identifier', 'sirsoft-board.boards.read')->exists(),
            '비보호 권한(boards.read)은 재설치 시 다시 attach되어야 합니다'
        );

        // boards.delete는 보호 → 해제 상태 유지
        $this->assertFalse(
            $adminRole->permissions()->where('identifier', 'sirsoft-board.boards.delete')->exists(),
            '보호된 권한(boards.delete)은 해제 상태가 유지되어야 합니다'
        );

        $this->assertCount(
            $adminLeaves - 1,
            $adminRole->permissions()->where('extension_identifier', 'sirsoft-board')->get(),
            '비보호 권한만 동기화되고 보호 권한은 유지되어야 합니다'
        );
    }

    /**
     * user_overrides가 없는 역할은 DB 기반 diff로 새 권한만 attach되는지 테스트
     *
     * 시나리오:
     * 1. 모듈 설치 → manager에 정의상 manager 리프 권한 전부 할당
     * 2. 유저가 manager에서 boards.read 해제 (user_overrides 미기록 — Listener 미동작 가정)
     * 3. 재설치
     * 4. DB 기반 diff → boards.read가 이미 없으므로 다시 attach됨
     *
     * @scenario case=reinstall
     *
     * @effects non_overridden_permission_reattached
     *
     * @return void 반환값 없음
     */
    public function test_db_based_diff_reattaches_removed_permission_without_user_overrides(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $managerRole = Role::where('identifier', 'manager')->first();
        $readPerm = Permission::where('identifier', 'sirsoft-board.boards.read')->first();

        // 유저가 해제 (user_overrides 미기록)
        $managerRole->permissions()->detach($readPerm->id);
        $this->assertFalse($managerRole->permissions()->where('identifier', 'sirsoft-board.boards.read')->exists());

        // 재설치 (업데이트 시뮬레이션)
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('assignPermissionsToRoles', [$module]);

        // user_overrides 없음 → DB 기반 diff → boards.read가 다시 attach됨
        $this->assertTrue(
            $managerRole->permissions()->where('identifier', 'sirsoft-board.boards.read')->exists(),
            'user_overrides가 없으면 DB 기반 diff로 누락된 권한이 다시 attach되어야 합니다'
        );
    }

    /**
     * 사용자가 수동으로 추가한 역할 할당이 보존되는지 테스트
     *
     * @scenario case=reinstall
     *
     * @effects user_added_assignment_preserved
     *
     * @return void 반환값 없음
     */
    public function test_user_added_role_assignments_are_preserved(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $customRole = Role::create([
            'identifier' => 'custom-editor',
            'name' => ['ko' => '커스텀 편집자', 'en' => 'Custom Editor'],
            'description' => ['ko' => '사용자 정의 편집자 역할', 'en' => 'Custom editor role'],
            'is_active' => true,
        ]);

        $readPerm = Permission::where('identifier', 'sirsoft-board.boards.read')->first();
        $customRole->permissions()->attach($readPerm->id, [
            'granted_at' => now(),
        ]);

        // 재설치 (업데이트 시뮬레이션)
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('createModulePermissions', [$module]);
        $this->callProtectedMethod('assignPermissionsToRoles', [$module]);

        // 사용자가 수동 추가한 할당이 보존됨
        $this->assertTrue(
            $customRole->permissions()->where('identifier', 'sirsoft-board.boards.read')->exists(),
            '사용자가 수동으로 추가한 역할 할당이 보존되어야 합니다'
        );

        // 기존 admin/manager 할당도 유지됨
        $adminRole = Role::where('identifier', 'admin')->first();
        $this->assertTrue($adminRole->permissions()->where('identifier', 'sirsoft-board.boards.read')->exists());

        $managerRole = Role::where('identifier', 'manager')->first();
        $this->assertTrue($managerRole->permissions()->where('identifier', 'sirsoft-board.boards.read')->exists());
    }
}
