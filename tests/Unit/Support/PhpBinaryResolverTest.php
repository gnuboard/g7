<?php

namespace Tests\Unit\Support;

use App\Support\PhpBinaryResolver;
use Tests\TestCase;

class PhpBinaryResolverTest extends TestCase
{
    /**
     * resolve()는 config('process.php_binary') 값을 반환한다.
     */
    public function test_resolve_returns_config_value(): void
    {
        config(['process.php_binary' => '/usr/local/php84/bin/php']);

        $this->assertEquals('/usr/local/php84/bin/php', PhpBinaryResolver::resolve());
    }

    /**
     * resolve()는 config 값이 빈 문자열이면 'php'를 반환한다.
     */
    public function test_resolve_returns_php_when_config_is_empty(): void
    {
        config(['process.php_binary' => '']);

        $this->assertEquals('php', PhpBinaryResolver::resolve());
    }

    /**
     * resolve()는 config 값이 null이면 'php'를 반환한다.
     */
    public function test_resolve_returns_php_when_config_is_null(): void
    {
        config(['process.php_binary' => null]);

        $this->assertEquals('php', PhpBinaryResolver::resolve());
    }

    /**
     * resolveComposer()는 config('process.composer_binary') 값을 반환한다.
     */
    public function test_resolve_composer_returns_config_value(): void
    {
        config(['process.composer_binary' => '/usr/local/bin/composer']);

        $this->assertEquals('/usr/local/bin/composer', PhpBinaryResolver::resolveComposer());
    }

    /**
     * resolveComposer()는 config 값이 빈 문자열이면 빈 문자열을 반환한다.
     */
    public function test_resolve_composer_returns_empty_when_config_is_empty(): void
    {
        config(['process.composer_binary' => '']);

        $this->assertEquals('', PhpBinaryResolver::resolveComposer());
    }

    /**
     * validate()는 빈 경로에 대해 invalid를 반환한다.
     */
    public function test_validate_returns_invalid_for_empty_path(): void
    {
        $result = PhpBinaryResolver::validate('');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['version']);
    }

    /**
     * validate()는 존재하지 않는 파일 경로에 대해 invalid를 반환한다.
     */
    public function test_validate_returns_invalid_for_nonexistent_path(): void
    {
        $result = PhpBinaryResolver::validate('/nonexistent/path/to/php');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['version']);
        $this->assertStringContainsString('존재하지 않습니다', $result['message']);
    }

    /**
     * validate()는 현재 시스템 PHP를 유효하게 판정한다.
     */
    public function test_validate_accepts_current_php_binary(): void
    {
        $result = PhpBinaryResolver::validate(PHP_BINARY);

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $result['version']);
    }

    /**
     * validate()는 'php' (시스템 PATH)를 유효하게 판정한다.
     */
    public function test_validate_accepts_system_php(): void
    {
        $result = PhpBinaryResolver::validate('php');

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['version']);
    }

    /**
     * buildComposerCommand()는 composerBinary가 빈 문자열이면 'composer'를 반환한다.
     */
    public function test_build_composer_command_returns_composer_when_no_binary(): void
    {
        $result = PhpBinaryResolver::buildComposerCommand('php', '');

        $this->assertEquals('composer', $result);
    }

    /**
     * buildComposerCommand()는 .phar 파일이면 PHP 바이너리로 실행한다.
     */
    public function test_build_composer_command_uses_php_for_phar(): void
    {
        $result = PhpBinaryResolver::buildComposerCommand('/usr/local/php84/bin/php', '/usr/local/bin/composer.phar');

        $this->assertStringContainsString('/usr/local/php84/bin/php', $result);
        $this->assertStringContainsString('composer.phar', $result);
    }

    /**
     * buildComposerCommand()는 일반 바이너리는 직접 실행한다.
     */
    public function test_build_composer_command_uses_binary_directly(): void
    {
        $result = PhpBinaryResolver::buildComposerCommand('php', '/usr/local/bin/composer');

        $this->assertStringContainsString('composer', $result);
        // PHP 바이너리는 포함되지 않아야 함 (.phar가 아니므로)
        $this->assertStringNotContainsString('php', $result);
    }

    /**
     * autoDetect()는 최소 하나의 PHP 바이너리를 감지한다.
     */
    public function test_auto_detect_finds_at_least_one_php(): void
    {
        $found = PhpBinaryResolver::autoDetect();

        $this->assertNotEmpty($found);
        $this->assertArrayHasKey('path', $found[0]);
        $this->assertArrayHasKey('version', $found[0]);
    }

    /**
     * autoDetect() 결과의 모든 항목은 유효한 버전 형식을 가진다.
     */
    public function test_auto_detect_results_have_valid_version_format(): void
    {
        $found = PhpBinaryResolver::autoDetect();

        foreach ($found as $entry) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $entry['version']);
        }
    }
}
