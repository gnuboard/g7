<?php

namespace Tests\Unit\Upgrades;

require_once __DIR__.'/../../../upgrades/data/7.1.6/migrations/02_FinalizeResidualInstallerRuntime.php';

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_1_6\Migrations\FinalizeResidualInstallerRuntime;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\IsolatesInstallerBasePath;
use Tests\TestCase;

/**
 * 7.1.6 업그레이드 스텝 — 설치가 끝난 사이트에 남은 인스톨러 런타임 파일을 `.env` 로 옮기고 지운다.
 *
 * 앱 루트를 임시 디렉터리로 돌려 실제 `.env` 를 건드리지 않는다. DB 접속 시험은 가짜 함수로 바꿔 끼운다.
 *
 * @scenario-manifest tests/scenarios/installer-residual-runtime-finalize.yaml
 */
class FinalizeResidualInstallerRuntimeTest extends TestCase
{
    use IsolatesInstallerBasePath;

    private const SECRETS = ['runtime-secret-pw', 'env-secret-pw', 'base64:RUNTIMEKEYRUNTIMEKEY=', 'base64:ENVKEYENVKEYENVKEY='];

    private string $base;

    private string $logPath;

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    /**
     * 앱 루트를 격리하고 시험용 로그 채널을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->base = $this->isolateInstallerBasePath();
        mkdir($this->base.'/storage/installer', 0777, true);
        $this->logPath = $this->base.'/storage/residual-test.log';
        config(['logging.channels.residual_test' => ['driver' => 'single', 'path' => $this->logPath, 'level' => 'debug']]);
        Log::forgetChannel('residual_test');

        foreach (['DB_WRITE_HOST', 'DB_WRITE_PORT', 'DB_WRITE_DATABASE', 'DB_WRITE_USERNAME', 'DB_WRITE_PASSWORD', 'DB_PREFIX', 'APP_KEY'] as $key) {
            $this->envSnapshot[$key] = getenv($key);
        }
    }

    /**
     * 프로세스 환경변수와 앱 루트를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        Log::forgetChannel('residual_test');
        $this->releaseInstallerBasePath();

        parent::tearDown();
    }

    /**
     * 런타임 파일이 없으면 아무것도 하지 않는다.
     *
     * @scenario case=no_runtime
     *
     * @effects residual_step_skips_when_nothing_to_do
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_runtime_file_is_absent(): void
    {
        $this->writeEnv(['INSTALLER_COMPLETED=true', 'DB_WRITE_HOST=db']);
        $before = file_get_contents($this->base.'/.env');

        $this->step()->run($this->context());

        $this->assertSame($before, file_get_contents($this->base.'/.env'));
        $this->assertSame([], glob($this->base.'/.env.bak-*') ?: []);
    }

    /**
     * 설치가 끝나지 않은 사이트(설치 중)의 런타임 파일은 건드리지 않는다.
     *
     * @scenario case=install_in_progress
     *
     * @effects residual_step_skips_when_nothing_to_do
     *
     * @return void 반환값 없음
     */
    public function test_keeps_runtime_file_while_install_is_in_progress(): void
    {
        $this->writeEnv(['INSTALLER_COMPLETED=false', 'DB_WRITE_HOST=']);
        $this->writeRuntime();

        $this->step()->run($this->context());

        $this->assertFileExists($this->runtimePath());
        $this->assertStringNotContainsString('runtime-host', (string) file_get_contents($this->base.'/.env'));
    }

    /**
     * `.env` 의 빈 칸을 런타임 값으로 채우고 사본을 남긴 뒤 런타임 파일을 지운다.
     *
     * @scenario case=blank_env
     *
     * @effects residual_step_fills_blank_env_and_removes_runtime
     *
     * @return void 반환값 없음
     */
    public function test_fills_blank_env_keys_backs_up_and_removes_runtime(): void
    {
        $this->writeEnv(['APP_NAME=G7', 'INSTALLER_COMPLETED=true', 'APP_KEY=', 'DB_WRITE_HOST=', 'DB_WRITE_PORT=', 'DB_WRITE_DATABASE=', 'DB_WRITE_USERNAME=', 'DB_WRITE_PASSWORD=', 'DB_PREFIX=', 'MAIL_HOST=smtp.example.com']);
        $this->writeRuntime();
        $step = $this->step(fn () => $this->fail('빈 칸을 채울 때는 접속 시험을 하지 않는다'));

        $step->run($this->context());

        $env = $this->readEnv();
        $this->assertSame('runtime-host', $env['DB_WRITE_HOST']);
        $this->assertSame('3306', $env['DB_WRITE_PORT']);
        $this->assertSame('runtime_db', $env['DB_WRITE_DATABASE']);
        $this->assertSame('runtime_user', $env['DB_WRITE_USERNAME']);
        $this->assertSame('runtime-secret-pw', $env['DB_WRITE_PASSWORD']);
        $this->assertSame('g7_', $env['DB_PREFIX']);
        $this->assertSame('base64:RUNTIMEKEYRUNTIMEKEY=', $env['APP_KEY']);
        // 다른 줄은 그대로
        $this->assertSame('G7', $env['APP_NAME']);
        $this->assertSame('smtp.example.com', $env['MAIL_HOST']);

        $backups = glob($this->base.'/.env.bak-7.1.6-*') ?: [];
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('DB_WRITE_HOST=', (string) file_get_contents($backups[0]));
        $this->assertStringNotContainsString('runtime-host', (string) file_get_contents($backups[0]));

        $this->assertFileDoesNotExist($this->runtimePath());
        // 같은 업데이트의 뒤 프로세스가 새 값으로 부팅한다
        $this->assertSame('runtime-host', getenv('DB_WRITE_HOST'));
    }

