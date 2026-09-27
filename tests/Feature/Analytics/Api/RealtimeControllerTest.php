<?php

namespace Tests\Feature\Analytics\Api;

use App\Events\GenericBroadcastEvent;
use App\Models\User;
use Illuminate\Support\Facades\Event;

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
     * 지금 보고 있는 페이지가 리포트와 같은 읽히는 모양으로 나가는지 확인합니다.
     *
     * 리포트는 사이트 호스트를 떼고 인코딩을 푸는데 실시간만 원문(호스트·%XX)이면 같은 페이지가 두 모양이 된다.
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=read, period=valid
     *
     * @effects page_labels_are_readable_for_display
     */
    public function test_current_page_is_readable_for_display(): void
    {
        config(['g7_settings.core.general.site_url' => 'https://example.test']);
        $name = $this->actionName('url', 'example.test/%EA%B2%8C%EC%8B%9C%ED%8C%90/free');
        $this->visit(['exit_action_name_id' => $name->id]);

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/realtime');

        $response->assertOk();
        $this->assertSame('/게시판/free', $response->json('data.visitors.0.page'));
    }

    /**
     * 현재 접속자 행이 국가·기기 코드에 사람이 읽을 이름을 붙이는지 확인합니다(#682 S4 라운드 8).
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=read, period=valid
     *
     * @effects stored_codes_are_shown_as_human_labels
     */
    public function test_realtime_rows_carry_human_names_for_codes(): void
    {
        $this->visit(['country_code' => 'KR', 'device_type' => 'mobile']);

        $row = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/realtime')->assertOk()->json('data.visitors.0');

        $this->assertSame('KR', $row['country_code']);
        $this->assertNotSame('KR', $row['country_name'] ?? 'KR', '국가 이름');
        $this->assertSame(__('analytics.device_type.mobile'), $row['device_type_label'] ?? null);
        $this->assertNotSame('mobile', $row['device_type_label']);
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

    /**
     * 회원 방문에 이름·uuid 가 실려 회원 화면으로 건너뛸 수 있는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=privacy
     *
     * @effects member_jump_is_gated_by_destination_permission
     */
    public function test_member_visits_carry_a_name_and_uuid_for_the_jump(): void
    {
        // 계획서 §4.10 은 현재 접속자 표에 「privacy 권한이면 IP 열 + 프로필 링크」를 적었다.
        // 이름·uuid 가 응답에 없으면 화면이 링크를 만들 수 없어, 지금 접속 중인 회원을 찾아도
        // 회원 목록에서 다시 뒤져야 한다(#682 S3 라운드 6).
        $member = User::factory()->create(['name' => '지금 보고 있는 회원']);
        $this->visit(['user_id' => $member->id, 'visitor_type' => 'member']);

        $row = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/realtime')
            ->assertOk()
            ->json('data.visitors.0');

        $this->assertNotNull($row['user'] ?? null, '회원 방문에 회원이 붙어야 한다');
        $this->assertSame('지금 보고 있는 회원', $row['user']['name']);
        $this->assertSame((string) $member->uuid, $row['user']['uuid'], 'uuid 가 없으면 회원 화면으로 갈 수 없다');
    }

    /**
     * 조회 권한만으로는 회원 신원이 나가지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=read
     *
     * @effects realtime_hides_addresses_without_the_privacy_permission
     */
    public function test_member_identity_is_hidden_without_the_privacy_permission(): void
    {
        // 이름은 주소와 같은 무게의 신원이다 — 주소를 가리는 권한 경계에서 함께 가린다.
        $member = User::factory()->create(['name' => '가려야 할 회원']);
        $this->visit(['user_id' => $member->id, 'visitor_type' => 'member']);

        $row = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/realtime')
            ->assertOk()
            ->json('data.visitors.0');

        $this->assertNull($row['user'] ?? null, '조회 권한만으로는 회원 이름이 나가면 안 된다');
    }

    /**
     * 현재 접속자 방송은 정해진 채널·이벤트로 나가고, 주소와 회원 신원을 싣지 않는다.
     *
     * 방송 채널은 통계 조회 권한만으로 구독된다. 방송에 주소나 회원 이름이 실리면 API 가
     * 개인정보 권한으로 가린 값이 소켓으로 새어 나간다.
     *
     * @return void 없음
     *
     * @scenario endpoint=realtime, role=read
     *
     * @effects realtime_broadcast_carries_no_address_or_identity
     */
    public function test_realtime_broadcast_carries_no_address_or_identity(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => 'localhost']);
        Event::fake([GenericBroadcastEvent::class]);

        $member = User::factory()->create(['name' => '가려야 할 회원']);
        $this->visit(['ip' => '203.0.113.50', 'user_id' => $member->id, 'visitor_type' => 'member']);

        $this->artisan('analytics:broadcast-realtime')->assertSuccessful();

        $events = Event::dispatched(GenericBroadcastEvent::class, fn (GenericBroadcastEvent $event): bool => $event->eventName === 'analytics.realtime.updated');
        $this->assertCount(1, $events, '현재 접속자 방송이 한 번 나가야 한다');

        $event = $events->first()[0];
        $this->assertSame('core.admin.analytics', $event->channel);
        $this->assertSame(1, $event->payload['data']['count'] ?? null);

        $payload = json_encode($event->payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('203.0.113.50', $payload, '방송에 방문자 주소가 실렸다');
        $this->assertStringNotContainsString('가려야 할 회원', $payload, '방송에 회원 이름이 실렸다');
    }
}
