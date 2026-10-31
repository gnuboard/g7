<?php

namespace Tests\Unit\Listeners;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Execution\CommandRunnerResolver;
use App\Execution\ExecutionMode;
use App\Listeners\ScheduleHealthAlertListener;
use App\Scheduling\ArtisanCommandExtractor;
use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use App\Scheduling\ScheduleRunSummary;
use App\Scheduling\WebCronPolicy;
use App\Scheduling\WebCronTokenStore;
use App\Services\SystemExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 예약 작업 미실행 경고의 발화 조건과 문구 분기 (D19 · D30).
 *
 * 두 가지가 이 축의 요점이다:
 *
 * - **발화는 실행 모드와 무관하다.** 서버 크론을 걸 수 있는 단독 서버에서도 crontab 이
 *   고장 나면 같은 일이 벌어진다 — "무CLI 환경에서만" 으로 좁히면 그 사고가 사각에 남는다.
 * - **갈리는 것은 조치 안내 문구뿐이다.** 무CLI 는 호출 주소 연결을, 서버 크론 환경은
 *   crontab 점검을 안내한다.
 *
 * @scenario caller=panel
 */
class ScheduleHealthAlertListenerTest extends TestCase
{
    /** 케이스 전용 상태 파일 */
    private string $statePath;

    /** 케이스 전용 토큰 파일 */
    private string $tokenPath;

    /**
     * 테스트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->statePath = storage_path('framework/testing/alert-state-'.$suffix.'.json');
        $this->tokenPath = storage_path('framework/testing/alert-token-'.$suffix.'.json');

        File::ensureDirectoryExists(dirname($this->statePath));

        Config::set('core.cron.stale_alert_days', 2);
    }

    /**
     * 테스트 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([$this->statePath, $this->tokenPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * 실행 기록이 아예 없는 사이트는 경고하지 않는다.
     *
     * 설치 직후부터 경고가 떠 있으면 그 경고가 배경이 되어, 정말로 멈춘 사이트의
     * 신호와 구분되지 않는다.
     *
     * @effects fresh_site_does_not_warn
     *
     * @return void 반환값 없음
     */
    public function test_site_without_any_run_history_does_not_warn(): void
    {
        $alerts = $this->listener(ExecutionMode::Subprocess)->addScheduleAlert([]);

        $this->assertSame([], $alerts);
    }

    /**
     * 기준 일수 안에 실행된 사이트는 경고하지 않는다.
     *
     * @effects recent_run_does_not_warn
     *
     * @return void 반환값 없음
     */
    public function test_recent_run_does_not_warn(): void
    {
        $this->recordExecutionAt(CarbonImmutable::now()->subHours(6));

        $this->assertSame([], $this->listener(ExecutionMode::Subprocess)->addScheduleAlert([]));
    }

    /**
     * 기준 일수를 넘기면 실행 모드와 무관하게 경고한다 (D30).
     *
     * @effects stale_schedule_warns_in_every_execution_mode
     *
     * @return void 반환값 없음
     */
    public function test_stale_schedule_warns_in_every_mode(): void
    {
        $this->recordExecutionAt(CarbonImmutable::now()->subDays(5));

        foreach ([ExecutionMode::Subprocess, ExecutionMode::Inprocess] as $mode) {
            $alerts = $this->listener($mode)->addScheduleAlert([]);

            $this->assertCount(1, $alerts, $mode->value.' 모드에서 경고가 발화하지 않았습니다.');
            $this->assertSame(ScheduleHealthAlertListener::ALERT_SUBTYPE, $alerts[0]['subtype']);
            $this->assertSame('warning', $alerts[0]['type']);
        }
    }

    /**
     * 조치 안내 문구만 실행 모드에 따라 갈린다 (D30).
     *
     * @effects guidance_text_branches_by_execution_mode
     *
     * @return void 반환값 없음
     */
    public function test_guidance_text_branches_by_execution_mode(): void
    {
        $this->recordExecutionAt(CarbonImmutable::now()->subDays(5));

        $withCli = $this->listener(ExecutionMode::Subprocess)->addScheduleAlert([])[0]['message'];
        $withoutCli = $this->listener(ExecutionMode::Inprocess)->addScheduleAlert([])[0]['message'];

        $this->assertNotSame($withCli, $withoutCli, '두 모드의 조치 안내가 같으면 분기가 사문화된 것이다.');
        $this->assertSame(__('schedule.alerts.not_running_guidance_cron'), $withCli);
        $this->assertSame(__('schedule.alerts.not_running_guidance_web'), $withoutCli);
    }

