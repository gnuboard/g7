<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SEO 봇 HTML — responsive 구간 classMerge·구간 classMap (7.2.1, engine-v1.98.0 짝)
 *
 * 봇은 데스크톱 폭(1024px)으로 본다. 데스크톱에 맞는 구간의 classMerge 는 기본 className 에 충돌 교체형으로
 * 덧붙고(엔진 applyResponsiveClassMerge 와 같은 규칙), 구간 classMap 은 기본 classMap 을 대신한다. 종전에는
 * 두 키 모두 무시돼 봇 화면에서만 구간 클래스가 빠졌다.
 */
class ComponentHtmlMapperResponsiveClassMergeTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * 테스트 준비 — 봇 렌더 설정과 확장 앞글자 목록을 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('seo.component_map', [
            'Div' => ['tag' => 'div'],
            'Span' => ['tag' => 'span'],
        ]);
        Config::set('seo.render_modes', []);
        Config::set('seo.self_closing', []);
        Config::set('seo.text_props', ['text']);
        Config::set('seo.attr_map', ['className' => 'class']);
        Config::set('seo.allowed_attrs', ['class', 'id']);

        $this->mapper = new ComponentHtmlMapper;
        $this->mapper->setExtensionClassPrefixes(['ecommerce']);
        $this->evaluator = new ExpressionEvaluator;
    }

    /**
     * 컴포넌트 트리를 봇 HTML 로 렌더링합니다.
     *
     * @param  array<int, array<string, mixed>>  $components  렌더링할 컴포넌트 트리
     * @param  array<string, mixed>  $context  표현식 평가용 데이터 컨텍스트
     * @return string 렌더링된 봇 HTML
     */
    private function render(array $components, array $context = []): string
    {
        return $this->mapper->render($components, $context, $this->evaluator);
    }

    /**
     * 데스크톱 구간 classMerge 가 같은 축 토큰만 교체해 덧붙는다.
     *
     * @scenario render_concern=responsive_props
     *
     * @effects responsive_class_merge_and_class_map_apply_on_bot_html
     *
     * @return void 반환값 없음
     */
    public function test_desktop_class_merge_replaces_same_axis_tokens(): void
    {
        $html = $this->render([[
            'name' => 'Div',
            'props' => ['className' => 'flex p-4 text-sm'],
            'responsive' => ['desktop' => ['classMerge' => 'p-8']],
            'text' => 'x',
        ]]);

        $this->assertStringContainsString('class="flex text-sm p-8"', $html);
    }

    /**
     * 데스크톱에 맞지 않는 구간(portable)의 classMerge 는 적용하지 않는다.
     *
     * @scenario render_concern=responsive_props
     *
     * @effects responsive_class_merge_and_class_map_apply_on_bot_html
     *
     * @return void 반환값 없음
     */
    public function test_portable_class_merge_is_not_applied_on_bot_width(): void
    {
        $html = $this->render([[
            'name' => 'Div',
            'props' => ['className' => 'flex p-8'],
            'responsive' => ['portable' => ['classMerge' => 'p-6']],
            'text' => 'x',
        ]]);

        $this->assertStringContainsString('class="flex p-8"', $html);
    }

    /**
     * className·classMerge 바인딩을 해석한 뒤 합친다.
     *
     * @scenario render_concern=responsive_props
     *
     * @effects responsive_class_merge_and_class_map_apply_on_bot_html
     *
     * @return void 반환값 없음
     */
    public function test_bindings_are_resolved_before_merge(): void
    {
        $html = $this->render([[
            'name' => 'Div',
            'props' => ['className' => '{{tone}} p-4'],
            'responsive' => ['desktop' => ['classMerge' => "{{wide ? 'p-10' : 'p-8'}}"]],
            'text' => 'x',
        ]], ['tone' => 'bg-gray-50', 'wide' => true]);

        $this->assertStringContainsString('class="bg-gray-50 p-10"', $html);
    }

    /**
     * 구간 classMap 이 기본 classMap 을 대신하고, classMerge 는 그 결과 뒤에 덧붙는다.
     *
     * @scenario render_concern=responsive_props
     *
     * @effects responsive_class_merge_and_class_map_apply_on_bot_html
     *
     * @return void 반환값 없음
     */
    public function test_breakpoint_class_map_replaces_base_class_map_then_merge(): void
    {
        $html = $this->render([[
            'name' => 'Span',
            'props' => ['className' => 'inline-flex'],
            'classMap' => ['key' => '{{status}}', 'variants' => ['paid' => 'text-green-600']],
            'responsive' => ['desktop' => [
                'classMap' => ['base' => 'font-bold', 'key' => '{{status}}', 'variants' => ['paid' => 'text-green-800']],
                'classMerge' => 'gap-2',
            ]],
            'text' => 'x',
        ]], ['status' => 'paid']);

        $this->assertStringContainsString('class="inline-flex font-bold text-green-800 gap-2"', $html);
        $this->assertStringNotContainsString('text-green-600', $html);
    }

    /**
     * 확장 앞글자 토큰도 같은 축으로 교체하고, 반복 항목에는 한 번만 적용한다.
     *
     * @scenario render_concern=responsive_props
     *
     * @effects responsive_class_merge_and_class_map_apply_on_bot_html
     *
     * @return void 반환값 없음
     */
    public function test_extension_prefix_and_iteration_apply_once(): void
    {
        $html = $this->render([[
            'name' => 'Span',
            'props' => ['className' => 'ecommerce:p-4 ecommerce-admin-card'],
            'iteration' => ['source' => 'items', 'item_var' => 'item'],
            'responsive' => ['desktop' => ['classMerge' => 'ecommerce:p-8 ecommerce-admin-card']],
            'text' => '{{item}}',
        ]], ['items' => ['a', 'b']]);

        $this->assertSame(2, substr_count($html, 'class="ecommerce:p-8 ecommerce-admin-card"'));
    }
}
