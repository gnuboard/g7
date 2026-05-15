<?php

namespace Tests\Unit\Providers;

use App\Providers\InstallerRuntimeServiceProvider;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * InstallerRuntimeServiceProvider 단위 테스트
 *
 * 설치 진행 중 storage/installer/runtime.php 의 동적 설정이
 * Laravel config 에 정상 주입되는지, 부재 시 부팅에 영향이 없는지 검증.
 *
 * @see https://github.com/gnuboard/g7/issues/23
 */
class InstallerRuntimeServiceProviderTest extends TestCase
{
    private string $runtimePath;

    private ?string $originalEnv = null;

    private string $envPath;

    private ?string $originalEnvContent = null;

    private bool $originalEnvExisted = false;

    /** @var array<string, string|null> */
    private array $originalEnvSnapshot = [];

    private const ENV_KEYS_FOR_SNAPSHOT = [
        'DB_WRITE_HOST', 'DB_WRITE_PORT', 'DB_WRITE_DATABASE', 'DB_WRITE_USERNAME', 'DB_WRITE_PASSWORD',
        'DB_READ_HOST', 'DB_READ_PORT', 'DB_READ_DATABASE', 'DB_READ_USERNAME', 'DB_READ_PASSWORD',
        'DB_PREFIX', 'APP_KEY',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimePath = base_path('storage/installer/runtime.php');
        $this->envPath = base_path('.env');

        // 테스트 시작 시 runtime.php 정리 (이전 테스트의 잔재 제거)
        if (is_file($this->runtimePath)) {
            @unlink($this->runtimePath);
        }

        // .env 원본 보존
        if (is_file($this->envPath)) {
            $this->originalEnvExisted = true;
            $this->originalEnvContent = file_get_contents($this->envPath);
        }

        // process ENV 스냅샷 (stale ENV 보정 테스트가 갱신하므로 다음 테스트 격리)
        foreach (self::ENV_KEYS_FOR_SNAPSHOT as $key) {
            $value = getenv($key);
            $this->originalEnvSnapshot[$key] = $value === false ? null : $value;
        }
    }

    protected function tearDown(): void
    {
        // 테스트 후 정리 — 운영 환경 영향 방지
        if (is_file($this->runtimePath)) {
            @unlink($this->runtimePath);
        }

        $dir = dirname($this->runtimePath);
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            @rmdir($dir);
        }

        if ($this->originalEnv !== null) {
            $this->app['env'] = $this->originalEnv;
            $this->originalEnv = null;
        }

        // .env 복원
        if ($this->originalEnvExisted && $this->originalEnvContent !== null) {
            file_put_contents($this->envPath, $this->originalEnvContent);
        } elseif (! $this->originalEnvExisted && is_file($this->envPath)) {
            @unlink($this->envPath);
        }

