<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

/**
 * ExtensionMenuSyncHelper 단위 테스트
 *
 * 확장 메뉴 동기화 헬퍼의 핵심 로직을 검증합니다.
 * user_overrides 기반 필드 보호 동작을 검증합니다:
 * - 필드별 독립 보호 (name, icon, order, url 각각)
 * - parent_id, is_active는 항상 갱신
 * - stale 메뉴 정리 (자식 포함, UpgradeStep 전용 — 자동 호출 폐기 #135)
 * - 재귀 메뉴 동기화
 */
class ExtensionMenuSyncHelperTest extends TestCase
{
    private MenuRepositoryInterface $menuRepository;

    private RoleRepositoryInterface $roleRepository;

    private ExtensionMenuSyncHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menuRepository = Mockery::mock(MenuRepositoryInterface::class);
        $this->roleRepository = Mockery::mock(RoleRepositoryInterface::class);
        $this->helper = new ExtensionMenuSyncHelper($this->menuRepository, $this->roleRepository);
    }

    /**
     * Eloquent 모델 Mock 생성 헬퍼
     *
     * makePartial()로 Eloquent의 setAttribute가 정상 동작하도록 합니다.
     *
     * @param  string  $modelClass  모델 클래스명
     * @param  array  $attributes  설정할 속성
     * @return \Mockery\MockInterface
     */
    private function createModelMock(string $modelClass, array $attributes = []): \Mockery\MockInterface
    {
        $mock = Mockery::mock($modelClass)->makePartial();
        foreach ($attributes as $key => $value) {
            $mock->{$key} = $value;
        }

        return $mock;
    }

    // ========== syncMenu 테스트 ==========

    /**
     * 신규 메뉴 생성 시 user_overrides 없이 생성되는지 테스트
     */
    public function test_sync_menu_creates_new_menu_without_user_overrides(): void
    {
        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->with('board-management', ExtensionOwnerType::Module, 'sirsoft-board')
            ->once()
            ->andReturn(null);

        $name = ['ko' => '게시판 관리', 'en' => 'Board Management'];

        $newMenu = $this->createModelMock(Menu::class, ['id' => 1]);

        $this->menuRepository
            ->shouldReceive('updateOrCreate')
            ->once()
            ->with(
                Mockery::on(fn ($conditions) => $conditions['slug'] === 'board-management'
                    && $conditions['extension_type'] === ExtensionOwnerType::Module
                    && $conditions['extension_identifier'] === 'sirsoft-board'),
                Mockery::on(fn ($values) => $values['name'] === $name
                    && $values['icon'] === 'fas fa-list'
                    && $values['order'] === 10
                    && $values['url'] === '/admin/boards'
                    && $values['is_active'] === true
                    && ! array_key_exists('user_overrides', $values))
            )
            ->andReturn($newMenu);

        $result = $this->helper->syncMenu(
            slug: 'board-management',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            newAttributes: [
                'name' => $name,
                'icon' => 'fas fa-list',
                'order' => 10,
                'url' => '/admin/boards',
            ],
        );

        $this->assertSame($newMenu, $result);
    }

    /**
     * user_overrides에 "name"이 있으면 name 갱신을 건너뛰는지 테스트
     */
    public function test_sync_menu_skips_user_modified_name(): void
    {
        $existingMenu = $this->createModelMock(Menu::class, [
            'id' => 1,
            'name' => ['ko' => '우리 게시판', 'en' => 'Our Board'],
            'icon' => 'fas fa-list',
            'order' => 10,
            'url' => '/admin/boards',
            'user_overrides' => ['name'],  // 유저가 name을 수정한 적 있음
        ]);

        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->once()
            ->andReturn($existingMenu);

        $this->menuRepository
            ->shouldReceive('update')
            ->once()
            ->with(
                $existingMenu,
                Mockery::on(function ($data) {
                    // name은 user_overrides에 있으므로 포함되지 않아야 함
                    return ! array_key_exists('name', $data)
                        && $data['icon'] === 'fas fa-clipboard'
                        && $data['order'] === 20
                        && $data['url'] === '/admin/boards/v2'
                        && $data['parent_id'] === null
                        && $data['is_active'] === true;
                })
            )
            ->andReturn(true);

        $freshMenu = $this->createModelMock(Menu::class, ['id' => 1]);
        $existingMenu->shouldReceive('fresh')->once()->andReturn($freshMenu);

        $this->helper->syncMenu(
            slug: 'board-management',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            newAttributes: [
                'name' => ['ko' => 'Board 관리', 'en' => 'Board Admin'],
                'icon' => 'fas fa-clipboard',
                'order' => 20,
                'url' => '/admin/boards/v2',
            ],
        );
    }

    /**
     * user_overrides에 "icon"이 있으면 icon 갱신을 건너뛰는지 테스트
     */
    public function test_sync_menu_skips_user_modified_icon(): void
    {
        $existingMenu = $this->createModelMock(Menu::class, [
            'id' => 1,
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'icon' => 'fas fa-star',
            'order' => 10,
            'url' => '/admin/boards',
            'user_overrides' => ['icon'],
        ]);

        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->once()
            ->andReturn($existingMenu);

        $newName = ['ko' => 'Board 관리', 'en' => 'Board Admin'];

        $this->menuRepository
            ->shouldReceive('update')
            ->once()
            ->with(
                $existingMenu,
                Mockery::on(function ($data) use ($newName) {
                    return $data['name'] === $newName
                        && ! array_key_exists('icon', $data)
                        && $data['order'] === 20
                        && $data['url'] === '/admin/boards/v2';
                })
            )
            ->andReturn(true);

        $freshMenu = $this->createModelMock(Menu::class, ['id' => 1]);
        $existingMenu->shouldReceive('fresh')->once()->andReturn($freshMenu);

        $this->helper->syncMenu(
            slug: 'board-management',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            newAttributes: [
                'name' => $newName,
                'icon' => 'fas fa-clipboard',
                'order' => 20,
                'url' => '/admin/boards/v2',
            ],
        );
    }

    /**
     * user_overrides에 "order"가 있으면 order 갱신을 건너뛰는지 테스트
     */
    public function test_sync_menu_skips_user_modified_order(): void
    {
        $existingMenu = $this->createModelMock(Menu::class, [
            'id' => 1,
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'icon' => 'fas fa-list',
            'order' => 99,
            'url' => '/admin/boards',
            'user_overrides' => ['order'],
        ]);

        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->once()
            ->andReturn($existingMenu);

        $newName = ['ko' => 'Board 관리', 'en' => 'Board Admin'];

        $this->menuRepository
            ->shouldReceive('update')
            ->once()
            ->with(
                $existingMenu,
                Mockery::on(function ($data) use ($newName) {
                    return $data['name'] === $newName
                        && $data['icon'] === 'fas fa-clipboard'
                        && ! array_key_exists('order', $data)
                        && $data['url'] === '/admin/boards/v2';
                })
            )
            ->andReturn(true);

        $freshMenu = $this->createModelMock(Menu::class, ['id' => 1]);
        $existingMenu->shouldReceive('fresh')->once()->andReturn($freshMenu);

        $this->helper->syncMenu(
            slug: 'board-management',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            newAttributes: [
                'name' => $newName,
                'icon' => 'fas fa-clipboard',
                'order' => 20,
                'url' => '/admin/boards/v2',
            ],
        );
    }

    /**
     * user_overrides에 "url"이 있으면 url 갱신을 건너뛰는지 테스트
     */
    public function test_sync_menu_skips_user_modified_url(): void
    {
        $existingMenu = $this->createModelMock(Menu::class, [
            'id' => 1,
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'icon' => 'fas fa-list',
            'order' => 10,
            'url' => '/admin/custom-boards',
            'user_overrides' => ['url'],
        ]);

        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->once()
            ->andReturn($existingMenu);

        $newName = ['ko' => 'Board 관리', 'en' => 'Board Admin'];

        $this->menuRepository
            ->shouldReceive('update')
            ->once()
            ->with(
                $existingMenu,
                Mockery::on(function ($data) use ($newName) {
                    return $data['name'] === $newName
                        && $data['icon'] === 'fas fa-clipboard'
                        && $data['order'] === 20
                        && ! array_key_exists('url', $data);
                })
            )
            ->andReturn(true);

        $freshMenu = $this->createModelMock(Menu::class, ['id' => 1]);
        $existingMenu->shouldReceive('fresh')->once()->andReturn($freshMenu);

        $this->helper->syncMenu(
            slug: 'board-management',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            newAttributes: [
                'name' => $newName,
                'icon' => 'fas fa-clipboard',
                'order' => 20,
                'url' => '/admin/boards/v2',
            ],
        );
    }

    /**
     * user_overrides에 없는 필드만 갱신되는지 테스트
     */
    public function test_sync_menu_updates_unmodified_fields(): void
    {
        $newName = ['ko' => 'Board 관리', 'en' => 'Board Admin'];

        $existingMenu = $this->createModelMock(Menu::class, [
            'id' => 1,
            'name' => ['ko' => '게시판 관리', 'en' => 'Board Management'],
            'icon' => 'fas fa-list',
            'order' => 10,
            'url' => '/admin/boards',
            'user_overrides' => [],  // 아무것도 수정 안 함
        ]);

        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->once()
            ->andReturn($existingMenu);

        $this->menuRepository
            ->shouldReceive('update')
            ->once()
            ->with(
                $existingMenu,
                Mockery::on(fn ($data) => $data['name'] === $newName
                    && $data['icon'] === 'fas fa-clipboard'
                    && $data['order'] === 20
                    && $data['url'] === '/admin/boards/v2'
                    && $data['parent_id'] === null
                    && $data['is_active'] === true)
            )
            ->andReturn(true);

        $freshMenu = $this->createModelMock(Menu::class, ['id' => 1]);
        $existingMenu->shouldReceive('fresh')->once()->andReturn($freshMenu);

        $this->helper->syncMenu(
            slug: 'board-management',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            newAttributes: [
                'name' => $newName,
                'icon' => 'fas fa-clipboard',
                'order' => 20,
                'url' => '/admin/boards/v2',
            ],
        );
    }

    /**
     * parent_id와 is_active는 항상 확장 정의값으로 업데이트되는지 테스트
     */
    public function test_sync_menu_always_updates_parent_and_active(): void
    {
        $name = ['ko' => '게시판 관리', 'en' => 'Board Management'];

        $existingMenu = $this->createModelMock(Menu::class, [
            'id' => 1,
            'name' => ['ko' => '커스텀', 'en' => 'Custom'],
            'icon' => 'fas fa-star',
            'order' => 99,
            'url' => '/admin/custom',
            'user_overrides' => ['name', 'icon', 'order', 'url'],  // 모든 필드 수정됨
        ]);

        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->once()
            ->andReturn($existingMenu);

        $this->menuRepository
            ->shouldReceive('update')
            ->once()
            ->with(
                $existingMenu,
                Mockery::on(function ($data) {
                    // 보호된 필드는 포함되지 않아야 함
                    $noName = ! array_key_exists('name', $data);
                    $noIcon = ! array_key_exists('icon', $data);
                    $noOrder = ! array_key_exists('order', $data);
                    $noUrl = ! array_key_exists('url', $data);
                    // parent_id와 is_active는 항상 포함
                    $hasParent = $data['parent_id'] === 5;
                    $hasActive = $data['is_active'] === true;

                    return $noName && $noIcon && $noOrder && $noUrl
                        && $hasParent && $hasActive;
                })
            )
            ->andReturn(true);

        $freshMenu = $this->createModelMock(Menu::class, ['id' => 1]);
        $existingMenu->shouldReceive('fresh')->once()->andReturn($freshMenu);

        $this->helper->syncMenu(
            slug: 'board-management',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            newAttributes: [
                'name' => $name,
                'icon' => 'fas fa-list',
                'order' => 10,
                'url' => '/admin/boards',
            ],
            parentId: 5,
        );
    }

    // ========== syncMenuRecursive 테스트 ==========

    /**
     * 다국어 문자열 name을 배열로 역호환 변환하는지 테스트
     */
    public function test_sync_menu_recursive_converts_string_name_to_array(): void
    {
        $newMenu = $this->createModelMock(Menu::class, ['id' => 1]);

        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->once()
            ->andReturn(null);

        $this->menuRepository
            ->shouldReceive('updateOrCreate')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(function ($values) {
                    // 문자열 name이 다국어 배열로 변환되었는지 확인
                    return is_array($values['name'])
                        && $values['name']['ko'] === '게시판 관리'
                        && $values['name']['en'] === '게시판 관리';
                })
            )
            ->andReturn($newMenu);

        $result = $this->helper->syncMenuRecursive(
            menuData: [
                'slug' => 'board-management',
                'name' => '게시판 관리', // 문자열 name
                'icon' => 'fas fa-list',
                'order' => 10,
            ],
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
        );

        $this->assertSame($newMenu, $result);
    }

    /**
     * children 재귀 처리 테스트
     */
    public function test_sync_menu_recursive_handles_children(): void
    {
        $parentMenu = $this->createModelMock(Menu::class, ['id' => 10]);
        $childMenu = $this->createModelMock(Menu::class, ['id' => 11]);

        // 부모 메뉴 생성
        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->with('board-management', ExtensionOwnerType::Module, 'sirsoft-board')
            ->once()
            ->andReturn(null);

        $this->menuRepository
            ->shouldReceive('updateOrCreate')
            ->once()
            ->with(
                Mockery::on(fn ($c) => $c['slug'] === 'board-management'),
                Mockery::any()
            )
            ->andReturn($parentMenu);

        // 자식 메뉴 생성 (parent_id = 10)
        $this->menuRepository
            ->shouldReceive('findBySlugAndExtension')
            ->with('board-list', ExtensionOwnerType::Module, 'sirsoft-board')
            ->once()
            ->andReturn(null);

        $this->menuRepository
            ->shouldReceive('updateOrCreate')
            ->once()
            ->with(
                Mockery::on(fn ($c) => $c['slug'] === 'board-list'),
                Mockery::on(fn ($v) => $v['parent_id'] === 10)
            )
            ->andReturn($childMenu);

        $result = $this->helper->syncMenuRecursive(
            menuData: [
                'slug' => 'board-management',
                'name' => ['ko' => '게시판', 'en' => 'Board'],
                'icon' => 'fas fa-list',
                'order' => 10,
                'children' => [
                    [
                        'slug' => 'board-list',
                        'name' => ['ko' => '게시판 목록', 'en' => 'Board List'],
                        'url' => '/admin/boards',
                        'order' => 1,
                    ],
                ],
            ],
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
        );

        $this->assertEquals(10, $result->id);
    }

    // ========== cleanupStaleMenus 테스트 ==========

    /**
     * stale 메뉴가 삭제되는지 테스트 (자식 포함)
     */
    public function test_cleanup_stale_menus_deletes_stale_menus_with_children(): void
    {
        // stale 자식 메뉴
        $staleChildRoles = Mockery::mock(BelongsToMany::class);
        $staleChildRoles->shouldReceive('detach')->once();

        $staleChild = $this->createModelMock(Menu::class, [
            'id' => 11,
            'slug' => 'stale-child',
        ]);
        $staleChild->shouldReceive('roles')->once()->andReturn($staleChildRoles);

        // stale 부모 메뉴
        $staleMenuRoles = Mockery::mock(BelongsToMany::class);
        $staleMenuRoles->shouldReceive('detach')->once();

        $staleMenuChildren = new Collection([$staleChild]);

        $staleMenu = $this->createModelMock(Menu::class, [
            'id' => 10,
            'slug' => 'stale-menu',
        ]);
        $staleMenu->shouldReceive('roles')->once()->andReturn($staleMenuRoles);
        $staleMenu->children = $staleMenuChildren;

        // 현재 유효한 메뉴
        $activeMenu = $this->createModelMock(Menu::class, [
            'id' => 1,
            'slug' => 'active-menu',
        ]);

        $this->menuRepository
            ->shouldReceive('getMenusByExtension')
            ->with(ExtensionOwnerType::Module, 'sirsoft-board')
            ->once()
            ->andReturn(new Collection([$activeMenu, $staleMenu]));

        $this->menuRepository
            ->shouldReceive('delete')
            ->with($staleChild)
            ->once()
            ->andReturn(true);

        $this->menuRepository
            ->shouldReceive('delete')
            ->with($staleMenu)
            ->once()
            ->andReturn(true);

        $deleted = $this->helper->cleanupStaleMenus(
            ExtensionOwnerType::Module,
            'sirsoft-board',
            ['active-menu'],
        );

        // stale 부모 1 + stale 자식 1 = 2
        $this->assertEquals(2, $deleted);
    }

    /**
     * 현재 유효한 메뉴는 보존되는지 테스트
     */
    public function test_cleanup_stale_menus_preserves_current_menus(): void
    {
        $menu1 = $this->createModelMock(Menu::class, ['id' => 1, 'slug' => 'menu-a']);
        $menu2 = $this->createModelMock(Menu::class, ['id' => 2, 'slug' => 'menu-b']);

        $this->menuRepository
            ->shouldReceive('getMenusByExtension')
            ->once()
            ->andReturn(new Collection([$menu1, $menu2]));

        // delete는 호출되지 않아야 함
        $this->menuRepository->shouldNotReceive('delete');

        $deleted = $this->helper->cleanupStaleMenus(
            ExtensionOwnerType::Module,
            'sirsoft-board',
            ['menu-a', 'menu-b'],
        );

        $this->assertEquals(0, $deleted);
    }

    // ========== collectSlugsRecursive 테스트 ==========

    /**
     * 재귀 slug 수집 테스트
     */
    public function test_collect_slugs_recursive(): void
    {
        $menuDataArray = [
            [
                'slug' => 'board-management',
                'name' => ['ko' => '게시판', 'en' => 'Board'],
                'children' => [
                    [
                        'slug' => 'board-list',
                        'name' => ['ko' => '목록', 'en' => 'List'],
                    ],
                    [
                        'slug' => 'board-settings',
                        'name' => ['ko' => '설정', 'en' => 'Settings'],
                    ],
                ],
            ],
            [
                'slug' => 'product-management',
                'name' => ['ko' => '상품', 'en' => 'Product'],
            ],
        ];

        $slugs = $this->helper->collectSlugsRecursive($menuDataArray);

        $this->assertCount(4, $slugs);
        $this->assertContains('board-management', $slugs);
        $this->assertContains('board-list', $slugs);
        $this->assertContains('board-settings', $slugs);
        $this->assertContains('product-management', $slugs);
    }

    /**
     * slug 없는 경우 name에서 slug 추출 테스트
     */
    public function test_collect_slugs_recursive_falls_back_to_name(): void
    {
        $menuDataArray = [
            [
                'name' => ['ko' => '게시판', 'en' => 'Board'],
                // slug 없음 → name 배열의 첫 번째 값 사용
            ],
        ];

        $slugs = $this->helper->collectSlugsRecursive($menuDataArray);

        $this->assertCount(1, $slugs);
        $this->assertEquals('게시판', $slugs[0]);
    }

    /**
     * 문자열 name에서 slug 추출 (역호환성)
     */
    public function test_collect_slugs_recursive_with_string_name(): void
    {
        $menuDataArray = [
            [
                'name' => 'Board Management',
                // slug 없음 → 문자열 name 기반 배열 변환 후 첫 번째 값
            ],
        ];

        $slugs = $this->helper->collectSlugsRecursive($menuDataArray);

        $this->assertCount(1, $slugs);
        $this->assertEquals('Board Management', $slugs[0]);
    }
}
