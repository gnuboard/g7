<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 워커 점유의 해제와 남은 시간 (D7).
 *
 * 배경 (현장 제보 2026-09-12, 무CLI 단계별 · 공유 호스팅):
 *   플러그인 파일 복사 중 디스크가 찼다. 진행 기록이 **줄 중간에서** 끊긴 것이 그 흔적이고
 *   (`→ resources/extensions/identity_` 뒤에 다음 타임스탬프가 이어붙었다), 실패 사유는
 *   어디에도 남지 않았다 — 사유를 적는 것도 디스크 쓰기이기 때문이다.
 *
 *   그 뒤가 이 테스트가 잠그는 자리다. shutdown 훅은 정상적으로 돌았고 `releaseWorkerLock()`
 *   을 불렀지만, 그 해제 역시 상태 파일 쓰기라 함께 실패했다. 그런데 그 함수는 `void` 였고
 *   호출부는 결과를 보지 않아 **실패가 어디에도 드러나지 않았다.** 점유는 남았고, 운영자가
 *   공간을 비우고 [재시도] 를 눌러도 화면은 「다른 창에서 설치가 이미 진행 중입니다」라고
 *   답했다 — 그 창은 없었다. 실측: `last_heartbeat` 로부터 429초 경과 / `worker_stale_after`
 *   600초. 즉 **10분** 동안 존재하지 않는 창을 가리켰고, 남은 출구는 [처음부터 다시] 뿐이었다.
 *
 * 왜 600초인가: 단계별만 `acquireWorkerLock($stepwiseTimeBudget)` 를 쓰고(SSE·폴링은 15초 고정),
 * 그 호스트는 `set_time_limit` 이 막혀 예산이 `max_execution_time`(600)으로 잡혔다. 이 값 자체는
 * **줄이지 않는다** — 태스크 실행 중에는 heartbeat 를 갱신할 수 없으므로, 창을 줄이면 살아 있는
 * 워커를 남이 이어받아 같은 태스크를 겹쳐 돌린다.
 *
 * 잠그는 계약:
 *   1. 남은 시간과 생존 판정은 **한 셈**에서 나온다 (사본이 갈라지지 않는다).
 *   2. 미래 heartbeat(시계 역행)는 신뢰하지 않는다 — 13차 환경#19 와 같은 결론.
 *   3. 해제는 성공 여부를 **돌려준다**.
 *   4. 남이 이미 이어받았으면 할 일이 없고, 그것은 실패가 아니다.
 *   5. shutdown 훅이 그 반환값을 **실제로 본다**(보고 기록한다).
 *   6. 진단은 여유 공간을 함께 적는다 — 원인을 가르는 값이 한 번의 호출로 얻어진다.
 *
 * 쓰기 실패 경로를 **행위로** 재지 않는 이유: 그러려면 「읽기는 되고 쓰기는 안 되는」 디렉터리가
 * 필요한데 이 저장소의 검증 환경(Windows)에서는 그것을 신뢰성 있게 만들 수 없다. 그래서 그
 * 분기는 프로덕션 소스를 잘라 **배선**을 단언한다 — 형제(D4 의 원자 교체)와 같은 기법이다.
 *
 * BASE_PATH 를 temp 로 박으므로 클래스 격리 실행이 필요하다.
 *
 * @scenario task_outcome=done
 *
 * @effects installer_worker_lock_release_reports_whether_it_persisted
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerWorkerLockReleaseTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행 필요: '.
                    'php artisan test --filter=InstallerWorkerLockReleaseTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-lock-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        foreach (['/storage/logs', '/storage'] as $dir) {
            if (! is_dir(self::$sharedBase.$dir)) {
                mkdir(self::$sharedBase.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$skipReason === '' && self::$sharedBase !== '') {
            foreach (glob(self::$sharedBase.'/storage/*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    /** 프로덕션 소스에서 함수 본문만 잘라 온다 — 길이 상수로 자르지 않는다(한글 주석은 글자당 3바이트). */
    private function functionBody(string $file, string $needle): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/'.$file);
        $at = strpos($source, $needle);
        $this->assertNotFalse($at, $needle.' 를 찾지 못했다');

        $next = strpos($source, "\nfunction ", $at + 1);
        $guard = strpos($source, "\nif (! function_exists(", $at + 1);
        if ($guard !== false && ($next === false || $guard < $next)) {
            $next = $guard;
        }

        return $next === false ? substr($source, $at) : substr($source, $at, $next - $at);
    }

    /**
     * 남은 시간이 곧 생존 판정이다 — 두 값이 어긋나면 안내가 판정과 다른 말을 한다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function remaining_seconds_and_liveness_never_disagree(): void
    {
        $now = time();
        $cases = [
            '점유자 없음' => [],
            '방금 잡음' => ['active_worker_id' => 'w1', 'last_heartbeat' => $now, 'worker_stale_after' => 600],
            '예산 한복판' => ['active_worker_id' => 'w1', 'last_heartbeat' => $now - 429, 'worker_stale_after' => 600],
            '예산 경계' => ['active_worker_id' => 'w1', 'last_heartbeat' => $now - 600, 'worker_stale_after' => 600],
            '예산 초과' => ['active_worker_id' => 'w1', 'last_heartbeat' => $now - 601, 'worker_stale_after' => 600],
            '미래 heartbeat' => ['active_worker_id' => 'w1', 'last_heartbeat' => $now + 120, 'worker_stale_after' => 600],
            '선언 없음(구 상태)' => ['active_worker_id' => 'w1', 'last_heartbeat' => $now - 5],
        ];

        foreach ($cases as $label => $state) {
            $remaining = installerWorkerLockRemainingSeconds($state, 15);
            $this->assertSame(
                $remaining > 0,
                installerActiveWorkerIsAlive($state, 15),
                $label.' — 「살아 있다」와 「남았다」가 갈라졌다. 갈라지면 화면이 판정과 다른 말을 한다'
            );
            $this->assertGreaterThanOrEqual(0, $remaining, $label.' — 음수는 카운트다운이 될 수 없다');
        }
    }

    /**
     * 현장에서 나온 그 값이 그대로 재현된다 — 429초 경과 / 600초 창 → 171초 남음.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function it_reproduces_the_field_numbers(): void
    {
        $state = [
            'active_worker_id' => 'e13ca6fa76d1be8c',
            'last_heartbeat' => time() - 429,
            'worker_stale_after' => 600,
        ];

        $this->assertTrue(installerActiveWorkerIsAlive($state, 15));
        $this->assertSame(171, installerWorkerLockRemainingSeconds($state, 15));
    }

    /**
     * 호출자 기본값과 점유자 선언 중 **큰 쪽**을 쓴다 — 짧은 쪽을 쓰면 살아 있는 워커를 앞지른다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function it_honours_the_longer_of_declared_and_fallback(): void
    {
        $state = ['active_worker_id' => 'w1', 'last_heartbeat' => time() - 20, 'worker_stale_after' => 600];
        $this->assertSame(580, installerWorkerLockRemainingSeconds($state, 15));

        // 선언이 기본값보다 짧아도 기본값이 하한이다.
        $short = ['active_worker_id' => 'w1', 'last_heartbeat' => time(), 'worker_stale_after' => 5];
        $this->assertSame(15, installerWorkerLockRemainingSeconds($short, 15));
    }

    /**
     * 해제가 **성공 여부를 돌려준다** (종전 `void`).
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function releasing_reports_success_and_clears_the_holder(): void
    {
        $lock = acquireWorkerLock(15);
        $this->assertTrue($lock['acquired']);

        $this->assertTrue(releaseWorkerLock((string) $lock['worker_id']));

        $state = getInstallationState();
        $this->assertArrayNotHasKey('active_worker_id', $state);
        $this->assertArrayNotHasKey('worker_stale_after', $state);
        $this->assertSame(0, installerWorkerLockRemainingSeconds($state, 15));
    }

    /**
     * 남이 이미 이어받았으면 할 일이 없다 — 그것은 실패가 아니다.
     *
     * 여기서 false 를 돌려주면 shutdown 훅이 정상 상황마다 오류를 적어, 진짜 유실이 그 소음에 묻힌다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function releasing_a_lock_someone_else_took_over_is_not_a_failure(): void
    {
        $lock = acquireWorkerLock(15);
        $this->assertTrue($lock['acquired']);

        $this->assertTrue(
            releaseWorkerLock('not-the-owner-'.bin2hex(random_bytes(3))),
            '점유자가 아니면 할 일이 없다 — 실패로 보고하면 정상 경로가 오류 로그를 만든다'
        );

        // 실제 점유자는 그대로 남아 있어야 한다 (남의 해제가 내 점유를 지우지 않는다).
        $this->assertSame($lock['worker_id'], getInstallationState()['active_worker_id'] ?? null);

        releaseWorkerLock((string) $lock['worker_id']);
    }

    /**
     * 해제의 반환값이 **저장 결과** 그 자체다 (배선 단언).
     *
     * 이 단언이 없으면 `return true;` 로 되돌아가도 위 행위 테스트가 전부 초록이다 — 그리고
     * 그 순간 현장 결함이 그대로 돌아온다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function the_release_returns_the_save_result(): void
    {
        $body = $this->functionBody('installer-state.php', 'function releaseWorkerLock');
        $this->assertGreaterThan(400, strlen($body), '함수 본문을 제대로 자르지 못했다');

        $this->assertStringContainsString(
            'return saveInstallationState($state);',
            $body,
            '저장 결과를 돌려주지 않으면 해제 유실이 종전처럼 조용해진다'
        );
    }

    /**
     * shutdown 훅이 그 반환값을 **본다** — 보고 기록한다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function the_shutdown_hook_records_a_release_that_did_not_persist(): void
    {
        $body = $this->functionBody('installer-state.php', 'function installerHandleWorkerShutdown');
        $this->assertGreaterThan(100, strlen($body), '함수 본문을 제대로 자르지 못했다');

        $this->assertStringContainsString(
            'if (! releaseWorkerLock($workerId))',
            $body,
            '반환값을 보지 않으면 해제 유실이 어디에도 드러나지 않는다 — 이번 현장 결함의 전파 경로다'
        );
        $this->assertStringContainsString(
            'installerErrorLog(',
            $body,
            '상태 파일은 이미 쓸 수 없는 상황이다 — 다른 매체(PHP 오류 로그)에 남겨야 한다'
        );
        $this->assertStringContainsString(
            'installerFreeSpaceNote()',
            $body,
            '여유 공간이 원인을 가르는 값이다 — 적지 않으면 다음 세션이 같은 조사를 다시 한다'
        );
    }

    /**
     * 진단 조각은 **항상** 한 조각을 돌려준다 — 실패 처리 중에 던지면 원래 오류를 덮는다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function the_free_space_note_is_always_usable(): void
    {
        $note = installerFreeSpaceNote();

        $this->assertNotSame('', $note);
        $this->assertStringStartsWith('free=', $note);
    }

    /**
     * 상태 저장 실패 기록에도 그 조각이 붙는다 — 현장에서 실제로 실패한 지점이 여기다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_worker_lock_release_reports_whether_it_persisted
     */
    #[Test]
    public function the_state_write_failure_message_carries_free_space(): void
    {
        $body = $this->functionBody('installer-state.php', 'function saveInstallationState');
        $this->assertGreaterThan(1000, strlen($body), '함수 본문을 제대로 자르지 못했다');

        $this->assertStringContainsString(
            'Failed to write state tmp file',
            $body,
            '이 메시지가 사라졌으면 아래 단언이 무의미하다'
        );
        $this->assertStringContainsString(
            'installerFreeSpaceNote()',
            $body,
            '디스크 풀이 이 실패의 현장 원인이었는데 종전 기록은 그것을 말하지 않았다'
        );
    }

    /**
     * 파손 사본 보존이 **실패했을 때도** 기록이 남는다 (D7 형제 — 전수 점검에서 나왔다).
     *
     * 같은 파일의 `installerPreserveCorruptState()` 는 성공만 적고 있었다. 그리고 그 사본을
     * 못 쓰는 가장 흔한 이유가 이번 현장의 원인(디스크 풀)이다 — 그 조합에서는 「파손을
     * 만났다」는 사실조차 어디에도 없다: 상태 파일은 이미 못 읽고, 사본은 없고, 함수는
     * 조용히 끝난다. D7 의 해제 유실과 **같은 침묵**이라 같은 방식으로 닫는다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_corrupt_state_copy_failure_is_recorded
     */
    #[Test]
    public function the_corrupt_state_copy_failure_is_recorded_too(): void
    {
        $body = $this->functionBody('installer-state.php', 'function installerPreserveCorruptState');
        $this->assertGreaterThan(500, strlen($body), '함수 본문을 제대로 자르지 못했다');

        $this->assertStringContainsString(
            'Corrupt state preserved at',
            $body,
            '성공 기록이 사라졌으면 아래 단언이 무엇과 대조되는지 알 수 없다'
        );
        $this->assertStringContainsString(
            'Corrupt state copy could not be written',
            $body,
            '성공만 적으면 실패는 아무 매체에도 남지 않는다 — D7 의 해제 유실과 같은 전파 경로다'
        );
        $this->assertStringContainsString(
            'installerFreeSpaceNote()',
            $body,
            '이 사본을 못 쓰는 가장 흔한 이유가 디스크 풀이다 — 원인을 가르는 값을 함께 적는다'
        );
    }
}
