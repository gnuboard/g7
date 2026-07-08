<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * config/database.php 의 PHP 8.5+ deprecation 회피 회귀 테스트
 *
 * PHP 8.5 에서 PDO::MYSQL_ATTR_SSL_CA 상수가 deprecated 되었으므로
 * config 로드 시 E_DEPRECATED 가 트리거되지 않아야 한다.
 *
 * 검증 방식:
 * 1. 동적: config 파일을 재include 하여 발생한 PDO 관련 deprecation 캡처 (PHP 8.5+ 에서만 트리거)
 * 2. 정적: 파일 텍스트에서 가드 없는 PDO::MYSQL_ATTR_SSL_CA 직접 참조 패턴 검출 (모든 PHP 버전)
 *
 * 정적 검사는 PHP 8.4 이하 CI 에서도 회귀를 차단하기 위함이다.
 *
 * @see https://github.com/gnuboard/g7/issues/23
 */
class DatabaseConfigDeprecationTest extends TestCase
{
    public function test_mysql_options_does_not_trigger_pdo_deprecation(): void
    {
        $deprecations = $this->captureDeprecations(function () {
            // Laravel 부팅 후 config 파일을 재include — database_path() 등 헬퍼 사용 가능
            $config = require config_path('database.php');
            $mysqlOptions = $config['connections']['mysql']['options'];
            $mariadbOptions = $config['connections']['mariadb']['options'];

            // array_filter 결과를 실제로 순회하여 키 상수 평가 강제
            foreach ($mysqlOptions as $k => $v) {
                $unused = $k;
            }
            foreach ($mariadbOptions as $k => $v) {
                $unused = $k;
            }
        });

        $this->assertEmpty(
            $deprecations,
            'config/database.php emitted PHP deprecation: '
                .json_encode($deprecations, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    public function test_config_file_does_not_reference_unguarded_deprecated_pdo_constant(): void
    {
        $file = file_get_contents(config_path('database.php'));

        // 가드 패턴: PHP_VERSION_ID >= 80500 ternary 안에서만 PDO::MYSQL_ATTR_SSL_CA 사용 허용
        $guardedPattern = '/PHP_VERSION_ID\s*>=\s*80500.*?Pdo\\\\Mysql::ATTR_SSL_CA.*?PDO::MYSQL_ATTR_SSL_CA/s';
        $hasGuard = (bool) preg_match($guardedPattern, $file);

        // 직접 참조 횟수
        $directReferences = preg_match_all('/PDO::MYSQL_ATTR_SSL_CA/', $file);

        if ($directReferences > 0 && ! $hasGuard) {
            $this->fail(
                'config/database.php references PDO::MYSQL_ATTR_SSL_CA without a '
                .'PHP_VERSION_ID >= 80500 ternary guard. This causes E_DEPRECATED '
                .'on PHP 8.5+. Use \\Pdo\\Mysql::ATTR_SSL_CA when PHP_VERSION_ID >= 80500.'
            );
        }

        $this->assertTrue(true);
    }

    /**
     * 이슈 #63 회귀 — Read 커넥션은 DB_READ_* 가 비어 있으면 DB_WRITE_* 로 fallback 한다.
     *
     * 미설정(null) / 빈 문자열('') / 명시값 세 케이스를 모두 검증한다. 특히 빈 문자열
     * 케이스는 config 가 `env('DB_READ_HOST', default)` (중첩 env) 로 되돌아가면
     * fallback 이 무력화되므로(env() 가 ''를 반환) 이 테스트가 그 회귀를 차단한다.
     *
     * @dataProvider readFallbackProvider
     */
    public function test_read_connection_falls_back_to_write_when_read_env_empty(
        ?string $readHost,
        string $expectedHost
    ): void {
        $original = [
            'DB_WRITE_HOST' => $_ENV['DB_WRITE_HOST'] ?? null,
            'DB_READ_HOST' => $_ENV['DB_READ_HOST'] ?? null,
        ];

        $this->setEnvVar('DB_WRITE_HOST', 'write-host.test');
        $this->setEnvVar('DB_READ_HOST', $readHost);

        try {
            // config 파일을 직접 require — 내부 env() 가 현재 $_ENV/putenv 값을 평가한다.
            $config = require config_path('database.php');
            $resolvedHost = $config['connections']['mysql']['read']['host'][0] ?? null;

            $this->assertSame($expectedHost, $resolvedHost);
        } finally {
            $this->setEnvVar('DB_WRITE_HOST', $original['DB_WRITE_HOST']);
            $this->setEnvVar('DB_READ_HOST', $original['DB_READ_HOST']);
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function readFallbackProvider(): array
    {
        return [
            'read 미설정 → write' => [null, 'write-host.test'],
            'read 빈 문자열 → write' => ['', 'write-host.test'],
            'read 명시 → read replica 존중' => ['read-replica.test', 'read-replica.test'],
        ];
    }

    /**
     * 정적 검사 — config 가 read fallback 에 Elvis(?:) 패턴을 사용하는지 확인.
     * 중첩 env(A, env(B)) 로 회귀하면(빈 문자열 fallback 실패) 이 테스트가 차단한다.
     */
    public function test_config_uses_elvis_fallback_for_read_connection(): void
    {
        $file = file_get_contents(config_path('database.php'));

        $this->assertMatchesRegularExpression(
            "/env\('DB_READ_HOST'\)\s*\?:\s*env\('DB_WRITE_HOST'/",
            $file,
            'config/database.php 의 read.host 가 Elvis(?:) write fallback 을 사용해야 한다 '
                .'(이슈 #63 — 빈 문자열 DB_READ_HOST 시 write 로 fallback).'
        );
    }

    /**
     * process env 를 임시로 설정/해제한다 (putenv + $_ENV + $_SERVER 동기).
     *
     * @param  string  $key  환경변수 키
     * @param  string|null  $value  값 (null 이면 제거)
     */
    private function setEnvVar(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @param  callable  $fn  실행 중 발생한 deprecation 을 수집할 콜백
     * @return array<int, array{message: string, file: string, line: int}>
     */
    private function captureDeprecations(callable $fn): array
    {
        $deprecations = [];

        set_error_handler(function ($severity, $message, $file, $line) use (&$deprecations) {
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                if (str_contains($message, 'PDO::') || str_contains($message, 'MYSQL_ATTR_')) {
                    $deprecations[] = compact('message', 'file', 'line');
                }
            }

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }
}
