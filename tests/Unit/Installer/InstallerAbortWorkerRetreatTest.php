<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 중단은 진행 중인 작업이 물러난 뒤에 되돌린다 (제품 결정 13차 — 11차 이월분).
 *
 * 종전 중단 처리는 `rollbackCurrentTask()` 를 **즉시** 불렀다. 그런데 워커는 태스크
 * 경계에서만 중단 표식을 확인하므로, 되돌리기가 **아직 실행 중인 그 태스크와 겹칠 수** 있다.
 * 마이그레이션이 도는 중에 그 마이그레이션을 되돌리는 형태다.
 *
 * 11차는 이 축을 「lock 을 남기는 방식」으로 좁히려다 철회했다(§16.17.4) — heartbeat 는
 * 블로킹 구간에서 갱신되지 않아 「살아 있음」 판정이 죽은 워커와 구분되지 않고, 좁히면
 * 8차가 닫은 「중단 후 최대 10분 거짓 busy」가 되살아난다.
 *
 * 제품 결정은 **기다린다** 이다. 다만 세 가지가 함께 성립해야 한다:
 *
 *  ① 중단 표식을 **먼저** 기록한다 — 그래야 워커가 다음 경계에서 물러난다.
 *  ② 「물러났다」의 판정은 워커 lock 해제 또는 그 시간 예산 경과다.
 *  ③ **무한 대기는 없다** — 상한을 넘으면 기다리지 않고 진행하고 그 사실을 기록한다.
 *     중단은 어떤 경우에도 완료되어야 한다(11차가 철회한 이유가 「탈출구가 막히는 것」이었다).
 *
 * 커버 축: worker_liveness already_gone·retreats_during_wait·never_retreats /
 *          wait_outcome immediate·waited·capped / request_budget unlimited·bounded
 *
 * @effects installer_abort_waits_for_the_running_task_to_step_down, installer_abort_proceeds_after_the_wait_cap, installer_abort_marker_precedes_rollback
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerAbortWorkerRetreatTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerAbortWorkerRetreatTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-abortwait-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/state-management.php';
        }

        $root = dirname(__DIR__, 3);

        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
    }

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
    }

    /**
     * 살아 있는 워커가 점유한 상태.
     *
     * @return array<string, mixed>
     */
    private function occupiedState(): array
    {
        return [
            'installation_status' => 'running',
            'current_task' => 'db_migrate',
            'completed_tasks' => ['env_update'],
            'active_worker_id' => 'worker-alive',
            'last_heartbeat' => time(),
            'worker_stale_after' => 180,
        ];
    }

    public function test_no_wait_when_the_worker_has_already_stepped_down(): void
    {
        $slept = 0;

        $result = installerWaitForWorkerToRetreat(
            15,
            static fn (): array => ['installation_status' => 'aborted', 'current_task' => 'db_migrate'],
            static function (int $ms) use (&$slept): void {
                $slept++;
            },
        );

        $this->assertTrue($result['retreated']);
        $this->assertSame(0, $result['waited_ms'], '이미 물러난 작업을 기다리면 중단이 그만큼 늦어진다');
        $this->assertSame(0, $slept, '대기 호출이 한 번도 없어야 한다');
    }

    public function test_waits_until_the_running_worker_steps_down(): void
    {
        $reads = 0;
        $slept = 0;

        $result = installerWaitForWorkerToRetreat(
            15,
            function () use (&$reads): array {
                $reads++;

                // 세 번째 확인에서 워커가 물러난다 (lock 해제).
                return $reads >= 3 ? ['installation_status' => 'aborted'] : $this->occupiedState();
            },
            static function (int $ms) use (&$slept): void {
                $slept++;
            },
        );

        $this->assertTrue($result['retreated'], '워커가 물러났는데 대기가 끝나지 않았다');
        $this->assertSame(2, $slept, '물러날 때까지만 기다려야 한다');
        $this->assertSame(2 * INSTALLER_ABORT_WAIT_POLL_MS, $result['waited_ms']);
    }

    /**
     * 상한을 넘으면 기다리지 않고 진행한다 — 중단은 어떤 경우에도 완료되어야 한다.
     */
    public function test_gives_up_waiting_at_the_cap_and_reports_it(): void
    {
        $slept = 0;

        $result = installerWaitForWorkerToRetreat(
            1,
            fn (): array => $this->occupiedState(),
            static function (int $ms) use (&$slept): void {
                $slept++;
            },
        );

        $this->assertFalse($result['retreated'], '물러나지 않았다는 사실이 호출자에게 전달되어야 한다 (진행 기록용)');
        $this->assertSame(1000, $result['waited_ms'], '상한을 넘겨 기다리면 중단 응답 자체가 끊긴다');
        $this->assertSame(intdiv(1000, INSTALLER_ABORT_WAIT_POLL_MS), $slept);
    }

    /**
     * 대기 상한은 이 요청에 실제로 적용된 시간 예산에서 도출한다.
     */
    public function test_wait_cap_leaves_room_for_the_rollback_inside_the_request_budget(): void
    {
        // 무제한(0)·판정 불가면 상한을 그대로 쓴다 — CLI 가 그렇다.
        $this->assertSame(INSTALLER_ABORT_WAIT_MAX_SECONDS, installerAbortWaitCapSeconds(0));
        $this->assertSame(INSTALLER_ABORT_WAIT_MAX_SECONDS, installerAbortWaitCapSeconds(-1));

        // 예산의 1/3 만 대기에 쓴다 — 나머지는 되돌리기 몫이다.
        $this->assertSame(10, installerAbortWaitCapSeconds(30));
        $this->assertSame(2, installerAbortWaitCapSeconds(6));

        // 예산이 넉넉해도 상한을 넘지 않는다.
        $this->assertSame(INSTALLER_ABORT_WAIT_MAX_SECONDS, installerAbortWaitCapSeconds(600));

        // 예산이 극단적으로 짧으면 기다리지 않는다 (되돌리기가 우선).
        $this->assertSame(0, installerAbortWaitCapSeconds(2));
    }

    /**
     * 중단 표식은 워커 lock 흔적과 current_task 를 **남긴다**.
     *
     * 지우면 「그 작업이 실제로 물러났는가」를 볼 근거가 사라지고, 되돌리기 대상도 함께 사라진다.
     */
    public function test_abort_marker_preserves_the_evidence_the_wait_needs(): void
    {
        saveInstallationState($this->occupiedState());

        $this->assertTrue(installerMarkAbortRequested());

        $state = getInstallationState();

        $this->assertSame('aborted', $state['installation_status'], '워커는 이 표식을 보고 다음 경계에서 물러난다');
        $this->assertSame('worker-alive', $state['active_worker_id'] ?? null, '물러남 판정 근거인 lock 흔적이 사라졌다');
        $this->assertSame(180, $state['worker_stale_after'] ?? null, '점유자가 선언한 시간 예산이 사라졌다');
        $this->assertSame('db_migrate', $state['current_task'] ?? null, '되돌리기 대상이 사라졌다');
        $this->assertSame('abort_reason_user', $state['abort_reason'] ?? null);
    }

    public function test_abort_marker_is_a_noop_when_the_installation_is_not_running(): void
    {
        saveInstallationState(['installation_status' => 'completed', 'current_task' => null]);

        $this->assertFalse(installerMarkAbortRequested(), '끝난 설치를 중단으로 되돌리면 완료 화면이 뒤집힌다');
        $this->assertSame('completed', getInstallationState()['installation_status']);
    }

    /**
     * 순서가 계약이다 — 표식 → 대기 → 되돌리기.
     *
     * 표식이 되돌리기 뒤에 있으면 워커는 물러날 기회를 얻지 못하고, 대기가 빠지면 이 조치
     * 전체가 사문이 된다. 세 함수가 모두 존재하는 것만으로는 겹침이 막히지 않는다.
     */
    public function test_abort_endpoint_marks_then_waits_then_rolls_back(): void
    {
        $source = $this->stripComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/state-management.php')
        );

        $this->assertMatchesRegularExpression(
            '/installerMarkAbortRequested\(\)[\s\S]*?installerWaitForWorkerToRetreat\([\s\S]*?rollbackCurrentTask\(/',
            $source,
            "중단 처리의 순서가 「표식 → 대기 → 되돌리기」가 아니다.\n"
            .'되돌리기가 앞서면 아직 실행 중인 그 태스크와 겹친다 — 예외도 오류도 남지 않는다.',
        );
    }

    /**
     * 17차 #B1 — 블로킹 구간에 들어가기 전에 세션 잠금을 놓는다.
     *
     * PHP 기본 세션 핸들러는 스크립트 종료까지 세션 파일을 배타 잠근다. 중단은 확정 대기
     * (최대 15초) + DB 롤백으로 블로킹하는데, 그동안 같은 세션의 폴링 조회(`?action=get` —
     * **같은 파일의 다른 분기**)가 `session_start()` 에서 전부 줄을 선다. 화면은 오류 없이
     * 얼어붙고, 90초를 넘기면 정지 감지가 「응답이 없습니다」라는 틀린 사유로 끝낸다.
     *
     * 형제 `install-process.php` 는 인라인 워커 앞에서 같은 조치를 하고 그 이유를 주석에 적어
     * 두었다 — 13차 결정 3 이 이 분기에 대기를 넣으면서 그 조치를 승계하지 않았다.
     *
     * 존재만 보면 대기 **뒤**에 놓아도 초록이므로 순서를 단언한다.
     */
    public function test_abort_releases_the_session_lock_before_blocking(): void
    {
        $source = $this->stripComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/state-management.php')
        );

        $this->assertMatchesRegularExpression(
            '/session_write_close\(\)[\s\S]*?installerWaitForWorkerToRetreat\(/',
            $source,
            "중단이 확정 대기·DB 롤백 전에 세션 잠금을 놓지 않는다.\n"
            .'같은 세션의 폴링 조회가 그 뒤에 줄을 서 화면이 조용히 얼어붙는다.',
        );
    }

    /**
     * 17차 #B1 — 형제 지점도 같은 규율을 지킨다 (비대칭 방지).
     *
     * 인라인 워커(최대 600초)는 이 조치가 없으면 폴링이 통째로 막힌다. 이쪽이 먼저 지키고
     * 있었고 중단만 빠져 있었으므로, 둘을 한 게이트에 묶어 다음에 한쪽만 빠지는 것을 막는다.
     */
    public function test_inline_polling_worker_also_releases_the_session_lock_first(): void
    {
        $source = $this->stripComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/install-process.php')
        );

        $this->assertMatchesRegularExpression(
            '/session_write_close\(\)[\s\S]*?runInstallationTasks\(/',
            $source,
            '인라인 워커가 세션 잠금을 쥔 채 최대 600초를 돌면 폴링 조회가 전부 막힌다.',
        );
    }
}
