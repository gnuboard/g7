<?php

namespace Tests\Feature\Console;

use App\Models\Schedule as ScheduleModel;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * 코어 만료 데이터 정리 스케줄 등록 회귀 테스트 (공개이슈 #110)
 *
 * 이 축의 테스트가 0건이었던 것이 "커맨드는 있는데 스케줄만 없다" 는 결함군이
 * 무가드로 통과된 구조적 원인입니다. 등록 전수와 실행 시각 기준을 함께 고정합니다.
 *
 * 서머타임 주의: 서머타임이 있는 시간대에서는 전환일에 특정 시각이 건너뛰거나
 * 두 번 발생할 수 있습니다. 아래 정리 배치는 모두 멱등이라 이중 실행이 데이터를
 * 해치지 않고, 건너뛴 날은 다음 날 배치가 흡수합니다.
 *
 * @scenario schedule_axis=code_registered, schedule_axis=admin_registered, timezone_setting=configured, timezone_setting=empty
 *
 * @effects all_gc_commands_are_registered_in_schedule, gc_commands_run_on_one_server_only, sanctum_prune_uses_hours_option, schedule_timezone_follows_site_general_timezone, schedule_timezone_falls_back_to_app_timezone_when_unset, app_timezone_stays_utc, admin_registered_schedule_next_run_uses_same_basis, new_commands_are_in_schedule_security_allowlist, new_commands_are_exposed_in_dev_dashboard
 */
class CoreScheduleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 부팅 시점에 확정된 예약 시각 기준 */
    private string $bootScheduleTimezone = 'UTC';

    /**
     * B1: 코어 GC 스케줄 전수가 등록되어 있고 주기/onOneServer 가 의도대로다.
     */
    public function test_core_gc_schedules_are_registered(): void
    {
        $expected = [
            'layout-previews:cleanup' => '*/30 * * * *',
            'sanctum:prune-expired' => '0 4 * * *',
            'auth:clear-resets' => '5 4 * * *',
            'queue:prune-failed' => '10 4 * * *',
            'queue:prune-batches' => '15 4 * * *',
            'notification:cleanup' => '20 4 * * *',
            'ext-bundles:cleanup' => '25 4 * * *',
            'seo:prune-stats' => '30 4 * * *',
            'schedules:prune-history' => '35 4 * * *',
            'identity:prune-logs' => '40 4 * * *',
            'activity-log:prune' => '45 4 * * *',
            'notification-log:prune' => '50 4 * * *',
            'attachments:prune-orphans' => '55 4 * * *',
            'storage:prune-leftovers' => '0 5 * * *',
            'identity:expire-challenges' => '0 * * * *',
        ];

        foreach ($expected as $command => $expression) {
            $event = $this->findScheduledEvent($command);

            $this->assertNotNull($event, "{$command} 스케줄이 등록되어야 합니다.");
            $this->assertSame($expression, $event->expression, "{$command} 실행 주기");
            $this->assertTrue($event->onOneServer, "{$command} 는 다중 서버에서 1대만 실행해야 합니다.");
        }
    }

    /**
     * B1: sanctum 정리는 --hours=24 로 실행된다.
     */
    public function test_sanctum_prune_uses_expected_hours_option(): void
    {
        $event = $this->findScheduledEvent('sanctum:prune-expired');

        $this->assertNotNull($event);
        $this->assertStringContainsString('--hours', (string) $event->command);
        $this->assertStringContainsString('24', (string) $event->command);
    }

    /**
     * B1-t: 예약 시각 기준은 사이트 설정 시간대(general.timezone)를 따른다.
     */
    public function test_schedule_timezone_follows_site_general_timezone(): void
    {
        // 테스트가 주입한 값을 그대로 되읽으면 파생 로직을 지워도 통과한다 —
        // 사이트 설정에서 app.schedule_timezone 이 실제로 파생되는지를 본다.
        Config::set('app', Arr::except(config('app'), ['schedule_timezone']));

        $this->applyGeneralSettings(['timezone' => 'Europe/Berlin']);

        $this->assertSame(
            'Europe/Berlin',
            config('app.schedule_timezone'),
            '사이트 설정 시간대가 예약 시각 기준으로 파생되지 않습니다'
        );

        // 사용자 표시용 기본 타임존도 같은 값에서 파생된다 (기존 계약 유지)
        $this->assertSame('Europe/Berlin', config('app.default_user_timezone'));

        // 저장 타임존은 UTC 불변 — 전 도메인의 저장값 해석 기준이라 건드리지 않는다.
        $this->assertSame('UTC', config('app.timezone'));
    }

    /**
     * B1-t: 사이트 설정에 시간대가 없으면 파생 키 자체를 만들지 않는다.
     */
    public function test_schedule_timezone_is_not_derived_when_setting_is_empty(): void
    {
        Config::set('app', Arr::except(config('app'), ['schedule_timezone']));

        $this->applyGeneralSettings(['site_name' => '시간대 없는 사이트']);

        $this->assertFalse(
            Config::has('app.schedule_timezone'),
            '시간대 미설정인데 파생 키가 생겨 Laravel 폴백이 차단됩니다'
        );
    }

    /**
     * B1-o: 정리 예약 전건이 관리자 화면에서도 등록 가능해야 한다 (artisan 허용목록).
     *
     * 허용목록에 없으면 운영자가 주기를 바꾸거나 임시로 다시 걸 수 없고, 그 사실이
     * 등록 시점의 거부로만 드러난다.
     */
    public function test_gc_commands_are_registerable_through_the_admin_allowlist(): void
    {
        $allowlist = (array) config('schedule_security.artisan.allowlist');
        $commands = $this->gcCommandNames();

        $this->assertNotEmpty($commands, '정리 예약을 소스에서 도출하지 못했습니다.');

        foreach ($commands as $command) {
            $this->assertArrayHasKey(
                $command,
                $allowlist,
                "{$command} 가 artisan 허용목록에 없어 관리자가 예약으로 등록할 수 없습니다."
            );
        }
    }

    /**
     * B1-o: 이 저장소가 소유한 정리 커맨드는 개발 대시보드에서 수동 실행 가능해야 한다.
     *
     * 배치가 실패한 날 운영자가 손으로 돌릴 통로가 없으면 다음 배치까지 방치된다.
     * 프레임워크·벤더 커맨드(`sanctum:prune-expired` 등)는 대시보드 노출 대상이 아니므로
     * 소유 커맨드(`app/Console/Commands`)로 한정한다 — 노출 정합 검사와 같은 범위다.
     */
    public function test_owned_gc_commands_are_exposed_in_the_dev_dashboard(): void
    {
        $dashboard = (string) file_get_contents(resource_path('views/dev-dashboard.blade.php'));
        $owned = $this->ownedCommandNames();

        $checked = 0;

        foreach ($this->gcCommandNames() as $command) {
            if (! in_array($command, $owned, true)) {
                continue;
            }

            $checked++;
            $this->assertStringContainsString(
                "runCommand('{$command}')",
                $dashboard,
                "{$command} 실행 버튼이 개발 대시보드에 없습니다."
            );
        }

        $this->assertGreaterThan(0, $checked, '소유 정리 커맨드를 하나도 판정하지 못했습니다.');
    }

    /**
     * 이 저장소가 소유한(= `app/Console/Commands` 에 정의된) artisan 커맨드 이름 목록.
     *
     * @return array<int, string> 커맨드 이름 목록
     */
    private function ownedCommandNames(): array
    {
        $root = base_path('app'.DIRECTORY_SEPARATOR.'Console'.DIRECTORY_SEPARATOR.'Commands');
        $names = [];

        foreach (Artisan::all() as $name => $command) {
            $file = (new \ReflectionClass($command))->getFileName();

            if ($file !== false && str_starts_with((string) $file, $root)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * 정리 예약 커맨드 이름을 예약 정의 소스에서 도출합니다.
     *
     * 손으로 적은 목록은 예약이 늘어도 그대로라 전수를 증명하지 못합니다 —
     * 다중 서버 1대 실행(`onOneServer`)으로 선언된 artisan 예약을 그대로 읽습니다.
     * 예약 문자열의 옵션은 런타임 validator(`parseArtisanCommand`)와 동일하게 명령명만 남깁니다.
     *
     * @return array<int, string> 커맨드 이름 목록
     */
    private function gcCommandNames(): array
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));

        $begin = strpos($source, '// gc-schedules:begin');
        $end = strpos($source, '// gc-schedules:end');

        $this->assertNotFalse($begin, '정리 예약 구간 시작 표식이 없습니다.');
        $this->assertNotFalse($end, '정리 예약 구간 종료 표식이 없습니다.');

        // 표식 밖의 예약(geoip 갱신·sitemap 생성 등)은 정리 배치가 아니다 —
        // `onOneServer()` 만으로 거르면 그런 예약까지 대상이 되어 오탐이 난다.
        $region = substr($source, $begin, $end - $begin);

        preg_match_all("/Schedule::command\(\s*'([^']+)'/", $region, $matches);

        return array_values(array_unique(array_map(
            static fn (string $command): string => (string) strtok($command, ' '),
            $matches[1] ?? []
        )));
    }

    /**
     * B1-t: 등록된 예약 이벤트 전부가 같은 시각 기준을 공유한다.
     */
    public function test_every_scheduled_event_shares_the_same_timezone(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);

        // 기대값을 특정 시간대로 박으면 사이트 설정이 다른 환경·CI 에서 실패한다.
        // Schedule 인스턴스가 부팅 시점에 해석한 값과 대조한다.
        foreach ($events as $event) {
            $this->assertSame(
                $this->bootScheduleTimezone,
                (string) $event->timezone,
                '예약 이벤트가 서로 다른 시각 기준을 씁니다: '.$event->command
            );
        }
    }

    /**
     * 사이트 general 설정을 주고 Provider 의 파생 로직을 실행합니다.
     *
     * @param  array<string, mixed>  $general  general 카테고리 설정값
     */
    private function applyGeneralSettings(array $general): void
    {
        $repository = Mockery::mock(JsonConfigRepository::class);
        $repository->shouldReceive('getCategory')->with('general')->andReturn($general);
        $repository->shouldReceive('getCategory')->andReturn([]);

        $provider = new SettingsServiceProvider($this->app);
        $apply = new \ReflectionMethod($provider, 'applyAppConfig');
        $apply->setAccessible(true);
        $apply->invoke($provider, $repository);
    }

    /**
     * B1-t: 사이트 설정 시간대가 비어 있으면 종전대로 app.timezone 으로 폴백한다.
     */
    public function test_schedule_timezone_falls_back_to_app_timezone(): void
    {
        $kernel = app(ConsoleKernelContract::class);
        $resolve = new \ReflectionMethod($kernel, 'scheduleTimezone');
        $resolve->setAccessible(true);

        // 사이트 설정에 시간대가 없으면 Provider 가 키 자체를 세팅하지 않는다(= 부재).
        Config::set('app', Arr::except(config('app'), ['schedule_timezone']));
        $this->assertFalse(Config::has('app.schedule_timezone'));
        $this->assertSame(config('app.timezone'), $resolve->invoke($kernel));

        Config::set('app.schedule_timezone', 'Asia/Seoul');
        $this->assertSame('Asia/Seoul', $resolve->invoke($kernel));
    }

    /**
     * B1-a: 관리자 등록 예약의 다음 실행 시각도 같은 기준으로 계산된다.
     */
    public function test_admin_registered_schedule_next_run_uses_site_timezone(): void
    {
        $schedule = new ScheduleModel([
            'expression' => '0 4 * * *',
            'is_active' => true,
        ]);
        $schedule->calculateNextRunAt();

        $this->assertNotNull($schedule->next_run_at);

        // 사이트 설정 시간대(Asia/Seoul, UTC+9)의 04:00 = UTC 19:00
        $this->assertSame(
            '04:00',
            $schedule->next_run_at->copy()->setTimezone('Asia/Seoul')->format('H:i')
        );
        $this->assertSame('19:00', $schedule->next_run_at->copy()->utc()->format('H:i'));
    }

    /**
     * 테스트 시간대 고정 — 사이트 설정 시간대를 Asia/Seoul 로 두고 앱을 부팅한다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 예약 이벤트의 시각 기준은 부팅 시점에 확정된다 — 아래 주입은 그 뒤라
        // 이미 등록된 이벤트에는 영향이 없다. 대조용으로 부팅 값을 먼저 붙잡는다.
        $this->bootScheduleTimezone = (string) (
            config('app.schedule_timezone') ?? config('app.timezone', 'UTC')
        );

        // 아래 계산 시점 해석(관리자 등록 예약)을 고정하기 위한 주입.
        Config::set('app.schedule_timezone', 'Asia/Seoul');
    }

    /**
     * 부팅된 앱의 스케줄러에서 커맨드 이름으로 이벤트를 찾습니다.
     *
     * @param  string  $command  artisan 커맨드 이름
     * @return Event|null 등록된 스케줄 이벤트
     */
    private function findScheduledEvent(string $command): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, "'{$command}'")
                || str_contains((string) $event->command, " {$command} ")
                || str_ends_with((string) $event->command, " {$command}")) {
                return $event;
            }
        }

        return null;
    }
}
