<?php

namespace Tests\Feature\Schedule;

use App\Enums\ExtensionOwnerType;
use App\Jobs\GenerateSitemapJob;
use App\Models\Schedule as ScheduleModel;
use App\Services\Schedule\ScheduleRegistrar;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Modules\G7TestingWeb\Providers\FakeWebCommandServiceProvider;
use RuntimeException;
use Tests\Fixtures\ScheduleCommandExtension\src\Console\Commands\DeclaredCommand;
use Tests\Fixtures\ScheduleCommandExtension\src\Console\Commands\UndeclaredCommand;
use Tests\TestCase;

/**
 * 이 서버에 없는 명령·작업 클래스의 스케줄은 실행하지 않고 건너뛴다 (#888).
 *
 * 같은 DB 를 쓰는 다른 서버(검수 서버에 먼저 올린 새 판)가 설치·업데이트로 공유 `schedules` 에 새 명령의 행을 넣거나 command 를
 * 새 값으로 바꾸면, 이 서버의 스케줄러가 자기 코드에 없는 명령·작업을 매 주기 실행해 실패 이력·오류 로그·실패 알림이 쌓였다.
 * 등록기는 이 서버에 없는 Artisan 명령·작업 클래스의 행에 건너뛰기 필터를 건다 — 두 실행기(서버 크론·웹 크론)가 보는 `filtersPass()` 가
 * false 라 실행·이력·실패 알림이 없다. 판정은 실행 시점이다(등록은 콘솔 커널 부팅 도중이라 그때 명령 목록을 읽으면 부팅이 다시 돈다).
 * 경고는 매 주기 같은 경고가 쌓이지 않게 한 번만. 실행 역할과 무관하게 적용한다.
 *
 * @scenario-manifest tests/scenarios/schedule-missing-target-skip.yaml
 */
class ScheduleRegistrarMissingTargetTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 스케줄이 켜진 운영 서버 역할로 맞춥니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['core.runtime_role' => 'live', 'core.scheduler.enabled' => true]);
    }

    /**
     * 이 서버에 없는 Artisan 명령의 행은 실행 시점에 건너뛴다. 있는 명령의 행은 종전대로 실행한다.
     *
     * @scenario target=artisan_missing, target=artisan_present
     *
     * @effects missing_artisan_command_row_is_skipped, present_target_row_still_runs
     *
     * @return void 반환값 없음
     */
    public function test_missing_artisan_command_row_is_skipped(): void
    {
        $missing = $this->row(['command' => 'acme-stage-only:new-command'], ExtensionOwnerType::Core, 'core');
        $missingWithOptions = $this->row(['command' => 'acme-stage-only:other-command --force']);
        $present = $this->row(['command' => 'inspire'], ExtensionOwnerType::Core, 'core');

        $events = $this->register();

        $this->assertFalse($this->runs($events, $missing), '이 서버에 없는 명령을 매 주기 실행한다');
        $this->assertFalse($this->runs($events, $missingWithOptions), '옵션이 붙은 없는 명령도 건너뛰어야 한다');
        $this->assertTrue($this->runs($events, $present), '있는 명령의 행은 종전대로 실행한다');
    }

    /**
     * 이 서버에 없는 작업(Job) 클래스의 행은 실행 시점에 건너뛴다. 있는 클래스의 행은 종전대로 실행한다.
     *
     * @scenario target=job_missing, target=job_present
     *
     * @effects missing_job_class_row_is_skipped, present_target_row_still_runs
     *
     * @return void 반환값 없음
     */
    public function test_missing_job_class_row_is_skipped(): void
    {
        $missing = $this->row(['type' => 'job', 'command' => 'App\\Jobs\\AcmeStageOnlyJobThatDoesNotExist']);
        $present = $this->row(['type' => 'job', 'command' => GenerateSitemapJob::class]);

        $events = $this->register();

        $this->assertFalse($this->runs($events, $missing), '이 서버에 없는 작업 클래스를 매 주기 실행한다');
        $this->assertTrue($this->runs($events, $present), '있는 작업 클래스의 행은 종전대로 실행한다');
    }

    /**
     * 건너뛴 행은 경고를 남기되, 등록·판정이 거듭돼도(매 분 `schedule:run`) 같은 행의 경고는 한 번만 남긴다.
     *
     * @scenario target=artisan_missing
     *
     * @effects skipped_row_warns_once
     *
     * @return void 반환값 없음
     */
    public function test_skipped_row_warns_only_once_across_registrations(): void
    {
        $missing = $this->row(['command' => 'acme-stage-only:warn-once']);

        Log::spy();

        for ($tick = 0; $tick < 3; $tick++) {
            $this->assertFalse($this->runs($this->register(), $missing));
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (mixed $message, mixed $context = []): bool => is_array($context) && (int) ($context['schedule_id'] ?? 0) === (int) $missing->id)
            ->once();
    }

    /**
     * 검수 서버 역할이 아니어도(운영 서버) 적용된다 — 판정은 이 서버의 코드에 대상이 있느냐 하나다.
     *
     * @scenario target=artisan_missing
     *
     * @effects skip_applies_regardless_of_runtime_role
     *
     * @return void 반환값 없음
     */
    public function test_skip_applies_on_live_role(): void
    {
        $this->assertSame('live', config('core.runtime_role'));

        $missing = $this->row(['command' => 'acme-stage-only:live-role']);

        $this->assertFalse($this->runs($this->register(), $missing));
    }

    /**
     * 콘솔 커널에 없는 확장 명령이라도 행의 `command_class` 나 활성 확장의 명령 디렉터리로 찾으면 건너뛰지 않는다.
     *
     * 웹 크론은 웹 요청이라 확장 명령이 콘솔 커널에 등록되지 않는다. 실행기는 그 명령을 `command_class`(없으면 활성 확장의 명령
     * 디렉터리)에서 찾아 등록한 뒤 실행하므로, 건너뛰기 판정도 같은 곳을 봐야 웹 크론에서 확장 스케줄이 멈추지 않는다.
     *
     * @scenario target=artisan_command_class, target=artisan_extension_directory
     *
     * @effects extension_command_outside_kernel_still_runs, present_target_row_still_runs
     *
     * @return void 반환값 없음
     */
    public function test_extension_command_outside_console_kernel_is_not_skipped(): void
    {
        require_once base_path('tests/Fixtures/ExtensionWebCommand/src/Providers/FakeWebCommandServiceProvider.php');
        require_once base_path('tests/Fixtures/ExtensionWebCommand/src/Console/Commands/FakeWebScheduleCommand.php');
        // 프로바이더는 명령을 등록하지 않는다 — 명령 클래스는 확장 명령 디렉터리(`src/Console/Commands`)에만 있다
        $this->app->register(FakeWebCommandServiceProvider::class);

        $kernelCommands = app(ConsoleKernel::class)->all();
        $this->assertArrayNotHasKey('g7-testing-web-command', $kernelCommands, '전제 — 콘솔 커널에 없는 확장 명령');
        $this->assertArrayNotHasKey('fixture-ext:declared', $kernelCommands, '전제 — 콘솔 커널에 없는 확장 명령');

        $byDirectory = $this->row(['command' => 'g7-testing-web-command --scope=all']);
        $byDeclaredClass = $this->row(['command' => 'fixture-ext:declared --scheduled', 'command_class' => DeclaredCommand::class]);

        $events = $this->register();

        $this->assertTrue($this->runs($events, $byDirectory), '활성 확장의 명령 디렉터리에 있는 명령을 이 서버에 없다고 건너뛴다');
        $this->assertTrue($this->runs($events, $byDeclaredClass), '행의 command_class 가 가리키는 명령을 이 서버에 없다고 건너뛴다');
    }

    /**
     * 행의 `command_class` 가 있는 클래스라도 커맨드가 아니거나 명령 이름이 다르면 「대상이 있다」고 보지 않는다.
     *
     * 실행기는 그런 `command_class` 를 무시하고 등록하지 않아 실행이 매 주기 실패한다. 건너뛰기 판정이 클래스가 있는지만 보면
     * 그 행을 실행해 실패 이력·알림이 쌓인다 — 실행기와 같은 조건(커맨드 하위 클래스, 같은 명령 이름)으로 판정한다.
     *
     * @scenario target=artisan_command_class_not_command, target=artisan_command_class_name_mismatch, target=artisan_command_class
     *
     * @effects invalid_command_class_is_not_a_present_target, extension_command_outside_kernel_still_runs
     *
     * @return void 반환값 없음
     */
    public function test_invalid_command_class_does_not_count_as_present(): void
    {
        $this->assertArrayNotHasKey('fixture-ext:declared', app(ConsoleKernel::class)->all(), '전제 — 콘솔 커널에 없는 명령');

        $notCommand = $this->row(['command' => 'fixture-ext:declared', 'command_class' => GenerateSitemapJob::class]);
        $nameMismatch = $this->row(['command' => 'fixture-ext:declared', 'command_class' => UndeclaredCommand::class]);
        $valid = $this->row(['command' => 'fixture-ext:declared', 'command_class' => DeclaredCommand::class]);

        $events = $this->register();

        $this->assertFalse($this->runs($events, $notCommand), 'command_class 가 커맨드가 아닌데 대상이 있다고 보고 실행한다');
        $this->assertFalse($this->runs($events, $nameMismatch), 'command_class 의 명령 이름이 행의 명령과 다른데 대상이 있다고 보고 실행한다');
        $this->assertTrue($this->runs($events, $valid), '명령 이름이 같은 커맨드 command_class 는 종전대로 실행한다');
    }

    /**
     * 대상 판정 자체가 예외면(콘솔 커널의 명령 하나가 생성 중 예외 등) 건너뛰지 않고 실행하며, 판정 실패 경고는 거듭돼도 한 번만 남긴다.
     *
     * 판정 예외가 필터 밖으로 새면 그 행만이 아니라 모든 DB 스케줄 행의 `filtersPass()` 가 예외가 돼 웹 크론 러너가 그 시점의
     * 실행 전체를 멈춘다. 판정을 못 하면 「없다」고 단정하지 않는다(fail-open) — 실행기의 검증이 마지막 방어선이다.
     *
     * @scenario target=target_check_error
     *
     * @effects target_check_error_fails_open_and_warns_once
     *
     * @return void 반환값 없음
     */
    public function test_target_check_error_fails_open_and_warns_once(): void
    {
        $error = 'acme-kernel-command-boot-failure-'.Str::lower(Str::random(8));
        $present = $this->row(['command' => 'inspire'], ExtensionOwnerType::Core, 'core');
        $other = $this->row(['command' => 'acme-stage-only:kernel-broken']);

        $this->mock(ConsoleKernel::class, function (MockInterface $kernel) use ($error): void {
            $kernel->shouldReceive('all')->andThrow(new RuntimeException($error));
        });
        Log::spy();

        for ($tick = 0; $tick < 3; $tick++) {
            $events = $this->register();

            $this->assertTrue($this->runs($events, $present), '대상 판정 예외로 있는 명령의 행까지 막는다');
            $this->assertTrue($this->runs($events, $other), '대상 판정을 못 했는데 없는 명령으로 단정해 건너뛴다');
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (mixed $message, mixed $context = []): bool => is_array($context) && str_contains((string) ($context['error'] ?? ''), $error))
            ->once();
    }

    /**
     * 행의 이벤트가 등록돼 있고 실행기가 실행할지(필터 통과) 판정합니다 — 두 실행기 모두 `filtersPass()` 가 false 면 건너뛴다.
     *
     * @param  array<string, Event>  $events  이름 → 이벤트
     * @param  ScheduleModel  $row  스케줄 행
     * @return bool 실행하면 true
     */
    private function runs(array $events, ScheduleModel $row): bool
    {
        $event = $events["schedule:{$row->id}"] ?? null;

        return $event !== null && $event->filtersPass($this->app);
    }

    /**
     * 활성 스케줄 행을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @param  ExtensionOwnerType|null  $ownerType  소유 타입
     * @param  string|null  $ownerIdentifier  소유 식별자
     * @return ScheduleModel 저장된 행
     */
    private function row(array $attributes = [], ?ExtensionOwnerType $ownerType = null, ?string $ownerIdentifier = null): ScheduleModel
    {
        $factory = ScheduleModel::factory();

        if ($ownerType !== null) {
            $factory = $factory->ownedBy($ownerType, (string) $ownerIdentifier);
        }

        return $factory->create($attributes);
    }

    /**
     * 새 스케줄러에 등록하고 이벤트를 이름으로 모읍니다.
     *
     * @return array<string, Event> 이름 → 이벤트
     */
    private function register(): array
    {
        $schedule = new Schedule;
        app(ScheduleRegistrar::class)->register($schedule);

        $events = [];
        foreach ($schedule->events() as $event) {
            $events[(string) $event->description] = $event;
        }

        return $events;
    }
}
