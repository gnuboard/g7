<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Models\User;
use App\Services\UserService;
use Plugins\Sirsoft\Sso\Bootstrap\Schedules;
use Plugins\Sirsoft\Sso\Bootstrap\Tables;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Models\SsoRoleGrant;
use Plugins\Sirsoft\Sso\Models\SsoScimEvent;
use Plugins\Sirsoft\Sso\Models\SsoScimGroup;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Models\SsoUserAttribute;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 프로비저닝 영역의 선언과 정리 — 스케줄 4종, 표 등록 순서, 회원 삭제 때 영역 표 정리(FK 오류 0), 보존 정리 명령.
 *
 * @since 1.0.0
 */
class ProvisioningMaintenanceTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 스케줄 선언 — 이름, 명령, cron 식이 계획서 §6.19 와 같다.
     *
     * @scenario declaration=schedules
     *
     * @effects four_schedules
     *
     * @return void 반환값 없음
     */
    public function test_schedule_declarations(): void
    {
        $byName = [];
        foreach (Schedules::all() as $schedule) {
            $byName[$schedule['name']] = [$schedule['command'], $schedule['expression']];
            $this->assertSame('artisan', $schedule['type']);
            $this->assertArrayHasKey('ko', $schedule['label']);
            $this->assertArrayHasKey('en', $schedule['label']);
        }

        $this->assertSame([
            'deprovision-check' => ['sirsoft-sso:deprovision-check', '*/5 * * * *'],
            'withdraw-deprovisioned' => ['sirsoft-sso:withdraw-deprovisioned', '10 4 * * *'],
            'metadata-refresh' => ['sirsoft-sso:metadata-refresh', '20 4 * * *'],
            'prune' => ['sirsoft-sso:prune', '40 4 * * *'],
        ], $byName);
    }

    /**
     * 표 등록 — 영역 표 6개가 기반 표(연결, IdP)보다 먼저 온다(삭제 순서).
     *
     * @scenario declaration=tables
     *
     * @effects provisioning_tables_before_core
     *
     * @return void 반환값 없음
     */
    public function test_tables_are_registered_before_core_tables(): void
    {
        $tables = Tables::all();
        foreach (['sso_scim_group_members', 'sso_scim_groups', 'sso_scim_events', 'sso_role_grants', 'sso_user_attributes', 'sso_sessions'] as $table) {
            $this->assertContains($table, $tables);
            $this->assertLessThan(array_search('sso_accounts', $tables, true), array_search($table, $tables, true), $table);
        }
        $this->assertLessThan(array_search('sso_scim_groups', $tables, true), array_search('sso_scim_group_members', $tables, true));
    }

    /**
     * 회원 삭제 — 속성, 역할 기록, 세션, 그룹 소속이 먼저 지워져 연결 삭제와 회원 삭제가 FK 오류 없이 끝난다.
     *
     * @scenario user_action=delete
     *
     * @effects provisioning_rows_deleted, no_fk_error
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_cleans_provisioning_rows(): void
    {
        $idp = $this->makeIdp();
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'clean-1');
        $role = $this->role('p3_clean_role');
        $group = SsoScimGroup::query()->create(['idp_id' => $idp->id, 'display_name' => '정리팀']);
        $group->members()->attach($account->id);
        SsoUserAttribute::query()->create(['user_id' => $user->id, 'idp_id' => $idp->id, 'key' => 'department', 'value' => 'x', 'synced_at' => now()]);
        SsoRoleGrant::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'idp_id' => $idp->id, 'granted_at' => now()]);
        SsoSession::query()->create(['idp_id' => $idp->id, 'user_id' => $user->id]);

        app(UserService::class)->deleteUser($user);

        $this->assertSame(0, SsoUserAttribute::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, SsoRoleGrant::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, SsoSession::query()->where('user_id', $user->id)->count());
        $this->assertSame([], $group->members()->pluck('sso_accounts.id')->all());
        $this->assertNull(User::query()->find($user->id));
    }

    /**
     * 보존 정리 — 기본 보존일(시도 90, SCIM 90, 끝난 세션 30)보다 오래된 행만 지운다.
     *
     * @scenario retention=default
     *
     * @effects old_rows_pruned, recent_rows_kept
     *
     * @return void 반환값 없음
     */
    public function test_prune_command(): void
    {
        $idp = $this->makeIdp();
        $user = User::factory()->create();
        $old = SsoScimEvent::query()->create(['idp_id' => $idp->id, 'method' => 'GET', 'resource' => 'Users', 'status' => 200]);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        $recent = SsoScimEvent::query()->create(['idp_id' => $idp->id, 'method' => 'GET', 'resource' => 'Users', 'status' => 200]);
        $ended = SsoSession::query()->create(['idp_id' => $idp->id, 'user_id' => $user->id, 'ended_at' => now()->subDays(31), 'end_reason' => 'logout']);
        $open = SsoSession::query()->create(['idp_id' => $idp->id, 'user_id' => $user->id]);
        $attempt = SsoLoginAttempt::query()->forceCreate(['idp_id' => $idp->id, 'intent' => 'login', 'guard' => 'user', 'outcome' => 'failed', 'user_agent' => 't', 'created_at' => now()->subDays(91)]);

        $this->artisan('sirsoft-sso:prune')->assertSuccessful();

        $this->assertNull(SsoScimEvent::query()->find($old->id));
        $this->assertNotNull(SsoScimEvent::query()->find($recent->id));
        $this->assertNull(SsoSession::query()->find($ended->id));
        $this->assertNotNull(SsoSession::query()->find($open->id));
        $this->assertNull(SsoLoginAttempt::query()->find($attempt->id));
    }
}
