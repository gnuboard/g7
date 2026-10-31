<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Settings;

use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 정책 저장 검증(W5, D26) — 비상 계정 0명, IdP 없는 사이트 전체 강제 거절, 최고관리자 전용 칸 403, 범위 밖 값 422.
 *
 * @since 1.0.0
 */
class SettingsValidationTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 비상 계정을 0명으로 저장하면 422 인지 확인한다.
     *
     * @scenario emergency_user_ids=empty, actor=super
     *
     * @effects emergency_required_422, policy_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_zero_emergency_accounts_refused(): void
    {
        $super = $this->makeSuper();
        Sanctum::actingAs($super);

        $response = $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['emergency_user_ids' => []]);

        $response->assertStatus(422);
        $this->assertSame('emergency_required', $response->json('errors.code'));
    }

    /**
     * 활성 IdP 없이 사이트 전체 강제를 켜면 422, IdP 가 있으면 저장되는지 확인한다.
     *
     * @scenario enforce_site=true, enabled_idps=0|1
     *
     * @effects site_enforce_requires_idp_422, saved_with_idp
     *
     * @return void 반환값 없음
     */
    public function test_site_enforcement_needs_enabled_idp(): void
    {
        $super = $this->makeSuper();
        Sanctum::actingAs($super);
        $this->setPolicy(['emergency_user_ids' => [$super->id]]);

        $this->makeIdp('off', ['enabled' => false]);
        $response = $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['enforce_site' => true]);
        $response->assertStatus(422);
        $this->assertSame('site_enforce_requires_idp', $response->json('errors.code'));
        $this->assertFalse(app(PolicyService::class)->current()['enforce_site']);

        $this->makeIdp('corp');
        $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['enforce_site' => true])->assertOk();
        $this->assertTrue(app(PolicyService::class)->current()['enforce_site']);
    }

    /**
     * 최고관리자가 아닌 관리자가 최고관리자 전용 칸을 바꾸면 403, 다른 칸은 저장되는지 확인한다.
     *
     * @scenario actor=delegate, key=enforce_site|enforce_roles|emergency_user_ids|session_max_minutes
     *
     * @effects super_only_403, other_keys_saved
     *
     * @return void 반환값 없음
     */
    public function test_super_only_keys(): void
    {
        $super = $this->makeSuper();
        $this->makeIdp('corp');
        $this->setPolicy(['emergency_user_ids' => [$super->id]]);
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.settings.view', 'sirsoft-sso.settings.update']));

        $this->makeRole('sso-staff-798');

        foreach (['enforce_site' => true, 'enforce_roles' => ['sso-staff-798'], 'emergency_user_ids' => [$super->id, $this->makeMember()->id]] as $key => $value) {
            $response = $this->putJson('/api/plugins/sirsoft-sso/admin/policy', [$key => $value]);
            $response->assertStatus(403);
            $this->assertSame('super_only', $response->json('errors.code'), $key);
        }

        $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['session_max_minutes' => 480, 'attempts_retention_days' => 30])->assertOk();
        $policy = app(PolicyService::class)->current();
        $this->assertSame(480, $policy['session_max_minutes']);
        $this->assertSame(30, $policy['attempts_retention_days']);
    }

    /**
     * 범위 밖 값과 없는 역할, 없는 회원은 422 인지 확인한다.
     *
     * @scenario value=out_of_range|unknown_role|unknown_user
     *
     * @effects validation_422
     *
     * @return void 반환값 없음
     */
    public function test_out_of_range_values(): void
    {
        Sanctum::actingAs($this->makeSuper());

        $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['session_max_minutes' => 99999])->assertStatus(422)->assertJsonValidationErrors('session_max_minutes');
        $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['enforce_roles' => ['no-such-role-798']])->assertStatus(422)->assertJsonValidationErrors('enforce_roles.0');
        $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['emergency_user_ids' => [999999999]])->assertStatus(422)->assertJsonValidationErrors('emergency_user_ids.0');
    }
}
