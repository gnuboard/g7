<?php

namespace Tests\Feature\Plugin;

use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 플러그인 권한 계층 구조 테스트
 *
 * PluginManager.createPluginPermissions()가 모듈과 동일한
 * 3레벨 계층(플러그인 → 카테고리 → 개별 권한)을 생성하는지 검증합니다.
 *
 * 검증 대상 플러그인: sirsoft-gdpr
 *  - 그룹 1 (sirsoft-gdpr)
 *  - 카테고리 1 (privacy)
 *  - 리프 2 (privacy.view, privacy.update)
 */
class PluginPermissionGroupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * GDPR 플러그인의 ServiceProvider/route/권한 정의를 테스트 앱에 로드.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * 검증 대상 GDPR 플러그인 인스턴스를 반환합니다.
     */
    private function gdprPlugin(): PluginInterface
    {
        $plugin = $this->pluginManager->getPlugin('sirsoft-gdpr');
        $this->assertNotNull($plugin, 'GDPR 플러그인이 로드되어야 합니다');

        return $plugin;
    }

    /**
     * PluginManager의 protected 메서드를 호출하는 헬퍼
     *
     * @param  string  $methodName  메서드명
     * @param  PluginInterface  $plugin  대상 플러그인
     */
    private function callProtectedMethod(string $methodName, PluginInterface $plugin): void
    {
        $method = new ReflectionMethod(PluginManager::class, $methodName);
        $method->invoke($this->pluginManager, $plugin);
    }

    /**
     * 1레벨: 플러그인 그룹 노드가 생성되는지 확인
     */
    public function test_creates_plugin_group_node(): void
    {
        $this->callProtectedMethod('createPluginPermissions', $this->gdprPlugin());

        $groupNode = Permission::where('identifier', 'sirsoft-gdpr')
            ->whereNull('parent_id')
            ->first();

        $this->assertNotNull($groupNode, '플러그인 그룹 노드가 생성되어야 합니다');
        $this->assertEquals(ExtensionOwnerType::Plugin, $groupNode->extension_type);
        $this->assertEquals('sirsoft-gdpr', $groupNode->extension_identifier);
        $this->assertEquals('GDPR (일반 데이터 보호 규정)', $groupNode->getLocalizedName());
    }

    /**
     * 2레벨: 카테고리 노드가 플러그인 그룹 노드의 자식으로 생성되는지 확인
     */
    public function test_creates_category_nodes_under_plugin(): void
    {
        $this->callProtectedMethod('createPluginPermissions', $this->gdprPlugin());

        $groupNode = Permission::where('identifier', 'sirsoft-gdpr')->first();

        // 카테고리 노드 확인
        $categories = Permission::where('parent_id', $groupNode->id)->orderBy('order')->get();
        $this->assertCount(1, $categories, '1개 카테고리가 있어야 합니다');

        $this->assertEquals('sirsoft-gdpr.privacy', $categories[0]->identifier);
        $this->assertEquals('개인정보 보호', $categories[0]->getLocalizedName());
    }

    /**
     * 3레벨: 개별 권한이 카테고리 노드의 자식으로 등록되는지 확인
     */
    public function test_permissions_are_children_of_category_nodes(): void
    {
        $this->callProtectedMethod('createPluginPermissions', $this->gdprPlugin());

        // privacy 카테고리의 자식 권한
        $privacyCategory = Permission::where('identifier', 'sirsoft-gdpr.privacy')->first();
        $privacyPerms = Permission::where('parent_id', $privacyCategory->id)->orderBy('order')->get();
        $this->assertCount(2, $privacyPerms);
        $this->assertEquals('sirsoft-gdpr.privacy.view', $privacyPerms[0]->identifier);
        $this->assertEquals('sirsoft-gdpr.privacy.update', $privacyPerms[1]->identifier);
    }

    /**
     * 리프 노드(개별 권한)만 할당 가능한지 확인
     */
    public function test_only_leaf_permissions_are_assignable(): void
    {
        $this->callProtectedMethod('createPluginPermissions', $this->gdprPlugin());

        // 그룹 노드: 자식 있음 → 할당 불가
        $groupNode = Permission::where('identifier', 'sirsoft-gdpr')->first();
        $this->assertTrue($groupNode->children()->exists());

        // 카테고리 노드: 자식 있음 → 할당 불가
        $privacyCategory = Permission::where('identifier', 'sirsoft-gdpr.privacy')->first();
        $this->assertTrue($privacyCategory->children()->exists());

        // 리프 권한: 자식 없음 → 할당 가능
        $leafPerm = Permission::where('identifier', 'sirsoft-gdpr.privacy.view')->first();
        $this->assertFalse($leafPerm->children()->exists());
    }

    /**
     * 전체 권한 수 확인 (그룹 1 + 카테고리 1 + 개별 2 = 4)
     */
    public function test_total_permission_count(): void
    {
        $this->callProtectedMethod('createPluginPermissions', $this->gdprPlugin());

        $total = Permission::where('extension_identifier', 'sirsoft-gdpr')->count();
        $this->assertEquals(4, $total, '그룹 1개 + 카테고리 1개 + 개별 권한 2개 = 4개');
    }

    /**
     * categories가 없는 플러그인은 권한 노드를 생성하지 않는지 확인
     */
    public function test_no_permissions_for_plugin_without_categories(): void
    {
        $mockPlugin = $this->createMock(PluginInterface::class);
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
        $plugin = $this->gdprPlugin();

        // 1차 실행
        $this->callProtectedMethod('createPluginPermissions', $plugin);
        $firstCount = Permission::where('extension_identifier', 'sirsoft-gdpr')->count();
        $firstGroupId = Permission::where('identifier', 'sirsoft-gdpr')->first()->id;

        // 2차 실행 (업데이트 시뮬레이션)
        $this->callProtectedMethod('createPluginPermissions', $plugin);
        $secondCount = Permission::where('extension_identifier', 'sirsoft-gdpr')->count();
        $secondGroupId = Permission::where('identifier', 'sirsoft-gdpr')->first()->id;

        $this->assertEquals($firstCount, $secondCount, '중복 생성 없이 동일 수');
        $this->assertEquals($firstGroupId, $secondGroupId, '그룹 노드 ID 유지');
        $this->assertEquals(4, $secondCount);
    }

    /**
     * 플러그인 삭제 시 모든 권한(그룹+카테고리+개별)이 삭제되는지 확인
     */
    public function test_remove_deletes_all_permission_levels(): void
    {
        $plugin = $this->gdprPlugin();

        $this->callProtectedMethod('createPluginPermissions', $plugin);
        $this->assertEquals(4, Permission::where('extension_identifier', 'sirsoft-gdpr')->count());

        $this->callProtectedMethod('removePluginPermissions', $plugin);
        $this->assertEquals(0, Permission::where('extension_identifier', 'sirsoft-gdpr')->count());
    }

    /**
     * 역할 할당이 카테고리 구조에서 올바르게 동작하는지 확인
     */
    public function test_assign_permissions_to_roles_with_categories(): void
    {
        $plugin = $this->gdprPlugin();

        // 플러그인 역할 생성
        Role::create([
            'identifier' => 'sirsoft-gdpr.privacy',
            'name' => ['ko' => '개인정보 운영자', 'en' => 'Privacy Operator'],
            'description' => ['ko' => '개인정보 운영', 'en' => 'Privacy operation'],
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => 'sirsoft-gdpr',
            'is_active' => true,
        ]);

        // 권한 생성
        $this->callProtectedMethod('createPluginPermissions', $plugin);

        // 역할 할당
        $this->callProtectedMethod('assignPermissionsToRoles', $plugin);

        // admin 역할에 2개 리프 권한 할당 확인
        $adminRole = Role::where('identifier', 'admin')->first();
        $assignedPerms = $adminRole->permissions()->where('extension_identifier', 'sirsoft-gdpr')->get();
        $this->assertCount(2, $assignedPerms, 'admin 역할에 2개 리프 권한이 할당되어야 합니다');

        // 그룹/카테고리 노드는 역할에 할당되지 않음
        $assignedIdentifiers = $assignedPerms->pluck('identifier')->toArray();
        $this->assertNotContains('sirsoft-gdpr', $assignedIdentifiers);
        $this->assertNotContains('sirsoft-gdpr.privacy', $assignedIdentifiers);
    }
}
