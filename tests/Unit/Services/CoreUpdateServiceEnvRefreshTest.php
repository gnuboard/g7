<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Tests\TestCase;

/**
 * `CoreUpdateService::refreshProcessEnvFromEnvFile()` 회귀 가드 (이슈 #371 후속).
 *
 * 배경:
 *   beta.4~6 손상 환경에서 beta.7 업그레이드 시 spawn 자식 (core:execute-upgrade-steps)
 *   이 .env 머지 + putenv 갱신을 수행해도, 그 자식 종료 후 부모 프로세스로 돌아오면
 *   부모의 process ENV 는 자식 putenv 의 영향을 받지 않는다. 그 직후 부모가 다시
 *   spawn 자식 2 (core:execute-bundled-updates) 를 띄우면 부모의 stale ENV
 *   (DB_WRITE_USERNAME=root + .env.example 기본값) 가 자식 2 에 그대로 전파되어,
 *   자식 2 의 CoreServiceProvider::isDatabaseConnectionValid() 가드가 root 를 감지
 *   하고 확장 로딩을 skip + ERROR 로그를 남긴다 → 번들 일괄 업데이트 전체 실패.
 *
 * 해결:
 *   부모가 spawn 자식 1 종료 직후, spawn 자식 2 띄우기 전에 디스크의 .env (자식 1 이
 *   이미 머지 완료) 를 다시 읽어 부모의 process ENV 도 신규 자격증명으로 갱신.
 *
 * 본 테스트는 그 새 메서드의 단위 동작을 검증한다. spawn 통합은 별도 통합 테스트가
 * 아닌 PO 의 실서버 재현으로 검증 (DB Access denied 회귀 해소 여부).
 */
class CoreUpdateServiceEnvRefreshTest extends TestCase
{
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

        $this->envPath = base_path('.env');

        if (is_file($this->envPath)) {
            $this->originalEnvExisted = true;
            $this->originalEnvContent = file_get_contents($this->envPath);
        }

        foreach (self::ENV_KEYS_FOR_SNAPSHOT as $key) {
            $value = getenv($key);
            $this->originalEnvSnapshot[$key] = $value === false ? null : $value;
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvExisted && $this->originalEnvContent !== null) {
            file_put_contents($this->envPath, $this->originalEnvContent);
        } elseif (! $this->originalEnvExisted && is_file($this->envPath)) {
            @unlink($this->envPath);
        }

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

    private function service(): CoreUpdateService
    {
        return app(CoreUpdateService::class);
    }

    /**
     * 핵심 회귀 가드: stale ENV (root) + 정상 .env 일 때 ENV 가 정상 값으로 갱신.
     */
    public function test_refreshes_process_env_from_disk_env_file_overwriting_stale_values(): void
    {
        // 디스크 .env 가 정상 자격증명 보유 (spawn 자식 1 의 머지 결과 시뮬레이션)
        file_put_contents($this->envPath, implode("\n", [
            'APP_ENV=production',
            'DB_WRITE_HOST=127.0.0.1',
            'DB_WRITE_PORT=3306',
            'DB_WRITE_DATABASE=g7_prod',
            'DB_WRITE_USERNAME=g7_user',
            'DB_WRITE_PASSWORD="real_pass_99"',
            'DB_READ_HOST=127.0.0.1',
            'DB_READ_PORT=3306',
            'DB_READ_DATABASE=g7_prod',
            'DB_READ_USERNAME=g7_user',
            'DB_READ_PASSWORD="real_pass_99"',
            'INSTALLER_COMPLETED=true',
            '',
        ]));

        // 부모 프로세스의 stale ENV 시뮬레이션 (Dotenv 가 부팅 시 .env.example 의 값을 적재한 상태)
        putenv('DB_WRITE_USERNAME=root');
        putenv('DB_WRITE_PASSWORD=');
        putenv('DB_READ_USERNAME=root');
        putenv('DB_READ_PASSWORD=');
        $_ENV['DB_WRITE_USERNAME'] = 'root';
        $_ENV['DB_WRITE_PASSWORD'] = '';
        $_ENV['DB_READ_USERNAME'] = 'root';
        $_ENV['DB_READ_PASSWORD'] = '';

        $this->service()->refreshProcessEnvFromEnvFile();

        $this->assertSame('g7_user', getenv('DB_WRITE_USERNAME'), 'getenv 가 디스크 .env 의 신규 username 으로 갱신되어야 함');
        $this->assertSame('real_pass_99', getenv('DB_WRITE_PASSWORD'), 'getenv 가 인용부호 제거된 신규 password 로 갱신되어야 함');
        $this->assertSame('g7_user', getenv('DB_READ_USERNAME'));
        $this->assertSame('real_pass_99', getenv('DB_READ_PASSWORD'));
        $this->assertSame('g7_user', $_ENV['DB_WRITE_USERNAME'] ?? null, '$_ENV 도 동기 갱신 — proc_open ENV 합집합 전파용');
        $this->assertSame('g7_user', $_SERVER['DB_WRITE_USERNAME'] ?? null, '$_SERVER 도 동기 갱신');
    }

    /**
     * .env 파일 부재 시 안전한 no-op (예외 안 던짐, ENV 변경 안 함).
     */
    public function test_no_op_when_env_file_absent(): void
    {
        if (is_file($this->envPath)) {
            @unlink($this->envPath);
        }
        putenv('DB_WRITE_USERNAME=preserved');
        $_ENV['DB_WRITE_USERNAME'] = 'preserved';

        $this->service()->refreshProcessEnvFromEnvFile();

        $this->assertSame('preserved', getenv('DB_WRITE_USERNAME'), '.env 부재 시 기존 ENV 보존');
    }

    /**
     * .env 의 인용부호로 감싼 값이 정확히 처리됨 (parse_ini_file INI_SCANNER_RAW 호환).
     */
    public function test_handles_quoted_values_correctly(): void
    {
        file_put_contents($this->envPath, implode("\n", [
            'APP_ENV=production',
            'DB_WRITE_PASSWORD="pass with spaces"',
            '',
        ]));
        putenv('DB_WRITE_PASSWORD=');
        $_ENV['DB_WRITE_PASSWORD'] = '';

        $this->service()->refreshProcessEnvFromEnvFile();

        $this->assertSame('pass with spaces', getenv('DB_WRITE_PASSWORD'), '인용부호로 감싼 값이 unwrap 되어 적용');
    }
}
