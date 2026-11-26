<?php

namespace Tests\Feature\Extension;

use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Menu;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

/**
 * 확장이 선언한 역할의 운영자가 관리자 왼쪽 메뉴에서 그 확장 화면을 찾을 수 있다.
 *
 * 확장 메뉴는 설치·업데이트 때 관리자 역할과 설치한 사람의 역할에만 붙었다. 그래서 확장이 선언한 역할
 * (예: 이커머스 관리자, SSO 운영자)만 가진 운영자는 그 확장 권한을 다 갖고도 사이드바가 「접근 가능한 메뉴가
 * 없습니다」로 비었다. 이제 동기화가 그 역할에 확장 메뉴 중 **역할이 가진 권한으로 열 수 있는 메뉴**를 붙인다.
 *
 * - 메뉴의 필요 권한은 메뉴 선언의 `permission` 키다. 필요 권한이 없는 하위 메뉴 묶음(상위 메뉴)은 열 수 있는
 *   하위 메뉴가 하나라도 있을 때 붙는다. 필요 권한을 선언하지 않은 단독 메뉴는 판정할 근거가 없어 붙이지 않는다.
 * - 권한 범위(scope_type)가 있는 권한도 보유로 본다(자기 것만 보는 내보내기 관리 등).
 * - 운영자가 역할 관리·메뉴 관리에서 그 역할을 넣거나 뺀 메뉴(메뉴의 user_overrides 에 역할 식별자)는 건드리지 않는다.
 * - 새 설치·확장 업데이트·활성 확장 재동기화(코어 업그레이드 단계)가 모두 같은 경로라 기존 사이트도 채워진다.
 *
 * 시나리오 SSoT: tests/scenarios/extension-declared-role-menus.yaml
 */
