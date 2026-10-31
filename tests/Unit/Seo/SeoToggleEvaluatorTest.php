<?php

namespace Tests\Unit\Seo;

use App\Seo\SeoToggleEvaluator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SeoToggleEvaluator 단위 테스트 (#155)
 *
 * 레이아웃 `meta.seo.toggle_setting` 판정은 봇 화면(SeoRenderer)과 사이트맵 정적 화면(SitemapGenerator)이
 * 같은 답을 내야 한다. SeoRenderer 의 private 판정을 공용 클래스로 옮기면서 동작이 바뀌지 않았는지 잠근다.
 *
 * @scenario sitemap-static-routes
 */
class SeoToggleEvaluatorTest extends TestCase
{
    private SeoToggleEvaluator $evaluator;

    /**
     * 테스트 초기화 - 판정기를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new SeoToggleEvaluator;
    }

    /**
     * toggle_setting 을 선언하지 않으면 켜진 것으로 본다.
     *
     * @scenario toggle=undeclared
     *
     * @effects toggle_undeclared_is_enabled
     *
     * @return void 반환값 없음
     */
    public function test_undeclared_toggle_is_enabled(): void
    {
        $this->assertTrue($this->evaluator->isEnabled('sirsoft-ecommerce', null, ['enabled' => true]));
        $this->assertTrue($this->evaluator->isEnabled(null, null, ['enabled' => true, 'toggle_setting' => '']));
    }

    /**
     * `$module_settings:key` 는 라우트의 모듈 식별자로 설정을 읽는다 (암묵 ID).
     *
     * #154 의 「식별자만 있으면 정상」 판정을 이 케이스가 잠근다.
     *
     * @scenario toggle=module_implicit
     *
     * @effects toggle_module_implicit_reads_route_module
     *
     * @return void 반환값 없음
     */
    public function test_module_settings_implicit_id_uses_route_module(): void
    {
        $seo = ['toggle_setting' => '$module_settings:seo.seo_product_detail'];

        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', false);
        $this->assertFalse($this->evaluator->isEnabled('sirsoft-ecommerce', null, $seo));

        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', true);
        $this->assertTrue($this->evaluator->isEnabled('sirsoft-ecommerce', null, $seo));

        // 모듈 식별자를 모르면 판정하지 않고 켜진 것으로 둔다(기존 동작)
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', false);
        $this->assertTrue($this->evaluator->isEnabled(null, null, $seo));
    }

    /**
     * `$module_settings:module-id:key` 는 명시한 모듈 설정을 읽는다 (템플릿 레이아웃의 `/search` 형식).
     *
     * @scenario toggle=module_explicit
     *
     * @effects toggle_module_explicit_reads_named_module
     *
     * @return void 반환값 없음
     */
    public function test_module_settings_explicit_id_reads_named_module(): void
    {
        $seo = ['toggle_setting' => '$module_settings:sirsoft-ecommerce:seo.seo_search_result'];

        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_search_result', false);
        $this->assertFalse($this->evaluator->isEnabled(null, null, $seo));

        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_search_result', true);
        $this->assertTrue($this->evaluator->isEnabled(null, null, $seo));
    }

    /**
     * 설정 값이 없으면 켜진 것으로 본다(기본값 true).
     *
     * @scenario toggle=module_missing
     *
     * @effects toggle_missing_setting_defaults_enabled
     *
     * @return void 반환값 없음
     */
    public function test_missing_setting_defaults_to_enabled(): void
    {
        Config::set('g7_settings.modules.test-missing-module', []);

        $this->assertTrue($this->evaluator->isEnabled('test-missing-module', null, ['toggle_setting' => '$module_settings:seo.nothing']));
    }

    /**
     * `$plugin_settings:` 도 암묵·명시 ID 를 같은 규칙으로 읽는다.
     *
     * @scenario toggle=plugin
     *
     * @effects toggle_plugin_reads_plugin_settings
     *
     * @return void 반환값 없음
     */
    public function test_plugin_settings_implicit_and_explicit_id(): void
    {
        Config::set('g7_settings.plugins.test-plugin.seo.page', false);

        $this->assertFalse($this->evaluator->isEnabled(null, 'test-plugin', ['toggle_setting' => '$plugin_settings:seo.page']));
        $this->assertFalse($this->evaluator->isEnabled(null, null, ['toggle_setting' => '$plugin_settings:test-plugin:seo.page']));
        $this->assertTrue($this->evaluator->isEnabled(null, null, ['toggle_setting' => '$plugin_settings:seo.page']));
    }

    /**
     * `$core_settings:key` 는 코어 설정을 읽고, 모르는 접두사는 켜진 것으로 본다.
     *
     * @scenario toggle=core
     *
     * @effects toggle_core_reads_core_settings
     *
     * @return void 반환값 없음
     */
    public function test_core_settings_and_unknown_prefix(): void
    {
        Config::set('g7_settings.core.seo.test_toggle', false);
        $this->assertFalse($this->evaluator->isEnabled(null, null, ['toggle_setting' => '$core_settings:seo.test_toggle']));

        Config::set('g7_settings.core.seo.test_toggle', true);
        $this->assertTrue($this->evaluator->isEnabled(null, null, ['toggle_setting' => '$core_settings:seo.test_toggle']));

        $this->assertTrue($this->evaluator->isEnabled(null, null, ['toggle_setting' => '$unknown:seo.x']));
    }

    /**
     * 확장 설정 키는 `id:key` 면 명시 ID, `key` 면 컨텍스트 ID 로 나뉜다.
     *
     * @scenario toggle=parse_key
     *
     * @effects extension_settings_key_parsing
     *
     * @return void 반환값 없음
     */
    public function test_parse_extension_settings_key(): void
    {
        $this->assertSame(['sirsoft-board', 'seo.seo_boards'], $this->evaluator->parseExtensionSettingsKey('sirsoft-board:seo.seo_boards', 'other'));
        $this->assertSame(['ctx-module', 'seo.key'], $this->evaluator->parseExtensionSettingsKey('seo.key', 'ctx-module'));
        $this->assertSame([null, 'seo.key'], $this->evaluator->parseExtensionSettingsKey('seo.key', null));
    }
}
