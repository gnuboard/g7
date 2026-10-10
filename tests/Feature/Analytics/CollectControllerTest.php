<?php

namespace Tests\Feature\Analytics;

use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\CollectFailureReason;
use App\Enums\Analytics\VisitorType;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsVisit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 비콘 수집 API — 응답 계약과 실패 처리.
 *
 * 이 경로의 응답은 **언제나 204** 다. 검증 실패든 적재 오류든 마찬가지다 —
 * 비콘에 4xx·5xx 를 주면 방문자 브라우저 콘솔에 오류가 뜨고, 그것을 본 운영자는
 * 사이트가 고장 났다고 판단한다. 통계를 못 남긴 것이 사이트 장애로 보여선 안 된다.
 */
class CollectControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 유효한 비콘이 204 와 함께 방문을 남긴다.
     *
     * @scenario source=beacon, visit_state=new
     *
     * @effects first_request_creates_visit_and_action
     *
     * @return void 없음
     */
    public function test_valid_beacon_returns_204_and_records_visit(): void
    {
        $response = $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/board/free',
            'title' => '자유게시판',
            'res' => '1920x1080',
            'init' => true,
        ]);

        $response->assertNoContent();
        $this->assertSame(1, AnalyticsVisit::count());
    }

    /**
     * 첫 방문에 방문자 쿠키를 심는다.
     *
     * 서버가 심어야 한다 — 브라우저에서 document.cookie 로 쓰면 동의 관리 플러그인의
     * 쿠키 차단기가 조용히 버린다.
     *
     * @scenario mode=full, visitor=guest
     *
     * @effects full_mode_stores_masked_ip_and_issues_cookie
     *
     * @return void 없음
     */
    public function test_issues_visitor_cookie_on_first_visit(): void
    {
        $response = $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/',
        ]);

        $response->assertNoContent();
        $response->assertCookie(config('analytics.cookies.visitor', 'g7_vid'));
    }

    /**
     * 형식이 어긋난 페이로드도 204 로 돌려주고 사유만 남긴다.
     *
     * @scenario source=beacon, exclusion=none
     *
     * @effects failures_are_recorded_with_a_reason, tracking_never_throws_to_the_caller
     *
     * @return void 없음
     */
    public function test_invalid_payload_still_returns_204_and_logs_failure(): void
    {
        $response = $this->postJson('/api/analytics/collect', [
            't' => 'not-a-real-type',
            'url' => 'https://example.test/',
        ]);

        $response->assertNoContent();
        $this->assertSame(0, AnalyticsVisit::count(), '잘못된 요청은 원장에 들어가지 않는다');
        $this->assertSame(
            1,
            AnalyticsCollectFailure::where('reason', CollectFailureReason::Validation->value)->count(),
            '왜 버렸는지는 남아야 한다',
        );
    }

    /**
     * 필수 값이 없어도 204 다.
     *
     * @scenario source=beacon, exclusion=none
     *
     * @effects failures_are_recorded_with_a_reason
     *
     * @return void 없음
     */
    public function test_missing_required_fields_returns_204(): void
    {
        $this->postJson('/api/analytics/collect', [])->assertNoContent();

        $this->assertSame(1, AnalyticsCollectFailure::count());
    }

    /**
     * 수집이 꺼져 있으면 아무것도 남기지 않는다.
     *
     * 실패 로그조차 남기지 않는다 — 끈 것은 결함이 아니라 운영자의 결정이다.
     *
     * @scenario exclusion=disabled
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_disabled_collection_records_nothing(): void
    {
        config(['g7_settings.core.analytics.enabled' => false]);

        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/',
        ])->assertNoContent();

        $this->assertSame(0, AnalyticsVisit::count());
        $this->assertSame(0, AnalyticsCollectFailure::count());
    }

    /**
     * 적재는 언제나 훅을 거친다.
     *
     * 훅은 확장이 같은 자리에 끼어드는 이음매다. 구독 리스너는 `sync => true` 라 큐를
     * 타지 않고 발화 자리에서 도는데(#682 S3 라운드 6), 그 사실은
     * `CollectionDispatchTest` 가 잡 수로 따로 잠근다 — 여기서는 **훅이 발화했는지**만 본다.
     *
     * @scenario dispatch=sync, source=beacon
     *
     * @effects first_request_creates_visit_and_action
     *
     * @return void 없음
     */
    public function test_collect_hands_off_through_hook(): void
    {
        $received = [];
        HookManager::addAction(
            'core.analytics.tracking_request_received',
            function (array $payload) use (&$received): void {
                $received[] = $payload;
            },
        );

        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/',
        ])->assertNoContent();

        $this->assertCount(1, $received, '적재는 훅을 거쳐야 한다 — 확장이 끼어드는 자리다');
        $this->assertSame('https://example.test/', $received[0]['url']);
    }

    /**
     * 첫 방문에 방문자 쿠키를 심는다.
     *
     * 쿠키는 적재 결과물이 아니라 **방문자 식별의 전제**다. 식별을 훅 뒤로 미루면
     * 구독자가 방문자 키를 새로 만들어 응답에 심은 쿠키와 어긋나고, 그러면 매 비콘이
     * 새 방문으로 잡혀 재방문이 항상 0 이 된다 — 화면에는 숫자가 보이므로 아무 증상이
     * 없고, 응답도 정상 204 다.
     *
     * @scenario source=beacon, visit_state=new
     *
     * @effects full_mode_stores_masked_ip_and_issues_cookie
     *
     * @return void 없음
     */
    public function test_collect_still_issues_visitor_cookie(): void
    {
        $response = $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/',
        ]);

        $response->assertNoContent();
        $response->assertCookie(config('analytics.cookies.visitor', 'g7_vid'));
    }

    /**
     * 제외 대상 경로는 원장에 들어가지 않는다.
     *
     * @scenario exclusion=url_pattern
     *
     * @effects excluded_requests_record_nothing, each_exclusion_reason_is_counted_separately
     *
     * @return void 없음
     */
    public function test_excluded_url_is_not_recorded(): void
    {
        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/admin/users',
        ])->assertNoContent();

        $this->assertSame(0, AnalyticsVisit::count());
    }

    /**
     * 내려받기 액션을 받는다.
     *
     * @scenario source=beacon, visit_state=new
     *
     * @effects download_and_outlink_actions_store_target_in_dictionary
     *
     * @return void 없음
     */
    public function test_accepts_download_action(): void
    {
        $this->postJson('/api/analytics/collect', [
            't' => 'download',
            'url' => 'https://example.test/board/free',
            'target' => 'https://example.test/files/report.pdf',
            'name' => 'report.pdf',
        ])->assertNoContent();

        $this->assertSame(1, AnalyticsVisit::count());
    }

    /**
     * 익명 모드는 204 지만 쿠키를 심지 않는다.
     *
     * @scenario mode=anonymous, source=beacon
     *
     * @effects anonymous_mode_issues_no_cookie
     *
     * @return void 없음
     */
    public function test_anonymous_mode_issues_no_cookie(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'anonymous']);

        $response = $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/',
            'res' => '1920x1080',
        ]);

        $response->assertNoContent();
        $response->assertCookieMissing(config('analytics.cookies.visitor', 'g7_vid'));
        $this->assertSame(1, AnalyticsVisit::count());
        $this->assertSame('config', AnalyticsVisit::first()->visitor_key_type);
    }

    /**
     * 분당 120회를 넘으면 429 다.
     *
     * 수집을 끈 상태로 두드려 원장 없이 스로틀만 본다.
     *
     * @scenario source=beacon
     *
     * @effects tracking_never_throws_to_the_caller
     *
     * @return void 없음
     */
    public function test_throttles_after_120_requests_per_minute(): void
    {
        config(['g7_settings.core.analytics.enabled' => false]);

        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'https://example.test/'])->assertNoContent();
        }

        $this->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'https://example.test/'])->assertStatus(429);
    }

    /**
     * 토큰이 있으면 회원·관리자로, 없으면 비회원으로 센다.
     *
     * @scenario source=beacon, visitor=guest|member|admin
     *
     * @effects first_request_creates_visit_and_action
     *
     * @return void 없음
     */
    public function test_optional_sanctum_sets_visitor_type(): void
    {
        $this->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'https://example.test/'])->assertNoContent();
        $this->assertSame(VisitorType::Guest, AnalyticsVisit::latest('id')->first()->visitor_type);

        $member = User::factory()->create();
        Sanctum::actingAs($member, ['*'], 'sanctum');
        $this->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'https://example.test/m'])->assertNoContent();
        $memberVisit = AnalyticsVisit::latest('id')->first();
        $this->assertSame(VisitorType::Member, $memberVisit->visitor_type);
        $this->assertSame($member->id, $memberVisit->user_id);

        $adminUser = User::factory()->create();
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']), 'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator'])],
        );
        $adminUser->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($adminUser->fresh(), ['*'], 'sanctum');
        $this->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'https://example.test/a'])->assertNoContent();
        $this->assertSame(VisitorType::Admin, AnalyticsVisit::latest('id')->first()->visitor_type);
    }

    /**
     * 공개 비콘으로는 사이트 검색 액션을 만들 수 없다.
     *
     * 결과 건수는 서버만 아는 값이다. 브라우저가 보낼 수 있으면 누구나 인기 검색어를
     * 위조한다 — 검증 실패로 버리고 사유만 남긴다.
     *
     * @scenario source=beacon, exclusion=none
     *
     * @effects beacon_cannot_forge_site_search, failures_are_recorded_with_a_reason
     *
     * @return void 없음
     */
    public function test_beacon_cannot_forge_site_search(): void
    {
        $this->postJson('/api/analytics/collect', [
            't' => 'site_search',
            'url' => 'https://example.test/search',
            'q' => '위조 검색어',
            'total' => 9999,
        ])->assertNoContent();

        $this->assertSame(0, AnalyticsAction::where('action_type', ActionType::SiteSearch->value)->count());
        $this->assertSame(0, AnalyticsVisit::count());
        $this->assertSame(1, AnalyticsCollectFailure::where('reason', CollectFailureReason::Validation->value)->count());

        // 페이지뷰에 검색 필드를 끼워 보내도 무시된다.
        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/search',
            'q' => '위조 검색어',
            'total' => 9999,
        ])->assertNoContent();

        $this->assertSame(0, AnalyticsAction::where('action_type', ActionType::SiteSearch->value)->count());
        $this->assertNull(AnalyticsAction::first()->search_result_count);
    }

    /**
     * URL 계열 필드는 실제 URL 이어야 한다.
     *
     * @scenario source=beacon, exclusion=none
     *
     * @effects failures_are_recorded_with_a_reason
     *
     * @return void 없음
     */
    public function test_url_fields_must_be_urls(): void
    {
        $this->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'not a url'])->assertNoContent();
        $this->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'https://example.test/', 'ref' => 'javascript:alert(1)'])->assertNoContent();
        $this->postJson('/api/analytics/collect', ['t' => 'outlink', 'url' => 'https://example.test/', 'target' => 'nope'])->assertNoContent();

        $this->assertSame(0, AnalyticsVisit::count());
        $this->assertSame(3, AnalyticsCollectFailure::where('reason', CollectFailureReason::Validation->value)->count());
    }
}
