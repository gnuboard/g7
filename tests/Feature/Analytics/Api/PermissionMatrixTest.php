<?php

namespace Tests\Feature\Analytics\Api;

/**
 * 권한 매트릭스 — 권한 셋이 실제로 갈리는지.
 *
 * 화면은 권한이 없는 메뉴를 숨기지만, 그것은 게이트가 아니다. 주소를 직접 치는 경로가
 * 남으므로 실질 차단은 라우트 미들웨어이고, 여기서 그 갈림을 전수로 잠근다.
 */
class PermissionMatrixTest extends AnalyticsApiTestCase
{
    /**
     * 조회 권한으로 열리는 경로 목록입니다.
     *
     * @return array<int, array{0: string, 1: string}> 메서드와 경로
     */
    private function readEndpoints(): array
    {
        return [
            ['get', '/api/admin/analytics/overview'],
            ['get', '/api/admin/analytics/series'],
            ['get', '/api/admin/analytics/patterns'],
            ['get', '/api/admin/analytics/bots'],
            ['get', '/api/admin/analytics/realtime'],
            ['get', '/api/admin/analytics/diagnostics'],
            ['get', '/api/admin/analytics/collect-failures'],
            ['get', '/api/admin/analytics/archive-runs'],
            ['get', '/api/admin/analytics/data'],
            ['get', '/api/admin/analytics/reports/page_url'],
        ];
    }

    /**
     * 로그인하지 않으면 전부 막히는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=none
     *
     * @effects unauthenticated_requests_are_rejected
     */
    public function test_unauthenticated_requests_are_rejected(): void
    {
        foreach ($this->readEndpoints() as [$method, $path]) {
            $this->{$method.'Json'}($path)->assertStatus(401);
        }

        $this->putJson('/api/admin/analytics/settings', [])->assertStatus(401);
        $this->getJson('/api/admin/analytics/visitors?user_id=1')->assertStatus(401);
    }

    /**
     * 조회 권한만 있으면 조회 경로가 열리는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read
     *
     * @effects read_permission_cannot_reach_update_endpoints
     */
    public function test_read_permission_opens_the_read_endpoints(): void
    {
        $admin = $this->readAdmin();

        foreach ($this->readEndpoints() as [$method, $path]) {
            $this->actingAs($admin)->{$method.'Json'}($path)->assertOk();
        }
    }

    /**
     * 조회 권한으로는 상태를 바꾸지 못하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=read
     *
     * @effects read_permission_cannot_reach_update_endpoints
     */
    public function test_read_permission_cannot_change_state(): void
    {
        $admin = $this->readAdmin();

        $this->actingAs($admin)->getJson('/api/admin/analytics/settings')->assertStatus(403);
        $this->actingAs($admin)->putJson('/api/admin/analytics/settings', ['retention_days' => 30])->assertStatus(403);
        $this->actingAs($admin)->deleteJson('/api/admin/analytics/data', ['confirm' => true])->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/analytics/archive-runs', ['run_type' => 'hour'])->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/analytics/cloud-ranges/refresh')->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/analytics/opt-out')->assertStatus(403);
    }

    /**
     * 조회 권한으로는 개별 방문자를 보지 못하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=read
     *
     * @effects read_permission_cannot_reach_privacy_endpoints
     */
    public function test_read_permission_cannot_reach_visitor_records(): void
    {
        $admin = $this->readAdmin();

        $this->actingAs($admin)->getJson('/api/admin/analytics/visitors?user_id=1')->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/admin/analytics/privacy/subjects?user_id=1')->assertStatus(403);
        $this->actingAs($admin)
            ->postJson('/api/admin/analytics/privacy/subjects/export', ['user_id' => 1])
            ->assertStatus(403);
        $this->actingAs($admin)
            ->deleteJson('/api/admin/analytics/privacy/subjects', ['user_id' => 1, 'confirm' => true])
            ->assertStatus(403);
    }

    /**
     * 설정 권한으로 설정을 저장할 수 있는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects update_permission_can_save_settings
     */
    public function test_update_permission_can_save_settings(): void
    {
        $this->actingAs($this->updateAdmin())
            ->putJson('/api/admin/analytics/settings', ['retention_days' => 30])
            ->assertOk();
    }

    /**
     * 개인정보 권한으로 방문자 기록을 볼 수 있는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects privacy_permission_can_read_visitor_profiles
     */
    public function test_privacy_permission_can_read_visitor_profiles(): void
    {
        $this->visit(['user_id' => 55]);

        $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/visitors?user_id=55')
            ->assertOk();
    }
}