        // process ENV 복원
        foreach ($this->originalEnvSnapshot as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    /**
     * 인스톨러 runtime 파일의 주입을 검증하기 위해 일시적으로 production 환경으로 전환.
     *
     * register() 가 testing 환경을 감지하면 즉시 return 하므로,
     * 주입 동작 자체를 검증하는 테스트는 비-테스팅 환경 컨텍스트에서 실행.
     */
    private function withProductionEnv(): void
    {
        $this->originalEnv = $this->app['env'] ?? null;
        $this->app['env'] = 'production';
    }

    public function test_provider_skips_silently_when_runtime_file_absent(): void
    {
        $this->withProductionEnv();
        $this->assertFileDoesNotExist($this->runtimePath);

        // 기존 config 값 보존 — Provider 가 덮어쓰지 않음
        $originalHost = Config::get('database.connections.mysql.write.host');
        $originalKey = Config::get('app.key');

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        $this->assertSame($originalHost, Config::get('database.connections.mysql.write.host'));
        $this->assertSame($originalKey, Config::get('app.key'));
    }

    public function test_provider_skips_in_testing_env_even_when_runtime_present(): void
    {
        // 회귀 가드: storage/installer/runtime.php 가 프로덕션 install 로 생성되어 있어도
        // testing 환경에서는 .env.testing 의 DB 자격증명이 SSoT 로 보존되어야 한다.
        // (회귀 사고: runtime 의 g7_2 가 .env.testing 의 g7_2_testing 을 덮어써
        //  RefreshDatabase 가 프로덕션 DB 를 대상으로 실행되던 결함)
        $this->writeRuntime([
            'db' => [
                'write' => [
                    'host' => 'should-be-ignored',
                    'port' => '3306',
                    'database' => 'should-be-ignored-db',
                    'username' => 'should-be-ignored-user',
                    'password' => 'should-be-ignored-pass',
                ],
                'prefix' => 'ignored_',
            ],
            'app' => ['key' => 'base64:'.base64_encode(random_bytes(32))],
        ]);

        $originalDb = Config::get('database.connections.mysql.write.database');
        $originalKey = Config::get('app.key');

        $this->assertSame('testing', $this->app->environment());

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        // testing env 에서는 runtime 의 값이 무시되어 기존 .env.testing 값 유지
        $this->assertSame($originalDb, Config::get('database.connections.mysql.write.database'));
        $this->assertSame($originalKey, Config::get('app.key'));
    }

    public function test_provider_injects_db_credentials_when_runtime_file_present(): void
    {
        $this->withProductionEnv();
        $this->writeRuntime([
            'db' => [
                'write' => [
                    'host' => 'test-db-host',
                    'port' => '13306',
                    'database' => 'test_db',
                    'username' => 'test_user',
                    'password' => 'test_pass',
                ],
                'prefix' => 'tt_',
            ],
        ]);

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        $this->assertSame(['test-db-host'], Config::get('database.connections.mysql.write.host'));
        $this->assertSame('13306', Config::get('database.connections.mysql.write.port'));
        $this->assertSame('test_db', Config::get('database.connections.mysql.write.database'));
        $this->assertSame('test_user', Config::get('database.connections.mysql.write.username'));
        $this->assertSame('test_pass', Config::get('database.connections.mysql.write.password'));
        $this->assertSame('tt_', Config::get('database.connections.mysql.prefix'));

        // read 미지정 시 write 값으로 동기화
        $this->assertSame(['test-db-host'], Config::get('database.connections.mysql.read.host'));
    }

    public function test_provider_injects_app_key(): void
    {
        $this->withProductionEnv();
        $key = 'base64:' . base64_encode(random_bytes(32));

        $this->writeRuntime([
            'app' => ['key' => $key],
        ]);

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        $this->assertSame($key, Config::get('app.key'));
    }

    public function test_provider_handles_separate_read_db_config(): void
    {
        $this->withProductionEnv();
        $this->writeRuntime([
            'db' => [
                'write' => [
                    'host' => 'write-host',
                    'port' => '3306',
                    'database' => 'g7',
                    'username' => 'root',
                    'password' => '',
                ],
                'read' => [
                    'host' => 'read-host',
                    'port' => '3307',
                    'database' => 'g7_replica',
                    'username' => 'reader',
                    'password' => 'rp',
                ],
                'prefix' => '',
            ],
        ]);

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        $this->assertSame(['read-host'], Config::get('database.connections.mysql.read.host'));
        $this->assertSame('3307', Config::get('database.connections.mysql.read.port'));
        $this->assertSame('reader', Config::get('database.connections.mysql.read.username'));
        $this->assertSame(['write-host'], Config::get('database.connections.mysql.write.host'));
    }

    public function test_provider_silently_skips_invalid_runtime_format(): void
    {
        $this->withProductionEnv();
        // 잘못된 형식 — 배열이 아닌 스칼라
        @mkdir(dirname($this->runtimePath), 0755, true);
        file_put_contents($this->runtimePath, "<?php\nreturn 'not-an-array';\n");

        $originalHost = Config::get('database.connections.mysql.write.host');

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        $this->assertSame($originalHost, Config::get('database.connections.mysql.write.host'));
    }

    public function test_provider_skips_app_key_when_empty(): void
    {
        $this->withProductionEnv();
        $this->writeRuntime([
            'app' => ['key' => ''],
        ]);

        $originalKey = Config::get('app.key');

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        // 빈 문자열은 무시 — 기존 .env 값 보존
        $this->assertSame($originalKey, Config::get('app.key'));
    }

    // -----------------------------------------------------------------------
    // beta.7 한시 보정 — beta.4~6 finalize 결함 환경에서 .env 머지 후 spawn 자식의
    //   stale process ENV (DB_WRITE_USERNAME=root 등) 회귀 차단.
    //
    // 트리거 조건 (모두 만족 시에만 보정):
    //   1. runtime.php 부재 (이미 finalize 완료된 상태)
    //   2. .env 존재 + 정합 자격증명 보유 (DB_WRITE_USERNAME 이 root 가 아니고 비어있지 않음)
    //   3. process ENV 의 DB_WRITE_USERNAME 이 'root' 또는 빈 값 (stale 시그니처)
    //   4. .env 의 INSTALLER_COMPLETED 가 truthy (finalize 성공 신호)
    //
    // 위 4개를 모두 만족하면 .env 의 DB_* / APP_KEY 를 process ENV (getenv/$_ENV/$_SERVER)
    // 에 적용. 정상 환경에서는 #3 (stale 시그니처) 가 false 라 트리거 안 됨.
    // -----------------------------------------------------------------------

    public function test_recovers_stale_process_env_when_disk_env_is_healthy(): void
    {
        $this->withProductionEnv();

        // 디스크 .env 는 정합 자격증명 + INSTALLER_COMPLETED (finalize 완료 신호)
        file_put_contents($this->envPath, implode("\n", [
            'APP_ENV=production',
            'DB_WRITE_USERNAME=g7_user',
            'DB_WRITE_PASSWORD="real_pw"',
            'DB_WRITE_DATABASE=g7',
            'DB_READ_USERNAME=g7_user',
            'DB_READ_PASSWORD="real_pw"',
            'DB_READ_DATABASE=g7',
            'INSTALLER_COMPLETED=true',
            '',
        ]));

        // process ENV 는 stale (Dotenv 가 부팅 시 .env.example fallback 을 적재한 상태)
        putenv('DB_WRITE_USERNAME=root');
        putenv('DB_WRITE_PASSWORD=');
        putenv('DB_READ_USERNAME=root');
        putenv('DB_READ_PASSWORD=');
        $_ENV['DB_WRITE_USERNAME'] = 'root';
        $_ENV['DB_WRITE_PASSWORD'] = '';
        $_ENV['DB_READ_USERNAME'] = 'root';
        $_ENV['DB_READ_PASSWORD'] = '';
        // $_SERVER 도 stale — bootstrap/app.php 의 Env::disablePutenv() 환경에서는
        // Dotenv 가 $_ENV/$_SERVER 만 채우므로 셋 다 stale 인 실서버 케이스 재현
        $_SERVER['DB_WRITE_USERNAME'] = 'root';
        $_SERVER['DB_WRITE_PASSWORD'] = '';
        $_SERVER['DB_READ_USERNAME'] = 'root';
        $_SERVER['DB_READ_PASSWORD'] = '';

        $this->assertFileDoesNotExist($this->runtimePath, 'precondition: runtime.php 부재');

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        $this->assertSame('g7_user', getenv('DB_WRITE_USERNAME'), 'stale root 가 .env 의 정합 값으로 갱신되어야 함');
        $this->assertSame('real_pw', getenv('DB_WRITE_PASSWORD'));
        $this->assertSame('g7_user', getenv('DB_READ_USERNAME'));
        $this->assertSame('real_pw', getenv('DB_READ_PASSWORD'));
        $this->assertSame('g7_user', $_ENV['DB_WRITE_USERNAME'] ?? null);
        $this->assertSame('g7_user', $_SERVER['DB_WRITE_USERNAME'] ?? null);
    }

    public function test_no_recovery_when_process_env_already_healthy(): void
    {
        $this->withProductionEnv();

        file_put_contents($this->envPath, implode("\n", [
            'DB_WRITE_USERNAME=g7_user',
            'DB_WRITE_PASSWORD="real_pw"',
            'INSTALLER_COMPLETED=true',
            '',
        ]));

        // 정상 환경 시뮬레이션 — process ENV 가 이미 정합
        putenv('DB_WRITE_USERNAME=already_correct');
        putenv('DB_WRITE_PASSWORD=already_correct_pw');
        $_ENV['DB_WRITE_USERNAME'] = 'already_correct';
        $_ENV['DB_WRITE_PASSWORD'] = 'already_correct_pw';

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        // 보정 트리거 안 됨 — 정합 ENV 보존 (beta.7 보정이 .env 의 g7_user 로 덮어쓰면 안 됨)
        $this->assertSame('already_correct', getenv('DB_WRITE_USERNAME'), 'stale 시그니처 아니므로 보정 미발동');
        $this->assertSame('already_correct_pw', getenv('DB_WRITE_PASSWORD'));
    }

    public function test_no_recovery_when_disk_env_also_stale(): void
    {
        $this->withProductionEnv();

        // 디스크 .env 도 stale (finalize 실패 분기 시뮬레이션)
        file_put_contents($this->envPath, implode("\n", [
            'DB_WRITE_USERNAME=root',
            'DB_WRITE_PASSWORD=',
            'INSTALLER_COMPLETED=true',
            '',
        ]));

        putenv('DB_WRITE_USERNAME=root');
        putenv('DB_WRITE_PASSWORD=');
        $_ENV['DB_WRITE_USERNAME'] = 'root';
        $_ENV['DB_WRITE_PASSWORD'] = '';

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        // .env 도 stale 이므로 보정 의미 없음 — 빈 값으로 덮어쓰지 않음
        $this->assertSame('root', getenv('DB_WRITE_USERNAME'), '.env 도 stale 이면 보정 미발동 (정상 ENV 손상 방지)');
    }

    public function test_no_recovery_when_installer_not_completed(): void
    {
        $this->withProductionEnv();

        // INSTALLER_COMPLETED 부재 — finalize 미수행 (인스톨러 중간 상태) 시그니처
        file_put_contents($this->envPath, implode("\n", [
            'DB_WRITE_USERNAME=g7_user',
            'DB_WRITE_PASSWORD="real_pw"',
            '',
        ]));

        putenv('DB_WRITE_USERNAME=root');
        $_ENV['DB_WRITE_USERNAME'] = 'root';

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        // INSTALLER_COMPLETED 부재 → 보정 미발동 (인스톨러 진행 중일 수 있음, 보수적 보호)
        $this->assertSame('root', getenv('DB_WRITE_USERNAME'), 'INSTALLER_COMPLETED 부재 시 보정 미발동');
    }

    public function test_no_recovery_when_runtime_php_still_present(): void
    {
        $this->withProductionEnv();

        // runtime.php 잔존 — 기존 register() 가 Config 주입을 처리하므로 보정 불필요
        $this->writeRuntime([
            'db' => ['write' => ['host' => '127.0.0.1', 'username' => 'rt_user', 'password' => 'rt_pw']],
        ]);

        file_put_contents($this->envPath, implode("\n", [
            'DB_WRITE_USERNAME=g7_user',
            'INSTALLER_COMPLETED=true',
            '',
        ]));

        putenv('DB_WRITE_USERNAME=root');
        $_ENV['DB_WRITE_USERNAME'] = 'root';

        $provider = new InstallerRuntimeServiceProvider($this->app);
        $provider->register();

        // runtime.php 존재 → 기존 경로 (Config 주입) 가 동작. 본 보정은 트리거 안 됨.
        $this->assertSame('root', getenv('DB_WRITE_USERNAME'), 'runtime.php 잔존 시 본 보정 미발동');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeRuntime(array $data): void
    {
        $dir = dirname($this->runtimePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $php = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        file_put_contents($this->runtimePath, $php);
    }
}
