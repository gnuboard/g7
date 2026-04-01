<?php

namespace Tests\Unit\Listeners;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Listeners\MenuUserOverridesListener;
use App\Models\Menu;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * MenuUserOverridesListener 테스트
 *
 * 메뉴 업데이트/순서 변경/역할 동기화 시 user_overrides 필드 기록을 검증합니다.
 */
class MenuUserOverridesListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MenuUserOverridesListener $listener;

    private MockInterface $menuRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menuRepository = Mockery::mock(MenuRepositoryInterface::class);
        $this->listener = new MenuUserOverridesListener($this->menuRepository);
    }

    /**
     * getSubscribedHooks()가 올바른 훅 매핑을 반환하는지 검증
     */
    public function test_get_subscribed_hooks_returns_correct_mapping(): void
    {
        $hooks = MenuUserOverridesListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.menu.before_update', $hooks);
        $this->assertArrayHasKey('core.menu.after_update_order', $hooks);
        $this->assertArrayHasKey('core.menu.after_sync_roles', $hooks);
        $this->assertEquals('handleBeforeUpdate', $hooks['core.menu.before_update']['method']);
        $this->assertEquals('handleAfterUpdateOrder', $hooks['core.menu.after_update_order']['method']);
        $this->assertEquals('handleAfterSyncRoles', $hooks['core.menu.after_sync_roles']['method']);
    }

    /**
     * name/icon/order/url 변경 시 user_overrides에 해당 필드명 기록
     */
    public function test_field_changes_record_user_overrides(): void
    {
        $menu = new Menu();
        $menu->name = ['ko' => '대시보드', 'en' => 'Dashboard'];
        $menu->icon = 'fa-home';
        $menu->order = 1;
        $menu->url = '/admin/dashboard';
        $menu->forceFill(['user_overrides' => []]);

        $data = [
            'name' => ['ko' => '홈', 'en' => 'Home'],
            'icon' => 'fa-house',
            'order' => 5,
            'url' => '/admin/home',
        ];

        $this->menuRepository->shouldReceive('update')
            ->once()
            ->with($menu, Mockery::on(function ($arg) {
                $overrides = $arg['user_overrides'];

                return in_array('name', $overrides, true)
                    && in_array('icon', $overrides, true)
                    && in_array('order', $overrides, true)
                    && in_array('url', $overrides, true);
            }))
            ->andReturn(true);

        $this->listener->handleBeforeUpdate($menu, $data);
    }

    /**
     * 변경 없는 필드는 user_overrides에 기록하지 않음
     */
    public function test_unchanged_field_not_recorded(): void
    {
        $menu = new Menu();
        $menu->name = ['ko' => '대시보드', 'en' => 'Dashboard'];
        $menu->icon = 'fa-home';
        $menu->order = 1;
        $menu->url = '/admin/dashboard';
        $menu->forceFill(['user_overrides' => []]);

        // 동일한 값으로 업데이트 → 변경 아님
        $data = [
            'name' => ['ko' => '대시보드', 'en' => 'Dashboard'],
            'icon' => 'fa-home',
        ];

        $this->menuRepository->shouldNotReceive('update');

        $this->listener->handleBeforeUpdate($menu, $data);
    }

    /**
     * 부모 메뉴 순서 변경 시 user_overrides에 "order" 기록
     */
    public function test_order_update_records_user_override_for_parent_menus(): void
    {
        $menu = new Menu();
        $menu->forceFill(['id' => 1, 'user_overrides' => []]);

        $this->menuRepository->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($menu);

        $this->menuRepository->shouldReceive('update')
            ->once()
            ->with($menu, Mockery::on(function ($arg) {
                return in_array('order', $arg['user_overrides'], true);
            }))
            ->andReturn(true);

        $this->listener->handleAfterUpdateOrder([
            'parent_menus' => [['id' => 1, 'order' => 3]],
        ]);
    }

    /**
     * 자식 메뉴 순서 변경 시 user_overrides에 "order" 기록
     */
    public function test_order_update_records_user_override_for_child_menus(): void
    {
        $childMenu = new Menu();
        $childMenu->forceFill(['id' => 10, 'user_overrides' => []]);

        $this->menuRepository->shouldReceive('findById')
            ->with(10)
            ->once()
            ->andReturn($childMenu);

        $this->menuRepository->shouldReceive('update')
            ->once()
            ->with($childMenu, Mockery::on(function ($arg) {
                return in_array('order', $arg['user_overrides'], true);
            }))
            ->andReturn(true);

        $this->listener->handleAfterUpdateOrder([
            'child_menus' => [
                1 => [['id' => 10, 'order' => 2]],
            ],
        ]);
    }

    /**
     * 역할 추가/제거 시 변경된 개별 역할 식별자를 user_overrides에 기록
     */
    public function test_sync_roles_records_changed_identifiers(): void
    {
        $menu = new Menu();
        $menu->forceFill(['user_overrides' => []]);

        $previous = ['admin', 'manager', 'editor'];
        $current = ['admin', 'manager'];  // editor 제거

        $this->menuRepository->shouldReceive('update')
            ->once()
            ->with($menu, Mockery::on(function ($arg) {
                return in_array('editor', $arg['user_overrides'], true)
                    && ! in_array('admin', $arg['user_overrides'], true)
                    && ! in_array('manager', $arg['user_overrides'], true);
            }))
            ->andReturn(true);

        $this->listener->handleAfterSyncRoles($menu, $previous, $current);
    }

    /**
     * 역할 변경 없으면 user_overrides 미변경
     */
    public function test_sync_roles_no_change_no_record(): void
    {
        $menu = new Menu();
        $menu->forceFill(['user_overrides' => []]);

        $roles = ['admin', 'manager'];

        // 이전/이후 동일 → update 호출 안 됨
        $this->menuRepository->shouldNotReceive('update');

        $this->listener->handleAfterSyncRoles($menu, $roles, $roles);
    }

    /**
     * 이미 기록된 필드는 중복 추가하지 않음
     */
    public function test_duplicate_override_not_added(): void
    {
        // name, icon이 이미 user_overrides에 있는 경우
        $menu = new Menu();
        $menu->name = ['ko' => '대시보드', 'en' => 'Dashboard'];
        $menu->icon = 'fa-home';
        $menu->forceFill(['user_overrides' => ['name', 'icon']]);

        $data = [
            'name' => ['ko' => '홈', 'en' => 'Home'],
            'icon' => 'fa-house',
        ];

        // 모든 변경 필드가 이미 기록되어 있으므로 update 호출 안 됨
        $this->menuRepository->shouldNotReceive('update');

        $this->listener->handleBeforeUpdate($menu, $data);
    }

    /**
     * 이미 기록된 역할 식별자는 중복 추가하지 않음
     */
    public function test_duplicate_role_identifier_not_added(): void
    {
        $menu = new Menu();
        $menu->forceFill(['user_overrides' => ['editor']]);

        $previous = ['admin', 'manager', 'editor'];
        $current = ['admin', 'manager'];  // editor 제거

        // editor가 이미 있으므로 update 호출 안 됨
        $this->menuRepository->shouldNotReceive('update');

        $this->listener->handleAfterSyncRoles($menu, $previous, $current);
    }

    /**
     * orderData에 id가 없는 항목은 건너뜀
     */
    public function test_order_update_skips_items_without_id(): void
    {
        $this->menuRepository->shouldNotReceive('findById');
        $this->menuRepository->shouldNotReceive('update');

        $this->listener->handleAfterUpdateOrder([
            'parent_menus' => [['order' => 3]],
        ]);
    }

    /**
     * parent_menus/child_menus 키가 없는 경우 안전하게 처리
     */
    public function test_order_update_handles_empty_order_data(): void
    {
        $this->menuRepository->shouldNotReceive('findById');
        $this->menuRepository->shouldNotReceive('update');

        $this->listener->handleAfterUpdateOrder([]);
    }
}
