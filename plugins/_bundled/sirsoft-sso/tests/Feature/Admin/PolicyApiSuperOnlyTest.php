<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Plugin;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 정책 저장 경로(W38) — 플러그인 API 만 정책을 바꾼다. 코어 플러그인 설정 저장 경로로는 같은 값을 우회 저장할 수 없다.
 *
 * @since 1.0.0
 */
class PolicyApiSuperOnlyTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 설정 스키마가 비어 있어 코어 설정 경로가 정책 키를 받지 않는지 확인한다.
     *
     * @scenario path=core_settings, key=enforce_site
     *
     * @effects schema_empty, policy_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_core_settings_path_cannot_store_policy(): void
    {
        $this->assertSame([], (new Plugin)->getSettingsSchema());

        $super = $this->makeSuper();
        Sanctum::actingAs($super);
        $this->putJson('/api/admin/plugins/sirsoft-sso/settings', ['enforce_site' => true, 'emergency_user_ids' => []]);

        $policy = app(PolicyService::class)->current();
        $this->assertFalse($policy['enforce_site']);
    }

    /**
     * 위임 관리자는 최고관리자 전용 칸을 바꿀 수 없고, 같은 값을 다시 보내는 것은 허용되는지 확인한다.
     *
     * @scenario actor=delegate, value=unchanged|changed
     *
     * @effects unchanged_resend_ok, change_403
     *
     * @return void 반환값 없음
     */
    public function test_delegate_can_resend_unchanged_values(): void
    {
        $super = $this->makeSuper();
        $this->setPolicy(['emergency_user_ids' => [$super->id]]);
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.settings.view', 'sirsoft-sso.settings.update']));

        $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['enforce_site' => false, 'emergency_user_ids' => [$super->id], 'enforce_linked' => true])->assertOk();
        $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['enforce_site' => true])->assertStatus(403);
        $this->assertTrue(app(PolicyService::class)->current()['enforce_linked']);
    }
}
