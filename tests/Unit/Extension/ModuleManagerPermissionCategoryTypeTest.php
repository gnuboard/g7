<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ModuleManager 카테고리 type 자동 결정 및 eval fresh-load 테스트
 *
 * 결함 수정 검증:
 * - 결함 2: 카테고리 노드 type이 하위 권한의 type을 따르는지
 * - 결함 1: eval fresh-load로 새 모듈 코드가 로드되는지
 */
class ModuleManagerPermissionCategoryTypeTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    private string $modulesPath;

    /** @var bool 활성 디렉토리가 테스트 전에 이미 존재했는지 */
    private bool $ecommerceExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesPath = base_path('modules');

        $activePath = $this->modulesPath.'/sirsoft-ecommerce';
        if (File::isDirectory($activePath) && ! File::exists($activePath.'/module.php')) {
            File::deleteDirectory($activePath);
        }

        $this->ecommerceExistedBefore = File::isDirectory($activePath);
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

        User::factory()->create(['email' => 'admin@test.com']);
    }

    protected function tearDown(): void
    {
        if (! $this->ecommerceExistedBefore) {
            $activePath = $this->modulesPath.'/sirsoft-ecommerce';
            if (File::isDirectory($activePath)) {
                File::deleteDirectory($activePath);
            }
        }

        parent::tearDown();
    }

    /**
     * protected 메서드를 호출하는 헬퍼
     */
    private function callProtectedMethod(string $methodName, array $args = []): mixed
    {
        $method = new ReflectionMethod($this->moduleManager, $methodName);

        return $method->invokeArgs($this->moduleManager, $args);
    }

    /**
     * 하위 권한이 모두 user type이면 카테고리도 user type이 되는지 테스트
     */
    public function test_category_type_follows_user_children(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        // user-products 카테고리 확인 (하위가 모두 user type)
        $userProductsCategory = Permission::where('identifier', 'sirsoft-ecommerce.user-products')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $this->assertNotNull($userProductsCategory, 'user-products 카테고리가 존재해야 합니다');
        $this->assertEquals(
            PermissionType::User,
            $userProductsCategory->type,
            'user-products 카테고리 type은 user여야 합니다'
        );

        // user-orders 카테고리 확인
        $userOrdersCategory = Permission::where('identifier', 'sirsoft-ecommerce.user-orders')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $this->assertNotNull($userOrdersCategory, 'user-orders 카테고리가 존재해야 합니다');
        $this->assertEquals(
            PermissionType::User,
            $userOrdersCategory->type,
            'user-orders 카테고리 type은 user여야 합니다'
        );
    }

    /**
     * 하위 권한이 모두 admin type이면 카테고리도 admin type인지 테스트
     */
    public function test_category_type_stays_admin_for_admin_children(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        // products 카테고리 확인 (admin 권한)
        $productsCategory = Permission::where('identifier', 'sirsoft-ecommerce.products')
            ->where('extension_type', ExtensionOwnerType::Module)
            ->first();

        $this->assertNotNull($productsCategory, 'products 카테고리가 존재해야 합니다');
        $this->assertEquals(
            PermissionType::Admin,
            $productsCategory->type,
            'products 카테고리 type은 admin이어야 합니다'
        );
    }

    /**
     * user type 카테고리의 리프 권한에 올바른 parent_id가 설정되는지 테스트
     */
    public function test_user_category_children_have_correct_parent(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        $userProductsCategory = Permission::where('identifier', 'sirsoft-ecommerce.user-products')->first();
        $this->assertNotNull($userProductsCategory);

        // user-products.read 리프 권한이 카테고리를 부모로 가지는지
        $readPermission = Permission::where('identifier', 'sirsoft-ecommerce.user-products.read')->first();
        $this->assertNotNull($readPermission, 'user-products.read 권한이 존재해야 합니다');
        $this->assertEquals(
            $userProductsCategory->id,
            $readPermission->parent_id,
            'user-products.read의 parent_id가 카테고리 노드를 가리켜야 합니다'
        );
    }

    /**
     * evalFreshModule이 ModuleInterface 인스턴스를 반환하는지 테스트
     */
    public function test_eval_fresh_module_returns_valid_instance(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        $moduleDir = $this->modulesPath.'/sirsoft-ecommerce';
        $moduleFile = $moduleDir.'/module.php';
        $namespace = $this->callProtectedMethod('convertDirectoryToNamespace', ['sirsoft-ecommerce']);
        $moduleClass = "Modules\\{$namespace}\\Module";

        // 클래스가 이미 로드된 상태에서 evalFreshModule 호출
        $this->assertTrue(class_exists($moduleClass, false), '모듈 클래스가 이미 로드되어 있어야 합니다');

        $freshModule = $this->callProtectedMethod('evalFreshModule', [$moduleFile, $moduleClass, $moduleDir]);

        $this->assertNotNull($freshModule, 'evalFreshModule이 null을 반환하면 안됩니다');
        $this->assertInstanceOf(ModuleInterface::class, $freshModule, 'ModuleInterface 인스턴스여야 합니다');
    }

    /**
     * evalFreshModule이 올바른 식별자를 반환하는지 테스트
     */
    public function test_eval_fresh_module_returns_correct_identifier(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        $moduleDir = $this->modulesPath.'/sirsoft-ecommerce';
        $moduleFile = $moduleDir.'/module.php';
        $namespace = $this->callProtectedMethod('convertDirectoryToNamespace', ['sirsoft-ecommerce']);
        $moduleClass = "Modules\\{$namespace}\\Module";

        $freshModule = $this->callProtectedMethod('evalFreshModule', [$moduleFile, $moduleClass, $moduleDir]);

        // modulePath가 설정되어 getIdentifier()가 정상 작동하는지
        $this->assertEquals(
            'sirsoft-ecommerce',
            $freshModule->getIdentifier(),
            'eval로 로드된 모듈의 식별자가 올바라야 합니다'
        );
    }

    /**
     * evalFreshModule이 getPermissions()를 정상 반환하는지 테스트
     */
    public function test_eval_fresh_module_returns_permissions(): void
    {
        $this->moduleManager->installModule('sirsoft-ecommerce');

        $moduleDir = $this->modulesPath.'/sirsoft-ecommerce';
        $moduleFile = $moduleDir.'/module.php';
        $namespace = $this->callProtectedMethod('convertDirectoryToNamespace', ['sirsoft-ecommerce']);
        $moduleClass = "Modules\\{$namespace}\\Module";

        $freshModule = $this->callProtectedMethod('evalFreshModule', [$moduleFile, $moduleClass, $moduleDir]);

        $permissions = $freshModule->getPermissions();
        $this->assertIsArray($permissions, 'getPermissions()가 배열을 반환해야 합니다');
        $this->assertNotEmpty($permissions, 'getPermissions()가 비어있으면 안됩니다');

        // user-products 카테고리가 포함되어 있는지
        $categories = $permissions['categories'] ?? [];
        $categoryIdentifiers = array_column($categories, 'identifier');
        $this->assertContains('user-products', $categoryIdentifiers, 'user-products 카테고리가 포함되어야 합니다');
    }
}
