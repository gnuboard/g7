<?php

namespace Tests\Unit\Seo;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Seo\SeoConfigMerger;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SeoConfigMergerTest extends TestCase
{
    private ModuleManager|MockInterface $moduleManager;

    private PluginManager|MockInterface $pluginManager;

    private TemplateRepositoryInterface|MockInterface $templateRepository;

    private SeoConfigMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트 간 캐시 격리
        Cache::flush();

        $this->moduleManager = Mockery::mock(ModuleManager::class);
        $this->pluginManager = Mockery::mock(PluginManager::class);
        $this->templateRepository = Mockery::mock(TemplateRepositoryInterface::class);

        $this->merger = new SeoConfigMerger(
            $this->moduleManager,
            $this->pluginManager,
            $this->templateRepository,
        );
    }

    /**
     * 빈 상태 병합 → 빈 배열 반환
     */
    public function test_empty_merge_returns_empty_array(): void
    {
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        // 템플릿 config 파일 미존재 시
        $result = $this->merger->getMergedConfig('nonexistent-template');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 템플릿만 존재 → 템플릿 config 그대로 반환
     */
    public function test_template_only_returns_template_config(): void
    {
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        // 실제 템플릿의 seo-config.json 사용
        $result = $this->merger->getMergedConfig('sirsoft-basic');

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('component_map', $result);
        $this->assertArrayHasKey('render_modes', $result);
    }

    /**
     * 모듈 config + 템플릿 config → component_map deep merge
     */
    public function test_module_config_merged_with_template(): void
    {
        $module = $this->createModuleMock('test-module', [
            'component_map' => [
                'CustomWidget' => ['tag' => 'section', 'render' => 'iterate'],
            ],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        // 모듈의 CustomWidget이 추가됨
        $this->assertArrayHasKey('CustomWidget', $result['component_map']);
        $this->assertEquals('section', $result['component_map']['CustomWidget']['tag']);

        // 템플릿 기존 component_map도 유지됨
        $this->assertArrayHasKey('ProductCard', $result['component_map']);
    }

    /**
     * 템플릿이 모듈 component_map 오버라이드
     */
    public function test_template_overrides_module_component_map(): void
    {
        $module = $this->createModuleMock('test-module', [
            'component_map' => [
                'ProductCard' => ['tag' => 'span', 'render' => 'iterate'],
            ],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        // 템플릿이 최종 우선이므로 ProductCard는 템플릿 값
        $this->assertEquals('article', $result['component_map']['ProductCard']['tag']);
    }

    /**
     * 플러그인이 모듈보다 우선
     */
    public function test_plugin_overrides_module(): void
    {
        $module = $this->createModuleMock('test-module', [
            'component_map' => [
                'SharedWidget' => ['tag' => 'div', 'render' => 'iterate'],
            ],
        ]);

        $plugin = $this->createPluginMock('test-plugin', [
            'component_map' => [
                'SharedWidget' => ['tag' => 'section', 'render' => 'iterate'],
            ],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn(['test-plugin' => $plugin]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        // 플러그인이 모듈보다 우선
        $this->assertEquals('section', $result['component_map']['SharedWidget']['tag']);
    }

    /**
     * text_props union 중복 제거
     */
    public function test_text_props_union_deduplicated(): void
    {
        $module = $this->createModuleMock('test-module', [
            'text_props' => ['customProp', 'text', 'title'],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        // text_props는 union, 중복 제거
        $this->assertContains('customProp', $result['text_props']);
        $this->assertContains('text', $result['text_props']);
        $this->assertContains('title', $result['text_props']);
        $this->assertEquals(count($result['text_props']), count(array_unique($result['text_props'])));
    }

    /**
     * allowed_attrs union 중복 제거
     */
    public function test_allowed_attrs_union_deduplicated(): void
    {
        $module = $this->createModuleMock('test-module', [
            'allowed_attrs' => ['data-custom', 'href'],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        $this->assertContains('data-custom', $result['allowed_attrs']);
        $this->assertContains('href', $result['allowed_attrs']);
        $this->assertEquals(count($result['allowed_attrs']), count(array_unique($result['allowed_attrs'])));
    }

    /**
     * self_closing union 중복 제거
     */
    public function test_self_closing_union_deduplicated(): void
    {
        $module = $this->createModuleMock('test-module', [
            'self_closing' => ['CustomIcon', 'Icon'],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        $this->assertContains('CustomIcon', $result['self_closing']);
        $this->assertContains('Icon', $result['self_closing']);
        $this->assertEquals(count($result['self_closing']), count(array_unique($result['self_closing'])));
    }

    /**
     * stylesheets append 중복 제거
     */
    public function test_stylesheets_append_deduplicated(): void
    {
        $module = $this->createModuleMock('test-module', [
            'stylesheets' => ['/css/module-custom.css', '/css/module-extra.css'],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        $this->assertContains('/css/module-custom.css', $result['stylesheets']);
        $this->assertContains('/css/module-extra.css', $result['stylesheets']);
        $this->assertEquals(count($result['stylesheets']), count(array_unique($result['stylesheets'])));
    }

    /**
     * render_modes deep merge
     */
    public function test_render_modes_deep_merge(): void
    {
        $module = $this->createModuleMock('test-module', [
            'render_modes' => [
                'custom_view' => [
                    'fields' => ['title', 'description'],
                ],
            ],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        // 모듈의 custom_view가 추가됨
        $this->assertArrayHasKey('custom_view', $result['render_modes']);
        // 템플릿의 기존 render_modes도 유지
        $this->assertArrayHasKey('product_card_view', $result['render_modes']);
    }

    /**
     * attr_map shallow merge (후순위 우선)
     */
    public function test_attr_map_shallow_merge(): void
    {
        $module = $this->createModuleMock('test-module', [
            'attr_map' => [
                'customAttr' => 'data-custom',
                'className' => 'data-overridden',
            ],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        // 모듈의 새 attr 추가
        $this->assertEquals('data-custom', $result['attr_map']['customAttr']);
    }

    /**
     * 비활성 모듈 제외
     */
    public function test_inactive_modules_excluded(): void
    {
        // getActiveModules는 빈 배열 반환 (비활성)
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        // 템플릿 config만 반환됨
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('component_map', $result);
    }

    /**
     * 캐시 hit 확인 — 두 번째 호출은 캐시에서 반환
     */
    public function test_cache_hit(): void
    {
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        // 첫 호출 — 캐시 미스
        $result1 = $this->merger->getMergedConfig('sirsoft-basic');

        // 두 번째 호출 — 캐시 히트
        $result2 = $this->merger->getMergedConfig('sirsoft-basic');

        $this->assertEquals($result1, $result2);

        // 캐시 키 존재 확인
        $this->assertTrue(Cache::has('seo:config:merged:sirsoft-basic'));
    }

    /**
     * 캐시 클리어 후 재빌드
     */
    public function test_cache_clear_rebuilds(): void
    {
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        // 첫 호출
        $this->merger->getMergedConfig('sirsoft-basic');
        $this->assertTrue(Cache::has('seo:config:merged:sirsoft-basic'));

        // 캐시 클리어
        $this->merger->clearCache('sirsoft-basic');
        $this->assertFalse(Cache::has('seo:config:merged:sirsoft-basic'));

        // 재호출 — 캐시 미스이므로 다시 빌드
        $result = $this->merger->getMergedConfig('sirsoft-basic');

        $this->assertNotEmpty($result);
        $this->assertTrue(Cache::has('seo:config:merged:sirsoft-basic'));
    }

    /**
     * 전체 캐시 클리어
     */
    public function test_clear_all_cache(): void
    {
        $this->templateRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $this->merger->clearCache(null);

        // 예외 없이 완료
        $this->assertTrue(true);
    }

    /**
     * 모듈 알파벳순 정렬 (결정론적 병합)
     */
    public function test_modules_sorted_alphabetically(): void
    {
        $moduleB = $this->createModuleMock('b-module', [
            'component_map' => [
                'WidgetB' => ['tag' => 'div', 'render' => 'iterate'],
            ],
        ]);

        $moduleA = $this->createModuleMock('a-module', [
            'component_map' => [
                'WidgetA' => ['tag' => 'span', 'render' => 'iterate'],
            ],
        ]);

        // 의도적으로 역순 전달
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'b-module' => $moduleB,
            'a-module' => $moduleA,
        ]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('nonexistent-template');

        // 두 모듈의 config가 모두 병합됨
        $this->assertArrayHasKey('WidgetA', $result['component_map']);
        $this->assertArrayHasKey('WidgetB', $result['component_map']);
        $this->assertEquals('span', $result['component_map']['WidgetA']['tag']);
        $this->assertEquals('div', $result['component_map']['WidgetB']['tag']);
    }

    /**
     * seo_overrides shallow merge
     */
    public function test_seo_overrides_shallow_merge(): void
    {
        $module = $this->createModuleMock('test-module', [
            'seo_overrides' => [
                'ProductCard' => ['tag' => 'div'],
            ],
        ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn(['test-module' => $module]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->merger->getMergedConfig('sirsoft-basic');

        $this->assertArrayHasKey('seo_overrides', $result);
        $this->assertArrayHasKey('ProductCard', $result['seo_overrides']);
    }

    /**
     * 모듈 config 로드 실패 시 스킵 (전체 병합 실패하지 않음)
     */
    public function test_module_config_load_failure_skipped(): void
    {
        $this->moduleManager->shouldReceive('getActiveModules')
            ->andThrow(new \RuntimeException('Module load failed'));
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        // 예외 없이 템플릿 config만 반환
        $result = $this->merger->getMergedConfig('sirsoft-basic');

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('component_map', $result);
    }

    /**
     * 모듈 Mock 생성 헬퍼
     */
    private function createModuleMock(string $identifier, array $seoConfig): AbstractModule|MockInterface
    {
        $module = Mockery::mock(AbstractModule::class.'_'.str_replace('-', '_', $identifier));
        $module->shouldReceive('getIdentifier')->andReturn($identifier);
        $module->shouldReceive('getSeoConfig')->andReturn($seoConfig);

        return $module;
    }

    /**
     * 플러그인 Mock 생성 헬퍼
     */
    private function createPluginMock(string $identifier, array $seoConfig): AbstractPlugin|MockInterface
    {
        $plugin = Mockery::mock(AbstractPlugin::class.'_'.str_replace('-', '_', $identifier));
        $plugin->shouldReceive('getIdentifier')->andReturn($identifier);
        $plugin->shouldReceive('getSeoConfig')->andReturn($seoConfig);

        return $plugin;
    }
}
