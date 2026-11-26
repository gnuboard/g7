<?php

namespace Tests\Feature\Console\Analytics;

use App\Models\Schedule as ScheduleModel;
use App\Services\Schedule\ScheduleRegistrar;
use Database\Seeders\CoreScheduleSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 방문자 통계 예약 등록 — 무엇이 언제, 어느 묶음에 걸렸는가.
 *
 * 집계가 안 돌면 화면 숫자가 그 시점에 멈춘 채 그럴듯하게 남고, 정리가 안 돌면 원장이
 * 조용히 자란다. 둘 다 증상이 없으므로 「등록됐는가」를 테스트가 지켜야 한다.
 *
 * 7.2.0 부터 예약은 DB 행을 거친다 — 코어 카탈로그(`config/core.php` schedules) → CoreScheduleSeeder →
 * ScheduleRegistrar → `schedule:{id}` 이벤트. 정리(prune)만 카탈로그 `group: gc` 여야 한다 — 그 묶음을
 * 읽어 정리 배치 목록을 도출하는 회귀 테스트가 있어서, 집계를 gc 로 두면 정리 목록이 오염된다.
 * 조건부 예약(클라우드 대역·실시간 브로드캐스트)은 늘 등록되고 조건은 실행 시점에 평가된다.
 */
class AnalyticsScheduleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var bool 이 테스트에서 코어 카탈로그를 시딩했는지 */
    private bool $seeded = false;

    /**
     * 방문자 통계 예약의 기대 주기입니다.
     *
     * @return array<string, string> 커맨드 → cron 식
     */
    private function expectedSchedules(): array
    {
        return [
            'analytics:prune' => '10 5 * * *',
            // 스케줄러 등록 실행만 `--scheduled` 를 단다 — 통계 화면은 이 표식으로 스케줄러 생존을 판정한다.
            'analytics:archive --period=hour --scheduled' => '5 * * * *',
            'analytics:archive --period=day --scheduled' => '15 0 * * *',
            'analytics:check-volume' => '5 5 * * *',
            'analytics:report --period=weekly' => '0 8 * * 1',
            'analytics:report --period=monthly' => '0 8 1 * *',
        ];
    }

    /**
     * 조건 없이 등록되는 예약 6건이 의도한 주기로 걸렸는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=within
     *
     * @effects analytics_schedules_are_registered_with_their_periods
     */
    public function test_the_six_unconditional_schedules_are_registered(): void
    {
        foreach ($this->expectedSchedules() as $command => $expression) {
            $event = $this->findEvent($command);

            $this->assertNotNull($event, "{$command} 예약이 등록되어야 합니다.");
            $this->assertSame($expression, $event->expression, "{$command} 실행 주기");
            $this->assertTrue($event->onOneServer, "{$command} 는 다중 서버에서 1대만 실행해야 합니다.");
        }
    }

    /**
     * 정리만 카탈로그 gc 묶음이고 집계·점검·리포트는 gc 밖에 있는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=beyond
     *
     * @effects only_prune_lives_inside_the_gc_block
     */
    public function test_only_prune_lives_inside_the_gc_block(): void
    {
        $gc = [];
        $others = [];

        foreach ((array) config('core.schedules') as $entry) {
            $command = (string) ($entry['command'] ?? '');

            if (($entry['group'] ?? null) === 'gc') {
                $gc[] = $command;
            } else {
                $others[] = $command;
            }
        }

        $this->assertNotEmpty($gc);
        $this->assertNotEmpty($others);

        $this->assertContains(
            'analytics:prune',
            $gc,
            '정리는 gc 묶음이어야 정리 배치 목록에 잡힌다',
        );

        foreach (['analytics:archive', 'analytics:check-volume', 'analytics:report', 'analytics:broadcast-realtime'] as $command) {
            $this->assertEmpty(
                array_filter($gc, static fn (string $c): bool => str_starts_with($c, $command)),
                "{$command} 는 정리가 아니다 — gc 묶음에 두면 정리 배치 목록이 오염된다",
            );
            $this->assertNotEmpty(array_filter($others, static fn (string $c): bool => str_starts_with($c, $command)));
        }
    }

    /**
     * 예약으로 도는 커맨드가 운영자 예약 화면의 허용 목록에 있는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=within
     *
     * @effects scheduled_commands_are_registerable_through_the_admin_allowlist
     */
    public function test_scheduled_commands_are_in_the_admin_allowlist(): void
    {
        $allowlist = (array) config('schedule_security.artisan.allowlist', []);

        foreach (array_keys($this->expectedSchedules()) as $signature) {
            $command = (string) strtok($signature, ' ');

            $this->assertArrayHasKey(
                $command,
                $allowlist,
                "{$command} 가 허용 목록에 없으면 운영자가 예약 화면에서 등록할 수 없다",
            );
        }

        // 옵션까지 허용돼야 예약 화면이 그 옵션으로 등록할 수 있다.
        $this->assertContains('period', $allowlist['analytics:archive']['options'] ?? []);
        $this->assertContains('scheduled', $allowlist['analytics:archive']['options'] ?? []);
        $this->assertContains('period', $allowlist['analytics:report']['options'] ?? []);
        $this->assertContains('days', $allowlist['analytics:prune']['options'] ?? []);
    }

    /**
     * 되돌릴 수 없는 커맨드가 허용 목록에 없는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=beyond
     *
     * @effects irreversible_commands_stay_out_of_the_allowlist
     */
    public function test_irreversible_commands_are_not_schedulable(): void
    {
        $allowlist = (array) config('schedule_security.artisan.allowlist', []);

        foreach (['analytics:purge', 'analytics:anonymize', 'analytics:purge-extension'] as $command) {
            $this->assertArrayNotHasKey(
                $command,
                $allowlist,
                "{$command} 는 되돌릴 수 없다 — 무인 예약으로 돌 수 있으면 안 된다",
            );
        }
    }

    /**
     * 운영자가 손으로 돌릴 커맨드가 개발 대시보드에 노출되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=within
     *
     * @effects maintenance_commands_are_exposed_in_the_dev_dashboard
     */
    public function test_maintenance_commands_are_exposed_in_the_dev_dashboard(): void
    {
        $blade = (string) file_get_contents(resource_path('views/dev-dashboard.blade.php'));

        foreach ([
            'analytics:archive --period=hour',
            'analytics:archive --period=day',
            'analytics:check-volume',
            'analytics:report --period=weekly',
            'analytics:update-cloud-ranges',
            'analytics:broadcast-realtime',
            'analytics:prune --dry-run',
            'analytics:prune',
        ] as $command) {
            $this->assertStringContainsString(
                "runCommand('".$command."')",
                $blade,
                "{$command} 버튼이 없으면 운영자가 손으로 돌 길이 없다",
            );
        }
    }

    /**
     * 조건부 예약이 그 조건이 꺼져 있을 때 실행되지 않는지 확인합니다.
     *
     * DB 스케줄은 조건과 무관하게 등록되고(관리 화면에 보여야 한다), 조건은 실행 시점에 평가된다 —
     * 「꺼져 있으면 돌지 않는다」를 이벤트의 실행 조건(filtersPass)으로 잰다.
     *
     * @return void 없음
     *
     * @scenario target=salts, row_age=within
     *
     * @effects conditional_schedules_stay_out_when_their_feature_is_off
     */
    public function test_conditional_schedules_do_not_run_when_their_feature_is_off(): void
    {
        // 조건을 **이 테스트가 만든다**. 개발자 `.env` 의 `REVERB_APP_KEY` 를 그대로 읽으면,
        // 웹소켓을 켜 둔 사람의 컴퓨터에서만 빨개져 정작 재려던 것을 못 잰다.
        config([
            'broadcasting.connections.reverb.key' => '',
            'g7_settings.core.analytics.block_cloud_ip_ranges' => false,
        ]);

        $cloud = $this->findEvent('analytics:update-cloud-ranges');
        $realtime = $this->findEvent('analytics:broadcast-realtime');

        $this->assertNotNull($cloud, '조건부 예약도 행으로 등록되어 관리 화면에 보여야 한다');
        $this->assertNotNull($realtime);
        $this->assertFalse($cloud->filtersPass($this->app), '클라우드 대역 차단이 꺼져 있으면 갱신하지 않는다');
        $this->assertFalse($realtime->filtersPass($this->app), '브로드캐스트 미설정이면 실시간 방송을 돌리지 않는다');
    }

    /**
     * 기능을 켠 사이트에서는 조건부 예약이 실행되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=salts, row_age=within
     *
     * @effects conditional_schedules_appear_when_their_feature_is_on
     */
    public function test_the_realtime_schedule_appears_when_broadcasting_is_configured(): void
    {
        // 꺼진 쪽만 재면 「어느 조건에서도 실행되지 않는다」와 구분이 안 된다 — 켠 쪽도 잰다.
        config([
            'broadcasting.connections.reverb.key' => 'test-key',
            'g7_settings.core.analytics.block_cloud_ip_ranges' => true,
        ]);

        $realtime = $this->findEvent('analytics:broadcast-realtime');
        $cloud = $this->findEvent('analytics:update-cloud-ranges');

        $this->assertNotNull($realtime);
        $this->assertTrue($realtime->filtersPass($this->app));
        $this->assertTrue($cloud?->filtersPass($this->app) ?? false);
    }

    /**
     * 코어 카탈로그를 시딩하고 새 스케줄러에 등록한 뒤, 명령으로 그 행의 이벤트를 찾습니다.
     *
     * 이벤트는 `schedule:{id}` 이름의 콜백 이벤트라 명령 문자열을 들고 있지 않다 — 시딩된 코어 행의
     * 명령으로 행을 찾고 그 id 의 이벤트를 고른다.
     *
     * @param  string  $signature  카탈로그 명령 (옵션 포함 그대로)
     * @return Event|null 예약 이벤트 (없으면 null)
     */
    private function findEvent(string $signature): ?Event
    {
        if (! $this->seeded) {
            (new CoreScheduleSeeder)->run();
            $this->seeded = true;
        }

        $row = ScheduleModel::query()
            ->where('extension_type', 'core')
            ->where('command', $signature)
            ->first();

        if ($row === null) {
            return null;
        }

        $schedule = new Schedule;
        app(ScheduleRegistrar::class)->register($schedule);

        foreach ($schedule->events() as $event) {
            if ($event->description === ScheduleRegistrar::EVENT_PREFIX.$row->id) {
                return $event;
            }
        }

        return null;
    }
}
