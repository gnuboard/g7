<?php

namespace Tests\Unit\Extension;

use App\Exceptions\CoreVersionMismatchException;
use App\Extension\CoreVersionChecker;
use Tests\TestCase;

/**
 * 플러그인 코어 버전 호환성 검증 단위 테스트.
 *
 * PluginManager 가 활성화/업데이트 경로에서 사용하는 검증 헬퍼
 * (CoreVersionChecker::validateExtension / isCompatible) 의 결과 도메인을
 * 플러그인 컨텍스트에서 검증합니다.
 *
 * 케이스:
 *   1. 현재 코어 < 요구 → 예외 + payload(extension_type=plugin, identifier, required, current, guide_url)
 *   2. 현재 코어 == 요구 → true 통과
 *   3. 현재 코어 > 요구 (caret) → true 통과
 *   4. requireVersion=null → 검증 생략 true 통과
 */
class PluginManagerVersionCheckTest extends TestCase
{
    private ?string $originalEnvVersion = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvVersion = $_ENV['APP_VERSION'] ?? null;
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        putenv('APP_VERSION');
        config()->set('app.version', '7.0.0-beta.1');
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        if ($this->originalEnvVersion !== null) {
            $_ENV['APP_VERSION'] = $this->originalEnvVersion;
            $_SERVER['APP_VERSION'] = $this->originalEnvVersion;
            putenv('APP_VERSION='.$this->originalEnvVersion);
        } else {
            putenv('APP_VERSION');
        }
        parent::tearDown();
    }

    public function test_incompatible_required_version_throws_with_plugin_payload(): void
    {
        try {
            CoreVersionChecker::validateExtension('>=7.1.0', 'sirsoft-payment', 'plugin');
            $this->fail('CoreVersionMismatchException 이 발생해야 합니다.');
        } catch (CoreVersionMismatchException $e) {
            $payload = $e->getPayload();
            $this->assertSame('plugin', $payload['extension_type']);
            $this->assertSame('sirsoft-payment', $payload['identifier']);
            $this->assertSame('>=7.1.0', $payload['required_core_version']);
            $this->assertSame('7.0.0-beta.1', $payload['current_core_version']);
            $this->assertSame('/admin/core/update', $payload['guide_url']);
        }
    }

    public function test_compatible_when_required_constraint_is_satisfied_loose(): void
    {
        config()->set('app.version', '7.1.0');
        $this->assertTrue(CoreVersionChecker::isCompatible('>=7.0.0'));
        $this->assertTrue(CoreVersionChecker::validateExtension('>=7.0.0', 'sirsoft-payment', 'plugin'));
    }

    public function test_compatible_when_caret_constraint_with_higher_patch(): void
    {
        config()->set('app.version', '7.0.5');
        $this->assertTrue(CoreVersionChecker::isCompatible('^7.0.0'));
    }

    public function test_null_required_version_skips_validation(): void
    {
        $this->assertTrue(CoreVersionChecker::isCompatible(null));
        $this->assertTrue(CoreVersionChecker::validateExtension(null, 'sirsoft-payment', 'plugin'));
    }

    public function test_env_app_version_overrides_config_for_plugin_check(): void
    {
        config()->set('app.version', '7.0.0-beta.1');
        $_ENV['APP_VERSION'] = '7.2.0';

        $this->assertTrue(CoreVersionChecker::isCompatible('>=7.1.0'));
    }
}
