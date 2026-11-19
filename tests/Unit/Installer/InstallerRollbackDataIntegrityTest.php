<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 롤백이 남의 데이터를 지우거나 복구 수단을 없애지 않는다는 계약.
 *
 * 고정하는 계약:
 *
 *  - 시드 롤백의 TRUNCATE 대상은 `db_prefix` 가 정한다. 접두 없는 이름(`users`)은 같은 DB 를
 *    쓰는 다른 애플리케이션의 테이블이며, 그것을 지우는 것은 Step 3 이 `foreign_data` 로
 *    감지해 경고하는 바로 그 상황이다. 빈 prefix 는 전체 일치가 되므로 삭제 자체를 거부한다
 *    (`filterTablesByPrefix` 가 db_cleanup 과 공유하는 규칙이다).
 *  - `complete_flag` 롤백은 runtime.php 를 지우지 않는다. 그 태스크가 만든 것은
 *    `INSTALLER_COMPLETED` 행과 `g7_installed` 파일뿐이고, runtime.php 에는 stepwise 토큰과
 *    APP_KEY·DB 비밀번호가 들어 있어 지우면 `run-task`·`abort`·`reset` 이 전부 403 이 된다.
 *  - `migrate:rollback` 은 이번 실행이 만든 배치만 되돌린다. 기존 스키마가 있는 DB 에서
 *    0건 적용 후 실패하면, 배치 지정 없는 롤백은 운영자의 직전 배치를 되돌린다.
 *
 * BASE_PATH 는 temp 로 고정한다 (R7) — 메서드마다 새 프로세스에서 돌려 상수 충돌을 피한다.
 *
 * 커버 축: rollback_target seed_truncate·complete_flag·migrate_batch / prefix present·empty / baseline known·unknown
 *
 * 효과 요약(마커 아님 — 평문): 시드 롤백은 접두사가 붙은 테이블만 비우고 빈 접두사를 거부하며, 완료 표시 롤백은 런타임 파일을 남기고, 마이그레이션 롤백은 이번 실행의 배치만 되돌린다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerRollbackDataIntegrityTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 프로세스마다 한 번 임시 BASE_PATH 와 저장소 디렉토리를 만들고 인스톨러 런타임과 롤백 함수를 적재한다.
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerRollbackDataIntegrityTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-rollback-data-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app', 'config/settings'] as $dir) {
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
        require_once $root.'/public/install/api/rollback-functions.php';
    }

    /**
     * 런타임을 적재하고 상태 파일, 설치 로그, 완료 표식, .env, 런타임 파일을 지운 뒤 진행 기록을 버퍼 송신기로 받는다.
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
            self::$base.'/storage/app/g7_installed',
            self::$base.'/.env',
        ] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_file(INSTALLER_RUNTIME_PATH)) {
            unlink(INSTALLER_RUNTIME_PATH);
        }

        setProgressEmitter(new \BufferEmitter);
    }

    /**
     * 주석을 지운 소스 — 배선이 「살아 있는지」는 주석이 아니라 코드가 증명한다.
     *
     * @param  string  $relative  저장소 상대 경로
     * @return string 주석이 제거된 소스
     */
    private function strippedSource(string $relative): string
    {
        return $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/'.$relative));
    }

    /**
     * PHP 주석을 토크나이저로 지운다 (줄 수는 보존).
     *
     * @param  string  $source  원본 소스
     * @return string 주석이 제거된 소스
     */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * #D1 — TRUNCATE 대상은 prefix 가 붙은 실재 테이블만이다.
     *
     * @scenario task=db_seed, task_outcome=failed_db
     *
     * @effects seed_truncate_respects_db_prefix
     *
     * @return void 반환값 없음
     */
    public function test_seed_truncate_targets_are_prefixed(): void
    {
        $existing = [
            'g7_users', 'g7_roles', 'g7_permissions', 'g7_role_user', 'g7_permission_role',
            'g7_posts',                 // 시드 대상이 아님
            'users', 'roles',           // 같은 DB 를 쓰는 다른 애플리케이션
        ];

        $targets = installerSeedRollbackTargetTables($existing, 'g7_');

        sort($targets);
        $this->assertSame(
            ['g7_permission_role', 'g7_permissions', 'g7_role_user', 'g7_roles', 'g7_users'],
            $targets,
            '접두 없는 users/roles 가 대상에 들어가면 같은 DB 를 쓰는 다른 애플리케이션의 데이터를 지운다',
        );
    }

    /**
     * #D1 — 실재하지 않는 테이블은 대상이 아니다 (부분 시딩 후 롤백).
     *
     * @scenario task=db_seed, task_outcome=failed_db
     *
     * @effects seed_truncate_respects_db_prefix
     *
     * @return void 반환값 없음
     */
    public function test_seed_truncate_skips_tables_that_do_not_exist(): void
    {
        $targets = installerSeedRollbackTargetTables(['g7_users', 'g7_roles'], 'g7_');

        sort($targets);
        $this->assertSame(['g7_roles', 'g7_users'], $targets);
    }

    /**
     * #D1 — 빈 prefix 는 전체 일치가 되므로 삭제 자체를 거부한다 (db_cleanup 과 같은 규칙).
     *
     * @scenario task=db_seed, task_outcome=failed_db
     *
     * @effects seed_truncate_refuses_empty_prefix
     *
     * @return void 반환값 없음
     */
    public function test_seed_truncate_refuses_empty_prefix(): void
    {
        $this->assertSame(
            [],
            installerSeedRollbackTargetTables(['users', 'roles', 'permissions'], ''),
            '빈 prefix 로 TRUNCATE 하면 같은 DB 의 모든 동명 테이블이 대상이 된다',
        );
    }

    /**
     * #D1 — 접두 없는 리터럴 테이블 목록이 TRUNCATE 경로에 남아 있지 않다.
     *
     * @scenario task=db_seed
     *
     * @effects seed_truncate_respects_db_prefix
     *
     * @return void 반환값 없음
     */
    public function test_seed_truncate_has_no_bare_table_literals(): void
    {
        $source = $this->strippedSource('public/install/api/rollback-functions.php');

        $this->assertStringNotContainsString(
            "\$tables = ['users', 'roles', 'permissions', 'role_user', 'permission_role']",
            $source,
            '접두 없는 리터럴 목록이 남아 있으면 prefix 설치본에서는 아무것도 지우지 못하고, prefix 없는 타 앱 테이블만 지운다',
        );
        $this->assertStringContainsString(
            'installerSeedRollbackTargetTables(',
            $source,
            'TRUNCATE 대상 도출은 prefix 를 아는 단일 헬퍼가 맡는다',
        );
    }

    /**
     * #D3 — complete_flag 롤백은 runtime.php 를 지우지 않는다.
     *
     * 지우면 stepwise 토큰·APP_KEY·DB 비밀번호가 한꺼번에 사라져 run-task·abort·reset·
     * skip_task 가 전부 403 이 되고, 유일한 출구인 재시작도 DB 비밀번호를 잃는다.
     *
     * @scenario task=complete_flag, installation_mode=stepwise
     *
     * @effects complete_flag_rollback_keeps_runtime
     *
     * @return void 반환값 없음
     */
    public function test_complete_flag_rollback_keeps_installer_runtime(): void
    {
        writeInstallerRuntime([
            'db' => ['write' => ['host' => 'localhost', 'port' => '3306', 'database' => 'g7_test', 'username' => 'g7', 'password' => 'pw']],
            'app' => ['key' => generateAppKeyInline()],
            'installer' => ['stepwise_token' => 'tok'],
        ]);

        $flagPath = self::$base.'/storage/app/g7_installed';
        file_put_contents($flagPath, date('Y-m-d H:i:s'));
        file_put_contents(self::$base.'/.env', "APP_NAME=G7\nINSTALLER_COMPLETED=true\n");

        $result = rollbackCompleteFlag();

        $this->assertTrue($result['success'] ?? false);
        $this->assertFileDoesNotExist($flagPath, 'complete_flag 이 만든 g7_installed 는 되돌린다');
        $this->assertStringNotContainsString('INSTALLER_COMPLETED', (string) file_get_contents(self::$base.'/.env'));

        $this->assertFileExists(
            INSTALLER_RUNTIME_PATH,
            'complete_flag 롤백이 runtime.php 를 지우면 stepwise 토큰이 사라져 모든 후속 요청이 403 이 된다',
        );
        $runtime = readInstallerRuntime();
        $this->assertSame('tok', $runtime['installer']['stepwise_token'] ?? null);
        $this->assertSame('pw', $runtime['db']['write']['password'] ?? null);
    }

    /**
     * #D3 — 롤백 경로에 runtime 삭제 배선이 남아 있지 않다.
     *
     * @scenario task=complete_flag
     *
     * @effects complete_flag_rollback_keeps_runtime
     *
     * @return void 반환값 없음
     */
    public function test_complete_flag_rollback_has_no_runtime_delete_wiring(): void
    {
        $source = $this->strippedSource('public/install/api/rollback-functions.php');

        $start = strpos($source, 'function rollbackCompleteFlag');
        $this->assertNotFalse($start);
        $body = substr($source, $start);

        $this->assertStringNotContainsString(
            'deleteInstallerRuntime(',
            $body,
            'runtime.php 삭제는 finalize 의 소유다 — 롤백이 지우면 복구 수단이 통째로 사라진다',
        );
    }

    /**
     * #D9 — 이번 실행이 만든 배치만 되돌린다.
     *
     * @scenario task=db_migrate, task_outcome=failed_db
     *
     * @effects migrate_rollback_limits_to_installed_batches
     *
     * @return void 반환값 없음
     */
    public function test_migrate_rollback_step_count_covers_only_new_batches(): void
    {
        // 빈 DB 에 설치 — 기준선 0, 이번 실행이 배치 1 로 3건 적용
        $this->assertSame(3, installerMigrateRollbackStepCount([1, 1, 1], 0));

        // 기존 스키마가 있는 DB 에서 이번 실행이 0건 적용 — 되돌릴 것이 없다
        $this->assertSame(
            0,
            installerMigrateRollbackStepCount([1, 2, 3], 3),
            '0건 적용 후 실패에 배치 지정 없이 롤백하면 운영자의 직전 배치가 사라진다',
        );

        // 기준선 3 위에 배치 4 로 2건 적용
        $this->assertSame(2, installerMigrateRollbackStepCount([3, 4, 4], 3));
    }

    /**
     * #D9 — 기준선을 모르면 되돌리지 않는다 (fail-closed).
     *
     * @scenario task=db_migrate, task_outcome=failed_db
     *
     * @effects migrate_rollback_limits_to_installed_batches
     *
     * @return void 반환값 없음
     */
    public function test_migrate_rollback_step_count_is_null_without_baseline(): void
    {
        $this->assertNull(
            installerMigrateRollbackStepCount([1, 2, 3], null),
            '기준선을 모르는 채 되돌리면 어느 배치가 이번 실행의 것인지 구분할 수 없다',
        );
    }

    /**
     * #D9 — 롤백 커맨드가 실제로 범위를 지정한다.
     *
     * @scenario task=db_migrate
     *
     * @effects migrate_rollback_limits_to_installed_batches
     *
     * @return void 반환값 없음
     */
    public function test_migrate_rollback_passes_step_option(): void
    {
        $source = $this->strippedSource('public/install/api/rollback-functions.php');

        $start = strpos($source, 'function executeMigrateRollback');
        $this->assertNotFalse($start);
        $body = substr($source, $start, 4000);

        $this->assertStringContainsString('installerMigrateRollbackStepCount(', $body);
        $this->assertStringContainsString("'--step'", $body, '배치 범위를 지정하지 않으면 마지막 배치(= 운영자의 것일 수 있다)가 대상이 된다');
    }

    /**
     * 마이그레이션 기준선은 `completed_tasks` 와 같은 수명을 갖는다.
     *
     * 기준선은 「이번 실행 직전의 최대 배치」라 실행이 바뀌면 뜻을 잃는다. 그런데
     * `installerRecordMigrationBatchBaseline()` 은 값이 있으면 **다시 기록하지 않으므로**,
     * 한 번 쓰인 값은 초기화·재시작을 넘어 이월된다. 그 상태에서 `--step` 이 계산되면 이번
     * 실행이 만들지도 않은 배치가 롤백 범위에 든다.
     *
     * 모집단을 손으로 적지 않는다 — `completed_tasks` 를 비우는 **파일 전수**를 소스에서
     * 도출한다. 새 초기화 지점이 생기면 그 파일이 자동으로 모집단에 들어온다.
     *
     * @scenario task=db_migrate, resume_point=retry_after_failure
     *
     * @effects migrate_rollback_limits_to_installed_batches
     *
     * @return void 반환값 없음
     */
    public function test_migrate_baseline_is_cleared_wherever_completed_tasks_are(): void
    {
        $root = dirname(__DIR__, 3).'/public/install';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = $this->stripComments((string) file_get_contents($file->getPathname()));

            // 「비운다」로 한정한다 — 읽기(`$state['completed_tasks'] ?? []`)는 대상이 아니다.
            if (preg_match("/\\\$state\['completed_tasks'\]\s*=/", $source) !== 1) {
                continue;
            }

            if (preg_match("/\\\$state\['db_migrate_batch_baseline'\]\s*=/", $source) !== 1) {
                $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '완료 마커를 비우면서 마이그레이션 기준선을 남기면, 이전 회전의 기준선으로 다음 회전의 롤백 범위가 계산된다',
        );
    }

    /**
     * 표식을 지우지 못했으면 「되돌렸다」고 답하지 않는다.
     *
     * 이 태스크가 되돌리는 두 표식(`.env` 의 `INSTALLER_COMPLETED` · `storage/app/g7_installed`)은
     * **인스톨러 진입 가드의 근거**다. 종전에는 두 제거가 모두 실패해도 `success:true` 를
     * 돌려주어, 운영자에게는 되돌리기가 성공한 것으로 보이는데 모든 인스톨러 엔드포인트는
     * 계속 410 이었다 — 화면에 사유가 없어 탈출구가 사라진다.
     *
     * 제거 실패는 디렉토리로 재현한다: `file_exists` 는 참이고 `unlink` 는 실패한다.
     *
     * @scenario task=complete_flag
     *
     * @effects complete_flag_rollback_reports_a_surviving_marker
     *
     * @return void 반환값 없음
     */
    public function test_complete_flag_rollback_reports_failure_when_a_marker_survives(): void
    {
        $flagPath = self::$base.'/storage/app/g7_installed';
        if (is_file($flagPath)) {
            unlink($flagPath);
        }
        @mkdir($flagPath, 0755, true);

        try {
            $result = rollbackCompleteFlag();

            $this->assertFalse(
                $result['success'],
                '표식이 남아 있는데 성공으로 답하면 운영자는 인스톨러가 왜 계속 닫혀 있는지 알 수 없다',
            );
        } finally {
            @rmdir($flagPath);
        }
    }

    /**
     * `.env` 를 읽지 못하면 **쓰지 않는다**.
     *
     * 종전에는 `file_get_contents` 의 false 를 검사하지 않았다. `preg_replace` 가 그것을 빈
     * 문자열로 다루므로 이어지는 `file_put_contents` 가 `.env` 를 **통째로 비운다** —
     * DB 자격증명과 APP_KEY 가 한 번에 사라지고, 이 경로에는 finalize 형제와 달리 사본이 없다.
     *
     * 읽기 실패는 `.env` 를 디렉토리로 만들어 재현한다.
     *
     * @scenario task=complete_flag
     *
     * @effects complete_flag_rollback_never_writes_an_unread_env
     *
     * @return void 반환값 없음
     */
    public function test_complete_flag_rollback_never_writes_env_it_could_not_read(): void
    {
        $envPath = self::$base.'/.env';
        if (is_file($envPath)) {
            unlink($envPath);
        }
        @mkdir($envPath, 0755, true);

        try {
            $result = @rollbackCompleteFlag();

            $this->assertFalse($result['success'], '읽지 못한 파일에 쓰지 않았음을 사유와 함께 보고해야 한다');
            $this->assertTrue(is_dir($envPath), '읽기에 실패한 대상을 쓰기로 덮으면 .env 가 비어 버린다');
        } finally {
            @rmdir($envPath);
        }
    }

    /**
     * 범위를 확정하지 못하면 `migrate:rollback` 을 **한 번도 부르지 않는다**.
     *
     * 위 소스 검사는 `'--step'` 리터럴의 존재만 본다. 그래서 「`$step === null` 일 때만
     * `--step` 없이 내려간다」는 형태를 잡지 못했고, 그 분기가 R1 의 실제 모습이었다 —
     * 기준선이 없고 DB 는 붙어 있는 상태(기록 시점 미도달·저장 실패·레거시 상태)에서
     * 배치 지정 없는 롤백이 **성공**해 운영자의 마지막 배치가 사라진다.
     *
     * 그래서 이 검사는 리터럴이 아니라 **호출 여부**를 단언한다. 러너를 스텁으로 바꿔
     * 실행 기록을 받고, 그 기록이 비어 있는지를 본다.
     *
     * @scenario task=db_migrate, task_outcome=failed_db
     *
     * @effects migrate_rollback_limits_to_installed_batches
     *
     * @return void 반환값 없음
     */
    public function test_migrate_rollback_runs_nothing_when_the_range_is_unknown(): void
    {
        require_once __DIR__.'/stubs/artisan_runner_spy_stub.php';

        $GLOBALS['g7_test_artisan_runs'] = [];

        // DB 설정이 없으므로 `installerReadMigrationBatches()` 는 null(접속 불가)을 돌려주고
        // 기준선도 없다 — 두 경로 모두 「범위 미확정」으로 수렴한다.
        $state = getInstallationState();
        unset($state['db_migrate_batch_baseline']);
        $state['config'] = [];
        saveInstallationState($state);

        $result = executeMigrateRollback('[test]');

        $this->assertSame(
            [],
            $GLOBALS['g7_test_artisan_runs'],
            '범위를 모르는 채 migrate:rollback 이 나가면 배치 지정이 없어 운영자의 마지막 배치가 사라진다',
        );

        $this->assertFalse(
            $result['success'],
            '되돌리지 않았는데 성공으로 보고하면 운영자가 DB 를 직접 확인할 이유를 잃는다',
        );

        $this->assertSame(
            lang('rollback_migrate_range_unknown'),
            $result['message'],
            '문구 자신이 「롤백을 건너뜁니다 — DB 를 직접 확인해 주세요」라고 약속한다',
        );
    }
}