#[Group('core')]
#[Group('extension')]
class ExtensionDeclaredRoleMenuGrantTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /** 이번 테스트의 가짜 확장 식별자 */
    private string $identifier;

    /**
     * 테스트 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->identifier = 'g7test-shop'.Str::lower(Str::random(6));
        $this->adminRole();
    }

    /**
     * 확장 역할만 가진 운영자의 사이드바에 그 역할이 열 수 있는 확장 메뉴가 나오고, 권한 밖 메뉴는 나오지 않는다.
     *
     * @scenario extension_type=module, entry=declarative_sync
     *
     * @effects declared_role_sees_openable_extension_menus, declared_role_does_not_see_menus_outside_its_permissions
     *
     * @return void 반환값 없음
     */
    public function test_module_declared_role_sidebar_shows_openable_menus_only(): void
    {
        $module = $this->makeModule();
        $this->registerModule();

        app(ModuleManager::class)->syncDeclarativeArtifacts($module);

        $this->assertSidebar($this->operator(), [
            $this->identifier => [$this->slug('products'), $this->slug('exports')],
        ]);
    }

    /**
     * 수정 전 사이트처럼 확장 역할에 메뉴가 하나도 없어도, 확장 업데이트·재동기화가 열 수 있는 메뉴를 채운다.
     *
     * @scenario extension_type=module, entry=resync_backfill
     *
     * @effects existing_site_backfilled_on_resync
     *
     * @return void 반환값 없음
     */
    public function test_resync_backfills_role_that_had_no_extension_menus(): void
    {
        $this->assertResyncBackfills('module');
    }

    /**
     * 플러그인도 확장 역할에 메뉴가 하나도 없는 기존 사이트에서 재동기화가 열 수 있는 메뉴를 채운다.
     *
     * @scenario extension_type=plugin, entry=resync_backfill
     *
     * @effects existing_site_backfilled_on_resync
     *
     * @return void 반환값 없음
     */
    public function test_plugin_resync_backfills_role_that_had_no_extension_menus(): void
    {
        $this->assertResyncBackfills('plugin');
    }

    /**
     * 운영자가 역할 관리에서 확장 역할에서 뺀 메뉴는 다음 업데이트·재동기화에서 다시 붙지 않는다.
     *
     * @scenario extension_type=module, entry=operator_removed_then_resync
     *
     * @effects operator_removed_menu_not_regranted
     *
     * @return void 반환값 없음
     */
    public function test_operator_removed_menu_is_not_regranted_on_resync(): void
    {
        $this->assertOperatorRemovedMenuNotRegranted('module');
    }

    /**
     * 플러그인 메뉴도 운영자가 확장 역할에서 뺀 메뉴는 재동기화에서 다시 붙지 않는다.
     *
     * @scenario extension_type=plugin, entry=operator_removed_then_resync
     *
     * @effects operator_removed_menu_not_regranted
     *
     * @return void 반환값 없음
     */
    public function test_plugin_operator_removed_menu_is_not_regranted_on_resync(): void
    {
        $this->assertOperatorRemovedMenuNotRegranted('plugin');
    }

    /**
     * 운영자가 확장 역할에서 상위 메뉴째 뺐으면 하위 메뉴도 다시 붙지 않아 사이드바가 빈 채로 남는다.
     *
     * @scenario extension_type=module, entry=operator_removed_parent_then_resync
     *
     * @effects operator_removed_menu_not_regranted
     *
     * @return void 반환값 없음
     */
    public function test_operator_removed_parent_keeps_whole_tree_off_on_resync(): void
    {
        $this->assertOperatorRemovedParentKeepsTreeOff('module');
    }

    /**
     * 플러그인 메뉴도 운영자가 상위 메뉴째 뺐으면 재동기화 뒤 하위 메뉴까지 빈 채로 남는다.
     *
     * @scenario extension_type=plugin, entry=operator_removed_parent_then_resync
     *
     * @effects operator_removed_menu_not_regranted
     *
     * @return void 반환값 없음
     */
    public function test_plugin_operator_removed_parent_keeps_whole_tree_off_on_resync(): void
    {
        $this->assertOperatorRemovedParentKeepsTreeOff('plugin');
    }

    /**
     * 플러그인도 같은 경로로 선언 역할에 열 수 있는 메뉴를 붙인다(SSO 운영자처럼 하위 메뉴 묶음 하나).
     *
     * @scenario extension_type=plugin, entry=declarative_sync
     *
     * @effects declared_role_sees_openable_extension_menus, declared_role_does_not_see_menus_outside_its_permissions
     *
     * @return void 반환값 없음
     */
    public function test_plugin_declared_role_sidebar_shows_openable_menus_only(): void
    {
        $plugin = $this->makePlugin();
        Plugin::query()->create([
            'identifier' => $this->identifier,
            'vendor' => 'g7test',
            'name' => ['ko' => '테스트 플러그인', 'en' => 'Test Plugin'],
            'version' => '1.0.0',
            'status' => 'active',
        ]);

        app(PluginManager::class)->syncDeclarativeArtifacts($plugin);

        $this->assertSidebar($this->operator(), [
            $this->identifier => [$this->slug('products'), $this->slug('exports')],
        ]);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * 확장 역할 메뉴를 비운 기존 사이트에서 재동기화가 열 수 있는 메뉴를 채우는지 확인합니다.
     *
     * @param  string  $type  확장 종류(module 또는 plugin)
     * @return void 반환값 없음
     */
    private function assertResyncBackfills(string $type): void
    {
        $sync = $this->prepareExtension($type);

        $sync();
        // 수정 전 동기화가 남긴 상태: 확장 역할의 role_menus 0행, 운영자 조정 기록 없음
        $role = $this->declaredRole();
        $role->menus()->detach();
        $this->assertSame([], $this->menuIdsOf($role));

        $sync();

        $this->assertSidebar($this->operator(), [
            $this->identifier => [$this->slug('products'), $this->slug('exports')],
        ]);
    }

    /**
     * 운영자가 역할 관리에서 뺀 하위 메뉴가 재동기화 뒤에도 다시 붙지 않는지 확인합니다.
     *
     * @param  string  $type  확장 종류(module 또는 plugin)
     * @return void 반환값 없음
     */
    private function assertOperatorRemovedMenuNotRegranted(string $type): void
    {
        $sync = $this->prepareExtension($type);
        $sync();

        $role = $this->declaredRole();
        $parent = $this->menuOf($this->identifier);
        $exports = $this->menuOf($this->slug('exports'));

        // 역할 관리 「접근 가능 메뉴」에서 상품 관리를 뺀다(상위·내보내기 관리는 남긴다)
        $this->actingAs($this->actor('super_admin'));
        app(MenuService::class)->syncRoleMenus($role, [$parent->id, $exports->id]);
        $this->assertContains($role->identifier, $this->menuOf($this->slug('products'))->user_overrides ?? []);
        auth()->forgetGuards();

        $sync();

        $this->assertSidebar($this->operator(), [
            $this->identifier => [$this->slug('exports')],
        ]);
    }

    /**
     * 운영자가 상위 메뉴째 뺀 확장 역할이 재동기화 뒤에도 메뉴 없이 남는지 확인합니다.
     *
     * @param  string  $type  확장 종류(module 또는 plugin)
     * @return void 반환값 없음
     */
    private function assertOperatorRemovedParentKeepsTreeOff(string $type): void
    {
        $sync = $this->prepareExtension($type);
        $sync();

        $role = $this->declaredRole();
        $this->actingAs($this->actor('super_admin'));
        app(MenuService::class)->syncRoleMenus($role, []);
        auth()->forgetGuards();

        $sync();

        $this->assertSame([], $this->menuIdsOf($role->fresh()));
        $this->assertSidebar($this->operator(), []);
    }

    /**
     * 가짜 확장을 만들고 활성 행을 등록한 뒤, 그 확장의 선언 산출물 동기화를 부르는 함수를 돌려줍니다.
     *
     * @param  string  $type  확장 종류(module 또는 plugin)
     * @return \Closure(): void 동기화를 한 번 실행하는 함수
     */
    private function prepareExtension(string $type): \Closure
    {
        if ($type === 'plugin') {
            $plugin = $this->makePlugin();
            $this->registerPlugin();

            return static fn () => app(PluginManager::class)->syncDeclarativeArtifacts($plugin);
        }

        $module = $this->makeModule();
        $this->registerModule();

        return static fn () => app(ModuleManager::class)->syncDeclarativeArtifacts($module);
    }

    /**
     * 활성 플러그인 행을 만듭니다(사이드바는 활성 확장의 메뉴만 보인다).
     *
     * @return void 반환값 없음
     */
    private function registerPlugin(): void
    {
        Plugin::query()->create([
            'identifier' => $this->identifier,
            'vendor' => 'g7test',
            'name' => ['ko' => '테스트 플러그인', 'en' => 'Test Plugin'],
            'version' => '1.0.0',
            'status' => 'active',
        ]);
    }

    /**
     * 확장 메뉴 슬러그를 만듭니다.
     *
     * @param  string  $suffix  하위 이름
     * @return string 슬러그
     */
    private function slug(string $suffix): string
    {
        return $this->identifier.'-'.$suffix;
    }

    /**
     * 확장이 선언한 역할 식별자.
     *
     * @return string 역할 식별자
     */
    private function roleIdentifier(): string
    {
        return $this->identifier.'.manager';
    }

    /**
     * 확장이 선언한 역할 모델.
     *
     * @return Role 역할
     */
    private function declaredRole(): Role
    {
        return Role::query()->where('identifier', $this->roleIdentifier())->firstOrFail();
    }

    /**
     * 확장 메뉴를 슬러그로 찾습니다.
     *
     * @param  string  $slug  슬러그
     * @return Menu 메뉴
     */
    private function menuOf(string $slug): Menu
    {
        return Menu::query()->where('slug', $slug)->where('extension_identifier', $this->identifier)->firstOrFail();
    }

    /**
     * 확장이 선언한 역할만 가진 운영자를 만듭니다.
     *
     * @return User 운영자
     */
    private function operator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->declaredRole()->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 활성 모듈 행을 만듭니다(사이드바는 활성 확장의 메뉴만 보인다).
     *
     * @return void 반환값 없음
     */
    private function registerModule(): void
    {
        Module::query()->create([
            'identifier' => $this->identifier,
            'vendor' => 'g7test',
            'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
            'version' => '1.0.0',
            'status' => 'active',
        ]);
    }

    /**
     * 운영자의 사이드바(`GET /api/admin/menus/active`)가 기대한 최상위·하위 메뉴와 정확히 같은지 확인합니다.
     *
     * @param  User  $operator  운영자
     * @param  array<string, array<int, string>>  $expected  [최상위 슬러그 => 하위 슬러그 목록]
     * @return void 반환값 없음
     */
    private function assertSidebar(User $operator, array $expected): void
    {
        $response = $this->actingAs($operator)->getJson('/api/admin/menus/active');
        $response->assertOk();

        $actual = [];
        foreach ($response->json('data') as $top) {
            $children = array_map(static fn (array $child) => $child['slug'], $top['children'] ?? []);
            sort($children);
            $actual[$top['slug']] = $children;
        }

        foreach ($expected as $slug => $children) {
            sort($children);
            $expected[$slug] = $children;
        }

        $this->assertSame($expected, $actual);
    }

    /**
     * 가짜 확장이 공통으로 선언하는 역할·권한·메뉴.
     *
     * - 역할 `{식별자}.manager`: 상품 조회, 내보내기 조회(자기 것만)
     * - 관리자만: 환경설정 조회
     * - 메뉴: 상위 묶음 아래 상품 관리·환경설정·내보내기 관리, 필요 권한을 선언하지 않은 단독 안내 메뉴
     *
     * @return array{roles: array, permissions: array, menus: array} 선언
     */
    private function declarations(): array
    {
        $role = $this->roleIdentifier();
        $perm = fn (string $action, array $roles) => [
            'action' => $action,
            'name' => ['ko' => $action, 'en' => $action],
            'description' => ['ko' => $action, 'en' => $action],
            'type' => 'admin',
            'roles' => $roles,
        ];
        $category = fn (string $identifier, array $permissions) => [
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'description' => ['ko' => $identifier, 'en' => $identifier],
            'permissions' => $permissions,
        ];
        $leaf = fn (string $suffix, ?string $permission, int $order) => array_filter([
            'name' => ['ko' => $suffix, 'en' => $suffix],
            'slug' => $this->slug($suffix),
            'url' => '/admin/'.$this->identifier.'/'.$suffix,
            'icon' => 'fas fa-circle',
            'order' => $order,
            'permission' => $permission,
        ], static fn ($value) => $value !== null);

        return [
            'roles' => [[
                'identifier' => $role,
                'name' => ['ko' => '테스트 운영자', 'en' => 'Test Operator'],
                'description' => ['ko' => '테스트', 'en' => 'Test'],
            ]],
            'permissions' => [
                'name' => ['ko' => '테스트', 'en' => 'Test'],
                'description' => ['ko' => '테스트', 'en' => 'Test'],
                'categories' => [
                    $category('products', [$perm('read', ['admin', $role])]),
                    $category('settings', [$perm('read', ['admin'])]),
                    $category('exports', [$perm('read', ['admin', ['role' => $role, 'scope_type' => 'self']])]),
                ],
            ],
            'menus' => [
                [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'slug' => $this->identifier,
                    'url' => null,
                    'icon' => 'fas fa-store',
                    'order' => 90,
                    'children' => [
                        $leaf('products', $this->identifier.'.products.read', 1),
                        $leaf('settings', $this->identifier.'.settings.read', 2),
                        $leaf('exports', $this->identifier.'.exports.read', 3),
                    ],
                ],
                $leaf('guide', null, 91),
            ],
        ];
    }

    /**
     * 선언 getter 가 공통 선언을 돌려주는 가짜 모듈을 만듭니다.
     *
     * @return AbstractModule 가짜 모듈
     */
    private function makeModule(): AbstractModule
    {
        $module = new class($this->declarations()) extends AbstractModule
        {
            /**
             * 선언을 보관합니다.
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
                return 'Test Module';
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
             * 역할 선언을 돌려줍니다.
             *
             * @return array 역할 선언
             */
            public function getRoles(): array
            {
                return $this->data['roles'];
            }

            /**
             * 권한 선언을 돌려줍니다.
             *
             * @return array 권한 선언
             */
            public function getPermissions(): array
            {
                return $this->data['permissions'];
            }

            /**
             * 관리자 메뉴 선언을 돌려줍니다.
             *
             * @return array 메뉴 선언
             */
            public function getAdminMenus(): array
            {
                return $this->data['menus'];
            }
        };

        $this->setIdentifier($module, AbstractModule::class);
        $this->markLoaded(ModuleManager::class, 'modules', $module);

        return $module;
    }

    /**
     * 선언 getter 가 공통 선언을 돌려주는 가짜 플러그인을 만듭니다.
     *
     * @return AbstractPlugin 가짜 플러그인
     */
    private function makePlugin(): AbstractPlugin
    {
        $plugin = new class($this->declarations()) extends AbstractPlugin
        {
            /**
             * 선언을 보관합니다.
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
                return 'Test Plugin';
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
             * 역할 선언을 돌려줍니다.
             *
             * @return array 역할 선언
             */
            public function getRoles(): array
            {
                return $this->data['roles'];
            }

            /**
             * 권한 선언을 돌려줍니다.
             *
             * @return array 권한 선언
             */
            public function getPermissions(): array
            {
                return $this->data['permissions'];
            }

            /**
             * 관리자 메뉴 선언을 돌려줍니다.
             *
             * @return array 메뉴 선언
             */
            public function getAdminMenus(): array
            {
                return $this->data['menus'];
            }
        };

        $this->setIdentifier($plugin, AbstractPlugin::class);
        $this->markLoaded(PluginManager::class, 'plugins', $plugin);

        return $plugin;
    }

    /**
     * 가짜 확장을 이 서버가 불러온 확장 목록에 넣습니다 — 사이드바·메뉴 관리 목록은 DB 활성이면서 이 서버가 불러온
     * 확장의 메뉴만 보인다. 매니저는 시험마다 새로 만들어지는 앱의 싱글턴이라 따로 되돌리지 않는다.
     *
     * @param  class-string  $managerClass  확장 매니저 클래스
     * @param  string  $property  로드된 확장을 담는 속성 이름
     * @param  object  $extension  가짜 확장 인스턴스
     * @return void 반환값 없음
     */
    private function markLoaded(string $managerClass, string $property, object $extension): void
    {
        $manager = app($managerClass);
        $prop = (new ReflectionClass($manager))->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($manager, [...$prop->getValue($manager), $this->identifier => $extension]);
    }

    /**
     * 추상 확장 클래스의 identifier 속성에 이번 테스트 식별자를 주입합니다.
     *
     * @param  object  $extension  대상 확장 인스턴스
     * @param  string  $abstractClass  identifier 속성을 선언한 추상 클래스
     * @return void 반환값 없음
     */
    private function setIdentifier(object $extension, string $abstractClass): void
    {
        $reflection = new ReflectionClass($abstractClass);
        if (! $reflection->hasProperty('identifier')) {
            return;
        }
        $prop = $reflection->getProperty('identifier');
        $prop->setAccessible(true);
        $prop->setValue($extension, $this->identifier);
    }
}
