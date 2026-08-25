<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 시그널로 죽은 워커의 고착 상태를 「다음 요청」이 실패로 승격하는지에 대한 회귀 테스트 (13차 환경#10).
 *
 * 배경:
 *   12차 #A2 가 `installerPromoteFatalShutdownToFailure()` 로 「요청이 갑자기 죽으면 상태가
 *   running 에 고착한다」를 닫았다고 기록했다. 그러나 그 승격은 `error_get_last()` 의 타입이
 *   E_ERROR / E_PARSE / E_CORE_ERROR / E_COMPILE_ERROR 일 때만 발동한다. 프로세스가 **시그널로**
 *   죽는 경우 — php-fpm 의 `request_terminate_timeout`(SIGTERM/SIGKILL), LiteSpeed 의
 *   `lsapi_max_process_time`, 컨테이너 cgroup OOM-kill — 는 shutdown 함수 자체가 돌지 않고
 *   `error_get_last()` 도 없다. 즉 mod_php 밖에서는 12차가 닫았다고 적은 구멍이 그대로 열려 있다.
 *
 *   증상은 오류가 아니라 **선택지의 부재**다: `installation_status` 가 running 인 채
 *   `failed_task` 가 비어 남으므로 `markInstallationTaskSkipped()` 의 요구(failed + failed_task)가
 *   성립하지 않아 [건너뛰고 계속] 이 원리상 불가능해지고, 운영자에게 남는 탈출구는 완료 마커를
 *   전소거하는 [처음부터 다시] 뿐이다.
 *
 * 고정하는 계약 (PO 결정 — 시그널에 영향받지 않는 유일한 채널은 「다음 요청」이다):
 *  - 워커는 진입 시 자기 시작 시각(`worker_started_at`)과 선언 예산(`worker_stale_after`)을 남긴다.
 *  - 어떤 진입점이든 상태를 읽을 때(단일 지점 `getInstallationState()`) 「running 인데 그 워커의
 *    선언 예산을 이미 넘겼다」면 실패로 승격한다 — 필드 집합은 치명 종료 승격과 **동일**하다.
 *  - 살아 있는 워커는 절대 죽이지 않는다: 자기 자신(점유자 프로세스) · heartbeat 가 아직 예산
 *    안인 워커 · 예산을 선언하지 않은 구 상태 파일 · 시계 역행(경과 음수)은 승격 대상이 아니다.
 *  - 정상 완료 · 중단 · 이미 실패한 설치는 건드리지 않는다(멱등).
 *
 * 이 테스트의 한계 (PO 지시로 명시):
 *   이 테스트는 시그널 종료를 **흉내낸 상태**(선언 예산을 넘긴 lock 흔적)로 판정을 검사한다.
 *   실제 SIGKILL 은 재현하지 않는다 — PHPUnit 프로세스에서 php-fpm 워커를 강제 종료시킬 수
 *   없고, 이 저장소의 P0 검증 환경은 Windows + Apache mod_php 라 원리상 그 종료가 발생하지
 *   않는다. 따라서 여기서 초록인 것은 「판정 규칙이 옳다」까지이며, 「실제 FPM 환경에서 이
 *   채널이 작동한다」는 G8 실측(계획서 §16.19.7)이 담당한다.
 *
 * 커버 축: task_outcome failed / resume_point retry_after_failure / installation_mode stepwise
 *
 * @effects killed_worker_promoted_by_next_request, killed_worker_promotion_keeps_skip_available, killed_worker_promotion_never_kills_a_live_worker, killed_worker_promotion_is_idempotent
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerKilledWorkerRecoveryTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 상태 파일 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerKilledWorkerRecoveryTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-killedworker-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/run-task.php';
        }

        $root = dirname(__DIR__, 3);
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::boot();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach ([
            self::$base.'/storage/installer-state.json',
            self::$base.'/storage/logs/installation.log',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * 시그널로 죽은 워커가 남긴 상태를 만든다 — lock 흔적은 남고 heartbeat 는 멈춰 있다.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedKilledWorker(array $overrides = []): void
    {
        $now = time();

        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['installation_mode'] = 'stepwise';
        $state['current_task'] = 'module_install:sirsoft-board';
        $state['completed_tasks'] = ['env_update', 'db_migrate'];
        // 180초를 선언하고 시작했는데 1000초 전이 마지막 흔적이다 — 그 요청은 이미 존재하지 않는다.
        $state['active_worker_id'] = 'deadbeefdeadbeef';
        $state['worker_started_at'] = $now - 1000;
        $state['last_heartbeat'] = $now - 1000;
        $state['worker_stale_after'] = 180;
        $state = array_replace($state, $overrides);

        $this->assertTrue(saveInstallationState($state));
    }

    public function test_the_next_request_promotes_a_signal_killed_worker_to_failed(): void
    {
        $this->seedKilledWorker();

        // 「다음 요청」은 상태를 읽는 것으로 시작한다 — 승격은 그 단일 지점에서 일어나야 한다.
        $state = getInstallationState();

        $this->assertSame('failed', $state['installation_status'] ?? null);
        $this->assertSame('module_install:sirsoft-board', $state['failed_task'] ?? null);
        $this->assertSame('sirsoft-board', $state['failed_task_target'] ?? null);
        $this->assertTrue((bool) ($state['failed_task_skippable'] ?? false));
        $this->assertSame('error_worker_terminated', $state['error_message_key'] ?? null);

        // 화면이 새로고침해도 같은 결말을 봐야 한다 — 승격은 상태 파일에 남는다.
        $reread = json_decode((string) file_get_contents(self::$base.'/storage/installer-state.json'), true);
        $this->assertSame('failed', $reread['installation_status'] ?? null);
    }

    public function test_promoted_state_makes_skip_and_continue_possible(): void
    {
        $this->seedKilledWorker();

        $result = markInstallationTaskSkipped(getInstallationState());

        $this->assertTrue(
            $result['success'],
            '시그널로 죽은 워커 뒤에도 [건너뛰고 계속] 이 성립해야 한다 — '
            .'승격이 없으면 status 가 running 이라 skip_task_not_failed 로 거부된다.'
        );
        $this->assertContains('module_install:sirsoft-board', $result['skipped']);
    }

    public function test_promotion_writes_the_same_field_set_as_the_fatal_shutdown_channel(): void
    {
        // 치명 종료 채널 (기존 빠른 경로)
        $this->seedKilledWorker([
            'active_worker_id' => null,
            'worker_started_at' => null,
            'last_heartbeat' => null,
            'worker_stale_after' => null,
        ]);
        installerPromoteFatalShutdownToFailure([
            'type' => E_ERROR,
            'message' => 'Allowed memory size exhausted',
            'file' => '/app/x.php',
            'line' => 1,
        ]);
        $fatal = getInstallationState();

        // 시그널 종료 채널 (신설 안전망)
        $this->setUp();
        $this->seedKilledWorker();
        $killed = getInstallationState();

        foreach ([
            'installation_status',
            'failed_task',
            'failed_task_target',
            'failed_task_skippable',
            'error_message_key',
            'error_message_params',
            'error_detail',
            'manual_commands',
        ] as $field) {
            $this->assertArrayHasKey(
                $field,
                $killed,
                "승격 필드 집합이 두 채널에서 다르면 화면이 아는 형태로 그리지 못한다 — 누락: {$field}"
            );
        }

        $this->assertSame($fatal['installation_status'], $killed['installation_status']);
        $this->assertSame($fatal['failed_task'], $killed['failed_task']);
        $this->assertSame($fatal['failed_task_target'], $killed['failed_task_target']);
        $this->assertSame($fatal['failed_task_skippable'], $killed['failed_task_skippable']);
        $this->assertSame($fatal['manual_commands'], $killed['manual_commands']);
    }

    public function test_a_worker_inside_its_declared_budget_is_never_promoted(): void
    {
        $now = time();
        $this->seedKilledWorker([
            'worker_started_at' => $now - 10,
            'last_heartbeat' => $now - 10,
            'worker_stale_after' => 180,
        ]);

        $this->assertSame('running', getInstallationState()['installation_status'] ?? null);
        $this->assertNull(getInstallationState()['failed_task'] ?? null);
    }

    public function test_a_heartbeating_worker_past_its_budget_is_not_promoted(): void
    {
        // 선언 예산은 넘겼지만 heartbeat 는 방금 갱신됐다 — 기존 생존 판정
        // (installerActiveWorkerIsAlive) 이 「살아 있다」고 말하는 워커다. 두 판정이
        // 모순되면 살아 있는 워커의 상태를 실패로 덮어쓴다.
        $now = time();
        $this->seedKilledWorker([
            'worker_started_at' => $now - 1000,
            'last_heartbeat' => $now,
            'worker_stale_after' => 180,
        ]);

        $this->assertSame('running', getInstallationState()['installation_status'] ?? null);
    }

    public function test_the_owning_process_never_promotes_its_own_state(): void
    {
        $this->seedKilledWorker(['active_worker_id' => 'myself']);

        // 이 프로세스가 그 lock 의 점유자다 — 블로킹 태스크로 heartbeat 가 멈췄을 뿐 살아 있다.
        installerCurrentWorkerId('myself');

        $this->assertSame('running', getInstallationState()['installation_status'] ?? null);
    }

    public function test_a_clock_rollback_does_not_promote(): void
    {
        // 시계가 뒤로 가면(NTP 보정·컨테이너 시각 점프) 경과가 음수가 된다 — 워커가 죽은 것이
        // 아니라 시계가 튄 것이므로 승격하지 않는다 (13차 환경#19 와 같은 규칙).
        $now = time();
        $this->seedKilledWorker([
            'worker_started_at' => $now + 5000,
            'last_heartbeat' => $now + 5000,
        ]);

        $this->assertSame('running', getInstallationState()['installation_status'] ?? null);
    }

    public function test_states_without_a_declared_budget_are_left_alone(): void
    {
        // 구 상태 파일에는 시작 시각이 없다 — 판정 근거가 없으므로 종전 동작을 유지한다.
        $this->seedKilledWorker(['worker_started_at' => null]);
        $this->assertSame('running', getInstallationState()['installation_status'] ?? null);

        // 예산 자체가 없는 흔적도 마찬가지다.
        $this->setUp();
        $this->seedKilledWorker(['worker_stale_after' => 0]);
        $this->assertSame('running', getInstallationState()['installation_status'] ?? null);

        // 점유자가 없는 running 은 「요청과 요청 사이」다 (stepwise) — 승격 대상이 아니다.
        $this->setUp();
        $this->seedKilledWorker(['active_worker_id' => null]);
        $this->assertSame('running', getInstallationState()['installation_status'] ?? null);
    }

    public function test_finished_installations_are_not_touched(): void
    {
        foreach (['completed', 'aborted', 'failed'] as $status) {
            $this->setUp();
            $this->seedKilledWorker(['installation_status' => $status, 'failed_task' => null]);

            $this->assertSame(
                $status,
                getInstallationState()['installation_status'] ?? null,
                "installation_status={$status} 는 이미 끝난 설치다 — 승격이 그 결말을 덮으면 안 된다."
            );
        }
    }

    public function test_promotion_is_idempotent_and_clears_the_dead_lock(): void
    {
        $this->seedKilledWorker();

        $first = getInstallationState();
        $second = getInstallationState();

        $this->assertSame('failed', $second['installation_status'] ?? null);
        $this->assertSame($first['failed_task'], $second['failed_task']);

        // 죽은 워커의 점유 흔적이 남으면 이후 재개가 그 예산만큼 거짓 busy 로 튕긴다.
        $this->assertArrayNotHasKey('active_worker_id', $second);
        $this->assertArrayNotHasKey('worker_started_at', $second);
        $this->assertArrayNotHasKey('worker_stale_after', $second);
    }

    public function test_lock_acquisition_records_the_start_time_and_release_clears_it(): void
    {
        $lock = acquireWorkerLock(180);
        $this->assertTrue($lock['acquired']);

        $held = getInstallationState();
        $this->assertIsInt($held['worker_started_at'] ?? null);
        $this->assertGreaterThan(0, (int) $held['worker_started_at']);
        $this->assertSame(180, (int) ($held['worker_stale_after'] ?? 0));

        releaseWorkerLock((string) $lock['worker_id']);

        $released = getInstallationState();
        $this->assertArrayNotHasKey('worker_started_at', $released);
        $this->assertArrayNotHasKey('worker_stale_after', $released);
    }

    public function test_clear_worker_lock_drops_the_recorded_start_time(): void
    {
        // 중단·초기화 경로는 lock 흔적을 통째로 지운다 — 시작 시각만 남으면 다음 점유자의
        // 예산 판정이 남의 시각을 기준으로 돈다.
        $cleared = clearWorkerLock([
            'active_worker_id' => 'x',
            'last_heartbeat' => time(),
            'worker_stale_after' => 600,
            'worker_started_at' => time(),
        ]);

        $this->assertArrayNotHasKey('worker_started_at', $cleared);
        $this->assertArrayNotHasKey('active_worker_id', $cleared);
    }

    public function test_the_judgment_lives_in_the_single_state_read_point(): void
    {
        // 세 진입점에 흩어 놓으면 한 곳만 빠져도 그 모드에서 같은 고착이 남는다 —
        // 이 저장소가 12라운드 동안 반복해서 겪은 형태다. 판정은 상태를 읽는 단일 지점이 한다.
        $source = $this->stripComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/installer-state.php')
        );

        $this->assertMatchesRegularExpression(
            '/function getInstallationState\(\).*installerRecoverKilledWorkerState\(/s',
            $source,
            'getInstallationState() 가 승격 채널을 경유하지 않는다 — 그러면 어떤 진입점이 그 판정을 하는지가 흩어진다.'
        );
    }
}
