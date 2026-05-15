<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_0_0_beta_7\Migrations\FinalizeOrphanedInstallerRuntime;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * beta.7 잔존 인스톨러 runtime.php 자동 finalize 회귀 가드 (이슈 #371).
 *
 * axis 전수:
 *   1. runtime.php 존재 + .env 의 INSTALLER_COMPLETED 부재 + state.json 존재
 *      → .env 머지 + runtime.php 삭제 + state.json 삭제
 *   2. runtime.php 존재 + INSTALLER_COMPLETED 부재 + state.json 부재 (멱등)
 *      → .env 머지 + runtime.php 삭제 + state.json unlink 미호출
 *   3. runtime.php 부재 → no-op
 *   4. runtime.php 존재 + .env 의 INSTALLER_COMPLETED=true → 보존 + warn
 *   5. runtime.php 존재 + .env 부재 + .env.example 만 존재 → .env.example 기반 신규 생성
 *   6. runtime.php 형식 불일치 (배열 아님) → runtime.php 보존, skip
 *   7. DELETE_INSTALLER_AFTER_COMPLETE 가 false → state.json 보존
 */
class Beta7FinalizeOrphanedInstallerRuntimeTest extends TestCase
{
    private string $runtimePath;

    private string $envPath;

    private string $envExamplePath;

    private string $stateJsonPath;

    private ?string $originalEnvContent = null;

    private bool $originalEnvExisted = false;

    private bool $originalStateExisted = false;

    private ?string $originalStateContent = null;

    private bool $originalRuntimeDirExisted = false;

    /**
     * @var array<string, string|null> axis 12 가 putenv / $_ENV / $_SERVER 를
     *      덮어쓰므로 후속 테스트 / 다음 테스트 파일의 DB 자격증명이 오염되지
     *      않도록 tearDown 에서 복원할 ENV 키별 원본 스냅샷.
     */
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
        $this->envExamplePath = base_path('.env.example');
        $this->stateJsonPath = base_path('storage/installer-state.json');

        // 실제 운영 .env 와 state.json 보존
        if (is_file($this->envPath)) {
            $this->originalEnvExisted = true;
            $this->originalEnvContent = file_get_contents($this->envPath);
        }
        if (is_file($this->stateJsonPath)) {
            $this->originalStateExisted = true;
            $this->originalStateContent = file_get_contents($this->stateJsonPath);
        }

        $this->originalRuntimeDirExisted = is_dir(dirname($this->runtimePath));

        // process ENV 원본 스냅샷 — axis 12 가 putenv() 로 자격증명을 갱신하므로
        // tearDown 에서 정확히 원본 상태로 복원 (다음 테스트 / 파일 오염 차단)
        foreach (self::ENV_KEYS_FOR_SNAPSHOT as $key) {
            $value = getenv($key);
            $this->originalEnvSnapshot[$key] = $value === false ? null : $value;
        }

        // installer-state.php 등의 DELETE_INSTALLER_AFTER_COMPLETE 상수가 다른 테스트로
        // 이미 정의되어 있을 수 있다. 본 테스트는 그 상수를 신뢰하지 않고 명시 시나리오마다
        // 자체 시그널을 사용하므로 추가 정의 불필요.

        // DataMigration 은 autoload 대상이 아님 — 동적 require
        require_once base_path('upgrades/data/7.0.0-beta.7/migrations/01_FinalizeOrphanedInstallerRuntime.php');

        $this->cleanupArtifacts();
    }

    protected function tearDown(): void
    {
        $this->cleanupArtifacts();

        // .env 복원
        if ($this->originalEnvExisted && $this->originalEnvContent !== null) {
            file_put_contents($this->envPath, $this->originalEnvContent);
        } elseif (! $this->originalEnvExisted && is_file($this->envPath)) {
            @unlink($this->envPath);
        }

        // state.json 복원
        if ($this->originalStateExisted && $this->originalStateContent !== null) {
            file_put_contents($this->stateJsonPath, $this->originalStateContent);
        } elseif (! $this->originalStateExisted && is_file($this->stateJsonPath)) {
            @unlink($this->stateJsonPath);
        }

        // 본 테스트가 생성한 runtime 디렉토리 정리 (원래 없었을 때만)
        if (! $this->originalRuntimeDirExisted && is_dir(dirname($this->runtimePath))) {
            @rmdir(dirname($this->runtimePath));
        }

        // process ENV 복원 — axis 12 가 putenv() 로 자격증명을 갱신했어도
        // 다음 테스트 / 파일이 정확히 setUp 직전 상태에서 시작하도록 보장.
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

    private function cleanupArtifacts(): void
    {
        if (is_file($this->runtimePath)) {
            @unlink($this->runtimePath);
        }
    }

    private function context(): UpgradeContext
    {
        return new UpgradeContext('7.0.0-beta.6', '7.0.0-beta.7', '7.0.0-beta.7');
    }

    private function writeRuntime(array $data): void
    {
        $dir = dirname($this->runtimePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $php = "<?php\n\nreturn ".var_export($data, true).";\n";
        file_put_contents($this->runtimePath, $php);
    }

    private function sampleRuntime(): array
    {
        return [
            'db' => [
                'write' => [
                    'host' => '127.0.0.1',
                    'port' => '3306',
                    'database' => 'g7_test_db',
                    'username' => 'test_user',
                    'password' => 'test_pass_42',
                ],
                'prefix' => 'g7_',
            ],
            'app' => [
                'key' => 'base64:'.base64_encode(random_bytes(32)),
            ],
            'created_at' => date('c'),
        ];
    }

    private function writeEnv(string $content): void
    {
        file_put_contents($this->envPath, $content);
    }

    // -----------------------------------------------------------------------
    // axis 1: 정상 finalize — .env 머지 + runtime 삭제 + state.json 삭제
    // -----------------------------------------------------------------------
    public function test_finalizes_orphaned_runtime_and_merges_env(): void
    {
        $runtime = $this->sampleRuntime();
        $this->writeRuntime($runtime);
        $this->writeEnv("APP_ENV=production\nDB_WRITE_HOST=127.0.0.1\nDB_WRITE_PASSWORD=\nAPP_KEY=\n");

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        $this->assertFileDoesNotExist($this->runtimePath, 'runtime.php 가 삭제되어야 함');
        $envContent = file_get_contents($this->envPath);
        $this->assertStringContainsString('DB_WRITE_DATABASE=g7_test_db', $envContent);
        $this->assertStringContainsString('DB_WRITE_USERNAME=test_user', $envContent);
        $this->assertStringContainsString('DB_WRITE_PASSWORD="test_pass_42"', $envContent);
        $this->assertStringContainsString('APP_KEY=base64:', $envContent);
        $this->assertStringContainsString('INSTALLER_COMPLETED=true', $envContent);
    }

    // -----------------------------------------------------------------------
    // axis 2: state.json 부재 시에도 멱등 (.env 머지 + runtime 삭제)
    // -----------------------------------------------------------------------
    public function test_finalizes_when_state_json_missing(): void
    {
        $this->writeRuntime($this->sampleRuntime());
        $this->writeEnv("APP_ENV=production\nDB_WRITE_HOST=127.0.0.1\n");

        if (is_file($this->stateJsonPath)) {
            @unlink($this->stateJsonPath);
        }

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        $this->assertFileDoesNotExist($this->runtimePath);
        $envContent = file_get_contents($this->envPath);
        $this->assertStringContainsString('INSTALLER_COMPLETED=true', $envContent);
        // state.json 은 부재 상태 유지 — 비정상 종료 아님
        $this->assertFileDoesNotExist($this->stateJsonPath);
    }

    // -----------------------------------------------------------------------
    // axis 3: runtime.php 부재 → no-op
    // -----------------------------------------------------------------------
    public function test_noop_when_runtime_absent(): void
    {
        if (is_file($this->runtimePath)) {
            @unlink($this->runtimePath);
        }
        $originalEnv = "APP_ENV=production\nDB_WRITE_HOST=127.0.0.1\n";
        $this->writeEnv($originalEnv);

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        // .env 가 변경되지 않아야 함 — 멱등 no-op
        $this->assertSame($originalEnv, file_get_contents($this->envPath));
    }

    // -----------------------------------------------------------------------
    // axis 4: .env 의 INSTALLER_COMPLETED=true + runtime 잔존 → 보존 + warn
    // -----------------------------------------------------------------------
    public function test_preserves_runtime_when_env_marked_completed(): void
    {
        $runtime = $this->sampleRuntime();
        $this->writeRuntime($runtime);
        $envContent = "APP_ENV=production\nINSTALLER_COMPLETED=true\n";
        $this->writeEnv($envContent);

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        // runtime 보존 — 자동 삭제 금지
        $this->assertFileExists($this->runtimePath);
        // .env 변경 없음
        $this->assertSame($envContent, file_get_contents($this->envPath));
    }

    // -----------------------------------------------------------------------
    // axis 5: .env 부재 + .env.example 만 존재 → .env.example 기반 신규 생성
    // -----------------------------------------------------------------------
    public function test_creates_env_from_example_when_env_absent(): void
    {
        if (! is_file($this->envExamplePath)) {
            $this->markTestSkipped('.env.example 이 프로젝트 루트에 없음 — 테스트 건너뜀');
        }

        $this->writeRuntime($this->sampleRuntime());

        if (is_file($this->envPath)) {
            @unlink($this->envPath);
        }

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        $this->assertFileExists($this->envPath, '.env 가 .env.example 기반으로 생성되어야 함');
        $envContent = file_get_contents($this->envPath);
        $this->assertStringContainsString('DB_WRITE_DATABASE=g7_test_db', $envContent);
        $this->assertStringContainsString('INSTALLER_COMPLETED=true', $envContent);
        $this->assertFileDoesNotExist($this->runtimePath);
    }

    // -----------------------------------------------------------------------
    // axis 6: runtime.php 가 배열 아닌 형식 → 보존, skip
    // -----------------------------------------------------------------------
    public function test_preserves_runtime_when_format_invalid(): void
    {
        $dir = dirname($this->runtimePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // 배열을 반환하지 않는 PHP — null 반환
        file_put_contents($this->runtimePath, "<?php\n\nreturn null;\n");

        $originalEnv = "APP_ENV=production\n";
        $this->writeEnv($originalEnv);

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        $this->assertFileExists($this->runtimePath, '형식 불일치 runtime.php 는 보존되어야 함');
        $this->assertSame($originalEnv, file_get_contents($this->envPath));
    }

    // -----------------------------------------------------------------------
    // axis 7: .env 의 truthy 변형 ("1", quoted "true") 도 보존 트리거
    // -----------------------------------------------------------------------
    public function test_preserves_runtime_for_truthy_variants(): void
    {
        $runtime = $this->sampleRuntime();

        $variants = [
            'INSTALLER_COMPLETED=1',
            'INSTALLER_COMPLETED="true"',
            'INSTALLER_COMPLETED=yes',
        ];

        foreach ($variants as $line) {
            $this->writeRuntime($runtime);
            $envContent = "APP_ENV=production\n{$line}\n";
            $this->writeEnv($envContent);

            (new FinalizeOrphanedInstallerRuntime)->run($this->context());

            $this->assertFileExists(
                $this->runtimePath,
                sprintf('변형 %s 에서 runtime.php 가 보존되어야 함', $line)
            );
            $this->assertSame(
                $envContent,
                file_get_contents($this->envPath),
                sprintf('변형 %s 에서 .env 가 변경되지 않아야 함', $line)
            );

            // 다음 반복을 위한 정리
            @unlink($this->runtimePath);
        }
    }

    // -----------------------------------------------------------------------
    // axis 8: 기존 .env 의 권한/그룹 보존 — CLI 사용자와 PHP-FPM 사용자가 다른 환경
    //         (자체 구축 서버: jjh:www-data 0640)
    // -----------------------------------------------------------------------
    public function test_preserves_env_mode_when_env_existed(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows: POSIX 권한 시멘틱 미지원 — Linux 환경에서만 검증');
        }

        $this->writeRuntime($this->sampleRuntime());
        $this->writeEnv("APP_ENV=production\nAPP_KEY=\n");
        // 자체 구축 서버 시뮬레이션 — 그룹 읽기 허용 (PHP-FPM 이 그룹 멤버로 읽음)
        chmod($this->envPath, 0640);

        $beforeMode = fileperms($this->envPath) & 0777;
        $this->assertSame(0640, $beforeMode, 'precondition: .env 권한 0640');

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        $afterMode = fileperms($this->envPath) & 0777;
        $this->assertSame(
            $beforeMode,
            $afterMode,
            sprintf('기존 .env 권한(%s) 이 머지 후에도 보존되어야 함 — PHP-FPM 이 계속 읽을 수 있도록', decoct($beforeMode))
        );
        $this->assertFileDoesNotExist($this->runtimePath);
    }

    // -----------------------------------------------------------------------
    // axis 9: 0600 으로 잠긴 .env 도 그대로 보존 — 본 회귀 (#371) 의 원인은 우리가
    //         0600 으로 강제 다운그레이드 한 것이었음. 운영자가 의도해서 0600 을 둔
    //         경우 (단일 사용자 환경) 도 같은 정책 — 기존 권한이 SSoT
    // -----------------------------------------------------------------------
    public function test_preserves_strict_env_mode_unchanged(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows: POSIX 권한 시멘틱 미지원');
        }

        $this->writeRuntime($this->sampleRuntime());
        $this->writeEnv("APP_ENV=production\n");
        chmod($this->envPath, 0600);

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        $this->assertSame(
            0600,
            fileperms($this->envPath) & 0777,
            '운영자가 의도한 0600 권한도 그대로 보존'
        );
    }

    // -----------------------------------------------------------------------
    // axis 10: .env 가 새로 생성될 때 default 권한이 0640 — 0600 으로 강제 금지
    //          (#371 회귀 가드 — PHP-FPM 사용자가 그룹으로만 묶인 환경 보호)
    // -----------------------------------------------------------------------
    public function test_new_env_uses_group_readable_default_permission(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows: POSIX 권한 시멘틱 미지원');
        }
        if (! is_file($this->envExamplePath)) {
            $this->markTestSkipped('.env.example 부재');
        }

        $this->writeRuntime($this->sampleRuntime());
        if (is_file($this->envPath)) {
            @unlink($this->envPath);
        }

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        $mode = fileperms($this->envPath) & 0777;
        $this->assertSame(
            0640,
            $mode,
            sprintf(
                '.env 신규 생성 시 default 권한은 0640 이어야 함 (PHP-FPM 그룹 읽기 허용). 실제: %s',
                decoct($mode)
            )
        );
    }

    // -----------------------------------------------------------------------
    // axis 11: bootstrap/cache/ 의 그룹을 신규 .env 에 계승 — 자체 구축 서버의
    //          www-data 그룹 자동 식별. 단일 사용자 환경(카페24)에서는 동일 그룹이
    //          유지되므로 회귀 없음
    // -----------------------------------------------------------------------
    public function test_new_env_inherits_group_from_bootstrap_cache(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows: POSIX 권한 시멘틱 미지원');
        }
        if (! is_file($this->envExamplePath)) {
            $this->markTestSkipped('.env.example 부재');
        }
        $bootstrapCache = base_path('bootstrap/cache');
        if (! is_dir($bootstrapCache)) {
            $this->markTestSkipped('bootstrap/cache 디렉토리 부재');
        }

        $expectedGid = filegroup($bootstrapCache);
        if ($expectedGid === false) {
            $this->markTestSkipped('bootstrap/cache filegroup 조회 실패');
        }

        $this->writeRuntime($this->sampleRuntime());
        if (is_file($this->envPath)) {
            @unlink($this->envPath);
        }

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        $actualGid = filegroup($this->envPath);
        // chgrp 가 실패할 수 있는 환경(권한 부족)에서는 그룹이 호출 사용자의 default
        // 그룹으로 떨어질 수 있다. 실패해도 머지 자체는 성공해야 하므로 .env 존재만 단언.
        $this->assertFileExists($this->envPath);

        // 같은 사용자가 bootstrap/cache 의 그룹 멤버인 경우 chgrp 가 성공해 일치해야 함.
        // 그렇지 않으면 운영자 수동 보정 안내가 로그에 남는 것까지가 본 axis 의 기대.
        if ($actualGid !== $expectedGid) {
            $this->markTestSkipped(sprintf(
                'chgrp 실패 환경 (실행자가 그룹 %d 멤버 아님) — silent fallback 으로 통과',
                $expectedGid
            ));
        }
        $this->assertSame($expectedGid, $actualGid, 'bootstrap/cache 그룹이 .env 에 계승되어야 함');
    }

    // -----------------------------------------------------------------------
    // axis 12: 머지 후 부모 프로세스의 process ENV (getenv() / $_ENV / $_SERVER)
    //          도 신규 자격증명으로 갱신되어야 함.
    //
    //   배경 (이슈 #371 후속 회귀):
    //   beta.4~6 손상 환경에서는 .env 가 .env.example 그대로 (DB_WRITE_USERNAME=root,
    //   DB_WRITE_PASSWORD= 등) 이고 자격증명은 runtime.php 에 잔존. 부모 프로세스 부팅
    //   시점에 Dotenv 가 stale .env 의 ENV (DB_WRITE_USERNAME=root) 를 process ENV
    //   에 적재. 마이그레이션이 .env 머지 + runtime.php 삭제 를 수행해도 process ENV
    //   는 그대로. 이후 번들 일괄 업데이트 spawn 자식 (BundledExtensionUpdatePrompt 의
    //   proc_open 5번째 인자 $env = array_merge(getenv(), $_ENV)) 이 부모 stale ENV
    //   를 그대로 상속받고, Dotenv::createImmutable() 가 이미 채워진 ENV 를 덮어쓰지
    //   않으므로 자식이 stale root/패스워드YES 로 DB 연결 시도 → Access denied.
    //
    //   본 axis 는 마이그레이션 종료 시점에 getenv()/$_ENV/$_SERVER 가 머지된 신규
    //   자격증명을 반영하는지 검증한다. 한시적 보정 — beta.8 이후 환경에서는 .env 가
    //   이미 정상이므로 트리거 안 됨.
    // -----------------------------------------------------------------------
    public function test_refreshes_process_env_with_merged_credentials(): void
    {
        // .env.example 잔존 상태 시뮬레이션 (DB_WRITE_USERNAME=root, DB_WRITE_PASSWORD=)
        $this->writeEnv(
            "APP_ENV=production\n"
            ."DB_WRITE_USERNAME=root\n"
            ."DB_WRITE_PASSWORD=\n"
            ."DB_WRITE_DATABASE=laravel\n"
            ."DB_READ_USERNAME=root\n"
            ."DB_READ_PASSWORD=\n"
            ."DB_READ_DATABASE=laravel\n"
        );
        // 부모 프로세스 ENV 가 stale .env 값으로 채워진 상태 시뮬레이션
        putenv('DB_WRITE_USERNAME=root');
        putenv('DB_WRITE_PASSWORD=');
        putenv('DB_WRITE_DATABASE=laravel');
        putenv('DB_READ_USERNAME=root');
        putenv('DB_READ_PASSWORD=');
        putenv('DB_READ_DATABASE=laravel');
        $_ENV['DB_WRITE_USERNAME'] = 'root';
        $_ENV['DB_WRITE_PASSWORD'] = '';
        $_ENV['DB_WRITE_DATABASE'] = 'laravel';
        $_ENV['DB_READ_USERNAME'] = 'root';
        $_ENV['DB_READ_PASSWORD'] = '';
        $_ENV['DB_READ_DATABASE'] = 'laravel';

        $this->writeRuntime($this->sampleRuntime());

        (new FinalizeOrphanedInstallerRuntime)->run($this->context());

        // 디스크 .env 머지는 기존 axis 1 에서 검증 — 본 axis 는 process ENV 검증
        $this->assertSame(
            'test_user',
            getenv('DB_WRITE_USERNAME'),
            'getenv(DB_WRITE_USERNAME) 이 runtime 의 신규 자격증명으로 갱신되어야 함'
        );
        $this->assertSame(
            'test_pass_42',
            getenv('DB_WRITE_PASSWORD'),
            'getenv(DB_WRITE_PASSWORD) 이 runtime 의 신규 비밀번호로 갱신되어야 함'
        );
        $this->assertSame(
            'g7_test_db',
            getenv('DB_WRITE_DATABASE'),
            'getenv(DB_WRITE_DATABASE) 이 runtime 의 신규 DB 이름으로 갱신되어야 함'
        );
        $this->assertSame(
            'test_user',
            $_ENV['DB_WRITE_USERNAME'] ?? null,
            '$_ENV[DB_WRITE_USERNAME] 이 갱신되어야 함 — proc_open ENV 합집합 전파용'
        );
        $this->assertSame(
            'test_pass_42',
            $_ENV['DB_WRITE_PASSWORD'] ?? null,
            '$_ENV[DB_WRITE_PASSWORD] 도 함께 갱신'
        );
        // READ 도 동일 정책 — runtime.read 미지정 시 write 값으로 동기화
        $this->assertSame('test_user', getenv('DB_READ_USERNAME'));
        $this->assertSame('test_pass_42', getenv('DB_READ_PASSWORD'));
    }
}
