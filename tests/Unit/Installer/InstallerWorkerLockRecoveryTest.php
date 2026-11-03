<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 워커 lock 복구 경로 · 롤백 디스패치 키 회귀 테스트 (4차 검증 라운드).
 *
 * 고정하는 계약:
 *
 *  - 롤백 디스패치는 확장 태스크의 합성 키("module_install:sirsoft-board")를 받아도
 *    bare id 와 같은 판정을 낸다. 합성 키는 completed_tasks 비교용으로 도입됐고
 *    (검증 3차 라운드), current_task 를 그대로 넘겨받는 중단·연결끊김 경로가 이를
 *    미지원하면 "롤백 불필요" 가 "알 수 없는 작업" 으로 뒤집혀 운영자에게 거짓
 *    "수동 정리 필요" 가 표시된다.
 *  - 점유자가 선언한 시간 예산(worker_stale_after)은 stale 판정의 하한이지만,
 *    죽은 워커의 잔존 선언이 복구를 영구히 막아서는 안 된다 — 재시작·초기화 경로가
 *    clearWorkerLock() 으로 워커 키를 비운다.
 *
 * BASE_PATH 는 temp 로 고정한다 (R7). 인스톨러 테스트 여럿이 실 저장소 루트를
 * BASE_PATH 로, 또는 자기 temp 를 STATE_PATH 로 define 하므로 같은 프로세스에서
 * 상수가 엇갈리면 이 클래스가 남의 상태 파일을 보게 된다 — 메서드마다 새 프로세스에서 돌린다.
 *
 * 커버 축: task_outcome done·aborted / resume_point retry_after_failure / endpoint_guard general
 *
 * 효과 요약(마커 아님 — 평문): 롤백 디스패치가 합성 키를 받고, 중단과 초기화가 워커 lock 을 비우며, 점유자 선언 창이 하한이고, 태스크 본문 안의 lock 상실은 실패로 바뀌지 않으며, lock 상실 판정을 세 송신기가 공유한다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerWorkerLockRecoveryTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 프로세스마다 한 번 임시 BASE_PATH 와 저장소 디렉토리를 만들고 인스톨러 런타임, 태스크 러너, 롤백 함수를 적재한다.
     *
     * BASE_PATH 가 이미 시스템 임시 디렉토리 밖으로 정의돼 있으면 적재하지 않고 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    private static function bootInstallerRuntime(): void
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerWorkerLockRecoveryTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-lockrecovery-'.bin2hex(random_bytes(4));
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
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
        require_once $root.'/public/install/api/rollback-functions.php';
    }

    /**
     * 런타임을 적재하고 상태 파일과 설치 로그를 지운 뒤 진행 기록을 버퍼 송신기로 받는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::bootInstallerRuntime();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach ([
            self::$base.'/storage/installer-state.json',
            self::$base.'/storage/logs/installation.log',
        ] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        setProgressEmitter(new \BufferEmitter);
    }

    /**
     * 중단·연결끊김 경로는 current_task 를 그대로 rollbackTask 로 넘긴다.
     * 확장 태스크의 current_task 는 합성 키이므로 디스패치가 이를 해석해야 한다.
     *
     * @scenario task_outcome=aborted, task=module
     *
     * @effects rollback_dispatch_accepts_composite_task_key
     *
     * @return void 반환값 없음
     */
    public function test_rollback_dispatch_accepts_composite_extension_task_key(): void
    {
        $state = ['completed_tasks' => []];

        $result = rollbackTask('module_install:sirsoft-board', $state);

        $this->assertTrue(
            $result['success'],
            '확장 설치 태스크는 덮어쓰기 가능이라 롤백 불필요(success=true) 여야 한다 — '
            .'합성 키를 해석하지 못하면 rollback_unknown_task 로 떨어져 거짓 "수동 정리 필요" 가 표시된다.'
        );
        $this->assertSame(lang('rollback_not_needed_overwritable'), $result['message']);
    }

    /**
     * bare id 는 종전과 동일하게 동작한다 (합성 키 지원이 기존 판정을 바꾸지 않는다).
     *
     * @scenario task_outcome=aborted, task=module, task=env
     *
     * @effects rollback_dispatch_accepts_composite_task_key
     *
     * @return void 반환값 없음
     */
    public function test_rollback_dispatch_keeps_bare_task_id_behaviour(): void
    {
        $state = ['completed_tasks' => []];

        $this->assertSame(
            lang('rollback_not_needed_overwritable'),
            rollbackTask('module_install', $state)['message']
        );
        $this->assertSame(
            lang('rollback_not_needed_recreatable'),
            rollbackTask('env_create', $state)['message']
        );
    }

    /**
     * 정말로 알 수 없는 태스크는 여전히 실패로 남는다 (가드가 넓어지지 않았다).
     *
     * @scenario task_outcome=aborted
     *
     * @effects rollback_dispatch_accepts_composite_task_key
     *
     * @return void 반환값 없음
     */
    public function test_rollback_dispatch_still_rejects_unknown_task(): void
    {
        $result = rollbackTask('no_such_task:target', ['completed_tasks' => []]);

        $this->assertFalse($result['success']);
    }

    /**
     * 태스크 본문 도중 lock 을 빼앗기면 그 요청은 **물러나야** 한다.
     *
     * 태스크 함수는 자기 실패를 `['success' => false]` 로 돌려주는데, lock 손실까지
     * 그렇게 변환하면 호출자가 롤백을 돌리고 installation_status=failed 를 기록한다 —
     * 이미 다른 요청(승자)이 이어받아 진행 중인 설치 위에 실패를 덮어쓰는 것이다.
     * 그래서 이 예외만은 catch (Exception) 을 통과해야 한다.
     *
     * @scenario installation_mode=stepwise, task=settings
     *
     * @effects lock_loss_inside_task_body_is_not_a_task_failure
     *
     * @return void 반환값 없음
     */
    public function test_lock_lost_inside_task_body_is_not_converted_into_task_failure(): void
    {
        $root = dirname(__DIR__, 3);
        @mkdir(self::$base.'/config/settings', 0755, true);
        @copy($root.'/config/settings/defaults.json', self::$base.'/config/settings/defaults.json');

        saveInstallationState([
            'installation_status' => 'running',
            'completed_tasks' => [],
            'config' => ['execution_mode' => 'auto'],
        ]);

        // try 블록 진입 전 emit 3개(task_start + log 2)는 통과시키고, 본문 안에서 빼앗긴다.
        // 인터페이스가 부팅 시점에 로드되므로 익명 클래스로 그 뒤에 만든다.
        setProgressEmitter(new class(3) implements \ProgressEmitter
        {
            private int $seen = 0;

            /**
             * 통과시킬 진행 이벤트 수를 받아 둔다.
             *
             * @param  int  $passThrough  예외 없이 통과시킬 이벤트 수
             */
            public function __construct(private readonly int $passThrough) {}

            /**
             * 정해 둔 수만큼은 통과시키고 그 뒤 이벤트에서 워커 lock 상실 예외를 던진다.
             *
             * @param  string  $event  진행 이벤트 이름
             * @param  array<string, mixed>  $data  이벤트 데이터
             * @return void 반환값 없음
             */
            public function emit(string $event, array $data): void
            {
                $this->seen++;

                if ($this->seen > $this->passThrough) {
                    throw new \InstallerWorkerLockLostException('worker-stub');
                }
            }

            /**
             * 클라이언트 연결은 늘 살아 있는 것으로 답한다.
             *
             * @return bool 연결 끊김 여부 (항상 false)
             */
            public function isConnectionAborted(): bool
            {
                return false;
            }
        });

        $this->expectException(\InstallerWorkerLockLostException::class);

        createSettingsJsonSSE();
    }

    /**
     * 점유자가 선언한 시간 예산은 하한이다 — 호출자가 더 짧은 값을 줘도 탈취하지 않는다.
     *
     * @scenario installation_mode=stepwise, runner_strategy=inprocess
     *
     * @effects worker_stale_window_declared_by_owner_is_lower_bound
     *
     * @return void 반환값 없음
     */
    public function test_owner_declared_stale_window_is_a_lower_bound(): void
    {
        $first = acquireWorkerLock(15);
        $this->assertTrue($first['acquired']);

        $this->assertTrue(extendWorkerStaleWindow($first['worker_id'], 600));

        $state = getInstallationState();
        $state['last_heartbeat'] = time() - 120;
        saveInstallationState($state);

        // 120초 경과 — 호출자 인자(15)만 보면 stale 이지만 점유자가 600 을 선언했다
        $second = acquireWorkerLock(15);

        $this->assertFalse($second['acquired'], '점유자 선언(600s) 이 호출자 인자(15s) 보다 우선해야 한다');
        $this->assertSame('busy', $second['reason']);
    }

    /**
     * 죽은 워커의 잔존 선언이 복구를 영구히 막지 않는다 —
     * 재시작·초기화 경로가 워커 키를 비우면 즉시 다시 점유할 수 있다.
     *
     * @scenario resume_point=retry_after_failure
     *
     * @effects worker_lock_cleared_on_abort_or_reset_allows_reacquire
     *
     * @return void 반환값 없음
     */
    public function test_clear_worker_lock_allows_reacquire_after_dead_worker(): void
    {
        $first = acquireWorkerLock(15);
        $this->assertTrue($first['acquired']);
        extendWorkerStaleWindow($first['worker_id'], 600);

        // 프로세스가 강제 종료돼 release 가 실행되지 않은 상태를 만든다
        $state = getInstallationState();
        $state['last_heartbeat'] = time() - 300;
        saveInstallationState($state);

        $this->assertFalse(acquireWorkerLock(15)['acquired'], '전제: 잔존 선언이 재점유를 막는다');

        $state = getInstallationState();
        $state = clearWorkerLock($state);
        saveInstallationState($state);

        $after = getInstallationState();
        $this->assertArrayNotHasKey('worker_stale_after', $after);
        $this->assertNull($after['active_worker_id'] ?? null);

        $recovered = acquireWorkerLock(15);
        $this->assertTrue($recovered['acquired'], '워커 키를 비운 뒤에는 재점유가 가능해야 한다');
    }

    /**
     * 완료 표시(complete_flag) 태스크도 lock 손실을 실패로 변환하지 않는다.
     *
     * 이 태스크의 롤백은 runtime.php 를 지운다 — 그 안에 stepwise 토큰이 들어 있으므로,
     * lock 을 빼앗긴 요청이 자기 실패로 오인해 롤백을 부르면 **승자 요청의 토큰까지 사라져**
     * 브라우저의 다음 run-task 가 403 이 된다(화면에서 복구 불가). 다른 두 태스크 본문과
     * 같은 이유이며, 판정 기준도 같다 — try 안에서 emit 하는 태스크 함수.
     *
     * @scenario installation_mode=stepwise, task=complete_flag
     *
     * @effects lock_loss_inside_task_body_is_not_a_task_failure
     *
     * @return void 반환값 없음
     */
    public function test_lock_lost_inside_complete_flag_is_not_converted_into_task_failure(): void
    {
        saveInstallationState([
            'installation_status' => 'running',
            'completed_tasks' => [],
            'config' => ['execution_mode' => 'auto'],
        ]);

        // try 블록 진입 전 emit 2개(task_start + log)는 통과시키고, 본문 안에서 빼앗긴다.
        setProgressEmitter(new class(2) implements \ProgressEmitter
        {
            private int $seen = 0;

            /**
             * 통과시킬 진행 이벤트 수를 받아 둔다.
             *
             * @param  int  $passThrough  예외 없이 통과시킬 이벤트 수
             */
            public function __construct(private readonly int $passThrough) {}

            /**
             * 정해 둔 수만큼은 통과시키고 그 뒤 이벤트에서 워커 lock 상실 예외를 던진다.
             *
             * @param  string  $event  진행 이벤트 이름
             * @param  array<string, mixed>  $data  이벤트 데이터
             * @return void 반환값 없음
             */
            public function emit(string $event, array $data): void
            {
                $this->seen++;

                if ($this->seen > $this->passThrough) {
                    throw new \InstallerWorkerLockLostException('worker-stub');
                }
            }

            /**
             * 클라이언트 연결은 늘 살아 있는 것으로 답한다.
             *
             * @return bool 연결 끊김 여부 (항상 false)
             */
            public function isConnectionAborted(): bool
            {
                return false;
            }
        });

        $this->expectException(\InstallerWorkerLockLostException::class);

        setInstallationCompleteSSE();
    }

    /**
     * 중단(abort)도 "이 실행은 무효" 가 확정된 경로다 — 워커 키를 비운다.
     *
     * 죽은 in-process 워커가 남긴 worker_stale_after(600) 는 stale 판정의 하한이므로,
     * 중단 후 [계속 진행] 이 최대 10분간 거짓 busy 로 막힌다. clearWorkerLock 의 계약은
     * "재시작·초기화" 인데 초기화(reset)에만 배선돼 있던 것을 중단까지 넓힌다.
     *
     * @scenario task_outcome=aborted, resume_point=retry_after_failure
     *
     * @effects worker_lock_cleared_on_abort_or_reset_allows_reacquire
     *
     * @return void 반환값 없음
     */
    public function test_abort_state_transition_clears_worker_lock(): void
    {
        $first = acquireWorkerLock(15);
        $this->assertTrue($first['acquired']);
        extendWorkerStaleWindow($first['worker_id'], 600);

        $state = getInstallationState();
        $state['installation_status'] = 'running';
        $state['last_heartbeat'] = time() - 300;
        saveInstallationState($state);

        $this->assertFalse(acquireWorkerLock(15)['acquired'], '전제: 잔존 선언이 재점유를 막는다');

        // 중단 경로가 상태에 적용하는 전이 (state-management.php::abortInstallation 과 동일 지점)
        $state = getInstallationState();
        $state = installerApplyAbortedState($state);
        saveInstallationState($state);

        $this->assertSame('aborted', $state['installation_status']);
        $this->assertTrue(
            acquireWorkerLock(15)['acquired'],
            '중단 후에는 재점유가 가능해야 한다 — 아니면 [계속 진행] 이 거짓 busy 로 막힌다'
        );
    }

    /**
     * lock 을 잡은 진입점과 그 뒤 시간 예산을 늘리는 코드가 같은 lock 을 가리킨다.
     *
     * 두 지점은 인자를 주고받지 않으므로 `installerCurrentWorkerId()` 가 그 유일한 통로다 —
     * 이 왕복이 끊기면 in-process 부팅이 예산을 늘려도 lock 창은 그대로라 아직 살아 있는
     * 요청을 다른 요청이 탈취해 같은 태스크를 겹쳐 돌린다.
     *
     * @scenario installation_mode=stepwise, runner_strategy=inprocess
     *
     * @effects worker_stale_window_declared_by_owner_is_lower_bound
     *
     * @return void 반환값 없음
     */
    public function test_current_worker_id_round_trips_between_entry_point_and_task_body(): void
    {
        $this->assertNull(installerCurrentWorkerId(), '기억된 값이 없으면 null');

        $lock = acquireWorkerLock(15);
        $this->assertTrue($lock['acquired']);

        // 진입점이 기억시키고
        $this->assertSame($lock['worker_id'], installerCurrentWorkerId($lock['worker_id']));

        // 태스크 본문이 인자 없이 조회한다
        $this->assertSame($lock['worker_id'], installerCurrentWorkerId());
    }

    /**
     * in-process 부팅의 시간 예산 상수가 lock 창으로 그대로 전달된다.
     *
     * 요청 수명(INSTALLER_INPROCESS_TIME_LIMIT)과 선언한 stale 창이 어긋나면 둘 중 하나다 —
     * 창이 짧으면 겹침 실행, 길면 죽은 워커가 복구를 막는다. 그래서 같은 값이어야 한다.
     *
     * @scenario runner_strategy=inprocess
     *
     * @effects worker_stale_window_declared_by_owner_is_lower_bound
     *
     * @return void 반환값 없음
     */
    public function test_inprocess_time_limit_becomes_the_declared_stale_window(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/artisan-executor.php';

        $this->assertTrue(defined('INSTALLER_INPROCESS_TIME_LIMIT'));

        $lock = acquireWorkerLock(15);
        $this->assertTrue($lock['acquired']);
        $this->assertSame(15, getInstallationState()['worker_stale_after'] ?? null);

        installerCurrentWorkerId($lock['worker_id']);
        $this->assertTrue(extendWorkerStaleWindow(installerCurrentWorkerId(), INSTALLER_INPROCESS_TIME_LIMIT));

        $this->assertSame(
            INSTALLER_INPROCESS_TIME_LIMIT,
            getInstallationState()['worker_stale_after'] ?? null,
            'in-process 요청 수명이 곧 선언한 stale 창이어야 한다'
        );
    }

    /**
     * 세 emitter 가 공유하는 lock 상실 판정 (11차 #D13).
     *
     * 종전에는 같은 판정이 세 사본에 흩어져 있었고 **예외로 끝나는 한 사본만** 행위
     * 테스트가 덮었다. 나머지 둘(SSE·폴링)은 조기 반환 조건을 뒤집어도 초록이라, lock 을
     * 뺏긴 워커가 계속 돌아 두 요청이 같은 DB 를 조작하는 상태가 검사 밖이었다.
     *
     * @scenario installation_mode=sse, installation_mode=polling, installation_mode=stepwise
     *
     * @effects installer_worker_lock_lost_judgment_is_shared
     *
     * @return void 반환값 없음
     */
    public function test_worker_lock_lost_judgment_is_shared_by_all_emitters(): void
    {
        $first = acquireWorkerLock(15);
        $this->assertTrue($first['acquired']);
        $workerId = $first['worker_id'];

        // 점유 중이면 물러나지 않는다
        $this->assertFalse(installerWorkerLockLost($workerId, false));

        // 판정하지 않는 두 경우 — worker_id 가 없거나 이미 물러나는 중
        $this->assertFalse(installerWorkerLockLost(null, false), 'worker_id 가 없으면 판정 대상이 아니다');
        $this->assertFalse(installerWorkerLockLost($workerId, true), '이미 물러나는 중이면 다시 판정하지 않는다');

        // 다른 요청이 점유를 가져가면 물러난다
        $state = getInstallationState();
        $state['active_worker_id'] = 'someone-else';
        saveInstallationState($state);

        $this->assertTrue(installerWorkerLockLost($workerId, false), '점유를 잃었으면 물러나야 한다 — 아니면 두 요청이 같은 DB 를 조작한다');
    }

    /**
     * 세 emitter 가 **그 판정을 실제로 부르는가** — 사본이 하나라도 남으면 그 경로만 뒤처진다.
     *
     * @scenario installation_mode=sse, installation_mode=polling, installation_mode=stepwise
     *
     * @effects installer_worker_lock_lost_judgment_is_shared
     *
     * @return void 반환값 없음
     */
    public function test_all_three_emitters_call_the_shared_judgment(): void
    {
        // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 배선도 글자로 남아, 원문 대조는 「그 배선이 살아 있다」를 증명하지 못한다 (12차 메타 검사 ①).
        $source = $this->stripComments((string) file_get_contents(
            dirname(__DIR__, 3).'/public/install/includes/progress-emitter.php'
        ));

        $this->assertSame(
            3,
            substr_count($source, 'installerWorkerLockLost($this->workerId'),
            'SSE·폴링·Buffer 세 emitter 가 모두 공유 판정을 불러야 한다'
        );
        $this->assertStringNotContainsString(
            'refreshWorkerHeartbeat($this->workerId)',
            $source,
            '판정을 인라인으로 되돌리면 그 사본만 검사 밖이 된다'
        );
    }
}
