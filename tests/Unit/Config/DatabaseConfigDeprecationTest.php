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
                . json_encode($deprecations, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
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
                "config/database.php references PDO::MYSQL_ATTR_SSL_CA without a "
                . "PHP_VERSION_ID >= 80500 ternary guard. This causes E_DEPRECATED "
                . "on PHP 8.5+. Use \\Pdo\\Mysql::ATTR_SSL_CA when PHP_VERSION_ID >= 80500."
            );
        }

        $this->assertTrue(true);
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