    /**
     * 앱 키가 다르면 지금까지 쓰인 값(런타임)을 채택한다.
     *
     * @scenario case=app_key_differs
     *
     * @effects residual_step_adopts_runtime_app_key
     *
     * @return void 반환값 없음
     */
    public function test_adopts_runtime_app_key_when_it_differs(): void
    {
        $this->writeEnv(['INSTALLER_COMPLETED=true', 'APP_KEY=base64:ENVKEYENVKEYENVKEY=', 'DB_WRITE_HOST=runtime-host', 'DB_WRITE_PORT=3306', 'DB_WRITE_DATABASE=runtime_db', 'DB_WRITE_USERNAME=runtime_user', 'DB_WRITE_PASSWORD=runtime-secret-pw', 'DB_PREFIX=g7_']);
        $this->writeRuntime();

        $this->step()->run($this->context());

        $this->assertSame('base64:RUNTIMEKEYRUNTIMEKEY=', $this->readEnv()['APP_KEY']);
        $this->assertFileDoesNotExist($this->runtimePath());
    }

    /**
     * DB 정보가 다르고 `.env` 값으로 접속되면 `.env` 를 유지한다(서버를 옮기며 고친 값).
     *
     * @scenario case=db_differs_env_works
     *
     * @effects residual_step_tests_connection_on_conflict
     *
     * @return void 반환값 없음
     */
    public function test_keeps_env_database_values_when_they_connect(): void
    {
        $this->writeEnv(['INSTALLER_COMPLETED=true', 'APP_KEY=base64:RUNTIMEKEYRUNTIMEKEY=', 'DB_WRITE_HOST=new-host', 'DB_WRITE_PORT=3306', 'DB_WRITE_DATABASE=new_db', 'DB_WRITE_USERNAME=new_user', 'DB_WRITE_PASSWORD=env-secret-pw', 'DB_PREFIX=g7_']);
        $this->writeRuntime();
        $tried = [];
        $step = $this->step(function (array $credentials) use (&$tried): bool {
            $tried[] = $credentials['host'];

            return $credentials['host'] === 'new-host';
        });

        $step->run($this->context());

        $env = $this->readEnv();
        $this->assertSame('new-host', $env['DB_WRITE_HOST']);
        $this->assertSame('new_user', $env['DB_WRITE_USERNAME']);
        $this->assertSame('env-secret-pw', $env['DB_WRITE_PASSWORD']);
        // `.env` 값으로 붙었으면 런타임 값은 시험하지 않는다
        $this->assertSame(['new-host'], $tried);
        // 바뀐 것이 없으니 사본도 없다
        $this->assertSame([], glob($this->base.'/.env.bak-*') ?: []);
        $this->assertFileDoesNotExist($this->runtimePath());
    }

    /**
     * DB 정보가 다르고 `.env` 값으로는 접속되지 않는데 런타임 값으로 되면 런타임 값을 채택한다.
     *
     * @scenario case=db_differs_runtime_works
     *
     * @effects residual_step_tests_connection_on_conflict
     *
     * @return void 반환값 없음
     */
    public function test_adopts_runtime_database_values_when_only_they_connect(): void
    {
        $this->writeEnv(['INSTALLER_COMPLETED=true', 'APP_KEY=base64:RUNTIMEKEYRUNTIMEKEY=', 'DB_WRITE_HOST=127.0.0.1', 'DB_WRITE_PORT=3306', 'DB_WRITE_DATABASE=laravel', 'DB_WRITE_USERNAME=root', 'DB_WRITE_PASSWORD=', 'DB_PREFIX=g7_']);
        $this->writeRuntime();
        $step = $this->step(fn (array $credentials): bool => $credentials['host'] === 'runtime-host' && $credentials['username'] === 'runtime_user');

        $step->run($this->context());

        $env = $this->readEnv();
        $this->assertSame('runtime-host', $env['DB_WRITE_HOST']);
        $this->assertSame('runtime_db', $env['DB_WRITE_DATABASE']);
        $this->assertSame('runtime_user', $env['DB_WRITE_USERNAME']);
        $this->assertCount(1, glob($this->base.'/.env.bak-7.1.6-*') ?: []);
        $this->assertFileDoesNotExist($this->runtimePath());
    }

