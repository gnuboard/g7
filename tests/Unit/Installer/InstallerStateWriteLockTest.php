<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 상태 파일 read-modify-write 의 배타 구간 회귀 테스트 (12차 검증 #A3).
 *
 * 배경:
 *   `acquireWorkerLock()` 만 `STATE_PATH.'.lock'` 에 `flock(LOCK_EX)` 를 걸고 그 안에서
 *   읽기·판정·쓰기를 했다. 같은 파일을 읽고 고쳐 쓰는 나머지 헬퍼(heartbeat 갱신 · lock
 *   해제 · stale 창 연장 · 완료 마커 기록 · 현재 태스크 기록 · 건너뛰기 기록)는 무보호라,
 *   두 요청이 겹치는 창(새로고침 직후의 끊긴 요청 + 재개 요청)에서 나중에 쓴 쪽이 상대의
 *   갱신을 통째로 덮어썼다. 완료 마커가 사라지면 그 태스크가 다시 뽑히고, DB 정리 태스크가
 *   다시 돌면 방금 만든 테이블이 DROP 된다 — 예외도 오류도 남지 않는다.
 *
 * 고정하는 계약:
 *  - 상태 파일을 읽고 고쳐 쓰는 헬퍼는 전부 `installerWithStateLock()` 구간 안에서 한다.
 *  - 그 헬퍼는 재진입해도 자기 자신과 교착하지 않는다(같은 파일에 두 번 flock 금지).
 *
 * 커버 축: state_write concurrent_helper / endpoint_guard general
 *
 * @effects state_mutating_helpers_share_one_exclusive_section, state_lock_is_reentrant_within_one_process
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerStateWriteLockTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions {
        functionBody as private scanFunctionBody;
    }

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 상태 파일을 읽고 고쳐 쓰는 헬퍼 전수 — 하나라도 구간 밖이면 그 경로가 곧 유실 창이다.
     *
     * @return array<int, string>
     */
    private static function stateMutatingHelpers(): array
    {
        return [
            'markTaskCompleted',
            'markTaskBestEffortFailed',
            'removeTaskCompleted',
            'updateCurrentTask',
            'acquireWorkerLock',
            'refreshWorkerHeartbeat',
            'releaseWorkerLock',
            'extendWorkerStaleWindow',
            'markInstallationTaskSkipped',
            // 17차 #C4 — 폴링 lock 실패 기록. 이 경로는 정의상 승자가 도는 중에만 실행되므로
            // 구간 밖 RMW 는 우발이 아니라 구조적 경합이다.
            'installerRecordBusyRejection',
        ];
    }

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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerStateWriteLockTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-statelock-'.bin2hex(random_bytes(4));
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
            self::$base.'/storage/installer-state.json.lock',
            self::$base.'/storage/logs/installation.log',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * installer-state.php 에서 함수 본문(중괄호 균형)을 잘라 낸다.
     *
     * 이름 열거 대신 본문을 직접 보는 이유: 「부른다」만 검사하면 구간 밖에서 한 번 더
     * 읽고 쓰는 코드가 그대로 통과한다.
     *
     * 주석을 먼저 지운다 — `installerWithStateLock(` 호출을 주석으로 감싸도 원문에는 글자가
     * 남아, 배타 구간이 죽은 채로 초록이 된다. 본문 절단은 공용 검출기에 위임한다(중복 구현
     * 제거 + 접두사가 같은 형제 함수를 잘못 집는 `strpos` 첫 일치 문제 해소).
     *
     * @param  string  $name  함수 이름
     * @return string 본문
     */
    private function functionBody(string $name): string
    {
        $source = $this->stripComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/installer-state.php')
        );

        $body = $this->scanFunctionBody($source, $name);
        $this->assertNotSame('', $body, "installer-state.php 에서 {$name}() 의 본문을 찾지 못했다.");

        return $body;
    }

    public function test_state_lock_helper_exists(): void
    {
        $this->assertTrue(
            function_exists('installerWithStateLock'),
            '상태 파일 쓰기의 배타 구간을 한 지점에 모으는 installerWithStateLock() 이 없다 — '
            .'헬퍼마다 각자 flock 을 열면 그 규칙이 갈리고, 새로 추가되는 헬퍼는 아무 보호도 받지 못한다.'
        );
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function helperProvider(): array
    {
        return array_map(static fn (string $name): array => [$name], self::stateMutatingHelpers());
    }

    #[DataProvider('helperProvider')]
    public function test_state_mutating_helper_runs_inside_the_shared_exclusive_section(string $helper): void
    {
        $body = $this->functionBody($helper);

        $this->assertStringContainsString(
            'installerWithStateLock(',
            $body,
            "{$helper}() 가 상태 파일을 읽고 고쳐 쓰는데 공용 배타 구간 밖이다 — "
            .'겹친 요청 중 나중에 쓴 쪽이 상대의 갱신을 통째로 덮어쓴다.'
        );
    }

    public function test_state_lock_is_reentrant_and_returns_the_callback_value(): void
    {
        $inner = installerWithStateLock(static function (): string {
            // 구간 안에서 상태를 고치는 헬퍼를 다시 부르는 경로가 실제로 있다
            // (건너뛰기 → 완료 마커). 같은 파일에 두 번 flock 하면 자기와 교착한다.
            return (string) installerWithStateLock(static fn (): string => 'nested-ok');
        });

        $this->assertSame('nested-ok', $inner);
    }

    public function test_helpers_still_persist_their_change_inside_the_section(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['completed_tasks'] = [];
        $state['current_task'] = null;
        $this->assertTrue(saveInstallationState($state));

        $this->assertTrue(updateCurrentTask('db_migrate'));
        $this->assertSame('db_migrate', getInstallationState()['current_task'] ?? null);

        $this->assertTrue(markTaskCompleted('db_migrate'));
        $persisted = getInstallationState();
        $this->assertContains('db_migrate', $persisted['completed_tasks'] ?? []);
        $this->assertArrayHasKey('current_task', $persisted);
        $this->assertNull($persisted['current_task']);

        $this->assertTrue(removeTaskCompleted('db_migrate'));
        $this->assertNotContains('db_migrate', getInstallationState()['completed_tasks'] ?? []);
    }

    public function test_worker_lock_helpers_still_work_inside_the_section(): void
    {
        $this->assertTrue(saveInstallationState(DEFAULT_INSTALLATION_STATE));

        $lock = acquireWorkerLock(180);
        $this->assertTrue($lock['acquired']);

        $workerId = (string) $lock['worker_id'];

        $this->assertTrue(refreshWorkerHeartbeat($workerId));
        $this->assertFalse(refreshWorkerHeartbeat('someone-else'));

        $this->assertTrue(extendWorkerStaleWindow($workerId, 600));
        $this->assertSame(600, (int) (getInstallationState()['worker_stale_after'] ?? 0));

        releaseWorkerLock($workerId);
        $this->assertNull(getInstallationState()['active_worker_id'] ?? null);
    }

    /**
     * 17차 #C3 — 배타 구간이 **읽기까지** 감싼다.
     *
     * `markInstallationTaskSkipped()` 는 상태를 인자로 받는다. 호출부(`state-management.php`)가
     * 구간 **밖**에서 읽어 넘기므로, 그 읽기와 저장 사이에 워커가 쓴 완료 마커는 저장 시점에
     * 통째로 덮인다 — 구간이 쓰기만 감싸면 유실 창은 그대로 남는다. 이 클래스가 선언한 계약
     * (「읽기·판정·쓰기를 전부 구간 안에서」)이 소스 문자열 검사만으로는 초록이던 자리다.
     *
     * 유실되면 그 태스크가 다시 뽑히고, DB 정리 태스크가 다시 돌면 방금 만든 테이블이 DROP 된다.
     */
    public function test_skip_does_not_clobber_writes_made_after_the_caller_read(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'failed';
        $state['failed_task'] = 'module_install:sirsoft-board';
        $state['failed_task_skippable'] = true;
        $state['completed_tasks'] = ['db_migrate'];
        $this->assertTrue(saveInstallationState($state));

        // 호출부가 구간 밖에서 읽은 스냅샷 (state-management.php 의 `$state = getInstallationState()`)
        $callerSnapshot = getInstallationState();

        // 그 사이에 워커가 다음 태스크를 끝낸다 — 이것이 실제 경합 창이다.
        $this->assertTrue(markTaskCompleted('db_seed'));

        markInstallationTaskSkipped($callerSnapshot);

        $persisted = getInstallationState();
        $this->assertContains(
            'db_seed',
            $persisted['completed_tasks'] ?? [],
            '스냅샷을 그대로 저장하면 그 사이 워커가 남긴 완료 마커가 사라진다 — 그 태스크가 다시 돈다',
        );
        $this->assertContains(
            'db_migrate',
            $persisted['completed_tasks'] ?? [],
            '기존 완료 마커도 보존되어야 한다',
        );
        $this->assertContains(
            'module_install:sirsoft-board',
            $persisted['skipped_tasks'] ?? [],
            '건너뛰기 자체는 종전대로 기록되어야 한다',
        );
    }
}
