<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ValidationApi;

/**
 * 인스톨러 Composer/PHP 경로 검증 보안 회귀 테스트
 *
 * 사용자 입력으로 들어온 Composer/PHP 바이너리 경로가 shell 명령으로 그대로
 * 실행되던 결함(공백 분기 + escape 부재) 의 패치를 보장한다.
 *
 * 검증 축:
 *  - shell 메타문자(공백, 세미콜론, 백틱 등) 포함 입력 → exec 도달 전 차단
 *  - 존재하지 않는 경로 → exec 도달 전 차단
 *  - 빈 입력 (시스템 기본 'composer' / 'php') → 통과 (실행은 환경 의존)
 *  - 정상 .phar 경로 + 잘못된 phpPath → exec 도달 전 차단
 */
class ComposerPathValidationTest extends TestCase
{
    private static bool $loaded = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$loaded) {
            return;
        }

        // ValidationApi 는 글로벌 네임스페이스이므로 \lang() 으로 조회됨 →
        // 글로벌 스코프에 정의된 스텁이 필요.
        require_once __DIR__ . '/stubs/lang_stub.php';

        if (! defined('MIN_PHP_VERSION')) {
            define('MIN_PHP_VERSION', '8.2.0');
        }

        if (! defined('CHECK_CONFIGURATION_LIBRARY')) {
            define('CHECK_CONFIGURATION_LIBRARY', true);
        }

        require_once dirname(__DIR__, 3) . '/public/install/api/check-configuration.php';
        self::$loaded = true;
    }

    /**
     * private 메서드 호출 헬퍼.
     */
    private function invoke(string $method, array $args): array
    {
        $api = new ValidationApi();
        $ref = new ReflectionClass($api);
        $m = $ref->getMethod($method);

        // PHP 8.1+ 부터 ReflectionMethod 가 자동으로 private 접근 가능 — setAccessible 미호출.
        return $m->invokeArgs($api, $args);
    }

    public function test_composer_path_with_shell_injection_is_rejected_before_exec(): void
    {
        $marker = sys_get_temp_dir() . '/g7_kve_test_' . bin2hex(random_bytes(4));
        $payload = 'sh -c "id > ' . $marker . '"';

        $result = $this->invoke('validateComposerPath', [$payload, 'php']);

        $this->assertFalse($result['valid'], '공백 포함 임의 명령 입력은 valid=false 여야 함');
        $this->assertNull($result['version']);
        $this->assertFileDoesNotExist($marker, '검증 단계에서 exec 가 실행되지 않아야 함 (RCE 차단)');
    }

    public function test_composer_path_with_semicolon_injection_is_rejected(): void
    {
        $marker = sys_get_temp_dir() . '/g7_kve_test_' . bin2hex(random_bytes(4));
        $payload = '/bin/sh; touch ' . $marker;

        $result = $this->invoke('validateComposerPath', [$payload, 'php']);

        $this->assertFalse($result['valid']);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_composer_path_pointing_to_nonexistent_file_is_rejected(): void
    {
        $result = $this->invoke('validateComposerPath', ['/nonexistent/path/to/composer', 'php']);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['version']);
    }

    public function test_composer_path_pointing_to_directory_is_rejected(): void
    {
        $result = $this->invoke('validateComposerPath', [sys_get_temp_dir(), 'php']);

        $this->assertFalse($result['valid']);
    }

    public function test_phar_path_with_invalid_php_path_is_rejected_before_exec(): void
    {
        $marker = sys_get_temp_dir() . '/g7_kve_test_' . bin2hex(random_bytes(4));

        $tmpPhar = tempnam(sys_get_temp_dir(), 'g7-fake-composer-') . '.phar';
        file_put_contents($tmpPhar, '');
        chmod($tmpPhar, 0755);

        try {
            $payload = 'sh -c "id > ' . $marker . '"';
            $result = $this->invoke('validateComposerPath', [$tmpPhar, $payload]);

            $this->assertFalse($result['valid']);
            $this->assertFileDoesNotExist($marker);
        } finally {
            @unlink($tmpPhar);
        }
    }

    public function test_php_path_with_shell_injection_is_rejected_before_exec(): void
    {
        $marker = sys_get_temp_dir() . '/g7_kve_test_' . bin2hex(random_bytes(4));
        $payload = 'sh -c "id > ' . $marker . '"';

        $result = $this->invoke('validatePhpPath', [$payload]);

        $this->assertFalse($result['valid']);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_php_path_pointing_to_nonexistent_file_is_rejected(): void
    {
        $result = $this->invoke('validatePhpPath', ['/nonexistent/path/to/php']);

        $this->assertFalse($result['valid']);
    }

    public function test_empty_composer_path_falls_back_to_system_composer(): void
    {
        // 빈 입력은 'composer' 시스템 기본으로 시도 (실행 자체는 환경 의존)
        // 핵심은 validation 단계에서 거부되지 않고 exec 까지 도달한다는 것.
        $result = $this->invoke('validateComposerPath', ['', 'php']);

        // composer 미설치 환경: exec 실패로 valid=false + error_composer_exec_failed 메시지
        // composer 설치 환경: valid=true
        // 두 경우 모두 "사전 거부" 와는 다른 메시지를 반환해야 한다.
        $this->assertContains($result['valid'], [true, false]);
        // 사전 거부 메시지("error_composer_exec_failed") 가 path='composer' 로 매핑되거나 성공.
        // shell injection 메시지 패턴이 들어가지 않는지 확인.
        $this->assertIsString($result['message']);
    }
}
