<?php

namespace Tests\Feature\Extension\Integrity;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Extension\ModuleManager;
use Mockery;

/**
 * 모듈 수동 설치(ZIP·GitHub)의 패키지 서명 검증 — 시나리오는 `ExtensionInstallSignatureTestCase`.
 *
 * @scenario package-integrity
 */
class ModuleInstallSignatureTest extends ExtensionInstallSignatureTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function type(): string
    {
        return 'module';
    }

    /**
     * {@inheritDoc}
     */
    protected function identifier(): string
    {
        return 'glitter-sigmod';
    }

    /**
     * {@inheritDoc}
     */
    protected function mockManager(): void
    {
        // 인터페이스가 아니라 구현 클래스로 목을 만든다 — 서비스가 넘기는 실패 사유 참조 인자(&$failureReason)를 그대로 받게 한다
        $manager = Mockery::mock(ModuleManager::class)->shouldIgnoreMissing();
        $manager->shouldReceive('getModuleManifestInfo')->andReturnNull();
        $manager->shouldReceive('installModule')->andReturnUsing(function () {
            $this->installCalls++;

            return true;
        });
        $manager->shouldReceive('getModuleInfo')->andReturnUsing(fn () => $this->installedInfo());
        $this->app->instance(ModuleManagerInterface::class, $manager);
    }
}
