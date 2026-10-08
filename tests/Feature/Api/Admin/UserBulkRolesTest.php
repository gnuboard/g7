<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Listeners\CoreActivityLogListener;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

/**
 * 회원 목록 역할 일괄 추가·제거 (#708)
 *
 * `PATCH /api/admin/users/bulk-roles` 는 정적 경로라 미들웨어의 범위 검사가 빠진다. 일괄 상태
 * 변경과 같은 두 축(등급·범위)과 역할 상한·자기 잠금을 서비스가 지키는지 찌른다.
 *
 * 시나리오 SSoT: tests/scenarios/user-bulk-role-assignment.yaml
 */
#[Group('core')]
#[Group('admin')]
#[Group('user')]
class UserBulkRolesTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /**
     * 회원 관리 권한을 가진 비-최고관리자 액터를 만듭니다.
     *
     * @param  string|null  $usersScope  core.users.update 의 scope_type
     * @return User 액터
     */
    private function operator(?string $usersScope = null): User
    {
        $user = User::factory()->create();
        $role = $this->roleWith([
            'core.users.read' => null,
            'core.users.update' => $usersScope,
        ]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 액터 상한 안의 역할(액터가 가진 권한만 담음).
     *
     * @return Role 역할
     */
    private function withinCeilingRole(): Role
    {
        return $this->roleWith(['core.users.read' => null]);
    }

    /**
     * 액터 상한 밖의 역할(액터가 없는 권한을 담음).
     *
     * @return Role 역할
     */
    private function aboveCeilingRole(): Role
    {
        return $this->roleWith(['core.settings.update' => null]);
    }

    /**
     * 일괄 요청을 보냅니다.
     *
     * @param  User  $actor  요청자
     * @param  array<int, User>  $targets  대상 회원
     * @param  array<int, int>  $roleIds  역할 ID
     * @param  string  $mode  작업 (add 추가 · remove 제거)
     * @return TestResponse 응답
     */
    private function bulk(User $actor, array $targets, array $roleIds, string $mode): TestResponse
    {
        return $this->actingAs($actor)->patchJson('/api/admin/users/bulk-roles', [
            'uuids' => array_map(fn (User $u) => $u->uuid, $targets),
            'role_ids' => $roleIds,
            'mode' => $mode,
        ]);
    }

    /**
     * 회원이 가진 역할 ID.
     *
     * @param  User  $user  회원
     * @return array<int, int> 역할 ID
     */
    private function roleIdsOf(User $user): array
    {
        return $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /**
     * 일반 회원에게 역할을 더하면 붙고 assigned_by 에 액터가 남는다. 다시 더해도 행이 겹치지 않는다.
     *
     * @scenario mode=add, target=normal, role=within_ceiling
     *
     * @effects assigned_by_recorded
     *
     * @return void 반환값 없음
     */
    public function test_add_to_normal_records_assigned_by_and_is_idempotent(): void
    {
        $actor = $this->operator();
        $role = $this->withinCeilingRole();
        $targets = [User::factory()->create(), User::factory()->create()];

        $this->bulk($actor, $targets, [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.skipped_count', 0);
        $this->bulk($actor, $targets, [$role->id], 'add')->assertOk()->assertJsonPath('data.updated_count', 0);

        foreach ($targets as $target) {
            $rows = DB::table('user_roles')->where('user_id', $target->id)->where('role_id', $role->id)->get();
            $this->assertCount(1, $rows);
            $this->assertSame($actor->id, (int) $rows[0]->assigned_by);
        }
    }

    /**
     * 이미 그 역할을 가진 회원(추가)·가지지 않은 회원(제거)은 「변경 없음」 으로 따로 센다 —
     * 변경·제외 어디에도 안 잡히면 4명을 골랐는데 「2명 변경, 1명 제외」 만 보여 한 명이 사라진다.
     *
     * @scenario mode=add, target=normal, role=within_ceiling
     *
     * @effects unchanged_reported
     *
     * @return void 반환값 없음
     */
    public function test_already_in_target_state_is_reported_as_unchanged(): void
    {
        $actor = $this->operator();
        $role = $this->withinCeilingRole();
        $other = $this->withinCeilingRole();
        $holder = User::factory()->create();
        $holder->roles()->sync([$role->id => ['assigned_at' => now()], $other->id => ['assigned_at' => now()]]);
        $fresh = User::factory()->create();
        $fresh->roles()->sync([$other->id => ['assigned_at' => now()]]);

        $this->bulk($actor, [$holder, $fresh], [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped_count', 0)
            ->assertJsonPath('data.unchanged_count', 1)
            ->assertJsonPath('data.unchanged.0.uuid', $holder->uuid)
            ->assertJsonPath('data.unchanged.0.reason', 'already_assigned')
            ->assertJsonPath('message', __('user.bulk_roles_updated_with_unchanged', ['count' => 1, 'skipped' => 0, 'unchanged' => 1]));

        // 제거: 이제 둘 다 role 을 가진다 — 한 명에게서 먼저 빼 두고 다시 둘에게 빼면 그 한 명은 「가지지 않음」.
        $holder->roles()->detach($role->id);
        $this->bulk($actor, [$holder, $fresh], [$role->id], 'remove')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.unchanged_count', 1)
            ->assertJsonPath('data.unchanged.0.uuid', $holder->uuid)
            ->assertJsonPath('data.unchanged.0.reason', 'not_assigned');

        // 모두 바뀌면 변경 없음은 0 이고 문구는 종전 그대로다.
        $this->bulk($actor, [$holder], [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.unchanged_count', 0)
            ->assertJsonPath('data.unchanged', [])
            ->assertJsonPath('message', __('user.bulk_roles_updated', ['count' => 1, 'skipped' => 0]));
    }

    /**
     * 비-최고관리자 액터가 최고관리자를 섞으면 그 회원은 grade 사유로 빠지고 나머지는 처리된다.
     *
     * @scenario mode=add, target=super_admin, role=within_ceiling
     *
     * @effects grade_filtered
     *
     * @return void 반환값 없음
     */
    public function test_add_skips_super_admin_target(): void
    {
        $actor = $this->operator();
        $role = $this->withinCeilingRole();
        $super = User::factory()->create(['is_super' => true]);
        $normal = User::factory()->create();

        $this->bulk($actor, [$super, $normal], [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped.0.uuid', $super->uuid)
            ->assertJsonPath('data.skipped.0.reason', 'grade');

        $this->assertNotContains($role->id, $this->roleIdsOf($super));
        $this->assertContains($role->id, $this->roleIdsOf($normal));
    }

    /**
     * 회원 수정 범위가 role 인 액터가 자기 역할 범위 밖 회원을 섞으면 scope 사유로 빠진다.
     *
     * @scenario mode=add, target=out_of_scope, role=within_ceiling
     *
     * @effects scope_filtered
     *
     * @return void 반환값 없음
     */
    public function test_add_skips_out_of_scope_target(): void
    {
        $actor = $this->operator('role');
        $actorRole = $actor->roles()->first();
        $role = $this->withinCeilingRole();
        $inScope = User::factory()->create();
        $inScope->roles()->attach($actorRole->id, ['assigned_at' => now()]);
        $outOfScope = User::factory()->create();

        $this->bulk($actor, [$inScope, $outOfScope], [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped.0.uuid', $outOfScope->uuid)
            ->assertJsonPath('data.skipped.0.reason', 'scope');
    }

    /**
     * 상한 밖 역할을 더하는 요청은 전체 403 이고 아무에게도 붙지 않는다.
     *
     * @scenario mode=add, target=normal, role=above_ceiling
     *
     * @effects ceiling_violation_rejects_request
     *
     * @return void 반환값 없음
     */
    public function test_add_above_ceiling_rejects_whole_request(): void
    {
        $actor = $this->operator();
        $safe = $this->withinCeilingRole();
        $above = $this->aboveCeilingRole();
        $target = User::factory()->create();

        $this->bulk($actor, [$target], [$safe->id, $above->id], 'add')
            ->assertForbidden()
            ->assertJsonPath('message', __('user.bulk_roles_ceiling'));

        $this->assertSame([], array_values(array_intersect([$safe->id, $above->id], $this->roleIdsOf($target))));
    }

    /**
     * 일반 회원에게서 역할을 빼면 떨어진다. 활동 로그 훅은 바뀐 회원마다 변경분을 싣는다.
     *
     * @scenario mode=remove, target=normal, role=within_ceiling
     *
     * @effects activity_log_per_user
     *
     * @return void 반환값 없음
     */
    public function test_remove_from_normal_reports_changes_per_user(): void
    {
        $actor = $this->operator();
        $role = $this->withinCeilingRole();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $a->roles()->attach($role->id, ['assigned_at' => now()]);
        // 뺀 뒤에도 역할이 남게 한다 — 마지막 역할 제거는 last_role 로 건너뛴다(아래 별도 케이스)
        $a->roles()->attach($this->withinCeilingRole()->id, ['assigned_at' => now()]);

        $captured = null;
        $spy = function (array $userIds, array $roleIds, string $mode, array $changes) use (&$captured) {
            $captured = compact('userIds', 'roleIds', 'mode', 'changes');
        };
        HookManager::addAction('core.user.after_bulk_update_roles', $spy);
        try {
            $this->bulk($actor, [$a, $b], [$role->id], 'remove')->assertOk()->assertJsonPath('data.updated_count', 1);
        } finally {
            HookManager::removeAction('core.user.after_bulk_update_roles', $spy);
        }

        $this->assertNotContains($role->id, $this->roleIdsOf($a));
        $this->assertSame([$a->id], $captured['userIds']);
        $this->assertSame('remove', $captured['mode']);
        $this->assertSame([['user_id' => $a->id, 'added' => [], 'removed' => [$role->id]]], $captured['changes']);
    }

    /**
     * 비-최고관리자가 최고관리자에게서 역할을 빼는 것도 grade 사유로 빠진다.
     *
     * @scenario mode=remove, target=super_admin, role=within_ceiling
     *
     * @effects grade_filtered
     *
     * @return void 반환값 없음
     */
    public function test_remove_skips_super_admin_target(): void
    {
        $actor = $this->operator();
        $role = $this->withinCeilingRole();
        $super = User::factory()->create(['is_super' => true]);
        $super->roles()->attach($role->id, ['assigned_at' => now()]);

        $this->bulk($actor, [$super], [$role->id], 'remove')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 0)
            ->assertJsonPath('data.skipped.0.reason', 'grade');

        $this->assertContains($role->id, $this->roleIdsOf($super));
    }

    /**
     * 범위 밖 회원에게서 빼는 것도 scope 사유로 빠진다.
     *
     * @scenario mode=remove, target=out_of_scope, role=within_ceiling
     *
     * @effects scope_filtered
     *
     * @return void 반환값 없음
     */
    public function test_remove_skips_out_of_scope_target(): void
    {
        $actor = $this->operator('role');
        $role = $this->withinCeilingRole();
        $outOfScope = User::factory()->create();
        $outOfScope->roles()->attach($role->id, ['assigned_at' => now()]);

        $this->bulk($actor, [$outOfScope], [$role->id], 'remove')
            ->assertOk()
            ->assertJsonPath('data.skipped.0.reason', 'scope');

        $this->assertContains($role->id, $this->roleIdsOf($outOfScope));
    }

    /**
     * 마지막 admin 인 액터가 자기와 다른 회원에게서 admin 을 빼면 자기만 last_admin 사유로 빠진다.
     *
     * @scenario mode=remove, target=self_last_admin, role=within_ceiling
     *
     * @effects last_admin_self_skipped
     *
     * @return void 반환값 없음
     */
    public function test_remove_admin_skips_actor_when_no_admin_would_remain(): void
    {
        $admin = $this->grantToAdmin(['core.users.read' => null, 'core.users.update' => null]);
        $actor = User::factory()->create();
        $actor->roles()->attach($admin->id, ['assigned_at' => now()]);
        $other = User::factory()->create();
        $other->roles()->attach($admin->id, ['assigned_at' => now()]);
        // admin 을 뺀 뒤에도 역할이 남게 한다 — 마지막 역할 제거는 last_role 로 건너뛴다
        $other->roles()->attach($this->withinCeilingRole()->id, ['assigned_at' => now()]);
        // 이 테스트 밖의 admin 보유자가 있으면 조건이 성립하지 않으므로 대상에서 제외한다
        DB::table('user_roles')->where('role_id', $admin->id)->whereNotIn('user_id', [$actor->id, $other->id])->delete();

        $this->bulk($actor->fresh(), [$actor, $other], [$admin->id], 'remove')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped.0.uuid', $actor->uuid)
            ->assertJsonPath('data.skipped.0.reason', 'last_admin');

        $this->assertContains($admin->id, $this->roleIdsOf($actor));
        $this->assertNotContains($admin->id, $this->roleIdsOf($other));
    }

    /**
     * 빼면 역할이 하나도 남지 않는 회원은 last_role 사유로 건너뛰고, 다른 역할이 남는 회원만 뺀다.
     * 단건 회원 수정의 「역할을 하나 이상 선택」 규칙과 같다 — 일괄 제거가 단건 폼이 막는 상태를 만들지 않는다.
     *
     * @scenario mode=remove, target=normal, role=within_ceiling
     *
     * @effects last_role_skipped
     *
     * @return void 반환값 없음
     */
    public function test_remove_skips_user_whose_last_role_would_be_removed(): void
    {
        $actor = $this->operator();
        $role = $this->withinCeilingRole();
        $onlyThis = User::factory()->create();
        $onlyThis->roles()->sync([$role->id => ['assigned_at' => now()]]);
        $hasOther = User::factory()->create();
        $other = $this->withinCeilingRole();
        $hasOther->roles()->sync([$role->id => ['assigned_at' => now()], $other->id => ['assigned_at' => now()]]);

        $this->bulk($actor, [$onlyThis, $hasOther], [$role->id], 'remove')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped.0.uuid', $onlyThis->uuid)
            ->assertJsonPath('data.skipped.0.reason', 'last_role');

        $this->assertSame([$role->id], $this->roleIdsOf($onlyThis));
        $this->assertSame([$other->id], $this->roleIdsOf($hasOther));
    }

    /**
     * 상한 밖 역할을 빼는 요청도 전체 403 이다(단건 수정과 같이 떼는 것도 상한 검사).
     *
     * @scenario mode=remove, target=normal, role=above_ceiling
     *
     * @effects ceiling_violation_rejects_request
     *
     * @return void 반환값 없음
     */
    public function test_remove_above_ceiling_rejects_whole_request(): void
    {
        $actor = $this->operator();
        $above = $this->aboveCeilingRole();
        $target = User::factory()->create();
        $target->roles()->attach($above->id, ['assigned_at' => now()]);

        $this->bulk($actor, [$target], [$above->id], 'remove')->assertForbidden();

        $this->assertContains($above->id, $this->roleIdsOf($target));
    }

    /**
     * 액터가 자기에게 역할을 더하면 요청이 들고 있는 액터 인스턴스의 권한 판정도 곧바로 바뀐다.
     *
     * @effects permission_cache_flushed
     *
     * @return void 반환값 없음
     */
    public function test_actor_permission_cache_is_flushed_when_own_roles_change(): void
    {
        $actor = User::factory()->create(['is_super' => true]);
        $this->actingAs($actor);
        $menuRead = $this->roleWith(['core.menus.read' => null]);

        // 판정 캐시를 데워 둔다 — 비우지 않으면 아래 판정이 옛 값(false)으로 남는다
        $this->assertFalse($actor->hasPermission('core.menus.read'));

        app(UserService::class)->bulkUpdateRoles([$actor->uuid], [$menuRead->id], 'add');

        $this->assertTrue($actor->hasPermission('core.menus.read'));
    }

    /**
     * 활동 로그 리스너는 바뀐 회원마다 user.bulk_roles 한 줄씩 남긴다.
     *
     * @effects activity_log_per_user
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_listener_logs_one_entry_per_changed_user(): void
    {
        $this->app->instance('request', Request::create('/api/admin/users/bulk-roles', 'PATCH'));
        $a = User::factory()->create();
        $b = User::factory()->create();

        $channel = Mockery::mock(LoggerInterface::class);
        $logged = [];
        $channel->shouldReceive('info')->andReturnUsing(function (string $action, array $context) use (&$logged) {
            $logged[] = [$action, $context['properties'], $context['description_params']];
        });
        Log::shouldReceive('channel')->with('activity')->andReturn($channel);

        app(CoreActivityLogListener::class)->handleUserAfterBulkUpdateRoles([$a->id, $b->id], [7], 'add', [
            ['user_id' => $a->id, 'added' => [7], 'removed' => []],
            ['user_id' => $b->id, 'added' => [7], 'removed' => []],
        ]);

        $this->assertCount(2, $logged);
        $this->assertSame('user.bulk_roles', $logged[0][0]);
        $this->assertSame(['mode' => 'add', 'added' => [7], 'removed' => []], array_intersect_key($logged[0][1], array_flip(['mode', 'added', 'removed'])));
        $this->assertSame(['user_id' => $a->id], $logged[0][2]);
    }
}
