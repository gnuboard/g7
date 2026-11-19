<?php

namespace Tests\Feature\Extension\Integrity;

use App\Contracts\Extension\TemplateManagerInterface;
use Mockery;

/**
 * 템플릿 수동 설치(ZIP·GitHub)의 패키지 서명 검증 (#916 ④) — 시나리오는 `ExtensionInstallSignatureTestCase`.
 *
 * @scenario package-integrity
 */
class TemplateInstallSignatureTest extends ExtensionInstallSignatureTestCase
{
    /**
     * 설치가 끝났는지 (설치 전에는 「설치 안 됨」으로 답한다)
     *
     * @var bool
     */
    private bool $installed = false;

    /**
     * {@inheritDoc}
     */
    protected function type(): string
    {
        return 'template';
    }

    /**
     * {@inheritDoc}
     */
    protected function identifier(): string
    {
        return 'glitter-sigtpl';
    }

    /**
     * {@inheritDoc}
     */
    protected function mockManager(): void
    {
        $manager = Mockery::mock(TemplateManagerInterface::class)->shouldIgnoreMissing();
        $manager->shouldReceive('installTemplate')->andReturnUsing(function () {
            $this->installCalls++;
            $this->installed = true;

            return true;
        });
        // 설치 전 확인(ensureTemplateNotInstalled)은 null, 설치 뒤 정보 조회는 정보를 돌려준다
        $manager->shouldReceive('getTemplateInfo')->andReturnUsing(function () {
            if (! $this->installed) {
                return null;
            }
            $this->installed = false;

            return $this->installedInfo();
        });
        $manager->shouldReceive('takeLastLayoutWarnings')->andReturn([]);
        $this->app->instance(TemplateManagerInterface::class, $manager);
    }
}
