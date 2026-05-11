<?php

namespace Tests\Feature\Module;

use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * 모듈 메뉴 동기화 통합 테스트
 *
 * ExtensionMenuSyncHelper를 통한 실제 DB 기반 메뉴 동기화를 검증합니다:
 * - 설치 시 메뉴 생성 (user_overrides 없음)
 * - 업데이트 시 user_overrides 기반 사용자 커스터마이징 보존
 * - 동적 메뉴/권한 보존 (#135: cleanup 자동 호출 폐기)
 * - 새 메뉴 추가
 */
class ModuleMenuSyncTest extends TestCase
{
    use ProtectsExtensionDirectories;
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    /** @var bool 활성 디렉토리가 테스트 전에 이미 존재했는지 (tearDown에서 정리 판단용) */
    private bool $boardExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        // 이전 세션에서 남은 좀비 디렉토리 정리
        $activePath = base_path('modules/sirsoft-board');
        if (File::isDirectory($activePath) && ! File::exists($activePath.'/module.php')) {
            File::deleteDirectory($activePath);
        }

        $this->boardExistedBefore = File::isDirectory($activePath);

        // 확장 디렉토리 보호 활성화
        $this->setUpExtensionProtection();

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

    protected function tearDown(): void
    {
        // 확장 디렉토리 보호 해제
        $this->tearDownExtensionProtection();

        // 테스트에서 생성한 활성 디렉토리만 정리
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
     */
    private function callProtectedMethod(string $methodName, array $args = []): mixed
    {
        $method = new ReflectionMethod($this->moduleManager, $methodName);

        return $method->invokeArgs($this->moduleManager, $args);
    }

    /**
     * 모듈 설치 시 메뉴가 올바르게 생성되는지 테스트
     */
    public function test_module_install_creates_menus(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // sirsoft-board: 부모 1 + 자식 3 = 4
        $menuCount = Menu::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->count();
        $this->assertEquals(4, $menuCount);

        // 부모 메뉴 확인
        $parentMenu = Menu::where('slug', 'sirsoft-board')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->first();

        $this->assertNotNull($parentMenu);
        $this->assertNotNull($parentMenu->name);
        $this->assertEquals('fas fa-clipboard-list', $parentMenu->icon);
        $this->assertEquals(30, $parentMenu->order);

        // 자식 메뉴 확인
        $childMenu = Menu::where('slug', 'sirsoft-board-list')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $this->assertNotNull($childMenu);
        $this->assertEquals($childMenu->parent_id, $parentMenu->id);
    }

    /**
     * 최초 설치 시 메뉴에 user_overrides가 비어있는지 테스트
     */
    public function test_no_user_overrides_on_fresh_menu_install(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $allMenus = Menu::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->get();

        foreach ($allMenus as $menu) {
            $this->assertTrue(
                empty($menu->user_overrides),
                "메뉴 '{$menu->slug}'의 user_overrides는 비어있어야 합니다"
            );
        }
    }

    /**
     * user_overrides에 "name"이 있으면 재설치 시 메뉴명이 보존되는지 테스트
     */
    public function test_user_menu_name_override_preserved_on_reinstall(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 사용자가 메뉴명 수정 + user_overrides 기록
        $parentMenu = Menu::where('slug', 'sirsoft-board')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $customName = ['ko' => '우리 게시판', 'en' => 'Our Board'];
        $parentMenu->update([
            'name' => $customName,
            'user_overrides' => ['name'],
        ]);

        // 재설치 (업데이트 시뮬레이션)
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('createModuleMenus', [$module]);

        $parentMenu->refresh();

        // 사용자 수정 이름 보존
        $this->assertEquals($customName, $parentMenu->name);
    }

    /**
     * user_overrides에 "order"가 있으면 재설치 시 메뉴 순서가 보존되는지 테스트
     */
    public function test_user_menu_order_override_preserved_on_reinstall(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 사용자가 순서 변경 + user_overrides 기록
        $parentMenu = Menu::where('slug', 'sirsoft-board')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $originalIcon = $parentMenu->icon;
        $parentMenu->update([
            'order' => 99,
            'user_overrides' => ['order'],
        ]);

        // 재설치 (업데이트 시뮬레이션)
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('createModuleMenus', [$module]);

        $parentMenu->refresh();

        // 사용자 수정 순서 보존
        $this->assertEquals(99, $parentMenu->order);

        // 미수정 아이콘은 확장 정의값 유지
        $this->assertEquals($originalIcon, $parentMenu->icon);
    }

    /**
     * user_overrides가 없으면 재설치 시 메뉴가 확장 정의값으로 갱신되는지 테스트
     */
    public function test_reinstall_updates_unmodified_menus(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $parentMenu = Menu::where('slug', 'sirsoft-board')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $originalName = $parentMenu->name;
        $originalIcon = $parentMenu->icon;
        $originalOrder = $parentMenu->order;

        // 재설치
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('createModuleMenus', [$module]);

        $parentMenu->refresh();

        // user_overrides 없으면 확장 정의값 그대로 유지
        $this->assertEquals($originalName, $parentMenu->name);
        $this->assertEquals($originalIcon, $parentMenu->icon);
        $this->assertEquals($originalOrder, $parentMenu->order);
    }

    /**
     * 제거 후 재설치 시 메뉴가 올바르게 유지·복원되는지 테스트
     *
     * PO 정책(#716eef99d, 2026-04-20): deleteData=false 시 메뉴/권한/역할은 보존된다.
     * 따라서 uninstall 후에도 메뉴는 DB 에 남아있고, 재설치는 idempotent (user_overrides 비어있음 유지).
     */
    public function test_reinstall_after_uninstall_creates_clean_menus(): void
    {
        // 설치
        $this->moduleManager->installModule('sirsoft-board');
        $this->assertTrue(Menu::where('extension_identifier', 'sirsoft-board')->exists());

        // 제거 (spy가 활성 디렉토리 삭제 차단) — deleteData=false 기본: 메뉴 보존
        $this->moduleManager->uninstallModule('sirsoft-board');
        $this->assertTrue(
            Menu::where('extension_identifier', 'sirsoft-board')->exists(),
            'deleteData=false 시 메뉴는 보존되어야 합니다 (재설치 시 사용자 역할 할당 복원 목적)'
        );

        // 재설치 (활성 디렉토리가 보존되어 있으므로 재로드 가능)
        $this->moduleManager->loadModules();
        $this->moduleManager->installModule('sirsoft-board');

        // 메뉴가 재설치 후에도 user_overrides 비어있음 (idempotent 동기화)
        $parentMenu = Menu::where('slug', 'sirsoft-board')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $this->assertNotNull($parentMenu);
        $this->assertTrue(
            empty($parentMenu->user_overrides),
            '재설치 후 user_overrides는 비어있어야 합니다'
        );
    }

    /**
     * 복수 필드 user_overrides 보존 테스트 (name + icon 동시 수정)
     */
    public function test_multiple_user_overrides_preserved_on_reinstall(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        $parentMenu = Menu::where('slug', 'sirsoft-board')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $customName = ['ko' => '우리 게시판', 'en' => 'Our Board'];
        $customIcon = 'fas fa-star';
        $originalOrder = $parentMenu->order;
        $originalUrl = $parentMenu->url;

        $parentMenu->update([
            'name' => $customName,
            'icon' => $customIcon,
            'user_overrides' => ['name', 'icon'],
        ]);

        // 재설치
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('createModuleMenus', [$module]);

        $parentMenu->refresh();

        // 수정된 필드 보존
        $this->assertEquals($customName, $parentMenu->name);
        $this->assertEquals($customIcon, $parentMenu->icon);

        // 미수정 필드는 확장 정의값 유지
        $this->assertEquals($originalOrder, $parentMenu->order);
        $this->assertEquals($originalUrl, $parentMenu->url);
    }

    // ========== 동적 메뉴/권한 보존 테스트 ==========

    /**
     * 모듈 업데이트 시 동적으로 생성된 메뉴가 보존되는지 테스트
     *
     * 시나리오: 게시판 모듈 설치 후 사용자가 개별 게시판을 관리자 메뉴에 등록.
     * 모듈 업데이트(createModuleMenus 재호출) 시 동적 메뉴가 삭제되지 않아야 함.
     */
    public function test_module_update_preserves_dynamic_menus(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 정적 메뉴 수 확인 (부모 1 + 자식 3 = 4)
        $staticMenuCount = Menu::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->count();
        $this->assertEquals(4, $staticMenuCount);

        // 동적 메뉴 생성 시뮬레이션 (BoardService::addToAdminMenu 동작 재현)
        Menu::create([
            'name' => ['ko' => '공지사항', 'en' => 'Notice'],
            'slug' => 'board-notice',
            'url' => '/admin/board/notice',
            'icon' => 'fas fa-clipboard-list',
            'parent_id' => null,
            'is_active' => true,
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'order' => 50,
        ]);

        Menu::create([
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'board-free',
            'url' => '/admin/board/free',
            'icon' => 'fas fa-clipboard-list',
            'parent_id' => null,
            'is_active' => true,
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'order' => 51,
        ]);

        // 동적 메뉴 포함 총 6개 확인 (정적 4 + 동적 2)
        $totalMenuCount = Menu::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->count();
        $this->assertEquals(6, $totalMenuCount);

        // 모듈 업데이트 시뮬레이션 (createModuleMenus 재호출)
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('createModuleMenus', [$module]);

        // 동적 메뉴가 보존되어야 함
        $this->assertTrue(
            Menu::where('slug', 'board-notice')
                ->where('extension_identifier', 'sirsoft-board')
                ->exists(),
            '동적 메뉴 board-notice가 모듈 업데이트 후에도 보존되어야 합니다'
        );

        $this->assertTrue(
            Menu::where('slug', 'board-free')
                ->where('extension_identifier', 'sirsoft-board')
                ->exists(),
            '동적 메뉴 board-free가 모듈 업데이트 후에도 보존되어야 합니다'
        );

        // 전체 메뉴 수 유지 (정적 4 + 동적 2 = 6)
        $afterUpdateCount = Menu::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->count();
        $this->assertEquals(6, $afterUpdateCount);
    }

    /**
     * 모듈 업데이트 시 동적으로 생성된 권한이 보존되는지 테스트
     *
     * 시나리오: 게시판 모듈 설치 후 BoardPermissionService가 동적 권한 생성.
     * 모듈 업데이트(createModulePermissions 재호출) 시 동적 권한이 삭제되지 않아야 함.
     */
    public function test_module_update_preserves_dynamic_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 정적 권한 수 확인
        $staticPermCount = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->count();
        $this->assertGreaterThan(0, $staticPermCount);

        // 동적 권한 생성 시뮬레이션 (BoardPermissionService::ensureBoardPermissions 동작 재현)
        $moduleRoot = Permission::where('identifier', 'sirsoft-board')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        // 모듈 루트 권한이 없으면 생성
        if (! $moduleRoot) {
            $moduleRoot = Permission::create([
                'identifier' => 'sirsoft-board',
                'name' => ['ko' => '게시판', 'en' => 'Board'],
                'description' => ['ko' => '게시판 모듈', 'en' => 'Board module'],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => 'sirsoft-board',
                'type' => 'admin',
            ]);
        }

        // 게시판별 동적 권한 생성
        $categoryPerm = Permission::create([
            'identifier' => 'sirsoft-board.notice',
            'name' => ['ko' => '공지사항 게시판', 'en' => 'Notice Board'],
            'description' => ['ko' => '공지사항 게시판 권한', 'en' => 'Notice board permissions'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'parent_id' => $moduleRoot->id,
            'type' => 'admin',
        ]);

        Permission::create([
            'identifier' => 'sirsoft-board.notice.posts.list',
            'name' => ['ko' => '글 목록', 'en' => 'List Posts'],
            'description' => ['ko' => '글 목록 조회', 'en' => 'List posts'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'parent_id' => $categoryPerm->id,
            'type' => 'admin',
        ]);

        $totalPermCountBefore = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->count();

        // 모듈 업데이트 시뮬레이션 (createModulePermissions 재호출)
        $module = $this->moduleManager->getModule('sirsoft-board');
        $this->callProtectedMethod('createModulePermissions', [$module]);

        // 동적 권한이 보존되어야 함
        $this->assertTrue(
            Permission::where('identifier', 'sirsoft-board.notice')
                ->where('extension_identifier', 'sirsoft-board')
                ->exists(),
            '동적 권한 sirsoft-board.notice가 모듈 업데이트 후에도 보존되어야 합니다'
        );

        $this->assertTrue(
            Permission::where('identifier', 'sirsoft-board.notice.posts.list')
                ->where('extension_identifier', 'sirsoft-board')
                ->exists(),
            '동적 권한 sirsoft-board.notice.posts.list가 모듈 업데이트 후에도 보존되어야 합니다'
        );

        // 전체 권한 수 유지 (정적 권한은 sync로 갱신, 동적 권한은 보존)
        $totalPermCountAfter = Permission::where('extension_type', ExtensionOwnerType::Module)
            ->where('extension_identifier', 'sirsoft-board')
            ->count();
        $this->assertEquals($totalPermCountBefore, $totalPermCountAfter);
    }

    /**
     * 모듈 제거 (deleteData=true) 시 동적 메뉴를 포함한 모든 메뉴가 삭제되는지 테스트
     *
     * PO 정책(#716eef99d, 2026-04-20): deleteData=true 경로에서만 메뉴/권한/역할 전수 삭제.
     * deleteData=false (기본) 경로는 메뉴 보존 → test_module_uninstall_preserves_menus_by_default 참조.
     */
    public function test_module_uninstall_removes_all_menus_including_dynamic(): void
    {
        $this->moduleManager->installModule('sirsoft-board');

        // 동적 메뉴 생성 시뮬레이션
        Menu::create([
            'name' => ['ko' => '공지사항', 'en' => 'Notice'],
            'slug' => 'board-notice',
            'url' => '/admin/board/notice',
            'icon' => 'fas fa-clipboard-list',
            'parent_id' => null,
            'is_active' => true,
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'order' => 50,
        ]);

        // 정적 + 동적 메뉴 존재 확인 (정적 4 + 동적 1 = 5)
        $this->assertEquals(5, Menu::where('extension_identifier', 'sirsoft-board')->count());

        // 모듈 제거 — deleteData=true 로 메뉴/권한/역할 전수 삭제 경로 사용
        $this->moduleManager->uninstallModule('sirsoft-board', deleteData: true);

        // 모든 메뉴 (정적 + 동적) 삭제 확인
        $this->assertFalse(
            Menu::where('extension_identifier', 'sirsoft-board')->exists(),
            'deleteData=true 시 동적 메뉴를 포함한 모든 메뉴가 삭제되어야 합니다'
        );
    }
}
