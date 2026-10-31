<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 설치 완료 직후 .env 최종 병합(finalize-env.php)과 설치 워커 마지막 작업의 순서 회귀 테스트.
 *
 * 회귀: 설치 워커는 「설치 완료 처리」(complete_flag) 작업에서 상태를 completed 로 기록한 뒤에도
 * 설정 캐시 생성(config:cache)·라우트 캐시·정적 게시를 이어서 돌린다. 폴링 화면(1초 간격)은
 * completed 를 보는 즉시 완료 화면을 띄우고 finalize-env.php 를 부르므로, .env 병합과 runtime.php·
 * 상태 파일 삭제가 워커의 config:cache 도중에 끼어들었다. 그 config:cache 는 바깥 부팅 때 읽은
 * 병합 전 .env(DB 계정 root)와, 새 부팅 때 이미 지워진 runtime.php(주입 없음)로 캐시를 만들어
 * 사이트·관리자가 모두 「데이터베이스 계정 설정 오류」(503)로 열리지 않았다. 상태 파일이 지워진
 * 워커는 다음 출력에서 「lock lost」 로 빠져 라우트 캐시·정적 게시도 건너뛰었다.
 *
 * 경합이 없을 때(SSE 정상 경로)도 워커의 설정 캐시는 병합 전 .env 로 만들어져
 * installer_completed=false 가 캐시에 남았다.
 *
 * 고친 동작:
 *   - finalize 는 설치 워커가 잠금을 쥐고 있는 동안 기다린 뒤 병합한다(상한 시간이 지나면 진행).
 *   - 병합 직전에 설정 캐시를 지우고, 병합·runtime.php 삭제 뒤 설정 캐시를 다시 만든다.
 *     다시 만들기에 실패하면 캐시를 남기지 않는다(다음 요청은 병합된 .env 로 부팅).
 *   - 동시에 두 번 불려도 병합은 한 번만 한다.
 *
 * 하위 PHP 프로세스로 실제 finalize-env.php 를 돌린다. 워커는 「바깥 부팅 때 .env 를 읽고, 1.5초 뒤
 * runtime.php 유무를 보고 캐시를 쓰는」 config:cache 의 동작을 흉내 낸 별도 프로세스, artisan 은
 * 같은 규칙으로 캐시를 쓰는 가짜 스크립트다. DB 는 쓰지 않는다.
 *
 * 시나리오 SSoT: tests/scenarios/installer-worker-termination.yaml
 *
 * `BASE_PATH` 는 하위 프로세스 스크립트 안에서만 정의하지만, 인스톨러 격리 게이트는 문자열 속 정의도
 * 정의로 본다(판정 불가를 정의로 치는 fail-closed). 그래서 클래스 단위 격리를 선언하고, 임시 트리는
 * 메서드마다(tearDown) 지우되 setUp 이 도중에 실패해 tearDown 이 돌지 않은 트리까지 클래스 끝에서 지운다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerFinalizeWaitsForWorkerTest extends TestCase
{
    /** @var array<int, string> 이 클래스가 만든 임시 BASE_PATH 트리 (tearDownAfterClass 가 남은 것을 지운다) */
    private static array $createdBases = [];

    private const INSTALLER_DB_USER = 'g7_installer_user';

    private const WORKER_ID = 'worker-sim';

    private string $projectRoot = '';

    private string $tempBase = '';

    /**
     * 하위 프로세스용 임시 BASE_PATH 를 만든다(설치 완료 처리 직후의 파일 상태).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = str_replace('\\', '/', dirname(__DIR__, 3));
        $this->tempBase = str_replace('\\', '/', sys_get_temp_dir()).'/g7-finalize-wait-'.bin2hex(random_bytes(4));
        self::$createdBases[] = $this->tempBase;

        foreach (['storage/app', 'storage/logs', 'storage/installer', 'bootstrap/cache', 'sessions'] as $dir) {
            @mkdir($this->tempBase.'/'.$dir, 0755, true);
        }

        $envExample = "APP_NAME=G7\nAPP_ENV=production\nAPP_KEY=\nAPP_URL=http://localhost\n"
            ."DB_CONNECTION=mysql\nDB_WRITE_HOST=127.0.0.1\nDB_WRITE_PORT=3306\nDB_WRITE_DATABASE=g7\n"
            ."DB_WRITE_USERNAME=root\nDB_WRITE_PASSWORD=\nDB_READ_HOST=\nDB_READ_PORT=\nDB_READ_DATABASE=\n"
            ."DB_READ_USERNAME=\nDB_READ_PASSWORD=\nDB_PREFIX=g7_\n";
        file_put_contents($this->tempBase.'/.env.example', $envExample);
        // 설치 중 .env 는 .env.example 복사본 그대로다(병합 전 — DB 계정 root).
        file_put_contents($this->tempBase.'/.env', $envExample);

        // complete_flag 가 만든 설치 완료 표시
        file_put_contents($this->tempBase.'/storage/app/g7_installed', date('Y-m-d H:i:s'));

        file_put_contents(
            $this->tempBase.'/storage/installer/runtime.php',
            "<?php\n\nreturn ".var_export([
                'db' => [
                    'write' => [
                        'host' => '127.0.0.1',
                        'port' => '3306',
                        'database' => 'g7',
                        'username' => self::INSTALLER_DB_USER,
                        'password' => 'secret',
                    ],
                    'prefix' => 'g7_',
                ],
                'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32))],
            ], true).";\n"
        );

        file_put_contents($this->tempBase.'/artisan', $this->fakeArtisanSource());
    }

    /**
     * 임시 디렉터리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempBase);

        parent::tearDown();
    }

    /**
     * 메서드 tearDown 이 돌지 못한 임시 트리(setUp 이 도중에 실패한 경우)를 지운다.
     *
     * @return void 반환값 없음
     */
    public static function tearDownAfterClass(): void
    {
        foreach (self::$createdBases as $base) {
            if (is_dir($base)) {
                (new self('cleanup'))->removeDirectory($base);
            }
        }
        self::$createdBases = [];

        parent::tearDownAfterClass();
    }

    /**
     * 워커가 설정 캐시를 만드는 중에 finalize 가 불려도 병합은 워커가 끝난 뒤에 하고,
     * 남는 설정 캐시는 병합된 .env 의 DB 계정과 설치 완료 플래그를 담는다.
     *
     * 수정 전: finalize 가 곧바로 병합·runtime.php·상태 파일 삭제 → 워커 캐시에 root + installer_completed=true,
     * 워커는 「lock lost」.
     *
     * @scenario situation=finalize_while_worker_builds_config_cache
     *
     * @effects finalize_merges_env_only_after_worker_releases_lock, config_cache_reflects_merged_env_after_finalize, worker_finishes_without_lock_lost
     *
     * @return void 반환값 없음
     */
    public function test_finalize_waits_for_worker_still_building_config_cache(): void
    {
        $this->writeState(['active_worker_id' => self::WORKER_ID, 'last_heartbeat' => time()]);

        $worker = $this->startWorkerSimulation();
        $finalize = $this->runFinalize();
        $workerResult = $this->finishProcess($worker, 20);

        $this->assertFalse($finalize['timed_out'], "finalize 가 제한 시간 안에 끝나지 않았다:\n".$finalize['output']);
        $this->assertFalse($workerResult['timed_out'], '워커 흉내 프로세스가 끝나지 않았다');

        $log = $this->readLog();
        $this->assertStringNotContainsString('lock lost', $log, "finalize 가 워커 잠금을 빼앗았다:\n".$log);

        $releasedAt = strpos($log, 'worker-sim: lock released');
        $mergedAt = strpos($log, '[finalize-env] .env 머지 완료');
        $this->assertNotFalse($releasedAt, "워커가 잠금을 풀지 못했다:\n".$log);
        $this->assertNotFalse($mergedAt, "finalize 가 .env 를 병합하지 않았다:\n".$log."\n".$finalize['output']);
        $this->assertLessThan($mergedAt, $releasedAt, "워커가 끝나기 전에 .env 를 병합했다:\n".$log);

        $cache = $this->readConfigCache();
        $this->assertIsArray($cache, '설정 캐시가 없다');
        $this->assertSame(self::INSTALLER_DB_USER, $cache['db_username'], '설정 캐시의 DB 계정이 설치 정보 값이 아니다(병합 전 root 가 굳음)');
        $this->assertTrue($cache['installer_completed'], '설정 캐시의 설치 완료 플래그가 병합된 .env 와 다르다');
        $this->assertSame('artisan', $cache['built_by'], '병합 뒤 설정 캐시를 다시 만들지 않았다');

        // 안전 문자셋 값은 `.env` 표기를 바꾸지 않고 원문으로 남는다(mergeRuntimeIntoEnv 의 escapeEnvLineValue,
        // InstallerRuntimeHelperTest 가 같은 표기를 잠근다) — 줄 전체가 설치 정보 계정이어야 한다.
        $this->assertMatchesRegularExpression(
            '/^DB_WRITE_USERNAME='.preg_quote(self::INSTALLER_DB_USER, '/').'\r?$/m',
            (string) file_get_contents($this->tempBase.'/.env')
        );
        $this->assertFileDoesNotExist($this->tempBase.'/storage/installer/runtime.php');
        $this->assertFileDoesNotExist($this->tempBase.'/storage/installer-state.json');
    }

    /**
     * 잠금이 끝내 풀리지 않으면(워커가 강제 종료돼 shutdown 이 돌지 못함) 상한 시간 뒤 병합을 진행한다.
     *
     * 수정 전: 기다리지 않는다(대기 기록 없음).
     *
     * @scenario situation=finalize_worker_lock_never_released
     *
     * @effects finalize_proceeds_after_wait_limit_when_lock_is_stuck, config_cache_reflects_merged_env_after_finalize
     *
     * @return void 반환값 없음
     */
    public function test_finalize_proceeds_after_wait_limit_when_lock_is_never_released(): void
    {
        $this->writeState(['active_worker_id' => 'dead-worker', 'last_heartbeat' => time() - 3600]);

        $startedAt = microtime(true);
        $finalize = $this->runFinalize(1);
        $elapsed = microtime(true) - $startedAt;

        $this->assertFalse($finalize['timed_out'], "finalize 가 제한 시간 안에 끝나지 않았다:\n".$finalize['output']);

        $log = $this->readLog();
        $this->assertStringContainsString('[finalize-env] 설치 워커 잠금이 1초 안에 풀리지 않아 병합을 진행합니다', $log, $log."\n".$finalize['output']);
        $this->assertGreaterThanOrEqual(1.0, $elapsed, '상한 시간만큼 기다리지 않았다');
        $this->assertStringContainsString('[finalize-env] .env 머지 완료', $log);

        $cache = $this->readConfigCache();
        $this->assertIsArray($cache);
        $this->assertSame(self::INSTALLER_DB_USER, $cache['db_username']);
        $this->assertTrue($cache['installer_completed']);
    }

    /**
     * 워커가 이미 끝났으면(SSE 정상 경로) 기다리지 않고 병합하고, 병합 전 .env 로 만든 워커의 설정 캐시를
     * 병합된 값으로 다시 만든다.
     *
     * 수정 전: 워커가 만든 캐시(installer_completed=false)가 그대로 남는다.
     *
     * @scenario situation=finalize_after_worker_released
     *
     * @effects finalize_does_not_wait_when_no_worker_holds_lock, config_cache_reflects_merged_env_after_finalize
     *
     * @return void 반환값 없음
     */
    public function test_finalize_rebuilds_config_cache_built_before_merge(): void
    {
        $this->writeState([]);
        // 워커의 config:cache 결과 — runtime.php 주입 계정 + 병합 전 .env 의 설치 완료 플래그(없음)
        $this->writeConfigCache(['built_by' => 'worker', 'db_username' => self::INSTALLER_DB_USER, 'installer_completed' => false]);

        $finalize = $this->runFinalize();

        $this->assertFalse($finalize['timed_out'], $finalize['output']);
        $log = $this->readLog();
        $this->assertStringNotContainsString('설치 워커 종료를 기다립니다', $log, '잠금이 비었는데 기다렸다');

        $cache = $this->readConfigCache();
        $this->assertIsArray($cache, "설정 캐시가 없다:\n".$log);
        $this->assertSame('artisan', $cache['built_by'], '병합 뒤 설정 캐시를 다시 만들지 않았다');
        $this->assertTrue($cache['installer_completed'], '설정 캐시에 병합 전 설치 완료 플래그(false)가 남았다');
        $this->assertSame(self::INSTALLER_DB_USER, $cache['db_username']);
        $this->assertStringContainsString('[finalize-env] 설정 캐시 다시 생성 완료', $log);
    }

    /**
     * 병합 뒤 설정 캐시 다시 만들기가 실패하면 병합 전 값으로 만든 캐시를 남기지 않는다.
     *
     * 수정 전: 병합 전 캐시가 그대로 남는다.
     *
     * @scenario situation=finalize_config_cache_rebuild_fails
     *
     * @effects stale_config_cache_never_survives_finalize
     *
     * @return void 반환값 없음
     */
    public function test_finalize_leaves_no_stale_config_cache_when_rebuild_fails(): void
    {
        $this->writeState([]);
        $this->writeConfigCache(['built_by' => 'worker', 'db_username' => 'root', 'installer_completed' => true]);
        file_put_contents($this->tempBase.'/artisan-fail', '1');

        $finalize = $this->runFinalize();

        $this->assertFalse($finalize['timed_out'], $finalize['output']);
        $log = $this->readLog();
        $this->assertStringContainsString('[finalize-env] .env 머지 완료', $log, $log."\n".$finalize['output']);
        $this->assertFileDoesNotExist(
            $this->tempBase.'/bootstrap/cache/config.php',
            "병합 전 값으로 만든 설정 캐시가 남았다:\n".$log
        );
        $this->assertStringContainsString('[finalize-env] 설정 캐시 다시 생성 실패', $log);
    }

    /**
     * 완료 화면이 두 번 떠 finalize 가 동시에 두 번 불려도(새로고침, 다른 탭) 병합·캐시 생성은 한 번만 한다.
     *
     * @scenario situation=finalize_called_twice_concurrently
     *
     * @effects finalize_merges_once_when_called_concurrently
     *
     * @return void 반환값 없음
     */
    public function test_concurrent_finalize_calls_merge_once(): void
    {
        $this->writeState(['active_worker_id' => self::WORKER_ID, 'last_heartbeat' => time()]);

        $worker = $this->startWorkerSimulation();
        $first = $this->startFinalize('a');
        $second = $this->startFinalize('b');

        $firstResult = $this->finishProcess($first, 30);
        $secondResult = $this->finishProcess($second, 30);
        $this->finishProcess($worker, 20);

        $this->assertFalse($firstResult['timed_out'], $firstResult['output']);
        $this->assertFalse($secondResult['timed_out'], $secondResult['output']);

        $log = $this->readLog();
        $this->assertSame(1, substr_count($log, '[finalize-env] .env 머지 완료'), "병합이 한 번이 아니다:\n".$log);
        $this->assertSame(1, substr_count((string) @file_get_contents($this->tempBase.'/storage/logs/artisan-calls.log'), 'config:cache'), '설정 캐시를 여러 번 만들었다');

        $cache = $this->readConfigCache();
        $this->assertIsArray($cache);
        $this->assertSame(self::INSTALLER_DB_USER, $cache['db_username']);
        $this->assertTrue($cache['installer_completed']);
    }

    /**
     * 설치 완료 처리 직후의 상태 파일을 쓴다(소유 세션 미주장 — finalize 의 소유자 확인을 건너뛴다).
     *
     * @param  array<string, mixed>  $extra  덧붙일 필드(워커 잠금 등)
     * @return void 반환값 없음
     */
    private function writeState(array $extra): void
    {
        $state = array_merge([
            'installation_status' => 'completed',
            'current_step' => 5,
            'completed_tasks' => ['complete_flag'],
            'current_task' => 'config_cache',
            'config' => [
                'db_write_host' => '127.0.0.1',
                'db_write_port' => '3306',
                'db_write_database' => 'g7',
                'db_write_username' => self::INSTALLER_DB_USER,
                'db_prefix' => 'g7_',
                'php_binary' => str_replace('\\', '/', PHP_BINARY),
            ],
        ], $extra);

        file_put_contents($this->tempBase.'/storage/installer-state.json', json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * 설정 캐시 파일을 쓴다.
     *
     * @param  array<string, mixed>  $values  캐시 값
     * @return void 반환값 없음
     */
    private function writeConfigCache(array $values): void
    {
        file_put_contents($this->tempBase.'/bootstrap/cache/config.php', '<?php return '.var_export($values, true).";\n");
    }

    /**
     * 설정 캐시 파일을 읽는다.
     *
     * @return array<string, mixed>|null 캐시 값 (없으면 null)
     */
    private function readConfigCache(): ?array
    {
        $path = $this->tempBase.'/bootstrap/cache/config.php';
        if (! is_file($path)) {
            return null;
        }

        $values = eval('?>'.file_get_contents($path));

        return is_array($values) ? $values : null;
    }

    /**
     * 설치 로그를 읽는다.
     *
     * @return string 로그 본문
     */
    private function readLog(): string
    {
        return (string) @file_get_contents($this->tempBase.'/storage/logs/installation.log');
    }

    /**
     * 가짜 artisan — config:cache 를 Laravel 과 같은 규칙으로 흉내 낸다.
     *
     * .env 를 읽고, runtime.php 가 있으면 그 DB 계정으로 덮어쓴다(InstallerRuntimeServiceProvider).
     * artisan-fail 파일이 있으면 아무것도 쓰지 않고 실패한다.
     *
     * @return string PHP 소스
     */
    private function fakeArtisanSource(): string
    {
        return <<<'PHP'
<?php
$base = __DIR__;
file_put_contents($base.'/storage/logs/artisan-calls.log', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
if (is_file($base.'/artisan-fail')) {
    fwrite(STDOUT, "config cache failed\n");
    exit(1);
}
$env = [];
foreach (file($base.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
        $env[$m[1]] = trim($m[2], '"');
    }
}
$user = $env['DB_WRITE_USERNAME'] ?? '';
$runtimePath = $base.'/storage/installer/runtime.php';
if (is_file($runtimePath)) {
    $runtime = include $runtimePath;
    $user = $runtime['db']['write']['username'] ?? $user;
}
file_put_contents($base.'/bootstrap/cache/config.php', '<?php return '.var_export([
    'built_by' => 'artisan',
    'db_username' => $user,
    'installer_completed' => ($env['INSTALLER_COMPLETED'] ?? '') === 'true',
], true).";\n");
fwrite(STDOUT, "Configuration cached successfully.\n");
exit(0);
PHP;
    }

    /**
     * 워커 흉내 프로세스를 띄운다 — 워커의 config:cache 가 도는 동안을 재현한다.
     *
     * 바깥 부팅 때 .env 를 읽어 두고(Dotenv 는 이미 읽은 값을 덮어쓰지 않는다), 1.5초 뒤 새 부팅에서
     * runtime.php 가 있으면 그 계정, 없으면 처음 읽은 .env 계정으로 캐시를 쓴다. 그다음 잠금이
     * 자기 것인지 확인하고(아니면 「lock lost」) 잠금을 푼다. 시작 표시 파일이 생길 때까지 기다린다.
     *
     * @return array{process: resource, out: string, err: string} 실행 중인 프로세스
     */
    private function startWorkerSimulation(): array
    {
        $workerId = self::WORKER_ID;
        $body = <<<PHP
require_once G7_ROOT.'/public/install/includes/config.php';
require_once G7_ROOT.'/public/install/includes/installer-state.php';

\$readEnv = static function (): array {
    \$env = [];
    foreach (file(BASE_PATH.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as \$line) {
        if (preg_match('/^([A-Z_]+)=(.*)\$/', \$line, \$m)) {
            \$env[\$m[1]] = trim(\$m[2], '"');
        }
    }
    return \$env;
};

\$outerEnv = \$readEnv();
addLog('worker-sim: config:cache started');
file_put_contents(BASE_PATH.'/worker-started', '1');
usleep(1500000);

\$runtimePath = BASE_PATH.'/storage/installer/runtime.php';
\$user = \$outerEnv['DB_WRITE_USERNAME'] ?? '';
if (is_file(\$runtimePath)) {
    \$runtime = include \$runtimePath;
    \$user = \$runtime['db']['write']['username'] ?? \$user;
}
\$freshEnv = \$readEnv();
file_put_contents(BASE_PATH.'/bootstrap/cache/config.php', '<?php return '.var_export([
    'built_by' => 'worker',
    'db_username' => \$user,
    'installer_completed' => (\$freshEnv['INSTALLER_COMPLETED'] ?? '') === 'true',
], true).";\\n");
addLog('worker-sim: Configuration cached successfully.');

if (! refreshWorkerHeartbeat('{$workerId}')) {
    addLog('=== Polling Worker lock lost — another worker took over, aborting ===');
    exit(0);
}
releaseWorkerLock('{$workerId}');
addLog('worker-sim: lock released');
PHP;

        $process = $this->startPhp($body, 'worker');

        $deadline = microtime(true) + 10;
        while (! is_file($this->tempBase.'/worker-started') && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($this->tempBase.'/worker-started', '워커 흉내 프로세스가 시작하지 않았다');

        return $process;
    }

    /**
     * finalize-env.php 를 하위 프로세스로 띄운다.
     *
     * @param  string  $tag  출력 파일 구분자
     * @param  int|null  $waitLimitSeconds  워커 잠금 대기 상한(초). null 이면 기본값
     * @return array{process: resource, out: string, err: string} 실행 중인 프로세스
     */
    private function startFinalize(string $tag, ?int $waitLimitSeconds = null): array
    {
        $limit = $waitLimitSeconds === null ? '' : "define('INSTALLER_FINALIZE_WORKER_WAIT_SECONDS', {$waitLimitSeconds});\n";
        $body = $limit.<<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_INSTALLER_FINALIZE'] = '1';
ini_set('session.save_path', BASE_PATH.'/sessions');
ini_set('session.use_cookies', '0');
require G7_ROOT.'/public/install/api/finalize-env.php';
PHP;

        return $this->startPhp($body, 'finalize-'.$tag);
    }

    /**
     * finalize-env.php 를 실행하고 끝날 때까지 기다린다.
     *
     * @param  int|null  $waitLimitSeconds  워커 잠금 대기 상한(초). null 이면 기본값
     * @return array{timed_out: bool, output: string} 강제 종료 여부와 전체 출력
     */
    private function runFinalize(?int $waitLimitSeconds = null): array
    {
        return $this->finishProcess($this->startFinalize('main', $waitLimitSeconds), 30);
    }

    /**
     * 임시 BASE_PATH 로 PHP 하위 프로세스를 띄운다(출력은 파일로 — Windows 파이프 non-blocking 미지원).
     *
     * @param  string  $body  PHP 본문 (<?php 없이)
     * @param  string  $tag  파일 구분자
     * @return array{process: resource, out: string, err: string} 실행 중인 프로세스
     */
    private function startPhp(string $body, string $tag): array
    {
        $script = $this->tempBase.'/scenario-'.$tag.'.php';
        $prelude = "<?php\n"
            ."error_reporting(E_ALL);\n"
            ."ini_set('display_errors', 'stderr');\n"
            ."define('G7_ROOT', '{$this->projectRoot}');\n"
            ."define('BASE_PATH', '{$this->tempBase}');\n"
            ."require_once G7_ROOT.'/app/Support/PrivilegedDatabaseAccounts.php';\n"
            ."require_once G7_ROOT.'/app/Support/OpcacheStatus.php';\n";
        file_put_contents($script, $prelude.$body."\n");

        $out = $this->tempBase.'/scenario-'.$tag.'.out';
        $err = $this->tempBase.'/scenario-'.$tag.'.err';
        $process = proc_open(
            [PHP_BINARY, '-d', 'max_execution_time=0', '-d', 'opcache.enable_cli=0', $script],
            [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'PHP 하위 프로세스를 띄우지 못함');

        return ['process' => $process, 'out' => $out, 'err' => $err];
    }

    /**
     * 하위 프로세스가 끝날 때까지 기다린다(넘으면 강제 종료).
     *
     * @param  array{process: resource, out: string, err: string}  $handle  실행 중인 프로세스
     * @param  int  $timeoutSeconds  최대 대기(초)
     * @return array{timed_out: bool, output: string} 강제 종료 여부와 전체 출력
     */
    private function finishProcess(array $handle, int $timeoutSeconds): array
    {
        $process = $handle['process'];
        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                if (DIRECTORY_SEPARATOR === '\\') {
                    @exec('taskkill /F /T /PID '.(int) $status['pid'].' 2>NUL');
                }
                proc_terminate($process, 9);
                break;
            }
            usleep(50000);
        }

        proc_close($process);

        return [
            'timed_out' => $timedOut,
            'output' => (string) @file_get_contents($handle['out']).(string) @file_get_contents($handle['err']),
        ];
    }

    /**
     * 디렉터리를 재귀 삭제한다.
     *
     * @param  string  $dir  디렉터리
     * @return void 반환값 없음
     */
    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
