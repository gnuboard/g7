<?php

namespace Tests\Feature\Analytics\Api;

use App\Analytics\AnalyticsClock;
use App\Models\Analytics\AnalyticsArchiveRun;
use Illuminate\Support\Facades\Cache;

/**
 * 화면 열 때 집계 미들웨어 — 어느 라우트가 집계를 일으키고 신선도를 싣는가.
 *
 * 집계 표를 읽는 조회 라우트만 집계를 일으켜야 한다. 원장을 직접 읽는 화면·공개 방문자 카운터·권한 없는
 * 요청이 집계를 일으키면 방문자 한 명 한 명이 서버에 집계를 시키게 된다.
 */
class ArchiveOnViewMiddlewareTest extends AnalyticsApiTestCase
{
    /**
     * 테스트 준비 — 바탕 클래스가 끈 화면 열 때 집계를 다시 켠다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['g7_settings.core.analytics.archive_on_view_enabled' => true]);
    }

    /**
     * 집계 조회 라우트 6개가 read 권한자 요청에 집계를 돌리고 `data.freshness` 를 싣는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=aggregate_reads, role=read
     *
     * @effects aggregate_routes_run_on_view_and_carry_freshness
     */
    public function test_aggregate_routes_run_on_view_and_carry_freshness(): void
    {
        $admin = $this->readAdmin();
        $this->visit();

        foreach ([
            '/api/admin/analytics/overview?preset=today',
            '/api/admin/analytics/series?preset=today',
            '/api/admin/analytics/patterns?preset=today',
            '/api/admin/analytics/bots?preset=today',
            '/api/admin/analytics/reports/page_url?preset=today',
            '/api/admin/analytics/reports/page_url/series?preset=today&label=example.test/',
        ] as $uri) {
            // 1분 문을 매번 열어 각 라우트가 스스로 집계를 일으키는지 본다.
            AnalyticsArchiveRun::query()->delete();
            Cache::lock('analytics:archive:on_view:gate')->forceRelease();

            $response = $this->actingAs($admin)->getJson($uri)->assertOk();

            $this->assertSame('ran', $response->json('data.freshness.on_view.status'), $uri);
            $this->assertNotNull($response->json('data.freshness.last_run_at'), $uri);
            $this->assertSame(1, AnalyticsArchiveRun::query()->where('trigger', 'on_view')->where('run_type', 'hour')->count(), $uri);
        }
    }

    /**
     * 원장을 직접 읽는 라우트·공개 요약은 집계를 일으키지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=ledger_reads, role=privacy
     *
     * @effects ledger_routes_do_not_run_on_view
     */
    public function test_ledger_routes_and_public_summary_do_not_run_on_view(): void
    {
        $admin = $this->privacyAdmin();
        $this->visit();

        foreach ([
            '/api/admin/analytics/realtime',
            '/api/admin/analytics/visitors?preset=today',
            '/api/admin/analytics/referrer-urls?preset=today&host=example.test',
            '/api/admin/analytics/registered',
            '/api/analytics/summary',
        ] as $uri) {
            $response = $this->actingAs($admin)->getJson($uri);
            $this->assertLessThan(500, $response->status(), $uri);
            $this->assertNull($response->json('data.freshness'), $uri);
        }

        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 권한 없는 관리자의 요청은 403 이고 집계를 일으키지 않는지 확인합니다(권한 미들웨어 뒤).
     *
     * @return void 없음
     *
     * @scenario endpoint=aggregate_reads, role=none
     *
     * @effects forbidden_request_does_not_run_on_view
     */
    public function test_forbidden_request_does_not_run_on_view(): void
    {
        $this->visit();

        $this->actingAs($this->adminWith([]))
            ->getJson('/api/admin/analytics/reports/page_url?preset=today')
            ->assertForbidden();

        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }

    /**
     * CSV 내려받기는 신선도를 싣지 않고 깨지지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, format=csv
     *
     * @effects csv_responses_are_left_untouched
     */
    public function test_csv_response_is_untouched(): void
    {
        $this->visit();

        $response = $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/reports/page_url?preset=today&format=csv')
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('freshness', $response->streamedContent());
    }

    /**
     * 봇 화면 요청 하나는 집계를 한 번만 일으키는지 확인합니다(내부에서 목록을 다시 부르지만 미들웨어는 한 번).
     *
     * @return void 없음
     *
     * @scenario endpoint=bots, role=read
     *
     * @effects bots_request_runs_on_view_once
     */
    public function test_bots_request_runs_on_view_once(): void
    {
        $this->visit();

        $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/bots?preset=today')->assertOk();

        $this->assertSame(1, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 설정에서 끄면 집계는 돌지 않고 신선도에 꺼짐이 실리는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, on_view=disabled
     *
     * @effects on_view_disabled_does_nothing
     */
    public function test_disabled_setting_reports_disabled(): void
    {
        config(['g7_settings.core.analytics.archive_on_view_enabled' => false]);
        $this->visit(['visit_date' => AnalyticsClock::today()]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/page_url?preset=today')
            ->assertOk();

        $this->assertSame('disabled', $response->json('data.freshness.on_view.status'));
        $this->assertFalse($response->json('data.freshness.on_view.enabled'));
        $this->assertTrue($response->json('data.freshness.stale'), '집계가 한 번도 없는데 방문이 있으면 멈춘 것');
        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }
}
