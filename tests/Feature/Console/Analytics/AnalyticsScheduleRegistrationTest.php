<?php

namespace Tests\Feature\Console\Analytics;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 방문자 통계 예약 등록 — 무엇이 언제, 어느 구간에 걸렸는가.
 *
 * 집계가 안 돌면 화면 숫자가 그 시점에 멈춘 채 그럴듯하게 남고, 정리가 안 돌면 원장이
 * 조용히 자란다. 둘 다 증상이 없으므로 「등록됐는가」를 테스트가 지켜야 한다.
 *
 * 정리(prune)만 gc 구간 **안**에 있어야 한다 — 그 구간을 읽어 정리 배치 목록을 도출하는
 * 회귀 테스트가 있어서, 집계를 그 안에 두면 정리 목록이 오염된다.
 */
class AnalyticsScheduleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 방문자 통계 예약의 기대 주기입니다.
     *
     * @return array<string, string> 커맨드 → cron 식
     */
    private function expectedSchedules(): array
    {
        return [
            'analytics:prune' => '10 5 * * *',
            'analytics:archive --period=hour' => '5 * * * *',
            'analytics:archive --period=day' => '15 0 * * *',
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
     * 정리만 gc 구간 안에 있고 집계·점검·리포트는 밖에 있는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=beyond
     *
     * @effects only_prune_lives_inside_the_gc_block
     */
    public function test_only_prune_lives_inside_the_gc_block(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));

        $begin = strpos($source, '// gc-schedules:begin');
        $end = strpos($source, '// gc-schedules:end');

        $this->assertNotFalse($begin);
        $this->assertNotFalse($end);

        $inside = substr($source, $begin, $end - $begin);
        $outside = substr($source, 0, $begin).substr($source, $end);

        $this->assertStringContainsString(
            "Schedule::command('analytics:prune')",
            $inside,
            '정리는 gc 구간 안에 있어야 정리 배치 목록에 잡힌다',
        );

        foreach (['analytics:archive', 'analytics:check-volume', 'analytics:report', 'analytics:broadcast-realtime'] as $command) {
            $this->assertStringNotContainsString(
                "Schedule::command('".$command,
                $inside,
                "{$command} 는 정리가 아니다 — gc 구간에 두면 정리 배치 목록이 오염된다",
            );
            $this->assertStringContainsString("Schedule::command('".$command, $outside);
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

        foreach (['analytics:purge', 'analytics:anonymize'] as $command) {
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
     * 조건부 예약이 그 조건이 꺼져 있을 때 등록되지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=salts, row_age=within
     *
     * @effects conditional_schedules_stay_out_when_their_feature_is_off
     */
    public function test_conditional_schedules_are_absent_when_their_feature_is_off(): void
    {
        // 이 환경은 클라우드 대역 차단이 꺼져 있고 브로드캐스트 키가 비어 있다 —
        // 그 상태에서 두 예약이 등록되면 없는 기능을 위해 배치가 돈다.
        $this->assertFalse((bool) g7_core_settings('analytics.block_cloud_ip_ranges', false));
        $this->assertSame('', (string) config('broadcasting.connections.reverb.key', ''));

        $this->assertNull($this->findEvent('analytics:update-cloud-ranges'));
        $this->assertNull($this->findEvent('analytics:broadcast-realtime'));
    }

    /**
     * 등록된 예약에서 커맨드 하나를 찾습니다.
     *
     * @param  string  $signature  커맨드 시그니처 (옵션 포함 가능)
     * @return Event|null 예약 이벤트 (없으면 null)
     */
    private function findEvent(string $signature): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            $command = (string) $event->command;

            if (! str_contains($command, "'".$signature."'") && ! str_contains($command, $signature)) {
                continue;
            }

            return $event;
        }

        return null;
    }
}
