<?php

namespace Tests\Feature\Analytics;

use App\Analytics\OnViewOutcome;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\ArchiveFreshnessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 집계 신선도 — 「숫자가 언제 기준인가」와 「스케줄러가 도는가」.
 *
 * 스케줄러 판정이 틀리면 cron 이 멀쩡한 사이트에 경고가 뜨거나(오경보), cron 이 없는 사이트에 경고가
 * 안 뜬다(제보 원인 그대로). 경계(119·121분)와 근거 부재(설치 직후)를 잠근다.
 */
class ArchiveFreshnessServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 표준시를 서울로 두어 사이트 표준시 변환까지 본다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'Asia/Seoul']);

        // 신선도 블록은 화면 열 때 집계 설정(켜짐·오늘 방문 상한)을 그대로 싣는다 — 개발 PC 가 관리자 화면에서
        // 저장한 설정(storage/app/settings/analytics.json)이 부팅 때 스며들면 기대값이 흔들리므로 기본값으로 고정한다.
        config([
            'g7_settings.core.analytics.archive_on_view_enabled' => true,
            'g7_settings.core.analytics.archive_on_view_max_visits' => 50000,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-28 12:00:00', 'UTC'));
    }

    /**
     * 테스트 정리 — 고정 시각 해제.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 서비스를 꺼냅니다.
     *
     * @return ArchiveFreshnessService 신선도 계산기
     */
    private function service(): ArchiveFreshnessService
    {
        return app(ArchiveFreshnessService::class);
    }

    /**
     * 실행 이력 한 행을 만듭니다.
     *
     * @param  string  $trigger  실행 주체
     * @param  int  $minutesAgo  몇 분 전에 시작했는지
     * @param  string  $status  상태
     * @param  string  $runType  실행 종류
     * @return AnalyticsArchiveRun 만들어진 행
     */
    private function runRow(string $trigger, int $minutesAgo, string $status = 'success', string $runType = 'hour'): AnalyticsArchiveRun
    {
        $started = Carbon::now('UTC')->subMinutes($minutesAgo);

        return AnalyticsArchiveRun::factory()->create([
            'run_type' => $runType,
            'trigger' => $trigger,
            'status' => $status,
            'started_at' => $started,
            'finished_at' => $status === ArchiveRunStatus::Running->value ? null : $started->copy()->addSeconds(30),
        ]);
    }

    /**
     * 예약 실행이 119분 전이면 살아 있고, 121분 전이면 멈춘 것으로 보는지 확인합니다(실패로 끝났어도 스케줄러는 돈 것).
     *
     * @return void 없음
     *
     * @scenario scheduler=alive_boundary
     *
     * @effects scheduler_alive_is_judged_by_scheduled_runs_within_120_minutes
     */
    public function test_scheduler_alive_boundary(): void
    {
        $this->runRow('scheduled', 119, ArchiveRunStatus::Failed->value);
        $this->assertTrue($this->service()->schedulerStatus()['alive']);

        AnalyticsArchiveRun::query()->delete();
        $this->runRow('scheduled', 121);
        $status = $this->service()->schedulerStatus();
        $this->assertFalse($status['alive']);
        // 사이트 표준시(서울, +9)로 낸다 — 09:59 UTC → 18:59
        $this->assertSame('2026-09-28 18:59:00', $status['last_run_at']);
    }

    /**
     * 손으로 돌린 실행·화면 열 때 집계는 스케줄러 생존 근거가 아닌지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario scheduler=manual_only
     *
     * @effects manual_runs_do_not_count_as_scheduler
     */
    public function test_manual_and_on_view_runs_do_not_prove_scheduler(): void
    {
        $this->runRow('manual', 200);
        $this->runRow('on_view', 1);

        $this->assertFalse($this->service()->schedulerStatus()['alive']);
    }

    /**
     * 판정 근거가 없거나(원장·이력 0) 쌓인 지 120분이 안 됐으면 모름(null)인지 확인합니다 — 설치 직후 오경보 방지.
     *
     * @return void 없음
     *
     * @scenario scheduler=unknown
     *
     * @effects fresh_install_does_not_warn
     */
    public function test_scheduler_is_unknown_without_enough_basis(): void
    {
        $this->assertNull($this->service()->schedulerStatus()['alive']);

        AnalyticsVisit::factory()->create(['first_action_at' => Carbon::now('UTC')->subMinutes(30)]);
        $this->assertNull($this->service()->schedulerStatus()['alive']);

        AnalyticsVisit::query()->update(['first_action_at' => Carbon::now('UTC')->subMinutes(130)]);
        $this->assertFalse($this->service()->schedulerStatus()['alive']);
    }

    /**
     * 신선도 블록의 마지막 집계·지연·멈춤 판정과 화면 열 때 집계 결과를 확인합니다.
     *
     * @return void 없음
     *
     * @scenario freshness=describe
     *
     * @effects freshness_block_reports_last_run_and_staleness
     */
    public function test_describe_reports_last_run_lag_and_stale(): void
    {
        // 한 번도 집계하지 않았는데 방문이 있으면 멈춘 것이다.
        AnalyticsVisit::factory()->create(['first_action_at' => Carbon::now('UTC')->subMinutes(10)]);
        $empty = $this->service()->describe();
        $this->assertNull($empty['last_run_at']);
        $this->assertNull($empty['lag_minutes']);
        $this->assertTrue($empty['stale']);

        $this->runRow('scheduled', 125, runType: ArchiveRunType::Day->value);
        $this->runRow('on_view', 3);

        $outcome = new OnViewOutcome(OnViewOutcome::RAN, 12, 50000);
        $block = $this->service()->describe($outcome);

        // 늦게 끝난 쪽(3분 전 시작 + 30초)을 고른다 — 사이트 표준시로
        $this->assertSame('2026-09-28 20:57:30', $block['last_run_at']);
        $this->assertSame('on_view', $block['last_run_trigger']);
        $this->assertTrue($block['is_today']);
        $this->assertSame(2, $block['lag_minutes']);
        $this->assertFalse($block['stale']);
        $this->assertSame(['enabled' => true, 'status' => 'ran', 'today_visits' => 12, 'max_visits' => 50000], $block['on_view']);

        AnalyticsArchiveRun::query()->where('trigger', 'on_view')->delete();
        $old = $this->service()->describe();
        $this->assertSame(124, $old['lag_minutes']);
        $this->assertTrue($old['stale']);
    }

    /**
     * 사이트 자정을 넘기면 어제 집계가 「오늘이 아님」으로 바뀌는지 확인합니다(사이트 표준시 기준).
     *
     * @return void 없음
     *
     * @scenario freshness=midnight
     *
     * @effects freshness_is_today_uses_site_timezone
     */
    public function test_is_today_follows_site_midnight(): void
    {
        // 서울 23:50 (UTC 14:50) 에 끝난 집계
        Carbon::setTestNow(Carbon::parse('2026-09-28 14:55:00', 'UTC'));
        $this->runRow('scheduled', 5);
        $this->assertTrue($this->service()->describe()['is_today']);

        // 서울 자정을 넘긴 00:10 (UTC 15:10)
        Carbon::setTestNow(Carbon::parse('2026-09-28 15:10:00', 'UTC'));
        $this->assertFalse($this->service()->describe()['is_today']);
    }
}
