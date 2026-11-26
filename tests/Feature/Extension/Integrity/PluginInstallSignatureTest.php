<?php

namespace Tests\Feature\Extension\Integrity;

use App\Contracts\Extension\PluginManagerInterface;
use App\Extension\PluginManager;
use Mockery;

/**
 * 플러그인 수동 설치(ZIP·GitHub)의 패키지 서명 검증 — 시나리오는 `ExtensionInstallSignatureTestCase`.
 *
 * @scenario package-integrity
 */
class PluginInstallSignatureTest extends ExtensionInstallSignatureTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function type(): string
    {
        return 'plugin';
    }

    /**
     * {@inheritDoc}
     */
    protected function identifier(): string
    {
        return 'glitter-sigplug';
    }

    /**
     * {@inheritDoc}
     */
    protected function mockManager(): void
    {
        // 인터페이스가 아니라 구현 클래스로 목을 만든다 — 서비스가 넘기는 실패 사유 참조 인자(&$failureReason)를 그대로 받게 한다
        $manager = Mockery::mock(PluginManager::class)->shouldIgnoreMissing();
        $manager->shouldReceive('getPluginManifestInfo')->andReturnNull();
        $manager->shouldReceive('installPlugin')->andReturnUsing(function () {
            $this->installCalls++;

            return true;
        });
        $manager->shouldReceive('getPluginInfo')->andReturnUsing(fn () => $this->installedInfo());
        $this->app->instance(PluginManagerInterface::class, $manager);
    }
}
