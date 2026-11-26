<?php

namespace Tests\Feature\Architecture;

use App\Enums\ExtensionOwnerType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\Menu;
use App\Models\MenuPermission;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Models\User;
use App\Notifications\Identity\IdentityNotificationType;
use App\Repositories\MenuRepository;
use App\Repositories\PermissionRepository;
use App\Services\IdentityLogService;
use App\Services\MenuService;
use App\Services\NotificationDefinitionService;
use App\Services\RoleService;
use App\Services\ScheduleService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 부모를 지우는 코어 서비스가 자식을 DB CASCADE 에 기대지 않고 명시적으로 지운다.
 *
 * 외래키 검사를 끈 상태(`Schema::disableForeignKeyConstraints()`)에서 삭제를 실행한다 — CASCADE 가 돌지 않으므로
 * 자식이 남으면 그 경로는 CASCADE 에 기대고 있다는 뜻이다. 마이그레이션의 cascade FK 는 안전망으로 남아 있다.
 *
 * @scenario-manifest tests/scenarios/cascade-explicit-deletion.yaml
 */
class NoCascadeDependentDeletionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 외래키 검사를 끕니다 — 같은 연결의 세션 설정이라 트랜잭션 롤백과 무관하게 tearDown 에서 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
    }

    /**
     * 외래키 검사를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    /**
     * 메뉴 하나와 그 역할 배정·메뉴 권한을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  메뉴 속성
     * @return array{0: Menu, 1: Role} [메뉴, 역할]
     */
    private function menuWithGrants(array $attributes = []): array
    {
        $menu = Menu::factory()->create($attributes);
        $role = Role::factory()->create();
        $menu->roles()->attach($role->id, ['permission_type' => 'read']);
        MenuPermission::query()->create(['menu_id' => $menu->id, 'role_id' => $role->id, 'permission_type' => 'read', 'is_allowed' => true]);

        return [$menu, $role];
    }

    /**
     * 메뉴 삭제가 역할 배정·메뉴 권한을 명시적으로 지운다.
     *
     * @scenario case=menus__service
     *
     * @effects children_deleted_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_menu_delete_removes_grants_explicitly(): void
    {
        [$menu] = $this->menuWithGrants();

        app(MenuService::class)->deleteMenu($menu);

        $this->assertSame(0, DB::table('role_menus')->where('menu_id', $menu->id)->count());
        $this->assertSame(0, MenuPermission::query()->where('menu_id', $menu->id)->count());
    }

    /**
     * 역할 삭제가 권한 부여·메뉴 배정·회원 배정·메뉴 권한을 명시적으로 지운다.
     *
     * @scenario case=roles__service
     *
     * @effects children_deleted_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_role_delete_removes_relations_explicitly(): void
    {
        [, $role] = $this->menuWithGrants();
        $role->users()->attach(User::factory()->create()->id);
        $role->permissions()->attach(Permission::factory()->create()->id);

        app(RoleService::class)->deleteRole($role);

        foreach (['role_permissions', 'role_menus', 'user_roles', 'menu_permissions'] as $table) {
            $this->assertSame(0, DB::table($table)->where('role_id', $role->id)->count(), "{$table} 고아");
        }
    }

    /**
     * 회원 삭제가 회원에게 직접 준 메뉴 권한·역할 배정·동의 이력을 명시적으로 지운다.
     *
     * @scenario case=users__service
     *
     * @effects children_deleted_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_removes_core_children_explicitly(): void
    {
        $user = User::factory()->create();
        $menu = Menu::factory()->create();
        MenuPermission::query()->create(['menu_id' => $menu->id, 'user_id' => $user->id, 'permission_type' => 'read', 'is_allowed' => true]);
        $user->roles()->attach(Role::factory()->create()->id);

        app(UserService::class)->deleteUser($user);

        foreach (['menu_permissions', 'user_roles', 'user_consents', 'user_password_histories', 'user_search_histories'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $user->id)->count(), "{$table} 고아");
        }
    }

    /**
     * 스케줄 일괄 삭제가 실행 이력을 먼저 명시적으로 지운다(단건 삭제와 같은 순서).
     *
     * @scenario case=schedules__service
     *
     * @effects children_deleted_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_schedule_bulk_delete_removes_histories_explicitly(): void
    {
        $schedules = Schedule::factory()->count(2)->create();
        foreach ($schedules as $schedule) {
            ScheduleHistory::query()->create(['schedule_id' => $schedule->id, 'started_at' => now(), 'status' => 'success', 'trigger_type' => 'manual']);
        }
        $ids = $schedules->pluck('id')->all();

        app(ScheduleService::class)->bulkDelete($ids);

        $this->assertSame(0, ScheduleHistory::query()->whereIn('schedule_id', $ids)->count());
    }

    /**
     * 운영자가 만든 본인인증 메시지 정의를 지우면 그 채널 템플릿을 모델 단위로 먼저 지운다.
     *
     * @scenario case=notification_definitions__service
     *
     * @effects children_deleted_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_identity_definition_delete_removes_templates_explicitly(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'operator_made_'.Str::random(6), 'hook_prefix' => 'core.identity', 'category' => IdentityNotificationType::CATEGORY,
            'extension_type' => 'core', 'extension_identifier' => 'admin', 'name' => ['ko' => '운영자 정의'], 'description' => ['ko' => ''],
            'channels' => ['mail'], 'hooks' => [], 'variables' => [], 'is_active' => true, 'is_default' => false,
        ]);
        NotificationTemplate::create(['definition_id' => $definition->id, 'channel' => 'mail', 'subject' => ['ko' => 's'], 'body' => ['ko' => 'b'], 'is_active' => true, 'is_default' => false]);

        app(NotificationDefinitionService::class)->deleteIdentityDefinition($definition);

        $this->assertSame(0, NotificationTemplate::query()->where('definition_id', $definition->id)->count());
    }

    /**
     * 확장 메뉴 일괄 삭제(설치 롤백 경로)가 하위 메뉴·배정·메뉴 권한을 명시적으로 지우고, 다른 출처 자식은 최상위로 올린다.
     *
     * @scenario case=menus__repository
     *
     * @effects children_deleted_without_cascade, operator_child_menu_lifted
     *
     * @return void 반환값 없음
     */
    public function test_menu_delete_by_extension_removes_subtree_and_lifts_operator_child(): void
    {
        $owner = ['extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => 'acme-cascade'];
        [$parent] = $this->menuWithGrants($owner);
        [$child] = $this->menuWithGrants($owner + ['parent_id' => $parent->id]);
        $operatorChild = Menu::factory()->create(['parent_id' => $child->id, 'extension_type' => null, 'extension_identifier' => null]);

        app(MenuRepository::class)->deleteByExtension(ExtensionOwnerType::Plugin, 'acme-cascade');

        $ids = [$parent->id, $child->id];
        $this->assertSame(0, Menu::query()->whereIn('id', $ids)->count());
        $this->assertSame(0, DB::table('role_menus')->whereIn('menu_id', $ids)->count());
        $this->assertSame(0, MenuPermission::query()->whereIn('menu_id', $ids)->count());
        $this->assertNull($operatorChild->fresh()->parent_id, '운영자가 만든 자식은 최상위로 올린다');
    }

    /**
     * 확장 권한 일괄 삭제가 역할 부여·훅 매핑·하위 권한을 명시적으로 지우고, 다른 출처 자식 권한은 부모 연결만 끊는다.
     *
     * @scenario case=permissions__repository
     *
     * @effects children_deleted_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_permission_delete_by_extension_removes_relations_explicitly(): void
    {
        $owner = ['extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => 'acme-cascade'];
        $parent = Permission::factory()->create($owner + ['identifier' => 'acme-cascade.root']);
        $child = Permission::factory()->create($owner + ['identifier' => 'acme-cascade.child', 'parent_id' => $parent->id]);
        $foreign = Permission::factory()->create(['identifier' => 'other.kept', 'parent_id' => $child->id, 'extension_type' => null, 'extension_identifier' => null]);
        $role = Role::factory()->create();
        $child->roles()->attach($role->id);
        $child->hooks()->create(['hook_name' => 'acme.cascade.probe']);

        app(PermissionRepository::class)->deleteByExtension(ExtensionOwnerType::Plugin, 'acme-cascade');

        $ids = [$parent->id, $child->id];
        $this->assertSame(0, Permission::query()->whereIn('id', $ids)->count());
        $this->assertSame(0, DB::table('role_permissions')->whereIn('permission_id', $ids)->count());
        $this->assertSame(0, DB::table('permission_hooks')->whereIn('permission_id', $ids)->count());
        $this->assertNull($foreign->fresh()->parent_id, '다른 출처 자식은 남고 부모 연결만 끊긴다');
    }

    /**
     * 본인확인 로그 파기가 배치마다 지우기 직전에 `core.identity_logs.before_purge` 를 그 배치의 로그 ID 로 발화한다 —
     * 확장 소유 자식(거래 매핑 등)은 그 확장의 리스너가 지운다.
     *
     * @scenario case=identity_verification_logs__service
     *
     * @effects purge_hook_fired_before_delete
     *
     * @return void 반환값 없음
     */
    public function test_identity_log_purge_fires_hook_with_ids_before_delete(): void
    {
        $old = IdentityVerificationLog::create([
            'id' => (string) Str::uuid(), 'provider_id' => 'mail', 'purpose' => 'signup', 'channel' => 'email',
            'target_hash' => hash('sha256', 'a@example.com'), 'status' => IdentityVerificationStatus::Verified->value, 'attempts' => 1, 'max_attempts' => 5,
        ]);
        IdentityVerificationLog::query()->whereKey($old->id)->update(['created_at' => now()->subDays(400)]);

        $seen = [];
        $listener = function (array $ids) use (&$seen): void {
            $seen[] = ['ids' => $ids, 'exists' => IdentityVerificationLog::query()->whereIn('id', $ids)->count()];
        };
        HookManager::addAction('core.identity_logs.before_purge', $listener);

        try {
            app(IdentityLogService::class)->purge(180);
        } finally {
            HookManager::removeAction('core.identity_logs.before_purge', $listener);
        }

        $this->assertNotEmpty($seen);
        $this->assertContains($old->id, array_merge(...array_column($seen, 'ids')));
        $this->assertGreaterThan(0, $seen[0]['exists'], '발화 시점에는 아직 로그가 있어야 한다');
        $this->assertNull(IdentityVerificationLog::find($old->id));
    }
}
