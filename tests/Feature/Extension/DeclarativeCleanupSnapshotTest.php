<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\Helpers\ExtensionScheduleSyncHelper;
use App\Extension\Helpers\GenericEntitySyncHelper;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Models\ActivityLog;
use App\Models\Menu;
use App\Models\MenuPermission;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\PermissionHook;
use App\Models\Role;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 선언형 동기화 헬퍼가 새 판 선언에서 빠진 항목을 지울 때 — 자식 명시 삭제 + 운영자 데이터 기록 + 판정 함수.
 *
 * FK 를 끈 상태에서 정리해도 고아 행이 0 이어야 한다(DB CASCADE 비의존). 운영자 데이터(`user_overrides`·운영자가 만든 자식·
 * 역할 부여)가 있으면 `{종류}.sync_deleted` 가 남고, 없으면 남지 않는다. 운영자가 만든 자식 메뉴는 지우지 않고 최상위로 올린다.
 */
class DeclarativeCleanupSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    /** 픽스처 확장 식별자 */
    private const EXT = 'testsync-ext';

    /**
     * FK 를 다시 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    /**
     * FK 를 끈 채 작업을 실행합니다.
     *
     * @param  \Closure  $callback  작업
     * @return mixed 반환값
     */
    private function withoutForeignKeys(\Closure $callback): mixed
    {
        Schema::disableForeignKeyConstraints();
        try {
            return $callback();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * 마지막 기록을 찾습니다.
     *
     * @param  string  $action  액션
     * @return ActivityLog|null 기록
     */
    private function lastLog(string $action): ?ActivityLog
    {
        return ActivityLog::where('action', $action)->latest('id')->first();
    }

    /**
     * 메뉴를 만듭니다.
     *
     * @param  string  $slug  슬러그
     * @param  int|null  $parentId  부모
     * @param  string  $owner  소유 확장 식별자
     * @param  array<int, string>|null  $overrides  운영자가 고친 필드
     * @return Menu 메뉴
     */
    private function menu(string $slug, ?int $parentId = null, string $owner = self::EXT, ?array $overrides = null): Menu
    {
        return Menu::create([
            'name' => ['ko' => $slug, 'en' => $slug], 'slug' => $slug, 'url' => '/admin/'.$slug, 'parent_id' => $parentId, 'order' => 1, 'is_active' => true,
            'extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => $owner, 'user_overrides' => $overrides,
        ]);
    }

    /**
     * 역할을 만듭니다.
     *
     * @param  string  $identifier  식별자
     * @param  array<int, string>|null  $overrides  운영자가 고친 필드
     * @return Role 역할
     */
    private function role(string $identifier, ?array $overrides = null): Role
    {
        return Role::create([
            'identifier' => $identifier, 'name' => ['ko' => $identifier, 'en' => $identifier], 'description' => ['ko' => '', 'en' => ''],
            'is_active' => true, 'extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => self::EXT, 'user_overrides' => $overrides,
        ]);
    }

    /**
     * 메뉴 하위 트리: 손자까지 명시 삭제, 역할 배정·메뉴 권한 제거, 다른 출처 자식은 최상위로, 기록 1건.
     *
     * @scenario case=menu__operator_child
     *
     * @effects declarative_children_deleted_explicitly, operator_child_menu_lifted, sync_deletion_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
    public function test_menu_subtree_is_deleted_explicitly_and_foreign_child_lifted(): void
    {
        $root = $this->menu('testsync-root');
        $child = $this->menu('testsync-child', $root->id);
        $grandchild = $this->menu('testsync-grand', $child->id);
        $foreign = $this->menu('testsync-operator-child', $root->id, 'core-operator');
        $role = $this->role('testsync-role-menu');
        $root->roles()->attach($role->id);
        $grandchild->roles()->attach($role->id);
        MenuPermission::create(['menu_id' => $grandchild->id, 'role_id' => $role->id, 'permission_type' => 'read', 'is_allowed' => true]);

        $deleted = $this->withoutForeignKeys(fn () => app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Plugin, self::EXT, []));

        $this->assertSame(3, $deleted, '뿌리·자식·손자');
        $this->assertSame(0, Menu::whereIn('id', [$root->id, $child->id, $grandchild->id])->count());
        $this->assertSame(0, DB::table('role_menus')->whereIn('menu_id', [$root->id, $grandchild->id])->count());
        $this->assertSame(0, MenuPermission::where('menu_id', $grandchild->id)->count());
        $this->assertNull($foreign->fresh()->parent_id, '운영자가 만든 자식은 지우지 않고 최상위로 올린다');
        $log = $this->lastLog('menu.sync_deleted');
        $this->assertSame('testsync-root', $log->properties['key']);
        $this->assertSame(['testsync-operator-child'], $log->properties['snapshot']['lifted_children']);
    }

    /**
     * 운영자 데이터가 없는 메뉴는 지워도 기록하지 않고, 판정 함수는 같은 대상을 낸다.
     *
     * @scenario case=menu__none
     *
     * @effects plan_cleanup_matches_cleanup
     *
     * @return void 반환값 없음
     */
    public function test_menu_without_operator_data_is_not_logged_and_plan_matches(): void
    {
        $this->menu('testsync-plain');
        $before = ActivityLog::where('action', 'menu.sync_deleted')->count();

        $plan = app(ExtensionMenuSyncHelper::class)->planCleanup(ExtensionOwnerType::Plugin, self::EXT, []);
        $this->assertSame([['type' => 'menu', 'key' => 'testsync-plain', 'has_operator_overrides' => false]], $plan);
        $this->assertSame(1, Menu::where('slug', 'testsync-plain')->count(), '판정 함수는 쓰지 않는다');

        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Plugin, self::EXT, []);

        $this->assertSame($before, ActivityLog::where('action', 'menu.sync_deleted')->count());
        $this->assertSame(0, Menu::where('slug', 'testsync-plain')->count());
    }

    /**
     * 권한 하위 트리: 훅 매핑·역할 부여 명시 삭제, 역할에 부여된 권한은 기록.
     *
     * @scenario case=permission__role_grant
     *
     * @effects declarative_children_deleted_explicitly, sync_deletion_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
    public function test_permission_subtree_removes_hooks_and_grants(): void
    {
        $parent = Permission::create(['identifier' => 'testsync-ext.cat', 'name' => ['ko' => 'c'], 'extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => self::EXT, 'type' => 'admin']);
        $child = Permission::create(['identifier' => 'testsync-ext.cat.read', 'parent_id' => $parent->id, 'name' => ['ko' => 'r'], 'extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => self::EXT, 'type' => 'admin']);
        PermissionHook::create(['permission_id' => $child->id, 'hook_name' => 'testsync.hook']);
        $role = $this->role('testsync-role-perm');
        $child->roles()->attach($role->id);

        $this->withoutForeignKeys(fn () => app(ExtensionRoleSyncHelper::class)->cleanupStalePermissions(ExtensionOwnerType::Plugin, self::EXT, []));

        $this->assertSame(0, Permission::whereIn('id', [$parent->id, $child->id])->count());
        $this->assertSame(0, PermissionHook::where('permission_id', $child->id)->count());
        $this->assertSame(0, DB::table('role_permissions')->where('permission_id', $child->id)->count());
        $log = $this->lastLog('permission.sync_deleted');
        $this->assertSame([$role->id], $log->properties['snapshot']['role_grants']['testsync-ext.cat.read']);
    }

    /**
     * 역할: 권한·메뉴 연결과 메뉴 권한을 명시적으로 떼고 지운다. 운영자가 고친 역할은 기록.
     *
     * @scenario case=role__overrides
     *
     * @effects declarative_children_deleted_explicitly, sync_deletion_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
    public function test_role_cleanup_detaches_explicitly_and_logs_overrides(): void
    {
        $role = $this->role('testsync-role', ['name']);
        $menu = $this->menu('testsync-role-menu-host', null, 'core-operator');
        $role->menus()->attach($menu->id);
        MenuPermission::create(['menu_id' => $menu->id, 'role_id' => $role->id, 'permission_type' => 'read', 'is_allowed' => true]);

        $this->withoutForeignKeys(fn () => app(ExtensionRoleSyncHelper::class)->cleanupStaleRoles(ExtensionOwnerType::Plugin, self::EXT, []));

        $this->assertNull(Role::find($role->id));
        $this->assertSame(0, DB::table('role_menus')->where('role_id', $role->id)->count());
        $this->assertSame(0, MenuPermission::where('role_id', $role->id)->count());
        $this->assertSame('testsync-role', $this->lastLog('role.sync_deleted')->properties['key']);
    }

    /**
     * 알림 템플릿 정리·시드 엔티티·스케줄 — 운영자가 고친 행은 지우기 직전 기록.
     *
     * @scenario case=entity__overrides
     *
     * @effects sync_deletion_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
    public function test_template_entity_and_schedule_overrides_are_logged(): void
    {
        $def = NotificationDefinition::create(['type' => 'testsync-def', 'hook_prefix' => 'x', 'extension_type' => 'plugin', 'extension_identifier' => self::EXT, 'name' => ['ko' => 'd'], 'description' => ['ko' => 'd'], 'channels' => ['mail', 'sms'], 'hooks' => [], 'variables' => [], 'is_active' => true, 'is_default' => true]);
        NotificationTemplate::create(['definition_id' => $def->id, 'channel' => 'sms', 'subject' => ['ko' => 's'], 'body' => ['ko' => '운영자 문구'], 'is_active' => true, 'is_default' => true, 'user_overrides' => ['body']]);
        app(NotificationSyncHelper::class)->cleanupStaleTemplates($def->id, ['mail']);
        $this->assertSame('testsync-def:sms', $this->lastLog('notification_template.sync_deleted')->properties['key']);

        $menu = $this->menu('testsync-entity', null, self::EXT, ['name']);
        app(GenericEntitySyncHelper::class)->cleanupStale(Menu::class, ['extension_identifier' => self::EXT], 'slug', [], '메뉴');
        $this->assertNull(Menu::find($menu->id));
        $this->assertSame('testsync-entity', $this->lastLog('entity.sync_deleted')->properties['key']);

        $schedule = Schedule::create(['name' => 'testsync-schedule', 'type' => 'artisan', 'command' => 'inspire', 'expression' => '0 * * * *', 'is_active' => true, 'extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => self::EXT, 'user_overrides' => ['expression']]);
        app(ExtensionScheduleSyncHelper::class)->cleanupSchedulesForExtension(ExtensionOwnerType::Plugin, self::EXT);
        $this->assertNull(Schedule::find($schedule->id));
        $this->assertSame('testsync-schedule', $this->lastLog('schedule.sync_deleted')->properties['key']);
    }
}
