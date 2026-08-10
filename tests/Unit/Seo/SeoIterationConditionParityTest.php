<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

/**
 * 반복 렌더 노드의 조건 평가 순서와 자동 변수 주입이 React 렌더러와 같은지 감시합니다.
 *
 * 배경:
 * 같은 레이아웃 JSON 을 React `DynamicRenderer` 와 SEO PHP 렌더러가 각각 그린다.
 * `iteration` 과 `if` 를 함께 쓴 노드에서, 조건이 항목 변수(`{{user.is_active}}` 등)를
 * 참조하면 부모 시점 컨텍스트에는 그 변수가 없다. 조건을 iteration 보다 먼저 평가하면
 * 항상 거짓이 되어 **목록 전체가 사라진다** — 예외도 경고도 남지 않는다.
 *
 * React 는 engine-v1.56.0 에서 "iteration 이 있으면 부모 시점 조건으로 끊지 않는다" 로
 * 고쳤다. 한쪽만 고치면 브라우저에서는 목록이 보이는데 봇 화면에서만 비므로, 이 테스트가
 * PHP 쪽 순서를 고정한다.
 *
 * `{item_var}_index` 자동 변수도 같은 이유로 양쪽에 있어야 한다.
 *
 * @see \App\Seo\ComponentHtmlMapper::renderComponent()
 * @see \App\Seo\ComponentHtmlMapper::renderIteration()
 */
class SeoIterationConditionParityTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * 테스트 초기화 - 최소 컴포넌트 매핑으로 매퍼를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        $this->mapper->setComponentMap([
            'Div' => ['tag' => 'div'],
            'Li' => ['tag' => 'li'],
            'Span' => ['tag' => 'span'],
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
     * 항목 변수를 참조하는 if 가 iteration 과 함께 있어도 목록이 통째로 사라지지 않습니다.
     */
    public function test_iteration_with_item_referencing_if_renders_matching_items(): void
    {
        $components = [
            [
                'name' => 'Li',
                'if' => '{{user.is_active}}',
                'iteration' => [
                    'data' => '{{users}}',
                    'item_var' => 'user',
                ],
                'props' => ['text' => '{{user.name}}'],
            ],
        ];

        $context = [
            'users' => [
                ['name' => '가', 'is_active' => true],
                ['name' => '나', 'is_active' => false],
                ['name' => '다', 'is_active' => true],
            ],
        ];

        $html = $this->render($components, $context);

        // 수정 전: 부모 시점에 `user` 가 없어 조건이 거짓 → 빈 문자열(목록 전체 소실)
        $this->assertStringContainsString('<li>가</li>', $html);
        $this->assertStringContainsString('<li>다</li>', $html);
        $this->assertStringNotContainsString('<li>나</li>', $html);
    }

    /**
     * condition 키로 작성해도 같은 순서가 적용됩니다.
     */
    public function test_iteration_with_item_referencing_condition_key(): void
    {
        $components = [
            [
                'name' => 'Li',
                'condition' => '{{row.visible}}',
                'iteration' => [
                    'source' => '{{rows}}',
                    'item_var' => 'row',
                ],
                'props' => ['text' => '{{row.label}}'],
            ],
        ];

        $context = [
            'rows' => [
                ['label' => 'A', 'visible' => true],
                ['label' => 'B', 'visible' => false],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<li>A</li>', $html);
        $this->assertStringNotContainsString('<li>B</li>', $html);
    }

    /**
     * 외곽 변수만 참조하는 if 는 종전과 같은 결과를 냅니다 (거짓이면 목록 미렌더).
     */
    public function test_iteration_with_outer_scope_if_false_renders_nothing(): void
    {
        $components = [
            [
                'name' => 'Li',
                'if' => '{{showList}}',
                'iteration' => [
                    'data' => '{{items}}',
                    'item_var' => 'item',
                ],
                'props' => ['text' => '{{item.name}}'],
            ],
        ];

        $context = [
            'showList' => false,
            'items' => [['name' => '가'], ['name' => '나']],
        ];

        $this->assertSame('', $this->render($components, $context));
    }

    /**
     * 외곽 변수만 참조하는 if 가 참이면 전 항목이 렌더됩니다.
     */
    public function test_iteration_with_outer_scope_if_true_renders_all_items(): void
    {
        $components = [
            [
                'name' => 'Li',
                'if' => '{{showList}}',
                'iteration' => [
                    'data' => '{{items}}',
                    'item_var' => 'item',
                ],
                'props' => ['text' => '{{item.name}}'],
            ],
        ];

        $context = [
            'showList' => true,
            'items' => [['name' => '가'], ['name' => '나']],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<li>가</li>', $html);
        $this->assertStringContainsString('<li>나</li>', $html);
    }

    /**
     * iteration 이 없는 노드의 if 는 종전처럼 즉시 차단합니다 (회귀 방지).
     */
    public function test_if_without_iteration_still_blocks_immediately(): void
    {
        $components = [
            [
                'name' => 'Div',
                'if' => '{{visible}}',
                'props' => ['text' => '숨김 대상'],
            ],
        ];

        $this->assertSame('', $this->render($components, ['visible' => false]));
    }

    /**
     * `{item_var}_index` 자동 변수가 항목 컨텍스트에 주입됩니다.
     */
    public function test_iteration_injects_item_var_index_automatic_variable(): void
    {
        $components = [
            [
                'name' => 'Li',
                'iteration' => [
                    'data' => '{{items}}',
                    'item_var' => 'row',
                ],
                'props' => ['text' => '{{row_index}}'],
            ],
        ];

        $context = ['items' => [['name' => '가'], ['name' => '나'], ['name' => '다']]];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<li>0</li>', $html);
        $this->assertStringContainsString('<li>1</li>', $html);
        $this->assertStringContainsString('<li>2</li>', $html);
    }

    /**
     * `{item_var}_index` 주입이 명시적 index_var 를 덮어쓰지 않습니다.
     */
    public function test_item_var_index_coexists_with_explicit_index_var(): void
    {
        $components = [
            [
                'name' => 'Li',
                'iteration' => [
                    'data' => '{{items}}',
                    'item_var' => 'row',
                    'index_var' => 'i',
                ],
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '{{i}}']],
                    ['name' => 'Span', 'props' => ['text' => '{{row_index}}']],
                ],
            ],
        ];

        $context = ['items' => [['name' => '가'], ['name' => '나']]];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<li><span>0</span><span>0</span></li>', $html);
        $this->assertStringContainsString('<li><span>1</span><span>1</span></li>', $html);
    }

    /**
     * 항목 조건이 자동 변수(`{item_var}_index`)를 참조해도 항목별로 평가됩니다.
     */
    public function test_item_condition_can_reference_injected_index_variable(): void
    {
        $components = [
            [
                'name' => 'Li',
                'if' => '{{row_index < 2}}',
                'iteration' => [
                    'data' => '{{items}}',
                    'item_var' => 'row',
                ],
                'props' => ['text' => '{{row.name}}'],
            ],
        ];

        $context = ['items' => [['name' => '가'], ['name' => '나'], ['name' => '다']]];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<li>가</li>', $html);
        $this->assertStringContainsString('<li>나</li>', $html);
        $this->assertStringNotContainsString('<li>다</li>', $html);
    }
}
