<?php

namespace Tests\Feature\Analytics\Api;

/**
 * 현재 접속자 API — 주소가 권한에 따라 갈리는 자리.
 *
 * 한 화면 안에서 두 권한이 갈린다. 목록은 통계 조회 권한으로 보이되, 그 IP 는 개인정보
 * 권한이 있어야 채워진다 — 이 갈림이 무너지면 조회 권한만 준 계정이 방문자 주소를 본다.
 *
 * @scenario endpoint=realtime, role=read
 */
class RealtimeControllerTest extends AnalyticsApiTestCase
{
    /**
     * 개인정보 권한이 없으면 주소가 비는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=read
     *
     * @effects realtime_hides_addresses_without_the_privacy_permission
     */
    public function test_addresses_are_hidden_without_the_privacy_permission(): void
    {
        $this->visit(['ip' => '203.0.113.50']);

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/realtime');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.count'));
        $this->assertNull(
            $response->json('data.visitors.0.ip'),
            '조회 권한만 준 계정이 방문자 주소를 보면 권한을 나눈 뜻이 사라진다',
        );
    }

    /**
     * 개인정보 권한이 있으면 주소가 채워지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=privacy
     *
     * @effects realtime_shows_addresses_with_the_privacy_permission
     */
    public function test_addresses_are_shown_with_the_privacy_permission(): void
    {
        $this->visit(['ip' => '203.0.113.50']);

        $response = $this->actingAs($this->privacyAdmin())->getJson('/api/admin/analytics/realtime');

        $response->assertOk();
        $this->assertSame('203.0.113.50', $response->json('data.visitors.0.ip'));
    }

    /**
     * 방문자 키가 앞자리만 나가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=read
     *
     * @effects realtime_hides_addresses_without_the_privacy_permission
     */
    public function test_only_a_prefix_of_the_visitor_key_is_exposed(): void
    {
        $key = str_repeat('c', 32);
        $this->visit(['visitor_key' => $key]);

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/realtime');

        $response->assertOk();
        $this->assertSame(substr($key, 0, 8), $response->json('data.visitors.0.visitor_key'));
    }

    /**
     * 구간을 벗어난 방문이 빠지고 범위 밖 값이 거절되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=read, period=too_long
     *
     * @effects realtime_hides_addresses_without_the_privacy_permission
     */
    public function test_window_bounds_are_enforced(): void
    {
        $this->visit(['last_action_at' => now()->subHours(3)]);

        $admin = $this->readAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/analytics/realtime?minutes=5');
        $response->assertOk();
        $this->assertSame(0, $response->json('data.count'), '세 시간 전 방문은 「지금」이 아니다');

        $this->actingAs($admin)->getJson('/api/admin/analytics/realtime?minutes=999')->assertStatus(422);
    }
}
