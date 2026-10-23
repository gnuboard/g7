<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

/**
 * 봇(SEO) HTML 의 링크 추출이 엔진과 같은 중첩 액션 트리를 따라간다 (7.1.3).
 *
 * 예전에는 click 액션의 sequence 한 단계만 봐서 parallel·conditions(then)·switch(cases)·중첩 sequence 안의 navigate 와,
 * path 를 최상위 target 에 둔 navigate 가 봇 HTML 에서 링크로 그려지지 않았다.
 *
 * @scenario-manifest tests/scenarios/seo-nested-link-action.yaml
 */
class ComponentHtmlMapperNestedLinkActionTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * 테스트용 최소 컴포넌트 매핑으로 매퍼와 평가기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://g7.test']);

        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        $this->mapper->setComponentMap(['Button' => ['tag' => 'button']]);
        $this->mapper->setRenderModes([]);
        $this->mapper->setSelfClosing([]);
        $this->mapper->setTextProps(['text']);
        $this->mapper->setAttrMap([]);
        $this->mapper->setAllowedAttrs([]);
    }

    /**
     * parallel·conditions·switch·중첩 sequence 안의 navigate 도 링크가 된다.
     *
     * @scenario nesting=nested_tree
     *
     * @effects nested_navigate_becomes_bot_link
     *
     * @return void 반환값 없음
     */
    public function test_navigate_in_nested_action_trees(): void
    {
        $navigate = ['handler' => 'navigate', 'params' => ['path' => '/nested']];
        $trees = [
            'parallel' => ['handler' => 'parallel', 'actions' => [['handler' => 'setState', 'params' => []], $navigate]],
            'conditions' => ['handler' => 'conditions', 'conditions' => [['if' => '{{true}}', 'then' => [$navigate]]]],
            'switch' => ['handler' => 'switch', 'cases' => ['a' => [$navigate]]],
            'nested_sequence' => ['handler' => 'sequence', 'actions' => [['handler' => 'sequence', 'params' => ['actions' => [$navigate]]]]],
        ];

        foreach ($trees as $label => $tree) {
            $html = $this->render([['name' => 'Button', 'text' => '이동', 'actions' => [$tree]]]);

            $this->assertStringContainsString('<a href="/nested"', $html, $label);
        }
    }

    /**
     * path 가 params 에 없고 최상위 target 에 있는 navigate 도 링크가 된다.
     *
     * @scenario nesting=top_level_target
     *
     * @effects top_level_target_becomes_bot_link
     *
     * @return void 반환값 없음
     */
    public function test_navigate_with_top_level_target(): void
    {
        $html = $this->render([['name' => 'Button', 'text' => '이동', 'actions' => [['handler' => 'navigate', 'target' => '/top-level']]]]);

        $this->assertStringContainsString('<a href="/top-level"', $html);
    }

    /**
     * replace: true 인 navigate 는 중첩 안에서도 링크로 만들지 않는다(필터·페이지네이션).
     *
     * @scenario nesting=replace_navigate
     *
     * @effects replace_navigate_stays_button
     *
     * @return void 반환값 없음
     */
    public function test_replace_navigate_in_nested_tree_is_not_a_link(): void
    {
        $tree = ['handler' => 'parallel', 'actions' => [['handler' => 'navigate', 'params' => ['path' => '/filter', 'replace' => true]]]];

        $html = $this->render([['name' => 'Button', 'text' => '필터', 'actions' => [$tree]]]);

        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * 컴포넌트 배열을 HTML 로 그립니다.
     *
     * @param  array<int, array<string, mixed>>  $components  컴포넌트 배열
     * @return string 렌더링된 HTML
     */
    private function render(array $components): string
    {
        return $this->mapper->render($components, [], $this->evaluator);
    }
}
