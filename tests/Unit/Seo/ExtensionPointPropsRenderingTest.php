<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use App\Services\LayoutExtensionService;
use Tests\TestCase;

/**
 * Extension Point 주입 컴포넌트의 `extensionPointProps` 가 SEO(봇) 렌더링에서
 * 평가 컨텍스트로 해석되는지 검증합니다.
 *
 * 배경 (이슈 #86):
 * LayoutExtensionService 가 extension_point 호스트의 props 를 주입 컴포넌트 최상위 키
 * `extensionPointProps` 로 부착하고, 주입 컴포넌트는 `{{extensionPointProps.content}}` 로
 * 그 값을 참조한다. React DynamicRenderer 는 이를 데이터 컨텍스트에 넣어 서브트리 전체에
 * 상속시키지만, SEO PHP 렌더러(ComponentHtmlMapper)는 이 키를 인식하지 못해
 * 표현식이 빈 문자열로 평가되었다 → 봇에게 본문이 빈 `<div></div>` 로 전달됨.
 *
 * 실제 피해 표면: 게시글 본문 / 원글 본문 / 댓글 본문 / 페이지 본문 / 상품 상세설명.
 *
 * @see LayoutExtensionService 주입 계약 (extensionPointProps 부착)
 * @see ComponentHtmlMapper::renderComponent SEO 측 해석
 */
class ExtensionPointPropsRenderingTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * sirsoft-basic 템플릿의 실제 seo-config.json 을 로드합니다.
     *
     * 하드코딩 config 로는 `html_content` 렌더 모드가 없어 #86 을 재현할 수 없으므로
     * RealWorldRenderingTest 와 동일하게 실제 설정을 사용합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        $configPath = base_path('templates/_bundled/sirsoft-basic/seo-config.json');
        $this->assertTrue(file_exists($configPath), 'seo-config.json must exist');

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertIsArray($config);

        if (! empty($config['component_map'])) {
            $this->mapper->setComponentMap($config['component_map']);
        }
        if (! empty($config['render_modes'])) {
            $this->mapper->setRenderModes($config['render_modes']);
        }
        if (! empty($config['self_closing'])) {
            $this->mapper->setSelfClosing($config['self_closing']);
        }
        if (! empty($config['text_props'])) {
            $this->mapper->setTextProps($config['text_props']);
        }
        if (! empty($config['attr_map'])) {
            $this->mapper->setAttrMap($config['attr_map']);
        }
        if (! empty($config['allowed_attrs'])) {
            $this->mapper->setAllowedAttrs($config['allowed_attrs']);
        }
    }

    /**
     * 테스트 헬퍼: 컴포넌트를 렌더링합니다.
     *
     * @param  array  $components  컴포넌트 배열
     * @param  array  $context  데이터 컨텍스트
     * @return string 렌더링된 HTML
     */
    private function render(array $components, array $context = []): string
    {
        return $this->mapper->render($components, $context, $this->evaluator);
    }

    /**
     * LayoutExtensionService 의 주입 규칙을 재현합니다.
     *
     * 호스트 props/callbacks 를 주입 컴포넌트 최상위 키로 부착하고, 주입 컴포넌트를
     * 호스트의 children 으로 설정합니다 (LayoutExtensionService::processExtensionPointsRecursive
     * mode=replace 경로와 동일).
     *
     * @param  array  $hostProps  extension_point 호스트가 선언한 props (표현식 포함)
     * @param  array  $injected  확장이 제공하는 주입 컴포넌트 배열
     * @param  array  $hostCallbacks  호스트가 선언한 callbacks
     * @param  array  $hostExtra  호스트 노드에 추가할 키 (if 등)
     * @return array extension_point 호스트 노드
     */
    private function applyExtensionPoint(
        array $hostProps,
        array $injected,
        array $hostCallbacks = [],
        array $hostExtra = []
    ): array {
        $componentsWithProps = array_map(function ($injectedComponent) use ($hostProps, $hostCallbacks) {
            if (! empty($hostProps)) {
                $injectedComponent['extensionPointProps'] = $hostProps;
            }
            if (! empty($hostCallbacks)) {
                $injectedComponent['extensionPointCallbacks'] = $hostCallbacks;
            }

            return $injectedComponent;
        }, $injected);

        return array_merge([
            'type' => 'extension_point',
            'name' => 'html_content',
            'props' => $hostProps,
            'children' => $componentsWithProps,
        ], $hostExtra);
    }

    /**
     * ckeditor5 플러그인이 제공하는 html_content 주입 컴포넌트 형상입니다.
     *
     * @return array 주입 컴포넌트 배열
     */
    private function ckeditorInjection(): array
    {
        return [
            [
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => [
                    'content' => "{{extensionPointProps.content ?? ''}}",
                    'isHtml' => '{{extensionPointProps.isHtml ?? true}}',
                ],
            ],
        ];
    }

    // ========================================================
    // 1. 핵심 회귀 — 본문이 SEO HTML 에 출력되는가
    // ========================================================

    /**
     * UT-1: 게시글 본문이 SEO HTML 에 출력됩니다 (이슈 #86 핵심 재현).
     *
     * @scenario ep_site=board_post, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects ep_props_resolved_into_context
     */
    public function test_board_post_content_is_rendered_through_extension_point_props(): void
    {
        $context = ['post' => ['data' => ['content' => '<p>게시글 본문입니다.</p>', 'content_mode' => 'html']]];

        $html = $this->render([
            $this->applyExtensionPoint(
                [
                    'content' => "{{post?.data?.content ?? ''}}",
                    'isHtml' => "{{(post?.data?.content_mode ?? 'text') === 'html'}}",
                ],
                $this->ckeditorInjection()
            ),
        ], $context);

        $this->assertStringContainsString('<p>게시글 본문입니다.</p>', $html);
        $this->assertStringNotContainsString('<div></div>', $html);
    }

    /**
     * UT-1b: 페이지 본문이 SEO HTML 에 출력됩니다.
     *
     * @scenario ep_site=page, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects ep_props_resolved_into_context
     */
    public function test_page_content_is_rendered_through_extension_point_props(): void
    {
        $context = ['page' => ['data' => ['content' => '<p>페이지 본문입니다.</p>', 'content_mode' => 'html']]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['content' => "{{page?.data?.content ?? ''}}"],
                $this->ckeditorInjection()
            ),
        ], $context);

        $this->assertStringContainsString('<p>페이지 본문입니다.</p>', $html);
    }

    /**
     * UT-1c: 상품 상세설명이 SEO HTML 에 출력됩니다.
     *
     * @scenario ep_site=product_detail, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects ep_props_resolved_into_context
     */
    public function test_product_description_is_rendered_through_extension_point_props(): void
    {
        $context = ['product' => ['data' => ['description_localized' => '<p>상품 상세설명입니다.</p>']]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['content' => "{{product.data?.description_localized ?? ''}}"],
                $this->ckeditorInjection()
            ),
        ], $context);

        $this->assertStringContainsString('<p>상품 상세설명입니다.</p>', $html);
    }

    /**
     * UT-2: 중첩된 extensionPointProps 표현식이 재귀적으로 평가됩니다.
     *
     * @effects nested_expression_resolved
     */
    public function test_nested_extension_point_props_are_resolved_recursively(): void
    {
        $context = ['post' => ['data' => ['title' => '중첩값', 'content' => '<p>본문</p>']]];

        $html = $this->render([
            $this->applyExtensionPoint(
                [
                    'content' => "{{post?.data?.content ?? ''}}",
                    'meta' => [
                        'heading' => "{{post?.data?.title ?? ''}}",
                        'deep' => ['label' => "{{post?.data?.title ?? ''}}"],
                    ],
                ],
                [
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'children' => [
                            ['type' => 'basic', 'name' => 'Span', 'text' => '{{extensionPointProps.meta.heading}}'],
                            ['type' => 'basic', 'name' => 'Span', 'text' => '{{extensionPointProps.meta.deep.label}}'],
                        ],
                    ],
                ]
            ),
        ], $context);

        $this->assertSame(2, substr_count($html, '중첩값'));
    }

    /**
     * UT-3: 직접 표현식 노드와 extensionPointProps 경유 노드가 동일한 HTML 을 출력합니다.
     *
     * 일반(React) 렌더링과 SEO 렌더링이 같은 본문을 내보내는지를 대리 검증합니다 —
     * 확장 비활성 시의 default 폴백(직접 표현식)이 곧 React 기준 출력이기 때문입니다.
     *
     * @effects ep_props_resolved_into_context, default_fallback_unchanged
     */
    public function test_injected_node_output_matches_direct_expression_node_output(): void
    {
        $context = ['post' => ['data' => ['content' => '<p>동일 본문</p>', 'content_mode' => 'html']]];

        $direct = $this->render([
            [
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => [
                    'content' => "{{post?.data?.content ?? ''}}",
                    'isHtml' => "{{(post?.data?.content_mode ?? 'text') === 'html'}}",
                ],
            ],
        ], $context);

        $injected = $this->render(
            $this->applyExtensionPoint(
                [
                    'content' => "{{post?.data?.content ?? ''}}",
                    'isHtml' => "{{(post?.data?.content_mode ?? 'text') === 'html'}}",
                ],
                $this->ckeditorInjection()
            )['children'],
            $context
        );

        $this->assertSame($direct, $injected);
    }

    /**
     * UT-4: 반복(iteration) 내부의 Extension Point 가 항목별 값을 각각 출력합니다.
     *
     * 댓글 목록(_reply_section.json) 형상 — iteration 은 EP 조상에 있고 EP props 가
     * 반복 변수(reply)를 참조합니다.
     *
     * @scenario ep_site=board_reply, injection=extension_active, nesting=inside_iteration, host_gate=if_true, content_kind=html_mode
     *
     * @effects iteration_scoped_ep_props
     */
    public function test_extension_point_inside_iteration_resolves_per_item(): void
    {
        $context = [
            'post' => ['data' => ['replies' => [
                ['content' => '<p>첫째 댓글</p>'],
                ['content' => '<p>둘째 댓글</p>'],
                ['content' => '<p>셋째 댓글</p>'],
            ]]],
        ];

        $html = $this->render([
            [
                'type' => 'basic',
                'name' => 'Div',
                'iteration' => [
                    'source' => '{{post?.data?.replies ?? []}}',
                    'item_var' => 'reply',
                    'index_var' => 'replyIndex',
                ],
                'children' => [
                    $this->applyExtensionPoint(
                        ['content' => "{{reply?.content ?? ''}}"],
                        $this->ckeditorInjection()
                    ),
                ],
            ],
        ], $context);

        $this->assertStringContainsString('<p>첫째 댓글</p>', $html);
        $this->assertStringContainsString('<p>둘째 댓글</p>', $html);
        $this->assertStringContainsString('<p>셋째 댓글</p>', $html);
    }

    /**
     * UT-5: extensionPointProps 는 주입 노드의 자손(손자)에게도 상속됩니다.
     *
     * React DynamicRenderer 가 extendedDataContext 를 자식에게 그대로 전달하는 동작과
     * 동일해야 합니다.
     *
     * @scenario ep_site=board_post, injection=extension_active, nesting=grandchild, host_gate=if_true, content_kind=html_mode
     *
     * @effects subtree_inherits_ep_props
     */
    public function test_extension_point_props_are_inherited_by_descendants(): void
    {
        $context = ['post' => ['data' => ['title' => '손자까지 상속']]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['label' => "{{post?.data?.title ?? ''}}"],
                [
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'children' => [
                            [
                                'type' => 'basic',
                                'name' => 'Div',
                                'children' => [
                                    ['type' => 'basic', 'name' => 'Span', 'text' => '{{extensionPointProps.label}}'],
                                ],
                            ],
                        ],
                    ],
                ]
            ),
        ], $context);

        $this->assertStringContainsString('손자까지 상속', $html);
    }

    /**
     * UT-6: extensionPointProps 는 Extension Point 서브트리 밖으로 새지 않습니다.
     *
     * @effects sibling_isolated_from_ep_props
     */
    public function test_extension_point_props_do_not_leak_to_siblings(): void
    {
        $context = ['post' => ['data' => ['title' => '누수금지']]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['label' => "{{post?.data?.title ?? ''}}"],
                [['type' => 'basic', 'name' => 'Span', 'text' => '{{extensionPointProps.label}}']]
            ),
            ['type' => 'basic', 'name' => 'Span', 'text' => '{{extensionPointProps.label}}'],
        ], $context);

        $this->assertSame(1, substr_count($html, '누수금지'));
    }

    /**
     * UT-7: Extension Point 호스트의 if 가 거짓이면 서브트리 전체가 출력되지 않습니다.
     *
     * 실사용처는 상품 공통정보 영역(`if: content_mode === 'html'`)이다.
     *
     * @scenario ep_site=product_common_info, injection=extension_active, nesting=direct_child, host_gate=if_false, content_kind=html_mode
     *
     * @effects host_gate_suppresses_subtree
     */
    public function test_extension_point_host_if_false_suppresses_subtree(): void
    {
        $context = ['product' => ['data' => ['common_info' => ['content_mode' => 'text', 'content' => '<p>공통정보</p>']]]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['content' => "{{product.data?.common_info?.content ?? ''}}"],
                $this->ckeditorInjection(),
                [],
                ['if' => "{{product.data?.common_info?.content_mode === 'html'}}"]
            ),
        ], $context);

        $this->assertSame('', $html);
    }

    /**
     * UT-7b: 상품 공통정보의 조건이 참이면 본문이 출력됩니다.
     *
     * `if: content_mode === 'html'` 게이트를 통과하는 경우로, 조건부 Extension Point 가
     * 참일 때 실제로 본문이 나오는지를 잠근다 (거짓 케이스만 검증하면 항상 빈 출력이어도 통과).
     *
     * @scenario ep_site=product_common_info, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects ep_props_resolved_into_context
     */
    public function test_product_common_info_renders_when_host_condition_is_true(): void
    {
        $context = ['product' => ['data' => ['common_info' => ['content_mode' => 'html', 'content' => '<p>배송/교환 안내</p>']]]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['content' => "{{product.data?.common_info?.content ?? ''}}"],
                $this->ckeditorInjection(),
                [],
                ['if' => "{{product.data?.common_info?.content_mode === 'html'}}"]
            ),
        ], $context);

        $this->assertStringContainsString('<p>배송/교환 안내</p>', $html);
        $this->assertStringNotContainsString('<div></div>', $html);
    }

    /**
     * UT-4b: 반복 밖의 댓글 Extension Point 도 본문을 출력합니다.
     *
     * 답글 카드가 반복 없이 단독 배치되는 경우(원글 상세의 대표 답글 등)를 잠급니다.
     *
     * @scenario ep_site=board_reply, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects ep_props_resolved_into_context
     */
    public function test_reply_content_is_rendered_outside_iteration(): void
    {
        $context = ['reply' => ['content' => '<p>단독 배치 댓글</p>', 'content_mode' => 'html']];

        $html = $this->render([
            $this->applyExtensionPoint(
                [
                    'content' => "{{reply?.content ?? ''}}",
                    'isHtml' => "{{(reply?.content_mode ?? 'text') === 'html'}}",
                ],
                $this->ckeditorInjection()
            ),
        ], $context);

        $this->assertStringContainsString('<p>단독 배치 댓글</p>', $html);
        $this->assertStringNotContainsString('<div></div>', $html);
    }

    /**
     * UT-8: extensionPointProps 가 없는 default 폴백(확장 비활성) 출력은 종전과 동일합니다.
     *
     * @scenario ep_site=board_post, injection=default_fallback, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects default_fallback_unchanged
     */
    public function test_default_fallback_without_extension_point_props_is_unchanged(): void
    {
        $context = ['post' => ['data' => ['content' => '<p>폴백 본문</p>', 'content_mode' => 'html']]];

        $html = $this->render([
            [
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => [
                    'content' => "{{post?.data?.content ?? ''}}",
                    'isHtml' => "{{(post?.data?.content_mode ?? 'text') === 'html'}}",
                ],
            ],
        ], $context);

        $this->assertStringContainsString('<p>폴백 본문</p>', $html);
    }

    /**
     * UT-8b: 댓글 default 폴백 출력은 종전과 동일합니다.
     *
     * @scenario ep_site=board_reply, injection=default_fallback, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects default_fallback_unchanged
     */
    public function test_reply_default_fallback_is_unchanged(): void
    {
        $context = ['reply' => ['content' => '<p>댓글 폴백</p>']];

        $html = $this->render([
            [
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => ['content' => "{{reply?.content ?? ''}}"],
            ],
        ], $context);

        $this->assertStringContainsString('<p>댓글 폴백</p>', $html);
    }

    /**
     * UT-8c: 페이지 default 폴백 출력은 종전과 동일합니다.
     *
     * @scenario ep_site=page, injection=default_fallback, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects default_fallback_unchanged
     */
    public function test_page_default_fallback_is_unchanged(): void
    {
        $context = ['page' => ['data' => ['content' => '<p>페이지 폴백</p>']]];

        $html = $this->render([
            [
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => ['content' => "{{page?.data?.content ?? ''}}"],
            ],
        ], $context);

        $this->assertStringContainsString('<p>페이지 폴백</p>', $html);
    }

    /**
     * UT-8d: 상품 상세설명 default 폴백 출력은 종전과 동일합니다.
     *
     * @scenario ep_site=product_detail, injection=default_fallback, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects default_fallback_unchanged
     */
    public function test_product_default_fallback_is_unchanged(): void
    {
        $context = ['product' => ['data' => ['description_localized' => '<p>상품 폴백</p>']]];

        $html = $this->render([
            [
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => ['content' => "{{product.data?.description_localized ?? ''}}"],
            ],
        ], $context);

        $this->assertStringContainsString('<p>상품 폴백</p>', $html);
    }

    /**
     * UT-9: extensionPointCallbacks 는 SEO 평가 컨텍스트에 주입되지 않습니다 (의도).
     *
     * SEO 파이프라인에는 액션 디스패처가 없으며, 배열을 주입하면 HTML 에 JSON 파편이
     * 노출될 위험이 있습니다.
     *
     * @effects callbacks_not_injected
     */
    public function test_extension_point_callbacks_are_not_injected_into_context(): void
    {
        $html = $this->render([
            $this->applyExtensionPoint(
                ['label' => '표시값'],
                [['type' => 'basic', 'name' => 'Span', 'text' => '{{extensionPointCallbacks.onSelect}}']],
                ['onSelect' => [['handler' => 'setState', 'params' => ['key' => 'x']]]]
            ),
        ], []);

        $this->assertStringNotContainsString('setState', $html);
        $this->assertStringNotContainsString('handler', $html);
    }

    /**
     * UT-10: 문자열이 아닌 extensionPointProps 값(bool/숫자/null)이 형을 유지합니다.
     *
     * evaluate()(string 고정) 가 아니라 evaluateRaw()(mixed) 로 해석해야 합니다.
     *
     * @effects non_string_values_preserved
     */
    public function test_non_string_extension_point_prop_values_are_preserved(): void
    {
        $context = ['flags' => ['enabled' => true, 'count' => 7]];

        $html = $this->render([
            $this->applyExtensionPoint(
                [
                    'enabled' => '{{flags.enabled}}',
                    'count' => '{{flags.count}}',
                ],
                [
                    [
                        'type' => 'basic',
                        'name' => 'Span',
                        'if' => '{{extensionPointProps.enabled}}',
                        'text' => '{{extensionPointProps.count}}',
                    ],
                ]
            ),
        ], $context);

        $this->assertStringContainsString('7', $html);
    }

    /**
     * UT-10b: null 값이 빈 문자열로 눌리지 않습니다 (`??` 폴백이 동작).
     *
     * evaluate() 는 미해석 경로를 `''` 로 반환하므로 `?? '기본값'` 이 발동하지 않는다.
     * evaluateRaw() 만 null 을 그대로 보존해 폴백이 성립한다 — 이 단언이 두 API 를 가른다.
     *
     * @effects non_string_values_preserved
     */
    public function test_null_extension_point_prop_value_keeps_nullish_fallback(): void
    {
        $context = ['post' => ['data' => ['subtitle' => null]]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['subtitle' => '{{post.data.subtitle}}'],
                [
                    [
                        'type' => 'basic',
                        'name' => 'Span',
                        'text' => "{{extensionPointProps.subtitle ?? '부제목 없음'}}",
                    ],
                ]
            ),
        ], $context);

        $this->assertStringContainsString('부제목 없음', $html);
    }

    /**
     * UT-10c: 배열 값이 배열로 보존되어 iteration source 로 쓰입니다.
     *
     * evaluate() 로 해석하면 배열이 JSON 문자열이 되어(valueToString) iteration 이 0건이 된다.
     *
     * @effects non_string_values_preserved
     */
    public function test_array_extension_point_prop_value_stays_iterable(): void
    {
        $context = ['post' => ['data' => ['tags' => ['공지', '이벤트', '자유']]]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['tags' => '{{post.data.tags}}'],
                [
                    [
                        'type' => 'basic',
                        'name' => 'Div',
                        'iteration' => [
                            'source' => '{{extensionPointProps.tags}}',
                            'item_var' => 'tag',
                        ],
                        'children' => [
                            [
                                'type' => 'basic',
                                'name' => 'Span',
                                'text' => '{{tag}}',
                            ],
                        ],
                    ],
                ]
            ),
        ], $context);

        $this->assertStringContainsString('공지', $html);
        $this->assertStringContainsString('이벤트', $html);
        $this->assertStringContainsString('자유', $html);
        // JSON 문자열로 눌렸다면 대괄호가 그대로 새어 나온다
        $this->assertStringNotContainsString('[&quot;', $html);
    }

    /**
     * UT-10d: bool false 가 문자열 `'false'` 로 눌리지 않습니다.
     *
     * evaluate() 는 false → `'false'`(비어 있지 않은 문자열)로 만들어 `?? ` 폴백을 통과시키고
     * 화면에 `false` 라는 글자를 노출한다.
     *
     * @effects non_string_values_preserved
     */
    public function test_boolean_false_extension_point_prop_is_not_stringified(): void
    {
        $context = ['flags' => ['secret' => false]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['secret' => '{{flags.secret}}'],
                [
                    [
                        'type' => 'basic',
                        'name' => 'Span',
                        'text' => '{{extensionPointProps.secret}}',
                    ],
                ]
            ),
        ], $context);

        $this->assertStringNotContainsString('false', $html);
    }

    /**
     * UT-11: 다중 바인딩 문자열 extensionPointProps 값이 정상 보간됩니다.
     *
     * @scenario ep_site=board_post, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=plain_text_mode
     *
     * @effects ep_props_resolved_into_context
     */
    public function test_multi_binding_extension_point_prop_is_interpolated(): void
    {
        $context = ['post' => ['data' => ['title' => '제목', 'author' => '작성자']]];

        $html = $this->render([
            $this->applyExtensionPoint(
                ['caption' => '{{post.data.title}} - {{post.data.author}}'],
                [['type' => 'basic', 'name' => 'Span', 'text' => '{{extensionPointProps.caption}}']]
            ),
        ], $context);

        $this->assertStringContainsString('제목 - 작성자', $html);
    }

    // ========================================================
    // 2. 계약 이음새(seam) — 실제 레이아웃 JSON × 실제 확장 자산
    // ========================================================

    /**
     * 실제 댓글 레이아웃(_reply_section.json)과 실제 ckeditor5 확장 자산을 결합해
     * 댓글 본문이 SEO HTML 에 출력되는지 검증합니다.
     *
     * #86 의 결함은 "레이아웃 파일"과 "확장 자산 파일" 사이에 있었으므로, 두 파일 중
     * 어느 쪽이 바뀌어도 다시 어긋나면 이 테스트가 실패합니다.
     *
     * @scenario ep_site=board_reply, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects ep_props_resolved_into_context
     */
    public function test_real_reply_layout_with_real_ckeditor_extension_renders_content(): void
    {
        $layoutPath = base_path('templates/_bundled/sirsoft-basic/layouts/partials/board/show/_reply_section.json');
        $extensionPath = base_path('plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-content.json');

        $this->assertFileExists($layoutPath);
        $this->assertFileExists($extensionPath);

        $layout = json_decode(file_get_contents($layoutPath), true);
        $extension = json_decode(file_get_contents($extensionPath), true);

        $host = $this->findNodeById($layout, 'reply_html_content');
        $this->assertIsArray($host, 'reply_html_content extension_point 노드를 찾지 못했습니다.');
        $this->assertSame('extension_point', $host['type'] ?? null);

        $node = $this->applyExtensionPoint(
            $host['props'] ?? [],
            $extension['components'] ?? [],
            [],
            isset($host['if']) ? ['if' => $host['if']] : []
        );

        $context = ['reply' => ['content' => '<p>실제 댓글 본문</p>', 'content_mode' => 'html', 'status' => 'published', 'is_secret' => false]];

        $html = $this->render([$node], $context);

        $this->assertStringContainsString('<p>실제 댓글 본문</p>', $html);
    }

    /**
     * 실제 게시글 레이아웃의 원글(parent) Extension Point 가 원글 본문을 출력합니다.
     *
     * @scenario ep_site=board_parent, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects ep_props_resolved_into_context
     */
    public function test_real_parent_post_layout_with_real_ckeditor_extension_renders_content(): void
    {
        $layoutPath = base_path('templates/_bundled/sirsoft-basic/layouts/partials/board/types/basic/show.json');
        $extensionPath = base_path('plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-content.json');

        $layout = json_decode(file_get_contents($layoutPath), true);
        $extension = json_decode(file_get_contents($extensionPath), true);

        $host = $this->findNodeById($layout, 'parent_post_html_content');
        $this->assertIsArray($host, 'parent_post_html_content extension_point 노드를 찾지 못했습니다.');

        $node = $this->applyExtensionPoint(
            $host['props'] ?? [],
            $extension['components'] ?? [],
            [],
            isset($host['if']) ? ['if' => $host['if']] : []
        );

        $context = ['post' => ['data' => [
            'parent' => ['content' => '<p>실제 원글 본문</p>', 'content_mode' => 'html', 'status' => 'published', 'is_secret' => false],
        ]]];

        $html = $this->render([$node], $context);

        $this->assertStringContainsString('<p>실제 원글 본문</p>', $html);
    }

    /**
     * text 모드 본문은 봇 화면에서도 글자로 나갑니다 (일반 화면과 동일).
     *
     * 실측 근거: `content_mode='text'` 인 글에 `<script>` 를 넣고 주소에 봇 파라미터를
     * 붙이자 브라우저에서 그 스크립트가 실행됐다. 일반 화면(HtmlContent, isHtml=false)은
     * 같은 본문을 글자로 이스케이프한다.
     *
     * @scenario ep_site=board_post, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=plain_text_mode
     *
     * @effects text_mode_content_escaped
     */
    public function test_text_mode_content_is_escaped_like_the_normal_screen(): void
    {
        $payload = '<b>굵게</b><script>window.__probe=1</script>';

        $node = $this->applyExtensionPoint(
            [
                'content' => "{{post.data?.content ?? ''}}",
                'isHtml' => "{{(post.data?.content_mode ?? 'text') === 'html'}}",
            ],
            [[
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => [
                    'content' => "{{extensionPointProps.content ?? ''}}",
                    'isHtml' => '{{extensionPointProps.isHtml ?? true}}',
                ],
            ]]
        );

        $html = $this->render([$node], ['post' => ['data' => [
            'content' => $payload,
            'content_mode' => 'text',
        ]]]);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;굵게&lt;/b&gt;', $html);
    }

    /**
     * html 모드 본문은 정화된 뒤 출력됩니다 (일반 화면의 DOMPurify 와 동일 강도).
     *
     * @scenario ep_site=board_post, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode_with_dangerous_markup
     *
     * @effects html_mode_content_sanitized
     */
    public function test_html_mode_content_is_sanitized_before_output(): void
    {
        $payload = '<p>본문</p><script>window.__probe=1</script>'
            .'<img src="a.png" onerror="window.__probe2=1"><iframe src="https://e.test"></iframe>';

        $node = $this->applyExtensionPoint(
            [
                'content' => "{{post.data?.content ?? ''}}",
                'isHtml' => "{{(post.data?.content_mode ?? 'text') === 'html'}}",
            ],
            [[
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => [
                    'content' => "{{extensionPointProps.content ?? ''}}",
                    'isHtml' => '{{extensionPointProps.isHtml ?? true}}',
                ],
            ]]
        );

        $html = $this->render([$node], ['post' => ['data' => [
            'content' => $payload,
            'content_mode' => 'html',
        ]]]);

        $this->assertStringContainsString('<p>본문</p>', $html);
        $this->assertStringContainsString('src="a.png"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    /**
     * 답글(댓글) 본문은 서식 구분 값이 없어 항상 글자로 나갑니다.
     *
     * 댓글에는 서식 모드 필드가 없어 판정식이 늘 거짓이 됩니다. 이스케이프하지 않으면
     * 댓글 한 줄만으로 봇 화면에 스크립트를 심을 수 있습니다.
     *
     * @scenario ep_site=board_reply, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=plain_text_mode
     *
     * @effects text_mode_content_escaped
     */
    public function test_reply_content_without_content_mode_is_escaped(): void
    {
        $node = $this->applyExtensionPoint(
            [
                'content' => "{{reply?.content ?? ''}}",
                'isHtml' => "{{(reply?.content_mode ?? 'text') === 'html'}}",
            ],
            $this->ckeditorInjection()
        );

        $html = $this->render([$node], ['reply' => ['content' => '<script>window.__probe=1</script>답글 본문']]);

        $this->assertStringContainsString('답글 본문', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * 상품 상세설명도 서식 구분 값이 평문이면 글자로 나갑니다.
     *
     * 실측: 서식 모드가 평문인데 본문에 태그가 들어 있는 상품에서, 일반 화면은 태그를
     * 글자로 보여 주고 봇 화면만 서식으로 해석해 두 화면이 어긋났다.
     *
     * @scenario ep_site=product_detail, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=plain_text_mode
     *
     * @effects text_mode_content_escaped
     */
    public function test_product_description_in_text_mode_is_escaped(): void
    {
        $node = $this->applyExtensionPoint(
            [
                'content' => "{{product.data?.description_localized ?? ''}}",
                'isHtml' => "{{(product.data?.description_mode ?? 'text') === 'html'}}",
            ],
            $this->ckeditorInjection()
        );

        $html = $this->render([$node], ['product' => ['data' => [
            'description_localized' => '<p>상품 설명</p>',
            'description_mode' => 'text',
        ]]]);

        $this->assertStringContainsString('&lt;p&gt;상품 설명&lt;/p&gt;', $html);
    }

    /**
     * 상품 상세설명의 서식 본문도 정화 후 출력됩니다.
     *
     * @scenario ep_site=product_detail, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode_with_dangerous_markup
     *
     * @effects html_mode_content_sanitized
     */
    public function test_product_description_in_html_mode_is_sanitized(): void
    {
        $node = $this->applyExtensionPoint(
            [
                'content' => "{{product.data?.description_localized ?? ''}}",
                'isHtml' => "{{(product.data?.description_mode ?? 'text') === 'html'}}",
            ],
            $this->ckeditorInjection()
        );

        $html = $this->render([$node], ['product' => ['data' => [
            'description_localized' => '<p>상품 설명</p><iframe src="https://e.test"></iframe><script>window.__probe=1</script>',
            'description_mode' => 'html',
        ]]]);

        $this->assertStringContainsString('<p>상품 설명</p>', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * 확장이 꺼져 있을 때 쓰이는 기본 구성도 평문 본문을 글자로 냅니다.
     *
     * 기본 구성은 주입 경로를 거치지 않으므로 별도로 잠급니다 — 여기가 뚫려 있으면
     * 편집기 플러그인을 끈 사이트에서만 같은 결함이 남습니다.
     *
     * @scenario ep_site=board_post, injection=default_fallback, nesting=direct_child, host_gate=if_true, content_kind=plain_text_mode
     *
     * @effects text_mode_content_escaped, default_fallback_unchanged
     */
    public function test_default_fallback_escapes_text_mode_content(): void
    {
        $html = $this->render([[
            'type' => 'composite',
            'name' => 'HtmlContent',
            'props' => [
                'content' => "{{post?.data?.content ?? ''}}",
                'isHtml' => "{{(post?.data?.content_mode ?? 'text') === 'html'}}",
            ],
        ]], ['post' => ['data' => [
            'content' => '<script>window.__probe=1</script>기본 구성 본문',
            'content_mode' => 'text',
        ]]]);

        $this->assertStringContainsString('기본 구성 본문', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * seam — 실제 ckeditor5 확장 자산 + 실제 게시글 본문 노드로 text 모드 이스케이프를 잠급니다.
     *
     * @scenario ep_site=board_post, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=plain_text_mode
     *
     * @effects text_mode_content_escaped, subtree_inherits_ep_props
     */
    public function test_seam_real_extension_escapes_text_mode_post_body(): void
    {
        $layoutPath = base_path('templates/_bundled/sirsoft-basic/layouts/partials/board/types/basic/show.json');
        $extensionPath = base_path('plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-content.json');

        $this->assertTrue(file_exists($layoutPath), 'basic/show.json 이 존재해야 합니다.');
        $this->assertTrue(file_exists($extensionPath), 'html-content.json 이 존재해야 합니다.');

        $layout = json_decode(file_get_contents($layoutPath), true);
        $extension = json_decode(file_get_contents($extensionPath), true);

        // 게시글 본문 EP 는 id 가 없으므로 content 표현식으로 찾는다.
        $host = $this->findExtensionPointByContentExpression($layout, "{{post?.data?.content ?? ''}}");
        $this->assertIsArray($host, '게시글 본문 extension_point 노드를 찾지 못했습니다.');

        $node = $this->applyExtensionPoint(
            $host['props'] ?? [],
            $extension['components'] ?? [],
            [],
            isset($host['if']) ? ['if' => $host['if']] : []
        );

        $context = ['post' => ['data' => [
            'content' => '<script>window.__probe=1</script>평문 본문',
            'content_mode' => 'text',
            'status' => 'published',
            'is_secret' => false,
        ]]];

        $html = $this->render([$node], $context);

        $this->assertStringContainsString('평문 본문', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * 정화 후에도 일반 서식은 살아남습니다 (과잉 제거 방지).
     *
     * @scenario ep_site=page, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
     *
     * @effects ordinary_formatting_survives_sanitization
     */
    public function test_sanitizer_keeps_ordinary_formatting_in_html_mode(): void
    {
        $node = $this->applyExtensionPoint(
            ['content' => "{{page.data?.content ?? ''}}", 'isHtml' => true],
            [[
                'type' => 'composite',
                'name' => 'HtmlContent',
                'props' => [
                    'content' => "{{extensionPointProps.content ?? ''}}",
                    'isHtml' => '{{extensionPointProps.isHtml ?? true}}',
                ],
            ]]
        );

        $html = $this->render([$node], ['page' => ['data' => [
            'content' => '<h2>소제목</h2><p><strong>강조</strong> 문장</p><ul><li>항목</li></ul>'
                .'<a href="https://gnuboard.test">링크</a>',
        ]]]);

        foreach (['<h2>소제목</h2>', '<strong>강조</strong>', '<li>항목</li>', 'href="https://gnuboard.test"'] as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
    }

    /**
     * 레이아웃 트리에서 content 표현식으로 extension_point 노드를 찾습니다.
     *
     * 본문 EP 중에는 id 가 없는 노드가 있어 id 기반 탐색이 통하지 않습니다.
     *
     * @param  mixed  $node  탐색 대상 (배열/스칼라)
     * @param  string  $contentExpression  props.content 표현식
     * @return array|null 발견한 노드 (없으면 null)
     */
    private function findExtensionPointByContentExpression(mixed $node, string $contentExpression): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node['type'] ?? null) === 'extension_point'
            && ($node['props']['content'] ?? null) === $contentExpression) {
            return $node;
        }

        foreach ($node as $child) {
            $found = $this->findExtensionPointByContentExpression($child, $contentExpression);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 레이아웃 트리에서 id 로 노드를 찾습니다.
     *
     * @param  mixed  $node  탐색 대상 (배열/스칼라)
     * @param  string  $id  찾을 노드 id
     * @return array|null 발견한 노드 (없으면 null)
     */
    private function findNodeById(mixed $node, string $id): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node['id'] ?? null) === $id) {
            return $node;
        }

        foreach ($node as $child) {
            $found = $this->findNodeById($child, $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