    /**
     * 경고는 기존 알림 배열을 보존한 채 덧붙는다 (필터 훅 규약).
     *
     * @effects alert_is_appended_without_dropping_existing_alerts
     *
     * @return void 반환값 없음
     */
    public function test_alert_is_appended_to_existing_alerts(): void
    {
        $this->recordExecutionAt(CarbonImmutable::now()->subDays(5));

        $alerts = $this->listener(ExecutionMode::Inprocess)->addScheduleAlert([['id' => 'existing']]);

        $this->assertCount(2, $alerts);
        $this->assertSame('existing', $alerts[0]['id']);
    }

    /**
     * 경고는 조치 화면으로 가는 경로를 함께 싣는다.
     *
     * @effects alert_carries_the_settings_path
     *
     * @return void 반환값 없음
     */
    public function test_alert_carries_the_settings_path(): void
    {
        $this->recordExecutionAt(CarbonImmutable::now()->subDays(5));

        $alert = $this->listener(ExecutionMode::Inprocess)->addScheduleAlert([])[0];

        $this->assertSame('/admin/settings?tab=system', $alert['action_path']);
    }

    /**
     * 발화 기준 일수는 설정에서 도출된다.
     *
     * @effects stale_threshold_comes_from_config
     *
     * @return void 반환값 없음
     */
    public function test_stale_threshold_comes_from_config(): void
    {
        $this->recordExecutionAt(CarbonImmutable::now()->subDays(3));

        Config::set('core.cron.stale_alert_days', 7);
        $this->assertSame([], $this->listener(ExecutionMode::Inprocess)->addScheduleAlert([]));

        Config::set('core.cron.stale_alert_days', 2);
        $this->assertCount(1, $this->listener(ExecutionMode::Inprocess)->addScheduleAlert([]));
    }

    /**
     * 마지막 실행 시각을 상태 파일에 심습니다.
     *
     * @param  CarbonImmutable  $at  실행 시각
     * @return void 반환값 없음
     */
    private function recordExecutionAt(CarbonImmutable $at): void
    {
        (new ScheduleRunState($this->statePath))->recordRun(
            new ScheduleRunSummary(
                startedAt: $at,
                finishedAt: $at,
                due: 1,
                executed: 1,
                failed: 0,
                skipped: 0,
                truncated: false,
            ),
            advanceCheckpoint: true,
        );
    }

    /**
     * 주어진 유효 실행 모드로 판정하는 리스너를 만듭니다.
     *
     * @param  ExecutionMode  $mode  유효 실행 모드
     * @return ScheduleHealthAlertListener 리스너
     */
    private function listener(ExecutionMode $mode): ScheduleHealthAlertListener
    {
        // 감지 결과를 주입해 모드를 고정한다 — 이 테스트가 재는 것은 경고의 발화·문구이지
        // 환경 감지 자체가 아니다 (그 축은 실행 모드 테스트가 따로 잰다).
        $resolver = new CommandRunnerResolver(fn (string $binary): array => [
            'mode' => $mode === ExecutionMode::Inprocess ? 'inprocess' : 'subprocess',
            'blocked_functions' => [],
            'php_binary' => $binary,
            'php_probe' => ['status' => 'valid', 'version' => '8.3.0', 'sapi' => 'cli', 'message' => '', 'message_key' => '', 'message_params' => []],
        ]);

        $state = new ScheduleRunState($this->statePath);

        $service = new SystemExecutionService(
            $resolver,
            new ScheduleRunner(
                $state,
                new ArtisanCommandExtractor,
                $resolver,
                app(ScheduleHistoryRepositoryInterface::class),
            ),
            $state,
            new WebCronPolicy,
            new WebCronTokenStore($this->tokenPath),
            app(ScheduleHistoryRepositoryInterface::class),
        );

        return new ScheduleHealthAlertListener($service, $resolver);
    }
}
