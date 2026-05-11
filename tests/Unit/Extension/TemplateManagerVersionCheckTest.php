<?php

namespace Tests\Unit\Extension;

use App\Exceptions\CoreVersionMismatchException;
use App\Extension\CoreVersionChecker;
use Tests\TestCase;

/**
 * 템플릿 코어 버전 호환성 검증 단위 테스트.
 *
 * TemplateManager 가 활성화/업데이트 경로에서 사용하는 검증 헬퍼
 * (CoreVersionChecker::validateExtension / isCompatible) 의 결과 도메인을
 * 템플릿 컨텍스트에서 검증합니다.
 */
class TemplateManagerVersionCheckTest extends TestCase
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

    public function test_incompatible_required_version_throws_with_template_payload(): void
    {
        try {
            CoreVersionChecker::validateExtension('>=8.0.0', 'sirsoft-admin_basic', 'template');
            $this->fail('CoreVersionMismatchException 이 발생해야 합니다.');
        } catch (CoreVersionMismatchException $e) {
            $payload = $e->getPayload();
            $this->assertSame('template', $payload['extension_type']);
            $this->assertSame('sirsoft-admin_basic', $payload['identifier']);
            $this->assertSame('>=8.0.0', $payload['required_core_version']);
        }
    }

    public function test_compatible_for_template_when_constraint_passes(): void
    {
        config()->set('app.version', '7.2.0');
        $this->assertTrue(CoreVersionChecker::isCompatible('^7.0.0'));
    }
}
