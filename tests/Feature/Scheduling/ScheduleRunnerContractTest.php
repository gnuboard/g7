<?php

namespace Tests\Feature\Scheduling;

use App\Scheduling\ArtisanCommandExtractor;
use App\Scheduling\ScheduleRunner;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SkipsWhenExtensionTablesAreMissing;
use Tests\TestCase;

/**
 * 스케줄 러너의 계약 테스트 (A-5 ㉢ · R17).
 *
 * 두 가지를 고정한다:
 *
 * 1. **등록된 전 이벤트가 되뽑기에 성공한다 (실패 0).** 되뽑기는 프레임워크 내부
 *    표현에 의존하므로, Laravel 업그레이드로 그 표현이 바뀌면 여기가 먼저 red 가 된다.
 *    실패를 조용히 건너뛰는 구현이면 이 테스트가 그것을 잡지 못하므로, 러너는
 *    실패 시 예외를 던지고 이 테스트는 그 예외를 세어 단언한다.
 * 2. **대상은 등록에서 도출한다.** 러너가 자체 목록을 들면 GC 스케줄 등록 전수를
 *    고정한 가드(CoreScheduleRegistrationTest)가 무의미해지고, 확장이 등록한
 *    스케줄이 통째로 빠진다.
 *
 * @scenario event_kind=shell_event, event_kind=callback_event
 */
class ScheduleRunnerContractTest extends TestCase
{
    // 러너가 실행 이력을 DB 에 남기므로 이 클래스도 격리가 필요하다 — 없으면
    // 커밋된 이력 행이 남아 뒤따르는 테스트의 모집단을 오염시킨다.
    use RefreshDatabase;
    use SkipsWhenExtensionTablesAreMissing;

    /**
     * 확장 테이블 판정은 앱 부팅 **전**에 한다 — 부팅이 바로 그 실패 지점이다.
     */
    protected function setUp(): void
    {
        $this->skipWhenExtensionTablesAreMissing();

        parent::setUp();
    }

    /**
     * 설명 없는 shell 형태 예약 하나를 스케줄러에 더합니다.
     *
     * 코어·번들 확장의 예약은 DB 등록기(`ScheduleRegistrar`)가 전부 `Schedule::call` 콜백 이벤트로
     * 등록하므로(7.2.0), 실제 등록분에는 shell 이벤트가 없다. 그래도 `routes/console.php`·확장
     * 공급자가 `$schedule->command()` 로 직접 등록하는 길은 열려 있고, 러너는 그 형태를 되뽑아
     * 실행한다. 그 계약이 공허하지 않게 프레임워크가 만드는 그대로의 shell 이벤트를 하나 세운다 —
     * 콘솔 커널을 먼저 부팅해 러너의 부팅(`registeredEvents()`)이 이 이벤트를 덮지 않게 한다.
     *
     * @return void 반환값 없음
     */
    private function registerProbeShellEvent(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        app(Schedule::class)->command('inspire')->daily();
    }

    /**
     * 등록된 shell 이벤트 전수가 Artisan 커맨드로 되뽑힌다 (실패 0).
     *
     * @effects every_registered_shell_event_is_recoverable
     */
    public function test_every_registered_shell_event_is_recoverable(): void
    {
        $this->registerProbeShellEvent();

        $extractor = new ArtisanCommandExtractor;
        $events = app(ScheduleRunner::class)->registeredEvents($this->app);

        $this->assertNotEmpty($events, '등록된 스케줄이 하나도 없으면 이 계약은 공허하게 통과한다.');

        $failures = [];
        $recovered = 0;

        foreach ($events as $event) {
            if ($event instanceof CallbackEvent) {
                continue;
            }

            try {
                $extractor->extract($event);
                $recovered++;
            } catch (\Throwable $e) {
                $failures[] = $event->command.' :: '.$e->getMessage();
            }
        }

        $this->assertSame([], $failures, "되뽑기에 실패한 예약:\n".implode("\n", $failures));
        $this->assertGreaterThan(0, $recovered, 'shell 형태 예약이 하나도 없으면 되뽑기 계약이 공허하다.');
    }

    /**
     * 러너의 대상 목록이 프레임워크 스케줄러의 등록 전수와 같다 (R17).
     *
     * 러너가 자체 목록을 두면 이 단언이 red 가 된다.
     *
     * @effects runner_targets_are_derived_from_registration
     */
    public function test_runner_targets_are_derived_from_registration(): void
    {
        $registered = app(Schedule::class)->events();
        $targets = app(ScheduleRunner::class)->registeredEvents($this->app);

        $this->assertCount(count($registered), $targets);

        foreach ($registered as $event) {
            $this->assertContains($event, $targets, '등록된 이벤트가 러너 대상에서 빠졌습니다.');
        }
    }

