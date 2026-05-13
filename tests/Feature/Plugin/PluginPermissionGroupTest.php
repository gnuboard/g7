<?php

namespace Tests\Feature\Plugin;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 플러그인 권한 계층 구조 테스트
 *
 * PluginManager.createPluginPermissions()가 모듈과 동일한
 * 3레벨 계층(플러그인 → 카테고리 → 개별 권한)을 생성하는지 검증합니다.
 */
class PluginPermissionGroupTest extends TestCase
{
    use RefreshDatabase;

    private PluginManager $pluginManager;

    private bool $verificationExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        $activePath = base_path('plugins/sirsoft-verification');
        if (File::isDirectory($activePath) && ! File::exists($activePath.'/plugin.php')) {
            File::deleteDirectory($activePath);
        }

        $this->verificationExistedBefore = File::isDirectory($activePath);
        $this->pluginManager = app(PluginManager::class);
        $this->pluginManager->loadPlugins();

        // 기본 시스템 역할 생성
        Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '시스템 관리자', 'en' => 'System Administrator'],
            'description' => ['ko' => '모든 권한을 가진 관리자', 'en' => 'Administrator with all permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $activePath = base_path('plugins/sirsoft-verification');
        if (! $this->verificationExistedBefore && File::isDirectory($activePath)) {
            File::deleteDirectory($activePath);
        }

        parent::tearDown();
    }

    /**
     * 1레벨: 플러그인 그룹 노드가 생성되는지 확인
     */
    public function test_creates_plugin_group_node(): void
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-verification');
        $this->assertNotNull($plugin, '본인인증 플러그인이 로드되어야 합니다');

        $method = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');
        $method->invoke($this->pluginManager, $plugin);

        $groupNode = Permission::where('identifier', 'sirsoft-verification')
            ->whereNull('parent_id')
            ->first();

        $this->assertNotNull($groupNode, '플러그인 그룹 노드가 생성되어야 합니다');
        $this->assertEquals(ExtensionOwnerType::Plugin, $groupNode->extension_type);
        $this->assertEquals('sirsoft-verification', $groupNode->extension_identifier);
        $this->assertEquals('본인인증', $groupNode->getLocalizedName());
    }

    /**
     * 2레벨: 카테고리 노드가 플러그인 그룹 노드의 자식으로 생성되는지 확인
     */
    public function test_creates_category_nodes_under_plugin(): void
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-verification');

        $method = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');
        $method->invoke($this->pluginManager, $plugin);

        $groupNode = Permission::where('identifier', 'sirsoft-verification')->first();

        // 카테고리 노드 확인
        $categories = Permission::where('parent_id', $groupNode->id)->orderBy('order')->get();
        $this->assertCount(2, $categories, '2개 카테고리가 있어야 합니다');

        $this->assertEquals('sirsoft-verification.settings', $categories[0]->identifier);
        $this->assertEquals('설정 관리', $categories[0]->getLocalizedName());

        $this->assertEquals('sirsoft-verification.user', $categories[1]->identifier);
        $this->assertEquals('사용자 인증 정보', $categories[1]->getLocalizedName());
    }

    /**
     * 3레벨: 개별 권한이 카테고리 노드의 자식으로 등록되는지 확인
     */
    public function test_permissions_are_children_of_category_nodes(): void
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-verification');

        $method = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');
        $method->invoke($this->pluginManager, $plugin);

        // settings 카테고리의 자식 권한
        $settingsCategory = Permission::where('identifier', 'sirsoft-verification.settings')->first();
        $settingsPerms = Permission::where('parent_id', $settingsCategory->id)->orderBy('order')->get();
        $this->assertCount(2, $settingsPerms);
        $this->assertEquals('sirsoft-verification.settings.view', $settingsPerms[0]->identifier);
        $this->assertEquals('sirsoft-verification.settings.update', $settingsPerms[1]->identifier);

        // user 카테고리의 자식 권한
        $userCategory = Permission::where('identifier', 'sirsoft-verification.user')->first();
        $userPerms = Permission::where('parent_id', $userCategory->id)->orderBy('order')->get();
        $this->assertCount(2, $userPerms);
        $this->assertEquals('sirsoft-verification.user.view', $userPerms[0]->identifier);
        $this->assertEquals('sirsoft-verification.user.update', $userPerms[1]->identifier);
    }

    /**
     * 리프 노드(개별 권한)만 할당 가능한지 확인
     */
    public function test_only_leaf_permissions_are_assignable(): void
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-verification');

        $method = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');
        $method->invoke($this->pluginManager, $plugin);

        // 그룹 노드: 자식 있음 → 할당 불가
        $groupNode = Permission::where('identifier', 'sirsoft-verification')->first();
        $this->assertTrue($groupNode->children()->exists());

        // 카테고리 노드: 자식 있음 → 할당 불가
        $settingsCategory = Permission::where('identifier', 'sirsoft-verification.settings')->first();
        $this->assertTrue($settingsCategory->children()->exists());

        // 리프 권한: 자식 없음 → 할당 가능
        $leafPerm = Permission::where('identifier', 'sirsoft-verification.settings.view')->first();
        $this->assertFalse($leafPerm->children()->exists());
    }

    /**
     * 전체 권한 수 확인 (그룹 1 + 카테고리 2 + 개별 4 = 7)
     */
    public function test_total_permission_count(): void
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-verification');

        $method = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');
        $method->invoke($this->pluginManager, $plugin);

        $total = Permission::where('extension_identifier', 'sirsoft-verification')->count();
        $this->assertEquals(7, $total, '그룹 1개 + 카테고리 2개 + 개별 권한 4개 = 7개');
    }

    /**
     * categories가 없는 플러그인은 권한 노드를 생성하지 않는지 확인
     */
    public function test_no_permissions_for_plugin_without_categories(): void
    {
        $mockPlugin = $this->createMock(\App\Contracts\Extension\PluginInterface::class);
        $mockPlugin->method('getPermissions')->willReturn([]);
        $mockPlugin->method('getIdentifier')->willReturn('test-no-permissions');

        $method = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');
        $method->invoke($this->pluginManager, $mockPlugin);

        $this->assertEquals(0, Permission::where('extension_identifier', 'test-no-permissions')->count());
    }

    /**
     * 재실행(syncPermission) 시 중복 생성 없이 업데이트되는지 확인
     */
    public function test_sync_updates_without_duplication(): void
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-verification');

        $method = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');

        // 1차 실행
        $method->invoke($this->pluginManager, $plugin);
        $firstCount = Permission::where('extension_identifier', 'sirsoft-verification')->count();
        $firstGroupId = Permission::where('identifier', 'sirsoft-verification')->first()->id;

        // 2차 실행 (업데이트 시뮬레이션)
        $method->invoke($this->pluginManager, $plugin);
        $secondCount = Permission::where('extension_identifier', 'sirsoft-verification')->count();
        $secondGroupId = Permission::where('identifier', 'sirsoft-verification')->first()->id;

        $this->assertEquals($firstCount, $secondCount, '중복 생성 없이 동일 수');
        $this->assertEquals($firstGroupId, $secondGroupId, '그룹 노드 ID 유지');
        $this->assertEquals(7, $secondCount);
    }

    /**
     * 플러그인 삭제 시 모든 권한(그룹+카테고리+개별)이 삭제되는지 확인
     */
    public function test_remove_deletes_all_permission_levels(): void
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-verification');

        $createMethod = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');
        $createMethod->invoke($this->pluginManager, $plugin);

        $this->assertEquals(7, Permission::where('extension_identifier', 'sirsoft-verification')->count());

        $removeMethod = new ReflectionMethod(PluginManager::class, 'removePluginPermissions');
        $removeMethod->invoke($this->pluginManager, $plugin);

        $this->assertEquals(0, Permission::where('extension_identifier', 'sirsoft-verification')->count());
    }

    /**
     * 역할 할당이 카테고리 구조에서 올바르게 동작하는지 확인
     */
    public function test_assign_permissions_to_roles_with_categories(): void
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-verification');

        // 플러그인 역할 생성
        Role::create([
            'identifier' => 'sirsoft-verification.manager',
            'name' => ['ko' => '본인인증 관리자', 'en' => 'Verification Manager'],
            'description' => ['ko' => '본인인증 관리', 'en' => 'Verification management'],
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => 'sirsoft-verification',
            'is_active' => true,
        ]);

        // 권한 생성
        $createMethod = new ReflectionMethod(PluginManager::class, 'createPluginPermissions');
        $createMethod->invoke($this->pluginManager, $plugin);

        // 역할 할당
        $assignMethod = new ReflectionMethod(PluginManager::class, 'assignPermissionsToRoles');
        $assignMethod->invoke($this->pluginManager, $plugin);

        // admin 역할에 4개 리프 권한 할당 확인
        $adminRole = Role::where('identifier', 'admin')->first();
        $assignedPerms = $adminRole->permissions()->where('extension_identifier', 'sirsoft-verification')->get();
        $this->assertCount(4, $assignedPerms, 'admin 역할에 4개 리프 권한이 할당되어야 합니다');

        // 그룹/카테고리 노드는 역할에 할당되지 않음
        $assignedIdentifiers = $assignedPerms->pluck('identifier')->toArray();
        $this->assertNotContains('sirsoft-verification', $assignedIdentifiers);
        $this->assertNotContains('sirsoft-verification.settings', $assignedIdentifiers);
        $this->assertNotContains('sirsoft-verification.user', $assignedIdentifiers);
    }
}
