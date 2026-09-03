<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

/**
 * props 값의 `$switch`/`$cases` 객체 해석이 React 렌더러와 같은지 감시합니다.
 *
 * 배경 (engine-v1.56.0 패리티):
 * React `DataBindingEngine.resolveObject` 는 `{ "$switch": "{{expr}}", "$cases": {...},
 * "$default": ... }` 형태의 props 값을 선언적 분기로 해석한다 (일반 경로 + 반복 경로).
 * 봇(SEO) 렌더러가 이를 모르면 그 prop 은 배열이라는 이유로 **속성 자체가 조용히
 * 사라진다** — 예외도 경고도 없다. 레이아웃 최상위 `computed` 의 `$switch` 는
 * `SeoRenderer::resolveComputedSwitch` 가 별도로 처리하며, 이 테스트는 **노드 props
 * 값** 축을 고정한다.
 *
 * 범위: props(속성) 축. 노드 `text` 키는 React 도 문자열만 해석하므로 대상이 아니다.
 *
 * @see ComponentHtmlMapper::buildAttributes()
 */
class SeoSwitchValueParityTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        $this->mapper->setComponentMap([
            'Div' => ['tag' => 'div'],
            'Li' => ['tag' => 'li'],
        ]);
        $this->mapper->setTextProps(['text']);
        $this->mapper->setAllowedAttrs(['class', 'id']);
        $this->mapper->setAttrMap(['className' => 'class']);
    }

    /**
     * 컴포넌트 배열을 렌더링합니다.
     *
     * @param  array  $components  컴포넌트 정의 배열
     * @param  array  $context  데이터 컨텍스트
     * @return string 렌더링된 HTML
     */
    private function render(array $components, array $context = []): string
    {
        return $this->mapper->render($components, $context, $this->evaluator);
    }

    /**
     * 일반 경로 — props 의 $switch 객체가 case 값으로 해석됩니다.
     *
     * @effects switch_prop_values_resolved_in_bot_render
     */
    public function test_general_path_switch_prop_resolves_to_case_value(): void
    {
        $html = $this->render([
            [
                'name' => 'Div',
                'props' => [
                    'className' => [
                        '$switch' => '{{tab}}',
                        '$cases' => ['products' => 'text-red', 'contents' => 'text-blue'],
                        '$default' => 'text-gray',
                    ],
                ],
                'text' => 'x',
            ],
        ], ['tab' => 'products']);

        $this->assertStringContainsString('class="text-red"', $html);
    }

    /**
     * 반복 경로 — 항목 변수를 참조하는 $switch 키가 항목마다 해석됩니다.
     *
     * @effects switch_prop_values_resolved_in_bot_render
     */
    public function test_iteration_path_switch_key_resolves_per_item(): void
    {
        $html = $this->render([
            [
                'name' => 'Li',
                'iteration' => [
                    'data' => '{{rows}}',
                    'item_var' => 'row',
                ],
                'props' => [
                    'className' => [
                        '$switch' => '{{row.kind}}',
                        '$cases' => ['a' => 'kind-a', 'b' => 'kind-b'],
                    ],
                ],
                'text' => '{{row.kind}}',
            ],
        ], ['rows' => [['kind' => 'a'], ['kind' => 'b']]]);

        $this->assertStringContainsString('class="kind-a"', $html);
        $this->assertStringContainsString('class="kind-b"', $html);
    }

    /**
     * 매칭 실패 시 $default 로 폴백합니다.
     *
     * @effects switch_prop_values_resolved_in_bot_render
     */
    public function test_switch_falls_back_to_default_when_no_case_matches(): void
    {
        $html = $this->render([
            [
                'name' => 'Div',
                'props' => [
                    'className' => [
                        '$switch' => '{{tab}}',
                        '$cases' => ['products' => 'text-red'],
                        '$default' => 'text-gray',
                    ],
                ],
                'text' => 'x',
            ],
        ], ['tab' => 'unknown']);

        $this->assertStringContainsString('class="text-gray"', $html);
    }

    /**
     * 매칭 실패 + $default 부재 → 속성이 방출되지 않습니다 (React undefined 와 동일).
     */
    public function test_switch_without_match_or_default_emits_no_attribute(): void
    {
        $html = $this->render([
            [
                'name' => 'Div',
                'props' => [
                    'className' => [
                        '$switch' => '{{tab}}',
                        '$cases' => ['products' => 'text-red'],
                    ],
                ],
                'text' => 'x',
            ],
        ], ['tab' => 'unknown']);

        $this->assertStringNotContainsString('class=', $html);
        $this->assertStringContainsString('x', $html);
    }

    /**
     * case 값의 `{{}}` 바인딩도 해석됩니다 (React 중첩 표현식 지원과 동일).
     *
     * @effects switch_prop_values_resolved_in_bot_render
     */
    public function test_switch_case_value_bindings_are_resolved(): void
    {
        $html = $this->render([
            [
                'name' => 'Div',
                'props' => [
                    'className' => [
                        '$switch' => '{{tab}}',
                        '$cases' => ['products' => 'badge-{{level}}'],
                    ],
                ],
                'text' => 'x',
            ],
        ], ['tab' => 'products', 'level' => 'gold']);

        $this->assertStringContainsString('class="badge-gold"', $html);
    }
}