    /**
     * 같은 이벤트는 호출마다 같은 식별 키를 받는다.
     *
     * 키가 흔들리면 "이미 실행했다" 는 기록이 매번 빗나가 소급 창이 같은 작업을
     * 반복 실행한다 — 오류 없이 실행 상한만 소모하는 형태다.
     *
     * @effects event_identity_key_is_stable_across_invocations
     */
    public function test_event_identity_key_is_stable_across_invocations(): void
    {
        $runner = app(ScheduleRunner::class);

        $this->assertSame(
            array_keys($runner->registeredEvents($this->app)),
            array_keys($runner->registeredEvents($this->app)),
        );
    }

    /**
     * 서로 다른 이벤트가 같은 키를 공유하지 않는다.
     *
     * 키가 겹치면 한 이벤트의 실행 기록이 다른 이벤트를 "이미 실행함" 으로 만들어
     * 그 예약이 영영 돌지 않는다.
     *
     * @effects event_identity_keys_do_not_collide
     */
    public function test_event_identity_keys_do_not_collide(): void
    {
        $targets = app(ScheduleRunner::class)->registeredEvents($this->app);

        $this->assertCount(
            count($targets),
            array_unique(array_keys($targets)),
            '서로 다른 예약이 같은 식별 키를 공유합니다.',
        );
    }

    /**
     * 실행 상한·소급 창 값은 설정에서 도출한다 (리터럴 재기입 금지).
     *
     * @effects runner_limits_come_from_config
     */
    public function test_runner_limits_come_from_config(): void
    {
        config([
            'core.cron.max_runtime_seconds' => 7,
            'core.cron.catch_up_window_minutes' => 11,
        ]);

        $runner = app(ScheduleRunner::class);

        $this->assertSame(7, $runner->maxRuntimeSeconds());
        $this->assertSame(11, $runner->catchUpWindowMinutes());
    }

    /**
     * 이력 화면에 쓰는 이름에 서버 경로·명령 원문이 실리지 않는다.
     *
     * `Event::getSummaryForDisplay()` 는 설명이 없으면 실행할 명령 원문을 돌려주는데, 그
     * 원문에는 이 서버의 PHP 실행 파일 절대 경로가 들어간다. 확장 예약은 설명을 달아 두어
     * 사람이 읽는 이름이 나오지만 코어 예약은 대부분 설명이 없어 그 갈래로 떨어졌고,
     * 운영자 화면에 경로가 그대로 노출됐다 (G8 실측 2026-09-02 · 계획서 §9.11.3 F6).
     *
     * 모집단은 실제 등록분에서 도출한다 — 목록을 손으로 들지 않는다.
     *
     * @effects schedule_history_name_is_human_readable
     */
    public function test_history_display_name_never_carries_the_php_binary_path(): void
    {
        // 설명 없는 shell 예약이 바로 경로가 새던 갈래다 — 실제 등록분은 전부 콜백이라 하나 세운다.
        $this->registerProbeShellEvent();

        $runner = app(ScheduleRunner::class);
        $events = $runner->registeredEvents($this->app);

        $this->assertNotEmpty($events, '등록된 스케줄이 없으면 이 계약은 공허하게 통과한다.');

        $method = (new \ReflectionClass($runner))->getMethod('displayNameOf');
        $method->setAccessible(true);

        $offenders = [];
        $checked = 0;

        foreach ($events as $event) {
            if ($event instanceof CallbackEvent) {
                continue;
            }

            $name = (string) $method->invoke($runner, $event);
            $checked++;

            // 명령 원문의 표지: 인용부호로 감싼 실행 파일 경로 또는 `artisan` 토큰.
            if (preg_match('~["\']|\bartisan\b|(?:^|[\s"])(?:[A-Za-z]:[\\/]|/)~', $name) === 1) {
                $offenders[] = $name;
            }
        }

        $this->assertGreaterThan(0, $checked, 'shell 형태 예약이 하나도 없으면 이 계약이 공허하다.');
        $this->assertSame(
            [],
            $offenders,
            "이력 이름에 명령 원문·서버 경로가 실렸습니다:\n".implode("\n", $offenders)
        );
    }
}
