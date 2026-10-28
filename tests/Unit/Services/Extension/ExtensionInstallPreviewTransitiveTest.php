<?php

namespace Tests\Unit\Services\Extension;

use App\Enums\LanguagePackScope;
use App\Services\Extension\CascadeInstallAuthorizer;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Mockery;
use Tests\TestCase;

/**
 * 설치 미리보기의 의존 후보에 의존의 의존(2단계)이 포함되는지 검증한다.
 *
 * 회귀: 미리보기는 직접 의존만 보여 줘, 2단계 의존 확장이 필요한 확장을 설치 창에서 설치하면 그 확장이 빠진 채
 * 실패했다. 이제 설치가 쓰는 연쇄 설치 서비스와 같은 해석으로 2단계 행을 덧붙인다(기존 행·키는 그대로).
 */
class ExtensionInstallPreviewTransitiveTest extends TestCase
{
    /**
     * 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 플러그인 → 모듈(미설치) → 모듈(미설치) 구조에서 미리보기가 두 의존을 모두 보인다.
     *
     * @effects install_preview_includes_transitive_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_preview_appends_second_level_dependency(): void
    {
        $modules = [
            'shop' => ['identifier' => 'shop', 'name' => ['ko' => '샵', 'en' => 'Shop'], 'version' => '1.3.0', 'is_installed' => false, 'status' => 'uninstalled',
                'dependencies' => [['type' => 'module', 'identifier' => 'base', 'required_version' => '>=2.0.0']]],
            'base' => ['identifier' => 'base', 'name' => ['ko' => '기반', 'en' => 'Base'], 'version' => '2.1.0', 'is_installed' => false, 'status' => 'uninstalled', 'dependencies' => []],
        ];

        $moduleService = Mockery::mock(ModuleService::class);
        $moduleService->shouldReceive('getModuleInfo')->andReturnUsing(fn (string $id) => $modules[$id] ?? null);
        $pluginService = Mockery::mock(PluginService::class);
        $pluginService->shouldReceive('getPluginInfo')->with('pay')->andReturn([
            'identifier' => 'pay', 'name' => ['ko' => '결제', 'en' => 'Pay'], 'version' => '1.0.0', 'is_installed' => false, 'status' => 'uninstalled',
            'dependencies' => [[
                'identifier' => 'shop', 'name' => '샵', 'type' => 'module', 'required_version' => '>=1.0.0',
                'installed_version' => null, 'is_active' => false, 'is_met' => false,
            ]],
        ]);
        $templateService = Mockery::mock(TemplateService::class);
        $languagePacks = Mockery::mock(LanguagePackService::class);
        $languagePacks->shouldReceive('getUninstalledBundledPacks')->andReturn(collect());

        $this->app->instance(CascadeInstallOrchestrator::class, new CascadeInstallOrchestrator($moduleService, $pluginService, $templateService, $languagePacks));
        $builder = new ExtensionInstallPreviewBuilder($moduleService, $pluginService, $templateService, $languagePacks, app(CascadeInstallAuthorizer::class));

        $preview = $builder->build(LanguagePackScope::Plugin, 'pay');

        $this->assertSame(['shop', 'base'], array_column($preview['dependencies'], 'identifier'));
        $this->assertSame('base', $preview['dependencies'][1]['identifier']);
        $this->assertSame('shop', $preview['dependencies'][1]['required_by']);
        $this->assertSame('>=2.0.0', $preview['dependencies'][1]['required_version']);
        $this->assertFalse($preview['dependencies'][1]['is_installed']);

        // 직접 의존 행의 기존 키는 그대로다
        foreach (['type', 'identifier', 'name', 'required_version', 'installed_version', 'is_installed', 'is_active', 'is_met', 'available', 'default_selected', 'can_select'] as $key) {
            $this->assertArrayHasKey($key, $preview['dependencies'][0]);
        }
    }
}
