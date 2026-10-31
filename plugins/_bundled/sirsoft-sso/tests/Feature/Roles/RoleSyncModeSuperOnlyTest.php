<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Roles;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Plugins\Sirsoft\Sso\Services\RoleSyncRuleValidator;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 역할 매핑 설정의 최고관리자 전용 검사(W14, W35) — 관리자 권한 역할 규칙의 추가, 삭제, 그리고 관리자가 연결된 IdP 의 모드, 그룹 출처 변경.
 *
 * @since 1.0.0
 */
class RoleSyncModeSuperOnlyTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 관리자 권한 역할로 가는 규칙의 추가와 삭제는 위임 관리자에게 403, 최고관리자는 통과. 일반 역할 규칙은 위임 관리자도 통과.
     *
     * @scenario actor=delegate|super, rule_role=admin|plain, change=add|remove
     *
     * @effects forbidden_for_delegate_admin_rule, allowed_plain_rule
     *
     * @return void 반환값 없음
     */
    public function test_admin_role_rules_are_super_only(): void
    {
        $this->role('p3_admin_like', admin: true);
        $this->role('p3_plain');
        $validator = app(RoleSyncRuleValidator::class);
        $delegate = User::factory()->create();
        $super = User::factory()->create(['is_super' => true]);
        $idp = $this->makeIdp(['role_mapping' => [['group' => 'IT', 'role' => 'p3_admin_like']]]);

        $validator->assertAllowed($idp, ['role_mapping' => [['group' => 'IT', 'role' => 'p3_admin_like'], ['group' => '영업', 'role' => 'p3_plain']]], $delegate);
        $validator->assertAllowed($idp, ['role_mapping' => []], $super);
        $validator->assertAllowed(null, ['role_mapping' => [['group' => 'IT', 'role' => 'p3_admin_like']]], $super);

        foreach ([
            'add' => [null, [['group' => '보안', 'role' => 'p3_admin_like']]],
            'remove' => [$idp, []],
            'retarget' => [$idp, [['group' => 'IT-2', 'role' => 'p3_admin_like']]],
        ] as $case => [$before, $mapping]) {
            try {
                $validator->assertAllowed($before, ['role_mapping' => $mapping], $delegate);
                $this->fail('위임 관리자가 관리자 역할 규칙을 바꿨다: '.$case);
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * 관리자가 연결된 IdP 의 `role_sync_mode`, `group_source` 변경은 최고관리자만. 관리자가 없으면 위임 관리자도 통과.
     *
     * @scenario linked_admin=yes|no, field=role_sync_mode|group_source
     *
     * @effects forbidden_for_delegate, allowed_without_admin
     *
     * @return void 반환값 없음
     */
    public function test_mode_change_with_linked_admin_is_super_only(): void
    {
        $validator = app(RoleSyncRuleValidator::class);
        $delegate = User::factory()->create();
        $plain = $this->makeIdp();
        $this->link(User::factory()->create(), $plain, 'plain-member');

        $validator->assertAllowed($plain, ['role_sync_mode' => 'authoritative', 'group_source' => 'scim'], $delegate);

        $guarded = $this->makeIdp();
        $admin = User::factory()->create();
        $admin->roles()->attach($this->role('p3_admin_like2', admin: true)->id);
        $this->link($admin, $guarded, 'admin-member');

        foreach (['role_sync_mode' => 'authoritative', 'group_source' => 'scim'] as $field => $value) {
            try {
                $validator->assertAllowed($guarded, [$field => $value], $delegate);
                $this->fail($field.' 변경이 통과했다');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $validator->assertAllowed($guarded, ['role_sync_mode' => 'managed_only'], $delegate);
        $validator->assertAllowed($guarded, ['role_sync_mode' => 'authoritative'], User::factory()->create(['is_super' => true]));
    }
}
