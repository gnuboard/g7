<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Roles;

use App\Models\ActivityLog;
use App\Models\User;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoRoleGrant;
use Plugins\Sirsoft\Sso\Services\RoleSyncService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 역할 매핑(D9, W13) — 모드 3종, 수동 역할 보존, 최고관리자 불변, 그룹 비교 규칙(대소문자, LDAP DN), 로그인에서의 적용.
 *
 * @since 1.0.0
 */
class RoleSyncTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 「인사팀 → hr_manager」 규칙 IdP 와 기본 역할을 준비합니다.
     *
     * @param  string  $mode  역할 동기화 모드
     * @param  array<string, mixed>  $config  설정
     * @return array{0: IdentityProvider, 1: User, 2: SsoAccount} IdP, 회원, 연결
     */
    private function setUpMapping(string $mode, array $config = []): array
    {
        $this->role('user');
        $this->role('hr_manager');
        $idp = $this->makeIdp(['role_sync_mode' => $mode, 'role_mapping' => [['group' => '인사팀', 'role' => 'hr_manager']], 'config' => $config]);
        $user = User::factory()->create();
        $user->roles()->attach($this->role('user')->id);
        $account = $this->link($user, $idp, 'role-'.$mode.'-'.$user->id);

        return [$idp, $user, $account];
    }

    /**
     * `managed_only` — 붙이고 기록, 그룹이 빠지면 매핑이 붙인 것만 뗀다. 같은 역할을 수동으로 갖고 있던 회원은 떼지 않는다.
     *
     * @scenario mode=managed_only, manual_role=none|same
     *
     * @effects role_added_and_recorded, role_removed, manual_role_kept
     *
     * @return void 반환값 없음
     */
    public function test_managed_only_adds_and_removes_only_granted(): void
    {
        [$idp, $user, $account] = $this->setUpMapping('managed_only');
        $sync = app(RoleSyncService::class);

        $added = $sync->sync($user, $account, $idp, ['인사팀'], RoleSyncService::TRIGGER_UPDATE);
        $this->assertSame(['hr_manager'], $added['added']);
        $this->assertSame(['hr_manager', 'user'], $this->roleIds($user));
        $this->assertSame(1, SsoRoleGrant::query()->where('user_id', $user->id)->count());

        $removed = $sync->sync($user, $account, $idp, [], RoleSyncService::TRIGGER_UPDATE);
        $this->assertSame(['hr_manager'], $removed['removed']);
        $this->assertSame(['user'], $this->roleIds($user));
        $this->assertSame(0, SsoRoleGrant::query()->where('user_id', $user->id)->count());

        [$idp2, $manual, $account2] = $this->setUpMapping('managed_only');
        $manual->roles()->attach($this->role('hr_manager')->id);
        $sync->sync($manual, $account2, $idp2, ['인사팀'], RoleSyncService::TRIGGER_UPDATE);
        $this->assertSame(0, SsoRoleGrant::query()->where('user_id', $manual->id)->count(), '수동 역할은 기록하지 않는다');
        $sync->sync($manual, $account2, $idp2, [], RoleSyncService::TRIGGER_UPDATE);
        $this->assertSame(['hr_manager', 'user'], $this->roleIds($manual), '수동 역할은 회수하지 않는다');
    }

    /**
     * `authoritative` — 규칙 결과 ∪ {user} 로 맞춘다(수동 역할도 뗀다).
     *
     * @scenario mode=authoritative
     *
     * @effects manual_role_removed, base_role_kept
     *
     * @return void 반환값 없음
     */
    public function test_authoritative_replaces_roles_but_keeps_base(): void
    {
        [$idp, $user, $account] = $this->setUpMapping('authoritative');
        $user->roles()->attach($this->role('p3_manual')->id);

        app(RoleSyncService::class)->sync($user, $account, $idp, ['인사팀'], RoleSyncService::TRIGGER_UPDATE);

        $this->assertSame(['hr_manager', 'user'], $this->roleIds($user));
    }

    /**
     * `first_only` — 처음 만들 때만 붙이고 이후 로그인에서는 바꾸지 않는다.
     *
     * @scenario mode=first_only, trigger=created|update
     *
     * @effects added_on_create_only
     *
     * @return void 반환값 없음
     */
    public function test_first_only_applies_on_create_only(): void
    {
        [$idp, $user, $account] = $this->setUpMapping('first_only');
        $sync = app(RoleSyncService::class);

        $sync->sync($user, $account, $idp, ['인사팀'], RoleSyncService::TRIGGER_UPDATE);
        $this->assertSame(['user'], $this->roleIds($user));

        $sync->sync($user, $account, $idp, ['인사팀'], RoleSyncService::TRIGGER_CREATED);
        $this->assertSame(['hr_manager', 'user'], $this->roleIds($user));

        $sync->sync($user, $account, $idp, [], RoleSyncService::TRIGGER_UPDATE);
        $this->assertSame(['hr_manager', 'user'], $this->roleIds($user));
    }

    /**
     * 최고관리자의 역할은 어떤 모드에서도 바꾸지 않고, 건너뛴 사실을 활동 로그 `sso.roles_synced` 에 남긴다(W13).
     *
     * @scenario target=super_admin, mode=authoritative
     *
     * @effects roles_unchanged, skip_activity_logged
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_roles_unchanged(): void
    {
        [$idp] = $this->setUpMapping('authoritative');
        $super = User::factory()->create(['is_super' => true]);
        $super->roles()->attach($this->role('p3_manual')->id);
        $account = $this->link($super, $idp, 'role-super');

        $result = app(RoleSyncService::class)->sync($super, $account, $idp, ['인사팀'], RoleSyncService::TRIGGER_UPDATE);

        $this->assertSame('super_admin', $result['skipped']);
        $this->assertSame(['p3_manual'], $this->roleIds($super));

        $log = ActivityLog::query()->where('action', 'sso.roles_synced')->where('loggable_id', $super->id)->sole();
        $this->assertSame('super_admin', $log->properties['skipped']);
        $this->assertSame([], $log->properties['added']);
        $this->assertSame($idp->key, $log->properties['idp_key']);
        $this->assertSame('sirsoft-sso::activity_log.description.sso.roles_sync_skipped', $log->description_key);
    }

    /**
     * 그룹 비교 — 기본은 대소문자 무시, 설정하면 구분. LDAP DN 은 CN 만 비교.
     *
     * @scenario group_case_sensitive=false|true, group=DN
     *
     * @effects matched_case_insensitive, not_matched_case_sensitive, dn_cn_matched
     *
     * @return void 반환값 없음
     */
    public function test_group_comparison_rules(): void
    {
        $this->role('user');
        $this->role('hr_manager');
        $sync = app(RoleSyncService::class);

        $insensitive = $this->makeIdp(['role_mapping' => [['group' => 'HR', 'role' => 'hr_manager']]]);
        $u1 = User::factory()->create();
        $sync->sync($u1, $this->link($u1, $insensitive, 'g1'), $insensitive, ['hr'], RoleSyncService::TRIGGER_UPDATE);
        $this->assertContains('hr_manager', $this->roleIds($u1));

        $sensitive = $this->makeIdp(['role_mapping' => [['group' => 'HR', 'role' => 'hr_manager']], 'config' => ['group_case_sensitive' => true]]);
        $u2 = User::factory()->create();
        $sync->sync($u2, $this->link($u2, $sensitive, 'g2'), $sensitive, ['hr'], RoleSyncService::TRIGGER_UPDATE);
        $this->assertNotContains('hr_manager', $this->roleIds($u2));

        $dn = $this->makeIdp(['role_mapping' => [['group' => '인사팀', 'role' => 'hr_manager']]]);
        $u3 = User::factory()->create();
        $sync->sync($u3, $this->link($u3, $dn, 'g3'), $dn, ['CN=인사팀,OU=Groups,DC=corp798,DC=test'], RoleSyncService::TRIGGER_UPDATE);
        $this->assertContains('hr_manager', $this->roleIds($u3));
    }

    /**
     * 로그인 확정 훅에서 IdP 가 준 그룹으로 매핑한다 (모의 OIDC, 그룹 2개 변종).
     *
     * @scenario trigger=login, groups=assertion
     *
     * @effects role_added_on_login
     *
     * @return void 반환값 없음
     */
    public function test_login_applies_mapping_from_assertion_groups(): void
    {
        $this->role('user');
        $this->role('hr_manager');
        $idp = $this->mockOidcIdp(['role_mapping' => [['group' => '인사팀', 'role' => 'hr_manager']]]);
        $user = User::factory()->create();
        $this->link($user, $idp, 'role-login');

        $this->roundTrip($idp, 'full', ['sub' => 'role-login']);

        $this->assertContains('hr_manager', $this->roleIds($user));
    }
}
