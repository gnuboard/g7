<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 관리자 「이력」 API — 로그인 시도와 SCIM 수신 목록, 필터, 권한.
 *
 * @since 1.0.0
 */
class AdminLogsApiTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 로그인 시도 목록이 IdP, 결과로 걸러지는지 확인한다.
     *
     * @scenario log=attempts, filter=idp_id|outcome
     *
     * @effects filtered_rows, newest_first
     *
     * @return void 반환값 없음
     */
    public function test_attempt_filters(): void
    {
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.logs.view']));
        $a = $this->makeIdp('corp');
        $b = $this->makeIdp('partner', ['owned_domains' => ['partner798.test']]);
        foreach ([[$a, 'handoff'], [$a, 'failed'], [$b, 'failed']] as [$idp, $outcome]) {
            SsoLoginAttempt::query()->create(['idp_id' => $idp->id, 'intent' => 'login', 'guard' => 'user', 'outcome' => $outcome, 'reason_code' => $outcome === 'failed' ? 'token_error' : null, 'created_at' => now()]);
        }

        $this->assertSame(2, $this->getJson('/api/plugins/sirsoft-sso/admin/logs/attempts?idp_id='.$a->id)->assertOk()->json('data.pagination.total'));
        $failed = $this->getJson('/api/plugins/sirsoft-sso/admin/logs/attempts?outcome=failed')->assertOk()->json('data');
        $this->assertSame(2, $failed['pagination']['total']);
        $this->assertSame('token_error', $failed['data'][0]['reason_code']);
    }

    /**
     * SCIM 이력이 상태 구분으로 걸러지고, 권한 없으면 403 인지 확인한다.
     *
     * @scenario log=scim, status_class=error, actor=no_permission
     *
     * @effects scim_rows, permission_403
     *
     * @return void 반환값 없음
     */
    public function test_scim_list_and_permission(): void
    {
        $idp = $this->makeIdp('corp');
        DB::table('sso_scim_events')->insert([
            ['idp_id' => $idp->id, 'method' => 'POST', 'resource' => 'Users', 'target_id' => 'u1', 'status' => 201, 'error_code' => null, 'created_at' => now()],
            ['idp_id' => $idp->id, 'method' => 'PATCH', 'resource' => 'Users', 'target_id' => 'u2', 'status' => 401, 'error_code' => 'invalid_token', 'created_at' => now()],
        ]);

        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.logs.view']));
        $errors = $this->getJson('/api/plugins/sirsoft-sso/admin/logs/scim?status_class=error')->assertOk()->json('data');
        $this->assertSame(1, $errors['pagination']['total']);
        $this->assertSame('invalid_token', $errors['data'][0]['error_code']);

        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view']));
        $this->getJson('/api/plugins/sirsoft-sso/admin/logs/attempts')->assertStatus(403);
    }
}
