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

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimePath = base_path('storage/installer/runtime.php');

        // 테스트 시작 시 runtime.php 정리 (이전 테스트의 잔재 제거)
        if (is_file($this->runtimePath)) {
            @unlink($this->runtimePath);
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