    /**
     * 두 값 모두 접속되지 않으면 `.env` 를 유지하고 경고를 남긴다.
     *
     * @scenario case=db_differs_none_works
     *
     * @effects residual_step_tests_connection_on_conflict
     *
     * @return void 반환값 없음
     */
    public function test_keeps_env_and_warns_when_neither_connects(): void
    {
        $this->writeEnv(['INSTALLER_COMPLETED=true', 'APP_KEY=base64:RUNTIMEKEYRUNTIMEKEY=', 'DB_WRITE_HOST=new-host', 'DB_WRITE_PORT=3306', 'DB_WRITE_DATABASE=new_db', 'DB_WRITE_USERNAME=new_user', 'DB_WRITE_PASSWORD=env-secret-pw', 'DB_PREFIX=g7_']);
        $this->writeRuntime();

        $this->step(fn (): bool => false)->run($this->context());

        $this->assertSame('new-host', $this->readEnv()['DB_WRITE_HOST']);
        $this->assertStringContainsString('둘 다 접속되지 않음', (string) file_get_contents($this->logPath));
        $this->assertFileDoesNotExist($this->runtimePath());
    }

    /**
     * 다시 돌려도 같은 결과다 — 두 번째는 할 일이 없다.
     *
     * @scenario case=rerun
     *
     * @effects residual_step_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_second_run_changes_nothing(): void
    {
        $this->writeEnv(['INSTALLER_COMPLETED=true', 'APP_KEY=', 'DB_WRITE_HOST=', 'DB_WRITE_DATABASE=', 'DB_WRITE_USERNAME=', 'DB_WRITE_PASSWORD=']);
        $this->writeRuntime();

        $this->step()->run($this->context());
        $after = file_get_contents($this->base.'/.env');
        $this->step()->run($this->context());

        $this->assertSame($after, file_get_contents($this->base.'/.env'));
        $this->assertCount(1, glob($this->base.'/.env.bak-7.1.6-*') ?: []);
    }

    /**
     * 기록에는 키 이름과 결정만 남고 값(계정·비밀번호·키)은 남지 않는다.
     *
     * @scenario case=log_redaction
     *
     * @effects residual_step_logs_keys_not_values
     *
     * @return void 반환값 없음
     */
    public function test_log_has_key_names_and_decisions_but_no_secret_values(): void
    {
        $this->writeEnv(['INSTALLER_COMPLETED=true', 'APP_KEY=base64:ENVKEYENVKEYENVKEY=', 'DB_WRITE_HOST=', 'DB_WRITE_DATABASE=', 'DB_WRITE_USERNAME=', 'DB_WRITE_PASSWORD=']);
        $this->writeRuntime();

        $this->step()->run($this->context());

        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('DB_WRITE_PASSWORD', $log);
        $this->assertStringContainsString('APP_KEY', $log);
        $this->assertStringContainsString('채움', $log);
        foreach ([...self::SECRETS, 'runtime_user', 'runtime-host', 'runtime_db', $this->base] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $log);
        }
    }

    /**
     * 스텝 인스턴스를 만듭니다.
     *
     * @param  callable|null  $connector  DB 접속 시험 대역 (없으면 「언제나 실패」)
     * @return FinalizeResidualInstallerRuntime 스텝
     */
    private function step(?callable $connector = null): FinalizeResidualInstallerRuntime
    {
        $step = new FinalizeResidualInstallerRuntime;
        $step->useConnector($connector ?? static fn (): bool => false);

        return $step;
    }

    /**
     * 업그레이드 컨텍스트를 만듭니다 (시험용 로그 채널).
     *
     * @return UpgradeContext 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext('7.1.5', '7.1.6', '7.1.6', 'residual_test');
    }

    /**
     * 런타임 파일 경로를 돌려줍니다.
     *
     * @return string 경로
     */
    private function runtimePath(): string
    {
        return $this->base.'/storage/installer/runtime.php';
    }

    /**
     * 격리된 앱 루트에 `.env` 를 씁니다.
     *
     * @param  list<string>  $lines  줄 목록
     * @return void 반환값 없음
     */
    private function writeEnv(array $lines): void
    {
        file_put_contents($this->base.'/.env', implode("\n", $lines)."\n");
    }

    /**
     * 격리된 앱 루트의 `.env` 를 키-값으로 읽습니다.
     *
     * @return array<string, string> 키-값
     */
    private function readEnv(): array
    {
        $result = [];
        foreach (explode("\n", (string) file_get_contents($this->base.'/.env')) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $result[trim($key)] = trim($value, "\"\r");
            }
        }

        return $result;
    }

    /**
     * 격리된 앱 루트에 인스톨러 런타임 파일을 씁니다.
     *
     * @return void 반환값 없음
     */
    private function writeRuntime(): void
    {
        $data = [
            'db' => [
                'write' => ['host' => 'runtime-host', 'port' => '3306', 'database' => 'runtime_db', 'username' => 'runtime_user', 'password' => 'runtime-secret-pw'],
                'prefix' => 'g7_',
            ],
            'app' => ['key' => 'base64:RUNTIMEKEYRUNTIMEKEY='],
        ];
        file_put_contents($this->runtimePath(), "<?php\n\nreturn ".var_export($data, true).";\n");
    }
}
