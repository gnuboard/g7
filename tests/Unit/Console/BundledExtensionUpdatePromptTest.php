<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Core\Concerns\BundledExtensionUpdatePrompt;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Mockery;
use Tests\TestCase;

/**
 * BundledExtensionUpdatePrompt trait 단위 테스트.
 *
 * collectBundledUpdates() 가 CoreUpdateService::collectBundledExtensionUpdates() 를
 * 통해 _bundled 버전 직접 비교 결과를 반환하는지 검증.
 */
class BundledExtensionUpdatePromptTest extends TestCase
{
    /**
     * collectBundledUpdates 는 CoreUpdateService 의 결과를 그대로 반환한다.
     */
    public function test_collect_bundled_updates_delegates_to_service(): void
    {
        $serviceResult = [
            'modules' => [
                ['identifier' => 'sirsoft-ecommerce', 'current_version' => '1.0.0', 'latest_version' => '1.1.0', 'update_source' => 'bundled'],
            ],
            'plugins' => [
                ['identifier' => 'sirsoft-payment', 'current_version' => '1.0.0', 'latest_version' => '1.0.1', 'update_source' => 'bundled'],
            ],
            'templates' => [],
        ];
        $langPackResult = [
            ['identifier' => 'g7-core-ja', 'current_version' => '1.0.0', 'latest_version' => '1.0.1', 'update_source' => 'bundled'],
        ];

        $serviceMock = Mockery::mock(CoreUpdateService::class);
        $serviceMock->shouldReceive('collectBundledExtensionUpdates')
            ->once()
            ->andReturn($serviceResult);
        $this->app->instance(CoreUpdateService::class, $serviceMock);

        $langPackMock = Mockery::mock(LanguagePackService::class);
        $langPackMock->shouldReceive('collectBundledLangPackUpdates')
            ->once()
            ->andReturn($langPackResult);
        $this->app->instance(LanguagePackService::class, $langPackMock);

        $harness = $this->makeTraitHarness();
        $result = $harness->callCollect(
            Mockery::mock(ModuleManager::class),
            Mockery::mock(PluginManager::class),
            Mockery::mock(TemplateManager::class),
        );

        // trait 은 CoreUpdateService 결과에 LanguagePackService 의 lang_packs 를 덧붙인다.
        $expected = $serviceResult + ['lang_packs' => $langPackResult];
        $this->assertSame($expected, $result);
    }

    /**
     * Service 가 빈 결과를 반환하면 trait 도 빈 결과를 반환한다.
     */
    public function test_collect_bundled_updates_returns_empty_when_service_returns_empty(): void
    {
        $serviceMock = Mockery::mock(CoreUpdateService::class);
        $serviceMock->shouldReceive('collectBundledExtensionUpdates')
            ->once()
            ->andReturn(['modules' => [], 'plugins' => [], 'templates' => []]);
        $this->app->instance(CoreUpdateService::class, $serviceMock);

        $langPackMock = Mockery::mock(LanguagePackService::class);
        $langPackMock->shouldReceive('collectBundledLangPackUpdates')
            ->once()
            ->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPackMock);

        $harness = $this->makeTraitHarness();
        $result = $harness->callCollect(
            Mockery::mock(ModuleManager::class),
            Mockery::mock(PluginManager::class),
            Mockery::mock(TemplateManager::class),
        );

        $this->assertEmpty($result['modules']);
        $this->assertEmpty($result['plugins']);
        $this->assertEmpty($result['templates']);
        $this->assertEmpty($result['lang_packs']);
    }

    /**
     * trait 을 사용하는 anonymous 테스트 harness.
     */
    private function makeTraitHarness(): object
    {
        return new class
        {
            use BundledExtensionUpdatePrompt;

            public function callCollect(ModuleManager $m, PluginManager $p, TemplateManager $t): array
            {
                return $this->collectBundledUpdates($m, $p, $t);
            }
        };
    }
}
