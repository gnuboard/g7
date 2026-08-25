<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * db_seed 롤백의 「이미 완료」 판정 근거 회귀 테스트 (12차 검증 #A4).
 *
 * 배경:
 *   `executeSeedTruncate()` 의 비강제 경로가 「`roles` 테이블에 행이 있으면 시딩이 이미
 *   끝난 것」이라는 휴리스틱으로 TRUNCATE 를 건너뛰고 「롤백 성공」을 보고했다. 그런데 이
 *   함수가 불리는 상황 중 하나는 **db_seed 가 실패했을 때**다 — 그 상황은 "시딩이 끝나지
 *   않았다" 가 증명된 상태이고, 시딩은 roles 를 먼저 채우므로 부분 시딩된 DB 에서 전제가
 *   정확히 반대로 성립한다. 아무것도 되돌리지 않은 채 성공으로 보고되므로 오류도 남지 않는다.
 *
 * 고정하는 계약:
 *  - 완료 판정은 DB 행 존재가 아니라 `completed_tasks` 의 `db_seed` 마커가 정한다.
 *  - 마커가 없으면(=실패·중단) 되돌린다. 마커가 있으면 건드리지 않는다.
 *
 * 커버 축: rollback db_seed / task_outcome failed
 *
 * @effects seed_rollback_completion_uses_task_marker_not_row_heuristic
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerSeedRollbackMarkerTest extends TestCase
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerSeedRollbackMarkerTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-seedmarker-'.bin2hex(random_bytes(4));
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
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
        require_once $root.'/public/install/api/rollback-functions.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::boot();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        if (is_file(self::$base.'/storage/installer-state.json')) {
            @unlink(self::$base.'/storage/installer-state.json');
        }

        setProgressEmitter(new \BufferEmitter);
    }

    public function test_completion_judgment_reads_the_task_marker(): void
    {
        $this->assertFalse(
            installerSeedRollbackIsAlreadyDone(['completed_tasks' => ['db_migrate']]),
            'db_seed 마커가 없다 = 시딩이 끝나지 않았다 — 되돌려야 한다.'
        );

        $this->assertTrue(
            installerSeedRollbackIsAlreadyDone(['completed_tasks' => ['db_migrate', 'db_seed']]),
            'db_seed 마커가 있으면 시딩이 끝난 것이다 — 건드리지 않는다.'
        );

        $this->assertFalse(installerSeedRollbackIsAlreadyDone([]));
    }

    public function test_already_completed_seed_returns_without_touching_the_database(): void
    {
        // 접속 자체가 불가능한 좌표 — 「이미 완료」 판정이 DB 를 보지 않고 끝난다는 증거다
        $result = executeSeedTruncate(
            [
                'db_write_host' => '127.0.0.1',
                'db_write_port' => '1',
                'db_write_database' => 'g7_seed_marker_probe',
                'db_write_username' => 'nobody',
                'db_write_password' => '',
            ],
            false,
            'rollback',
            true,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(lang('rollback_seed_already_done'), $result['message']);

        // 19차 R3 — `success` 와 「되돌렸는가」는 다른 사실이다.
        $this->assertFalse(
            $result['reverted'],
            '아무것도 되돌리지 않았는데 되돌렸다고 답하면, 호출부가 그 답으로 완료 마커를 지운다',
        );
    }

    /**
     * 19차 R3 — 되돌리지 않은 갈래가 「되돌렸다」로 답하지 않는다.
     *
     * `executeSeedTruncate()` 는 아무것도 되돌리지 않고 `success:true` 를 돌려주는 갈래를
     * 넷 갖는다(마커로 이미 끝남 · DB 설정 없음 · 대상 테이블 없음 · 접속 실패 스킵).
     * 호출부가 그 `true` 를 「되돌렸다」로 읽고 `removeTaskCompleted('db_seed')` 를 부르면
     * **DB 에는 시드가 남고 상태에는 없는** 상태가 만들어지고, 재개하면 `db:seed` 가 다시 돌아
     * `AdminUserSeeder` 가 전체 사용자를 삭제한다.
     */
    public function test_no_config_branch_reports_nothing_reverted(): void
    {
        $result = executeSeedTruncate([], false, 'rollback', false);

        $this->assertTrue($result['success'], 'DB 설정이 없는 것은 롤백 실패가 아니다');
        $this->assertFalse(
            $result['reverted'],
            'DB 에 닿지도 않았는데 되돌렸다고 답하면 호출부가 완료 마커를 지운다',
        );
    }

    /**
     * 19차 R3 — 두 호출부가 「되돌렸을 때만」 마커를 지운다.
     *
     * 중단 경로는 `markTaskCompleted($taskId)` **뒤**에 있어, db_seed 성공 직후의 중단에서는
     * 방금 세운 마커가 곧바로 「이미 끝났다」 단락을 성립시킨다. 그 갈래의 `success:true` 로
     * 마커를 지우는 것이 R3 의 실체이므로, 두 지점 모두 `reverted` 를 조건으로 갖는지 본다.
     */
    public function test_both_marker_removals_are_conditioned_on_actual_revert(): void
    {
        $source = $this->stripComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/task-runner.php')
        );

        $removals = preg_match_all("/removeTaskCompleted\(\s*'db_seed'\s*\)/", $source);

        $this->assertSame(
            2,
            $removals,
            'db_seed 마커 제거 지점 수가 바뀌었다 — 새 지점이 생겼으면 그 지점도 조건을 가져야 한다',
        );

        $guarded = preg_match_all(
            "/\\\$rollbackResult\['reverted'\]\s*\?\?\s*true\)\s*===\s*true\)\s*\{\s*removeTaskCompleted\(\s*'db_seed'\s*\);/",
            $source
        );

        $this->assertSame(
            2,
            $guarded,
            '되돌리지 않은 롤백의 success:true 로 마커를 지우면 DB 와 상태가 어긋난다 — 재개가 시딩을 다시 돌린다',
        );
    }

    public function test_row_count_heuristic_is_gone(): void
    {
        // 주석을 지운 뒤 대조한다 — 주석 처리된 판정 호출도 원문에는 글자로 남는다.
        $source = $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/rollback-functions.php'));

        $this->assertStringNotContainsString(
            "SHOW TABLES LIKE 'roles'",
            $source,
            '시딩 완료 여부를 DB 행 존재로 추정하는 코드가 남아 있다 — 실패 롤백에서 전제가 반대로 성립한다.'
        );
        $this->assertStringNotContainsString('SELECT COUNT(*) FROM roles', $source);
    }

    public function test_seed_rollback_passes_the_marker_judgment(): void
    {
        // 주석을 지운 뒤 대조한다 — 주석 처리된 판정 호출도 원문에는 글자로 남는다.
        $source = $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/rollback-functions.php'));

        $this->assertMatchesRegularExpression(
            '/function rollbackDbSeed\(array \$state\): array\s*\{.*installerSeedRollbackIsAlreadyDone\(\$state\)/s',
            $source,
            'rollbackDbSeed() 가 완료 판정을 넘기지 않는다 — 넘기지 않으면 실패 롤백이 다시 휴리스틱으로 떨어진다.'
        );
    }
}
