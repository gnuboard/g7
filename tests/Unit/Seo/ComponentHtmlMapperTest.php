<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

class ComponentHtmlMapperTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * 테스트 초기화 - ComponentHtmlMapper 및 ExpressionEvaluator 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        // 기본 config 설정 (seo-config.json 시뮬레이션)
        $this->mapper->setComponentMap($this->getDefaultComponentMap());
        $this->mapper->setRenderModes($this->getDefaultRenderModes());
        $this->mapper->setSelfClosing($this->getDefaultSelfClosing());
        $this->mapper->setTextProps($this->getDefaultTextProps());
        $this->mapper->setAttrMap($this->getDefaultAttrMap());
        $this->mapper->setAllowedAttrs($this->getDefaultAllowedAttrs());
    }

    /**
     * 기본 컴포넌트 매핑을 반환합니다.
     *
     * @return array 컴포넌트 매핑
     */
    private function getDefaultComponentMap(): array
    {
        return [
            'Div' => ['tag' => 'div'],
            'Span' => ['tag' => 'span'],
            'P' => ['tag' => 'p'],
            'H1' => ['tag' => 'h1'],
            'H2' => ['tag' => 'h2'],
            'H3' => ['tag' => 'h3'],
            'H4' => ['tag' => 'h4'],
            'H5' => ['tag' => 'h5'],
            'H6' => ['tag' => 'h6'],
            'A' => ['tag' => 'a'],
            'Button' => ['tag' => 'button'],
            'Img' => ['tag' => 'img'],
            'Input' => ['tag' => 'input'],
            'Form' => ['tag' => 'form'],
            'Ul' => ['tag' => 'ul'],
            'Ol' => ['tag' => 'ol'],
            'Li' => ['tag' => 'li'],
            'Nav' => ['tag' => 'nav'],
            'Header' => ['tag' => 'header', 'render' => 'text_format', 'format' => '{site_name}', 'defaults' => ['site_name' => 'G7']],
            'Footer' => ['tag' => 'footer', 'render' => 'text_format', 'format' => '© {site_name}', 'defaults' => ['site_name' => 'G7']],
            'Main' => ['tag' => 'main'],
            'Section' => ['tag' => 'section'],
            'Article' => ['tag' => 'article'],
            'Icon' => ['tag' => 'i', 'name_to_class' => 'fas fa-{name}'],
            'Table' => ['tag' => 'table'],
            'Thead' => ['tag' => 'thead'],
            'Tbody' => ['tag' => 'tbody'],
            'Tr' => ['tag' => 'tr'],
            'Th' => ['tag' => 'th'],
            'Td' => ['tag' => 'td'],
            'Label' => ['tag' => 'label'],
            'Select' => ['tag' => 'select'],
            'Textarea' => ['tag' => 'textarea'],
            'Strong' => ['tag' => 'strong'],
            'Em' => ['tag' => 'em'],
            'Small' => ['tag' => 'small'],
            'Hr' => ['tag' => 'hr'],
            'Br' => ['tag' => 'br'],
            'Fragment' => ['tag' => ''],
            // Composite 컴포넌트
            'Container' => ['tag' => 'div'],
            'Flex' => ['tag' => 'div'],
            'Grid' => ['tag' => 'div'],
            'SectionLayout' => ['tag' => 'section'],
            'Card' => ['tag' => 'article'],
            'FormField' => ['tag' => 'div'],
            'Badge' => ['tag' => 'span'],
            'Breadcrumb' => ['tag' => 'nav'],
            'Pagination' => ['tag' => 'nav', 'render' => 'pagination_links'],
            'SearchBar' => ['tag' => 'div'],
            'Avatar' => ['tag' => 'span', 'render' => 'text_format', 'format' => '{author.nickname}'],
            'UserInfo' => ['tag' => 'span', 'render' => 'text_format', 'format' => '{author.nickname} · {subText}'],
            'ProductCard' => ['tag' => 'article', 'render' => 'product_card_view', 'props_source' => 'product'],
            'PageHeader' => ['tag' => 'header'],
            'DataGrid' => ['tag' => 'table'],
            'ExpandableContent' => ['tag' => 'div'],
            // skip 컴포넌트
            'Modal' => ['tag' => 'div', 'skip' => true],
            'Toast' => ['tag' => 'div', 'skip' => true],
            'Toggle' => ['tag' => 'div', 'skip' => true],
            'FileUploader' => ['tag' => 'div', 'skip' => true],
            // render 모드 컴포넌트
            'ProductImageViewer' => ['tag' => 'div', 'render' => 'image_gallery', 'props_source' => 'images'],
            'ImageGallery' => ['tag' => 'div', 'render' => 'image_gallery', 'props_source' => 'images'],
            'TabNavigation' => ['tag' => 'nav', 'render' => 'tab_list', 'props_source' => 'tabs'],
            'StarRating' => ['tag' => 'span', 'render' => 'text_format', 'format' => '{value} / {max}', 'defaults' => ['max' => '5']],
            'HtmlContent' => ['tag' => 'div', 'render' => 'html_content', 'props_source' => 'content'],
        ];
    }

    /**
     * 기본 렌더 모드를 반환합니다.
     *
     * @return array 렌더 모드 정의
     */
    private function getDefaultRenderModes(): array
    {
        return [
            'image_gallery' => [
                'type' => 'iterate',
                'source' => '$props_source',
                'item_tag' => 'img',
                'item_attrs' => ['src' => '{url|download_url|src|image_url}', 'alt' => '{alt_text_current|alt_text|alt}'],
            ],
            'tab_list' => [
                'type' => 'iterate',
                'source' => '$props_source',
                'item_tag' => 'span',
                'item_content' => '{label}',
                'badge_field' => 'badge',
            ],
            'text_format' => [
                'type' => 'format',
            ],
            'html_content' => [
                'type' => 'raw',
                'source' => '$props_source',
            ],
            'product_card_view' => [
                'type' => 'fields',
                'source' => '$props_source',
                'link' => [
                    'href' => '/products/{id}',
                    'base_url' => '$var:shopBase',
                ],
                'fields' => [
                    ['tag' => 'img', 'attrs' => ['src' => '{thumbnail_url}', 'alt' => '{name_localized|name}']],
                    ['tag' => 'h3', 'content' => '{name_localized|name}'],
                    [
                        'tag' => 'p',
                        'children' => [
                            ['tag' => 'span', 'content' => '{primary_category}', 'if' => '{primary_category}'],
                            ['tag' => 'span', 'content' => '{brand_name}', 'if' => '{brand_name}'],
                        ],
                    ],
                    [
                        'tag' => 'p',
                        'children' => [
                            ['tag' => 'span', 'content' => '{selling_price_formatted}'],
                            ['tag' => 'del', 'content' => '{list_price_formatted}', 'if' => '{discount_rate}'],
                            ['tag' => 'span', 'content' => '{discount_rate}%', 'if' => '{discount_rate}'],
                        ],
                    ],
                    ['tag' => 'p', 'iterate' => 'labels', 'item_tag' => 'span', 'item_content' => '{name}'],
                    ['tag' => 'span', 'content' => '{sales_status_label}', 'if' => '{sales_status}'],
                ],
            ],
            'pagination_links' => [
                'type' => 'pagination',
                'max_links' => 10,
            ],
        ];
    }

    /**
     * 기본 셀프 클로징 태그 목록을 반환합니다.
     *
     * @return array 셀프 클로징 태그
     */
    private function getDefaultSelfClosing(): array
    {
        return ['img', 'input', 'hr', 'br'];
    }

    /**
     * 컴포넌트 배열을 HTML로 렌더링하는 헬퍼 메서드입니다.
     *
     * @param  array  $components  컴포넌트 배열
     * @param  array  $context  데이터 컨텍스트
     * @return string 렌더링된 HTML
     */
    private function render(array $components, array $context = []): string
    {
        return $this->mapper->render($components, $context, $this->evaluator);
    }

    // =========================================================================
    // 기존 테스트 마이그레이션 (17개) — config 기반
    // =========================================================================

    /**
     * Div + children 텍스트를 올바르게 렌더링합니다.
     */
    public function test_div_with_children_text(): void
    {
        $components = [
            [
                'name' => 'Div',
                'children' => [
                    [
                        'name' => 'Span',
                        'props' => ['text' => '내용'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<div>', $html);
        $this->assertStringContainsString('<span>내용</span>', $html);
        $this->assertStringContainsString('</div>', $html);
    }

    /**
     * H1 + text prop을 올바르게 렌더링합니다.
     */
    public function test_h1_with_text_prop(): void
    {
        $components = [
            [
                'name' => 'H1',
                'props' => ['text' => '제목'],
            ],
        ];

        $html = $this->render($components);

        $this->assertSame('<h1>제목</h1>', $html);
    }

    /**
     * Img + src/alt를 셀프 클로징 태그로 렌더링합니다.
     */
    public function test_img_with_src_and_alt(): void
    {
        $components = [
            [
                'name' => 'Img',
                'props' => [
                    'src' => 'https://example.com/image.jpg',
                    'alt' => '설명',
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="https://example.com/image.jpg"', $html);
        $this->assertStringContainsString('alt="설명"', $html);
        $this->assertStringNotContainsString('</img>', $html);
    }

    /**
     * A + href + children을 올바르게 렌더링합니다.
     */
    public function test_a_with_href_and_children(): void
    {
        $components = [
            [
                'name' => 'A',
                'props' => ['href' => 'https://example.com'],
                'children' => [
                    [
                        'name' => 'Span',
                        'props' => ['text' => '텍스트'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="https://example.com">', $html);
        $this->assertStringContainsString('<span>텍스트</span>', $html);
        $this->assertStringContainsString('</a>', $html);
    }

    /**
     * Container 컴포넌트는 div 태그로 렌더링됩니다.
     */
    public function test_container_renders_as_div(): void
    {
        $components = [
            [
                'name' => 'Container',
                'props' => ['className' => 'container'],
                'children' => [
                    [
                        'name' => 'Span',
                        'props' => ['text' => '내용'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<div class="container">', $html);
        $this->assertStringContainsString('<span>내용</span>', $html);
    }

    /**
     * 중첩 컴포넌트를 올바르게 렌더링합니다.
     */
    public function test_nested_components(): void
    {
        $components = [
            [
                'name' => 'Div',
                'children' => [
                    [
                        'name' => 'Section',
                        'children' => [
                            [
                                'name' => 'Article',
                                'children' => [
                                    [
                                        'name' => 'P',
                                        'props' => ['text' => '깊은 중첩'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertSame('<div><section><article><p>깊은 중첩</p></article></section></div>', $html);
    }

    /**
     * if=true 조건의 컴포넌트는 렌더링에 포함됩니다.
     */
    public function test_if_true_included(): void
    {
        $components = [
            [
                'name' => 'Div',
                'if' => '{{visible}}',
                'props' => ['text' => '보이는 요소'],
            ],
        ];

        $html = $this->render($components, ['visible' => true]);

        $this->assertStringContainsString('<div>보이는 요소</div>', $html);
    }

    /**
     * if=false 조건의 컴포넌트는 렌더링에서 제외됩니다.
     */
    public function test_if_false_excluded(): void
    {
        $components = [
            [
                'name' => 'Div',
                'if' => '{{visible}}',
                'props' => ['text' => '숨겨진 요소'],
            ],
        ];

        $html = $this->render($components, ['visible' => false]);

        $this->assertSame('', $html);
    }

    /**
     * iteration으로 반복 렌더링을 올바르게 수행합니다.
     */
    public function test_iteration_renders_repeated_output(): void
    {
        $components = [
            [
                'name' => 'Li',
                'iteration' => [
                    'data' => '{{items}}',
                    'item_var' => 'item',
                    'index_var' => 'idx',
                ],
                'props' => ['text' => '{{item.name}}'],
            ],
        ];

        $context = [
            'items' => [
                ['name' => '사과'],
                ['name' => '바나나'],
                ['name' => '체리'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<li>사과</li>', $html);
        $this->assertStringContainsString('<li>바나나</li>', $html);
        $this->assertStringContainsString('<li>체리</li>', $html);
    }

    /**
     * iteration의 source 키로 반복 렌더링을 수행합니다 (레이아웃 JSON 스키마 호환).
     */
    public function test_iteration_with_source_key(): void
    {
        $components = [
            [
                'name' => 'Div',
                'iteration' => [
                    'source' => '{{product.data.notice.values}}',
                    'item_var' => 'noticeItem',
                ],
                'props' => ['className' => 'flex'],
                'children' => [
                    ['name' => 'Div', 'props' => ['text' => '{{noticeItem.key}}']],
                    ['name' => 'Div', 'props' => ['text' => '{{noticeItem.value}}']],
                ],
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'notice' => [
                        'values' => [
                            ['key' => '색상', 'value' => '빨강'],
                            ['key' => '사이즈', 'value' => 'M'],
                        ],
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('색상', $html);
        $this->assertStringContainsString('빨강', $html);
        $this->assertStringContainsString('사이즈', $html);
        $this->assertStringContainsString('M', $html);
    }

    /**
     * 알 수 없는 컴포넌트명은 div 태그로 폴백됩니다.
     */
    public function test_unknown_component_fallback_to_div(): void
    {
        $components = [
            [
                'name' => 'UnknownWidget',
                'props' => ['text' => '폴백 내용'],
            ],
        ];

        $html = $this->render($components);

        $this->assertSame('<div>폴백 내용</div>', $html);
    }

    /**
     * className prop이 class 속성으로 변환됩니다.
     */
    public function test_classname_prop_to_class_attribute(): void
    {
        $components = [
            [
                'name' => 'Div',
                'props' => ['className' => 'text-lg font-bold'],
                'children' => [
                    [
                        'name' => 'Span',
                        'props' => ['text' => '스타일'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('class="text-lg font-bold"', $html);
    }

    /**
     * children이 빈 배열이면 빈 태그를 렌더링합니다.
     */
    public function test_empty_children_renders_empty_tag(): void
    {
        $components = [
            [
                'name' => 'Div',
                'children' => [],
            ],
        ];

        $html = $this->render($components);

        $this->assertSame('<div></div>', $html);
    }

    /**
     * 데이터 바인딩이 포함된 prop을 올바르게 평가합니다.
     */
    public function test_data_binding_in_props(): void
    {
        $components = [
            [
                'name' => 'A',
                'props' => ['href' => '{{link.url}}'],
                'children' => [
                    [
                        'name' => 'Span',
                        'props' => ['text' => '{{link.label}}'],
                    ],
                ],
            ],
        ];

        $context = [
            'link' => [
                'url' => '/products/1',
                'label' => '상품 상세',
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('href="/products/1"', $html);
        $this->assertStringContainsString('<span>상품 상세</span>', $html);
    }

    /**
     * 허용되지 않은 속성(이벤트 핸들러 등)은 HTML에 포함되지 않습니다.
     */
    public function test_disallowed_attributes_excluded(): void
    {
        $components = [
            [
                'name' => 'Button',
                'props' => [
                    'text' => '클릭',
                    'onClick' => 'handleClick',
                    'type' => 'button',
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringNotContainsString('onClick', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('<button', $html);
    }

    /**
     * 셀프 클로징 태그(hr, br)가 올바르게 렌더링됩니다.
     */
    public function test_self_closing_tags(): void
    {
        $components = [
            ['name' => 'Hr'],
            ['name' => 'Br'],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<hr>', $html);
        $this->assertStringContainsString('<br>', $html);
        $this->assertStringNotContainsString('</hr>', $html);
        $this->assertStringNotContainsString('</br>', $html);
    }

    /**
     * 컴포넌트 레벨 text 속성이 렌더링됩니다.
     */
    public function test_component_level_text_attribute(): void
    {
        $components = [
            [
                'name' => 'Span',
                'text' => '$t:shop.back',
            ],
        ];

        app('translator')->addLines(['shop.back' => '뒤로가기'], 'ko');
        app()->setLocale('ko');

        $html = $this->render($components);

        $this->assertStringContainsString('<span>뒤로가기</span>', $html);
    }

    /**
     * 컴포넌트 레벨 text가 props.text보다 우선합니다.
     */
    public function test_component_text_takes_priority_over_props_text(): void
    {
        $components = [
            [
                'name' => 'Span',
                'text' => '우선 텍스트',
                'props' => ['text' => '차선 텍스트'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('우선 텍스트', $html);
        $this->assertStringNotContainsString('차선 텍스트', $html);
    }

    /**
     * Composite 컴포넌트가 div 태그로 렌더링되고 children이 포함됩니다.
     */
    public function test_composite_component_renders_with_children(): void
    {
        $components = [
            [
                'type' => 'composite',
                'name' => 'FormField',
                'props' => ['className' => 'mb-4'],
                'children' => [
                    [
                        'name' => 'Label',
                        'props' => ['text' => '이름'],
                    ],
                    [
                        'name' => 'Input',
                        'props' => ['name' => 'username', 'type' => 'text'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<div class="mb-4">', $html);
        $this->assertStringContainsString('<label>이름</label>', $html);
        $this->assertStringContainsString('<input', $html);
    }

    /**
     * ProductImageViewer가 이미지 태그를 렌더링합니다.
     */
    public function test_product_image_viewer_renders_images(): void
    {
        $components = [
            [
                'type' => 'composite',
                'name' => 'ProductImageViewer',
                'props' => [
                    'images' => '{{product.data.images}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'images' => [
                        ['url' => 'https://example.com/img1.jpg', 'alt' => '메인 이미지'],
                        ['url' => 'https://example.com/img2.jpg', 'alt' => '서브 이미지'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<img src="https://example.com/img1.jpg"', $html);
        $this->assertStringContainsString('alt="메인 이미지"', $html);
        $this->assertStringContainsString('<img src="https://example.com/img2.jpg"', $html);
    }

    /**
     * TabNavigation이 탭 버튼을 렌더링합니다.
     */
    public function test_tab_navigation_renders_tabs(): void
    {
        app('translator')->addLines([
            'shop.tabs.detail_info' => '상세정보',
            'shop.tabs.reviews' => '리뷰',
        ], 'ko');
        app()->setLocale('ko');

        $components = [
            [
                'type' => 'composite',
                'name' => 'TabNavigation',
                'props' => [
                    'tabs' => [
                        ['id' => 'info', 'label' => '$t:shop.tabs.detail_info'],
                        ['id' => 'reviews', 'label' => '$t:shop.tabs.reviews', 'badge' => '{{reviewCount}}'],
                    ],
                ],
            ],
        ];

        $context = ['reviewCount' => 15];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('상세정보', $html);
        $this->assertStringContainsString('리뷰', $html);
        $this->assertStringContainsString('15', $html);
    }

    /**
     * dangerouslySetInnerHTML prop이 HTML 콘텐츠를 렌더링합니다.
     */
    public function test_dangerously_set_inner_html_renders_content(): void
    {
        $components = [
            [
                'name' => 'Div',
                'props' => [
                    'dangerouslySetInnerHTML' => '{{product.data.description}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'description' => '<p>상품 설명 <strong>내용</strong></p>',
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<p>상품 설명 <strong>내용</strong></p>', $html);
    }

    /**
     * Fragment 컴포넌트는 래퍼 태그 없이 children만 렌더링합니다.
     */
    public function test_fragment_renders_children_without_wrapper(): void
    {
        $components = [
            [
                'name' => 'Fragment',
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '첫째']],
                    ['name' => 'Span', 'props' => ['text' => '둘째']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertSame('<span>첫째</span><span>둘째</span>', $html);
    }

    // =========================================================================
    // 6-B. config/fallback 검증 (신규 8개)
    // =========================================================================

    /**
     * componentMap 미설정 시 모든 컴포넌트가 div로 렌더링됩니다.
     * textProps/allowedAttrs도 미설정이므로 텍스트/속성 미출력 (안전한 저하).
     */
    public function test_no_config_all_components_fallback_to_div(): void
    {
        $mapper = new ComponentHtmlMapper;
        $html = $mapper->render([
            ['name' => 'Div', 'props' => ['text' => '내용']],
            ['name' => 'CustomWidget', 'props' => ['text' => '커스텀']],
        ], [], $this->evaluator);

        // componentMap 없이도 기본 textProps로 텍스트 추출
        $this->assertSame('<div>내용</div><div>커스텀</div>', $html);
    }

    /**
     * renderModes 미설정 + component_map에 render 키 → 빈 문자열 반환
     */
    public function test_no_render_modes_render_key_ignored(): void
    {
        $mapper = new ComponentHtmlMapper;
        $mapper->setComponentMap([
            'Widget' => ['tag' => 'div', 'render' => 'custom_mode'],
        ]);

        $html = $mapper->render([
            ['name' => 'Widget', 'props' => ['text' => '내용']],
        ], [], $this->evaluator);

        $this->assertSame('', $html);
    }

    /**
     * selfClosing 미설정 시 img, hr도 닫힘 태그 생성
     */
    public function test_no_self_closing_all_tags_have_closing(): void
    {
        $mapper = new ComponentHtmlMapper;
        $mapper->setComponentMap([
            'Img' => ['tag' => 'img'],
            'Hr' => ['tag' => 'hr'],
        ]);

        $html = $mapper->render([
            ['name' => 'Img', 'props' => ['src' => 'test.jpg']],
            ['name' => 'Hr'],
        ], [], $this->evaluator);

        $this->assertStringContainsString('</img>', $html);
        $this->assertStringContainsString('</hr>', $html);
    }

    /**
     * selfClosing에 커스텀 태그 추가 시 해당 태그 셀프 클로징
     */
    public function test_custom_self_closing_tags(): void
    {
        $mapper = new ComponentHtmlMapper;
        $mapper->setComponentMap(['CustomTag' => ['tag' => 'custom']]);
        $mapper->setSelfClosing(['custom']);
        $mapper->setAllowedAttrs(['id']);

        $html = $mapper->render([
            ['name' => 'CustomTag', 'props' => ['id' => 'test']],
        ], [], $this->evaluator);

        $this->assertStringContainsString('<custom id="test">', $html);
        $this->assertStringNotContainsString('</custom>', $html);
    }

    /**
     * skip:true 컴포넌트는 렌더링되지 않습니다.
     */
    public function test_skip_true_component_renders_nothing(): void
    {
        $components = [['name' => 'Modal', 'props' => ['text' => '모달 내용']]];

        $html = $this->render($components);

        $this->assertSame('', $html);
    }

    /**
     * skip:true 컴포넌트는 children이 있어도 렌더링되지 않습니다.
     */
    public function test_skip_true_ignores_children(): void
    {
        $components = [
            [
                'name' => 'Modal',
                'children' => [
                    ['name' => 'Div', 'props' => ['text' => '자식']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertSame('', $html);
    }

    /**
     * tag:"" (Fragment) → 래퍼 없이 children만 렌더링
     */
    public function test_empty_tag_renders_children_only(): void
    {
        $components = [
            [
                'name' => 'Fragment',
                'children' => [
                    ['name' => 'P', 'props' => ['text' => '단락 1']],
                    ['name' => 'P', 'props' => ['text' => '단락 2']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertSame('<p>단락 1</p><p>단락 2</p>', $html);
    }

    /**
     * 같은 이름으로 다른 tag 지정 시 config 태그 사용
     */
    public function test_component_map_overrides_default_behavior(): void
    {
        $mapper = new ComponentHtmlMapper;
        $mapper->setComponentMap([
            'CustomDiv' => ['tag' => 'section'],
        ]);

        $html = $mapper->render([
            ['name' => 'CustomDiv', 'props' => ['text' => '내용']],
        ], [], $this->evaluator);

        $this->assertStringContainsString('<section>', $html);
        $this->assertStringNotContainsString('<div>', $html);
    }

    // =========================================================================
    // 6-C. 선언적 렌더 모드 — iterate 타입 (신규 8개)
    // =========================================================================

    /**
     * iterate 모드: image_gallery → img 태그 목록 생성
     */
    public function test_iterate_mode_renders_image_gallery(): void
    {
        $components = [
            [
                'name' => 'ProductImageViewer',
                'props' => [
                    'images' => '{{product.images}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'images' => [
                    ['url' => 'https://cdn.test/a.jpg', 'alt' => 'A'],
                    ['url' => 'https://cdn.test/b.jpg', 'alt' => 'B'],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<img src="https://cdn.test/a.jpg" alt="A">', $html);
        $this->assertStringContainsString('<img src="https://cdn.test/b.jpg" alt="B">', $html);
    }

    /**
     * iterate 모드: 빈 배열 → 빈 래퍼 태그만
     */
    public function test_iterate_mode_with_empty_array(): void
    {
        $components = [
            [
                'name' => 'ProductImageViewer',
                'props' => ['images' => '{{product.images}}'],
            ],
        ];

        $context = ['product' => ['images' => []]];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<div>', $html);
        $this->assertStringContainsString('</div>', $html);
    }

    /**
     * iterate 모드: source가 null → 빈 래퍼 태그만
     */
    public function test_iterate_mode_with_null_source(): void
    {
        $components = [
            [
                'name' => 'ProductImageViewer',
                'props' => ['images' => '{{product.images}}'],
            ],
        ];

        $context = ['product' => ['images' => null]];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<div>', $html);
    }

    /**
     * iterate 모드: 1개 아이템 → 정확히 1개 아이템 태그
     */
    public function test_iterate_mode_with_single_item(): void
    {
        $components = [
            [
                'name' => 'ProductImageViewer',
                'props' => ['images' => '{{images}}'],
            ],
        ];

        $context = [
            'images' => [
                ['url' => 'https://cdn.test/single.jpg', 'alt' => '단일'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertSame(1, substr_count($html, '<img'));
    }

    /**
     * iterate 모드: 10개 아이템 → 10개 아이템 태그
     */
    public function test_iterate_mode_with_many_items(): void
    {
        $images = [];
        for ($i = 0; $i < 10; $i++) {
            $images[] = ['url' => "https://cdn.test/img{$i}.jpg", 'alt' => "이미지{$i}"];
        }

        $components = [
            ['name' => 'ProductImageViewer', 'props' => ['images' => '{{images}}']],
        ];

        $html = $this->render($components, ['images' => $images]);

        $this->assertSame(10, substr_count($html, '<img'));
    }

    /**
     * iterate 모드: tab_list → span 태그에 label 텍스트
     */
    public function test_iterate_mode_tab_list_with_labels(): void
    {
        $components = [
            [
                'name' => 'TabNavigation',
                'props' => [
                    'tabs' => [
                        ['id' => 'info', 'label' => '상세정보'],
                        ['id' => 'reviews', 'label' => '리뷰'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<span>상세정보</span>', $html);
        $this->assertStringContainsString('<span>리뷰</span>', $html);
    }

    /**
     * iterate 모드: tab_list + badge → badge span 포함
     */
    public function test_iterate_mode_tab_list_with_badge(): void
    {
        $components = [
            [
                'name' => 'TabNavigation',
                'props' => [
                    'tabs' => [
                        ['id' => 'reviews', 'label' => '리뷰', 'badge' => '{{reviewCount}}'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components, ['reviewCount' => 42]);

        $this->assertStringContainsString('리뷰', $html);
        $this->assertStringContainsString('42', $html);
    }

    /**
     * iterate 모드: item_attrs {url|src} → url 없으면 src 사용
     */
    public function test_iterate_mode_item_attrs_fallback_field(): void
    {
        $components = [
            ['name' => 'ProductImageViewer', 'props' => ['images' => '{{images}}']],
        ];

        $context = [
            'images' => [
                ['src' => 'https://cdn.test/fallback.jpg', 'alt' => '폴백'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('src="https://cdn.test/fallback.jpg"', $html);
    }

    // =========================================================================
    // 6-D. 선언적 렌더 모드 — format 타입 (신규 5개)
    // =========================================================================

    /**
     * format 모드: "{value} / {max}" → "4.5 / 5"
     */
    public function test_format_mode_star_rating(): void
    {
        $components = [
            [
                'name' => 'StarRating',
                'props' => ['value' => '4.5', 'max' => '5'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('4.5 / 5', $html);
        $this->assertStringContainsString('<span', $html);
    }

    /**
     * format 모드: max 미전달 → defaults의 "5" 사용
     */
    public function test_format_mode_with_defaults(): void
    {
        $components = [
            [
                'name' => 'StarRating',
                'props' => ['value' => '3.2'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('3.2 / 5', $html);
    }

    /**
     * format 모드: props 없음 → 기본값만 표시
     */
    public function test_format_mode_all_placeholders_missing(): void
    {
        $components = [
            [
                'name' => 'StarRating',
                'props' => [],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString(' / 5', $html);
    }

    /**
     * format 모드: 일부 prop만 있음 → 있는 것만 치환
     */
    public function test_format_mode_partial_placeholders(): void
    {
        $components = [
            [
                'name' => 'StarRating',
                'props' => ['value' => '4.0'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('4.0 / 5', $html);
    }

    /**
     * format 모드: format 키 없음 → 빈 문자열
     */
    public function test_format_mode_no_format_string(): void
    {
        $mapper = new ComponentHtmlMapper;
        $mapper->setComponentMap([
            'NoFormat' => ['tag' => 'span', 'render' => 'text_format'],
        ]);
        $mapper->setRenderModes(['text_format' => ['type' => 'format']]);

        $html = $mapper->render([
            ['name' => 'NoFormat', 'props' => ['value' => '4.5']],
        ], [], $this->evaluator);

        $this->assertStringContainsString('<span>', $html);
    }

    // =========================================================================
    // 6-E. 선언적 렌더 모드 — raw 타입 (신규 4개)
    // =========================================================================

    /**
     * raw 모드: HTML 콘텐츠 그대로 출력 (이스케이프 없음)
     */
    public function test_raw_mode_html_content(): void
    {
        $components = [
            [
                'name' => 'HtmlContent',
                'props' => ['content' => '{{page.content}}'],
            ],
        ];

        $context = ['page' => ['content' => '<p>본문 <strong>내용</strong></p>']];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<p>본문 <strong>내용</strong></p>', $html);
    }

    /**
     * raw 모드: {{binding}} → context에서 해석 후 출력
     */
    public function test_raw_mode_with_binding(): void
    {
        $components = [
            [
                'name' => 'HtmlContent',
                'props' => ['content' => '{{product.description}}'],
            ],
        ];

        $context = ['product' => ['description' => '<h2>상세 설명</h2><p>내용</p>']];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<h2>상세 설명</h2><p>내용</p>', $html);
    }

    /**
     * raw 모드: 콘텐츠 없음 → 빈 래퍼 태그
     */
    public function test_raw_mode_empty_content(): void
    {
        $components = [
            [
                'name' => 'HtmlContent',
                'props' => ['content' => '{{page.content}}'],
            ],
        ];

        $context = ['page' => ['content' => '']];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<div>', $html);
        $this->assertStringContainsString('</div>', $html);
    }

    /**
     * raw 모드: 여러 줄 HTML → 전부 보존
     */
    public function test_raw_mode_multiline_html(): void
    {
        $multilineHtml = "<h1>제목</h1>\n<p>첫 번째 단락</p>\n<p>두 번째 단락</p>";

        $components = [
            [
                'name' => 'HtmlContent',
                'props' => ['content' => '{{content}}'],
            ],
        ];

        $html = $this->render($components, ['content' => $multilineHtml]);

        $this->assertStringContainsString('<h1>제목</h1>', $html);
        $this->assertStringContainsString('<p>첫 번째 단락</p>', $html);
        $this->assertStringContainsString('<p>두 번째 단락</p>', $html);
    }

    // =========================================================================
    // 6-F. 실제 페이지 구조 렌더링 검증 (신규 12개)
    // =========================================================================

    /**
     * 상품 상세 페이지: Header+Main(이미지갤러리+상품정보)+Footer
     */
    public function test_product_detail_page_structure(): void
    {
        $components = [
            [
                'name' => 'Header',
                'children' => [['name' => 'Nav', 'children' => [['name' => 'A', 'props' => ['href' => '/', 'text' => '홈']]]]],
            ],
            [
                'name' => 'Main',
                'children' => [
                    ['name' => 'ProductImageViewer', 'props' => ['images' => '{{product.images}}']],
                    ['name' => 'H1', 'props' => ['text' => '{{product.name}}']],
                    ['name' => 'Span', 'props' => ['text' => '{{product.price}}']],
                ],
            ],
            ['name' => 'Footer', 'children' => [['name' => 'P', 'props' => ['text' => '© 2026']]]],
        ];

        $context = [
            'product' => [
                'name' => '테스트 상품',
                'price' => '29,000원',
                'images' => [['url' => 'https://cdn.test/p1.jpg', 'alt' => '상품 이미지']],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<header>', $html);
        $this->assertStringContainsString('<main>', $html);
        $this->assertStringContainsString('<footer>', $html);
        $this->assertStringContainsString('<h1>테스트 상품</h1>', $html);
        $this->assertStringContainsString('29,000원', $html);
        $this->assertStringContainsString('<img src="https://cdn.test/p1.jpg"', $html);
    }

    /**
     * 상품 목록 페이지: 카테고리명+ProductCard iteration
     */
    public function test_product_list_page_with_iteration(): void
    {
        $components = [
            ['name' => 'H1', 'props' => ['text' => '{{category.name}}']],
            [
                'name' => 'ProductCard',
                'iteration' => ['data' => '{{products}}', 'item_var' => 'product'],
                'children' => [
                    ['name' => 'H3', 'props' => ['text' => '{{product.name}}']],
                    ['name' => 'Span', 'props' => ['text' => '{{product.price}}']],
                ],
            ],
        ];

        $context = [
            'category' => ['name' => '전자제품'],
            'products' => [
                ['name' => '노트북', 'price' => '1,500,000원'],
                ['name' => '태블릿', 'price' => '800,000원'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<h1>전자제품</h1>', $html);
        $this->assertSame(2, substr_count($html, '<article'));
        $this->assertStringContainsString('노트북', $html);
        $this->assertStringContainsString('태블릿', $html);
    }

    /**
     * 카테고리 페이지: Breadcrumb+H1+Grid+Pagination
     */
    public function test_category_page_with_nested_components(): void
    {
        $components = [
            ['name' => 'Breadcrumb', 'children' => [['name' => 'A', 'props' => ['href' => '/', 'text' => '홈']]]],
            ['name' => 'H1', 'props' => ['text' => '카테고리']],
            [
                'name' => 'Grid',
                'children' => [
                    ['name' => 'Article', 'props' => ['text' => '상품 A']],
                    ['name' => 'Article', 'props' => ['text' => '상품 B']],
                ],
            ],
            ['name' => 'Pagination'],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<nav>', $html);
        $this->assertStringContainsString('<h1>카테고리</h1>', $html);
        $this->assertSame(2, substr_count($html, '<article>'));
    }

    /**
     * 검색 결과 페이지: SearchBar+결과목록+조건부 빈결과
     */
    public function test_search_results_page(): void
    {
        $components = [
            ['name' => 'SearchBar'],
            ['name' => 'H2', 'props' => ['text' => '검색 결과: {{query}}']],
            [
                'name' => 'Div',
                'if' => '{{hasResults}}',
                'children' => [
                    [
                        'name' => 'Article',
                        'iteration' => ['data' => '{{results}}', 'item_var' => 'result'],
                        'props' => ['text' => '{{result.title}}'],
                    ],
                ],
            ],
            [
                'name' => 'P',
                'if' => '{{noResults}}',
                'props' => ['text' => '검색 결과가 없습니다.'],
            ],
        ];

        // 결과 있는 경우
        $context = [
            'query' => '노트북',
            'hasResults' => true,
            'noResults' => false,
            'results' => [['title' => '노트북 A'], ['title' => '노트북 B']],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('검색 결과: 노트북', $html);
        $this->assertStringContainsString('노트북 A', $html);
        $this->assertStringNotContainsString('검색 결과가 없습니다', $html);
    }

    /**
     * 로그인 폼: Form+FormField(Label+Input)+Button
     */
    public function test_login_page_form_structure(): void
    {
        $components = [
            [
                'name' => 'Form',
                'children' => [
                    [
                        'name' => 'FormField',
                        'children' => [
                            ['name' => 'Label', 'props' => ['text' => '이메일']],
                            ['name' => 'Input', 'props' => ['name' => 'email', 'type' => 'text']],
                        ],
                    ],
                    [
                        'name' => 'FormField',
                        'children' => [
                            ['name' => 'Label', 'props' => ['text' => '비밀번호']],
                            ['name' => 'Input', 'props' => ['name' => 'password', 'type' => 'password']],
                        ],
                    ],
                    ['name' => 'Button', 'props' => ['text' => '로그인', 'type' => 'submit']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<form>', $html);
        $this->assertStringContainsString('<label>이메일</label>', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('<button type="submit">로그인</button>', $html);
    }

    /**
     * 게시글 페이지: 제목+작성자+본문(raw HTML)
     */
    public function test_article_page_with_html_content(): void
    {
        $components = [
            ['name' => 'H1', 'props' => ['text' => '{{post.title}}']],
            ['name' => 'Span', 'props' => ['text' => '{{post.author}}']],
            ['name' => 'HtmlContent', 'props' => ['content' => '{{post.body}}']],
        ];

        $context = [
            'post' => [
                'title' => '첫 번째 글',
                'author' => '홍길동',
                'body' => '<p>본문 내용입니다.</p><ul><li>항목 1</li></ul>',
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<h1>첫 번째 글</h1>', $html);
        $this->assertStringContainsString('홍길동', $html);
        $this->assertStringContainsString('<p>본문 내용입니다.</p>', $html);
        $this->assertStringContainsString('<ul><li>항목 1</li></ul>', $html);
    }

    /**
     * 헤더: Logo(Img)+Nav(Ul>Li>A 반복)
     */
    public function test_header_navigation_structure(): void
    {
        $components = [
            [
                'name' => 'Header',
                'children' => [
                    ['name' => 'Img', 'props' => ['src' => 'https://cdn.test/logo.png', 'alt' => '로고']],
                    [
                        'name' => 'Nav',
                        'children' => [
                            [
                                'name' => 'Ul',
                                'children' => [
                                    [
                                        'name' => 'Li',
                                        'iteration' => ['data' => '{{menuItems}}', 'item_var' => 'menu'],
                                        'children' => [['name' => 'A', 'props' => ['href' => '{{menu.url}}', 'text' => '{{menu.label}}']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $context = [
            'menuItems' => [
                ['url' => '/', 'label' => '홈'],
                ['url' => '/shop', 'label' => '쇼핑'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<header>', $html);
        $this->assertStringContainsString('<img src="https://cdn.test/logo.png"', $html);
        $this->assertStringContainsString('<nav>', $html);
        $this->assertStringContainsString('홈', $html);
        $this->assertStringContainsString('쇼핑', $html);
    }

    /**
     * 푸터: 링크+저작권 텍스트
     */
    public function test_footer_with_links_and_copyright(): void
    {
        $components = [
            [
                'name' => 'Footer',
                'children' => [
                    ['name' => 'A', 'props' => ['href' => '/terms', 'text' => '이용약관']],
                    ['name' => 'A', 'props' => ['href' => '/privacy', 'text' => '개인정보']],
                    ['name' => 'P', 'props' => ['text' => '© 2026 G7']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<footer>', $html);
        $this->assertStringContainsString('이용약관', $html);
        $this->assertStringContainsString('개인정보', $html);
        $this->assertStringContainsString('© 2026 G7', $html);
    }

    /**
     * 조건부 섹션: 로그인 시 마이페이지, 비로그인 시 로그인 버튼
     */
    public function test_page_with_conditional_sections(): void
    {
        $components = [
            ['name' => 'A', 'if' => '{{isLoggedIn}}', 'props' => ['href' => '/mypage', 'text' => '마이페이지']],
            ['name' => 'A', 'if' => '{{isGuest}}', 'props' => ['href' => '/login', 'text' => '로그인']],
        ];

        // 로그인 상태
        $html = $this->render($components, ['isLoggedIn' => true, 'isGuest' => false]);
        $this->assertStringContainsString('마이페이지', $html);
        $this->assertStringNotContainsString('로그인', $html);

        // 비로그인 상태
        $html2 = $this->render($components, ['isLoggedIn' => false, 'isGuest' => true]);
        $this->assertStringNotContainsString('마이페이지', $html2);
        $this->assertStringContainsString('로그인', $html2);
    }

    /**
     * 전체 페이지에 걸친 데이터 바인딩
     */
    public function test_page_with_data_binding_throughout(): void
    {
        $components = [
            ['name' => 'H1', 'props' => ['text' => '{{product.name}}']],
            ['name' => 'Span', 'props' => ['text' => '{{product.price}}']],
            ['name' => 'P', 'props' => ['text' => '{{product.summary}}']],
            ['name' => 'Img', 'props' => ['src' => '{{product.thumbnail}}', 'alt' => '{{product.name}}']],
        ];

        $context = [
            'product' => [
                'name' => '프리미엄 노트북',
                'price' => '2,500,000원',
                'summary' => '최신 프로세서 탑재',
                'thumbnail' => 'https://cdn.test/notebook.jpg',
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<h1>프리미엄 노트북</h1>', $html);
        $this->assertStringContainsString('2,500,000원', $html);
        $this->assertStringContainsString('최신 프로세서 탑재', $html);
        $this->assertStringContainsString('src="https://cdn.test/notebook.jpg"', $html);
    }

    /**
     * 5단계 이상 중첩: Div>Section>Article>Div>P
     */
    public function test_deeply_nested_layout(): void
    {
        $components = [
            [
                'name' => 'Div',
                'children' => [[
                    'name' => 'Section',
                    'children' => [[
                        'name' => 'Article',
                        'children' => [[
                            'name' => 'Div',
                            'children' => [[
                                'name' => 'P',
                                'children' => [[
                                    'name' => 'Strong',
                                    'props' => ['text' => '최하위'],
                                ]],
                            ]],
                        ]],
                    ]],
                ]],
            ],
        ];

        $html = $this->render($components);

        $this->assertSame('<div><section><article><div><p><strong>최하위</strong></p></div></article></section></div>', $html);
    }

    /**
     * 상품 카드 iteration 내부에 이미지+제목+가격+Badge
     */
    public function test_complex_iteration_with_nested_children(): void
    {
        $components = [
            [
                'name' => 'Div',
                'iteration' => ['data' => '{{products}}', 'item_var' => 'p'],
                'children' => [
                    ['name' => 'Img', 'props' => ['src' => '{{p.image}}', 'alt' => '{{p.name}}']],
                    ['name' => 'H3', 'props' => ['text' => '{{p.name}}']],
                    ['name' => 'Span', 'props' => ['text' => '{{p.price}}']],
                    ['name' => 'Badge', 'props' => ['text' => '{{p.badge}}']],
                ],
            ],
        ];

        $context = [
            'products' => [
                ['name' => '상품 A', 'price' => '10,000원', 'image' => 'https://cdn.test/a.jpg', 'badge' => 'NEW'],
                ['name' => '상품 B', 'price' => '20,000원', 'image' => 'https://cdn.test/b.jpg', 'badge' => 'SALE'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertSame(2, substr_count($html, '<h3>'));
        $this->assertSame(2, substr_count($html, '<img'));
        $this->assertStringContainsString('NEW', $html);
        $this->assertStringContainsString('SALE', $html);
    }

    // =========================================================================
    // 6-G. 엣지 케이스 검증 (신규 10개)
    // =========================================================================

    /**
     * name 키 없음 → div fallback
     */
    public function test_component_with_no_name(): void
    {
        $components = [['props' => ['text' => '이름없음']]];
        $html = $this->render($components);

        $this->assertStringContainsString('<div>이름없음</div>', $html);
    }

    /**
     * name:"" → div fallback
     */
    public function test_component_with_empty_name(): void
    {
        $components = [['name' => '', 'props' => ['text' => '빈이름']]];
        $html = $this->render($components);

        $this->assertStringContainsString('<div>빈이름</div>', $html);
    }

    /**
     * text에 <script> → 이스케이프 처리
     */
    public function test_xss_prevention_in_text(): void
    {
        $components = [['name' => 'Div', 'props' => ['text' => '<script>alert("xss")</script>']]];
        $html = $this->render($components);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * className에 xss 시도 → 이스케이프 처리
     */
    public function test_xss_prevention_in_attributes(): void
    {
        $components = [['name' => 'Div', 'props' => ['className' => '" onload="alert(1)']]];
        $html = $this->render($components);

        // 큰따옴표가 &quot;로 이스케이프되어 XSS 방지
        $this->assertStringContainsString('&quot;', $html);
        $this->assertStringNotContainsString('onload="alert', $html);
    }

    /**
     * context에서 null 값 → 빈 문자열
     */
    public function test_null_context_values(): void
    {
        $components = [['name' => 'Span', 'props' => ['text' => '{{nullValue}}']]];
        $html = $this->render($components, ['nullValue' => null]);

        $this->assertSame('<span></span>', $html);
    }

    /**
     * 빈 components 배열 → 빈 문자열
     */
    public function test_empty_components_array(): void
    {
        $html = $this->render([]);

        $this->assertSame('', $html);
    }

    /**
     * iteration source가 문자열 → 빈 결과 (에러 없음)
     */
    public function test_iteration_with_non_array_data(): void
    {
        $components = [
            [
                'name' => 'Li',
                'iteration' => ['data' => '{{items}}', 'item_var' => 'item'],
                'props' => ['text' => '{{item}}'],
            ],
        ];

        $html = $this->render($components, ['items' => '문자열']);

        $this->assertSame('', $html);
    }

    /**
     * {{a.b.c.d.e}} 5단계 중첩 경로 → 정상 해석
     */
    public function test_binding_with_nested_dot_path(): void
    {
        $components = [['name' => 'Span', 'props' => ['text' => '{{a.b.c.d.e}}']]];
        $context = ['a' => ['b' => ['c' => ['d' => ['e' => '깊은 값']]]]];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('깊은 값', $html);
    }

    /**
     * {{first}} {{last}} 여러 바인딩 → 모두 치환
     */
    public function test_multiple_bindings_in_single_text(): void
    {
        $components = [['name' => 'Span', 'props' => ['text' => '{{first}} {{last}}']]];
        $context = ['first' => '홍', 'last' => '길동'];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('홍 길동', $html);
    }

    /**
     * 한국어, 특수 문자 → 정상 렌더링
     */
    public function test_special_characters_in_content(): void
    {
        $components = [
            ['name' => 'P', 'props' => ['text' => '한국어 テスト &amp; 특수문자']],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('한국어 テスト', $html);
    }

    // =========================================================================
    // 6-H. self_closing 태그 검증 (신규 4개)
    // =========================================================================

    /**
     * Img → <img ...> (닫힘 태그 없음)
     */
    public function test_img_self_closing_no_end_tag(): void
    {
        $components = [['name' => 'Img', 'props' => ['src' => 'test.jpg']]];
        $html = $this->render($components);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringNotContainsString('</img>', $html);
    }

    /**
     * Input → <input ...> (닫힘 태그 없음)
     */
    public function test_input_self_closing_no_end_tag(): void
    {
        $components = [['name' => 'Input', 'props' => ['type' => 'text']]];
        $html = $this->render($components);

        $this->assertStringContainsString('<input', $html);
        $this->assertStringNotContainsString('</input>', $html);
    }

    /**
     * Hr, Br → <hr>, <br> (닫힘 태그 없음)
     */
    public function test_hr_br_self_closing(): void
    {
        $components = [['name' => 'Hr'], ['name' => 'Br']];
        $html = $this->render($components);

        $this->assertSame('<hr><br>', $html);
    }

    /**
     * Div, Span → 반드시 닫힘 태그 있음
     */
    public function test_non_self_closing_has_end_tag(): void
    {
        $components = [
            ['name' => 'Div', 'children' => []],
            ['name' => 'Span', 'children' => []],
        ];
        $html = $this->render($components);

        $this->assertStringContainsString('</div>', $html);
        $this->assertStringContainsString('</span>', $html);
    }

    // =========================================================================
    // 6-I. $t: 번역 + 데이터 바인딩 통합 검증 (신규 4개)
    // =========================================================================

    /**
     * $t:shop.title → 번역된 한국어 텍스트
     */
    public function test_translation_key_in_text(): void
    {
        app('translator')->addLines(['shop.title' => '쇼핑몰'], 'ko');
        app()->setLocale('ko');

        $components = [['name' => 'H1', 'text' => '$t:shop.title']];
        $html = $this->render($components);

        $this->assertStringContainsString('<h1>쇼핑몰</h1>', $html);
    }

    /**
     * $t:shop.greeting|name=홍길동 → 파라미터 치환
     */
    public function test_translation_with_params(): void
    {
        app('translator')->addLines(['shop.greeting' => ':name님 환영합니다'], 'ko');
        app()->setLocale('ko');

        $components = [['name' => 'Span', 'text' => '$t:shop.greeting|name=홍길동']];
        $html = $this->render($components);

        $this->assertStringContainsString('홍길동님 환영합니다', $html);
    }

    /**
     * 같은 페이지에 $t: 텍스트 + {{data}} 바인딩 공존
     */
    public function test_binding_and_translation_mixed(): void
    {
        app('translator')->addLines(['shop.title' => '상품 목록'], 'ko');
        app()->setLocale('ko');

        $components = [
            ['name' => 'H1', 'text' => '$t:shop.title'],
            ['name' => 'Span', 'props' => ['text' => '{{count}}개']],
        ];

        $html = $this->render($components, ['count' => 42]);

        $this->assertStringContainsString('상품 목록', $html);
        $this->assertStringContainsString('42개', $html);
    }

    /**
     * 존재하지 않는 번역 키 → 빈 문자열 (raw key 노출 방지)
     */
    public function test_missing_translation_key_empty_string(): void
    {
        $components = [['name' => 'Span', 'text' => '$t:nonexistent.key']];
        $html = $this->render($components);

        $this->assertStringNotContainsString('nonexistent.key', $html);
        $this->assertStringContainsString('<span>', $html);
    }

    // =========================================================================
    // responsive.desktop props 병합 테스트
    // =========================================================================

    /**
     * responsive.desktop의 props가 기본 props에 병합됩니다.
     */
    public function test_responsive_desktop_props_merged(): void
    {
        $components = [
            [
                'name' => 'Div',
                'props' => ['className' => 'hidden'],
                'responsive' => [
                    'desktop' => [
                        'props' => ['className' => 'block max-w-7xl'],
                    ],
                ],
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '내용']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('class="block max-w-7xl"', $html);
        $this->assertStringNotContainsString('class="hidden"', $html);
    }

    /**
     * responsive.desktop의 if 조건이 적용됩니다.
     */
    public function test_responsive_desktop_if_override(): void
    {
        $components = [
            [
                'name' => 'Div',
                'props' => ['text' => '데스크톱 전용'],
                'responsive' => [
                    'desktop' => [
                        'if' => '{{showDesktop}}',
                    ],
                ],
            ],
        ];

        // showDesktop=true → 렌더링됨
        $html = $this->render($components, ['showDesktop' => true]);
        $this->assertStringContainsString('데스크톱 전용', $html);

        // showDesktop=false → 렌더링 안됨
        $html = $this->render($components, ['showDesktop' => false]);
        $this->assertSame('', $html);
    }

    /**
     * responsive.desktop의 text 속성이 적용됩니다.
     */
    public function test_responsive_desktop_text_override(): void
    {
        $components = [
            [
                'name' => 'Span',
                'text' => '모바일 텍스트',
                'responsive' => [
                    'desktop' => [
                        'text' => '데스크톱 텍스트',
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('데스크톱 텍스트', $html);
        $this->assertStringNotContainsString('모바일 텍스트', $html);
    }

    /**
     * responsive에 desktop이 없으면 기본 속성이 유지됩니다.
     */
    public function test_responsive_without_desktop_keeps_defaults(): void
    {
        $components = [
            [
                'name' => 'Div',
                'props' => ['className' => 'mobile-only'],
                'responsive' => [
                    'portable' => [
                        'props' => ['className' => 'portable-class'],
                    ],
                ],
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '내용']],
                ],
            ],
        ];

        $html = $this->render($components);

        // portable은 무시, 기본 className 유지
        $this->assertStringContainsString('class="mobile-only"', $html);
    }

    // =========================================================================
    // Icon name_to_class 변환 테스트
    // =========================================================================

    /**
     * Icon의 name prop이 Font Awesome class로 변환됩니다.
     */
    public function test_icon_name_to_font_awesome_class(): void
    {
        $components = [
            [
                'name' => 'Icon',
                'props' => ['name' => 'shopping-cart'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('class="fas fa-shopping-cart"', $html);
        $this->assertStringNotContainsString('name=', $html);
    }

    /**
     * Icon의 name이 바인딩 표현식인 경우 평가 후 변환합니다.
     */
    public function test_icon_name_binding_evaluated(): void
    {
        $components = [
            [
                'name' => 'Icon',
                'props' => ['name' => '{{iconName}}'],
            ],
        ];

        $html = $this->render($components, ['iconName' => 'heart']);

        $this->assertStringContainsString('class="fas fa-heart"', $html);
    }

    /**
     * Icon의 fa- 접두사가 중복 방지됩니다.
     */
    public function test_icon_fa_prefix_dedup(): void
    {
        $components = [
            [
                'name' => 'Icon',
                'props' => ['name' => 'fa-star'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('class="fas fa-star"', $html);
        $this->assertStringNotContainsString('fas fa-fa-star', $html);
    }

    /**
     * Icon의 기존 className이 name_to_class 결과와 병합됩니다.
     */
    public function test_icon_classname_merged(): void
    {
        $components = [
            [
                'name' => 'Icon',
                'props' => ['name' => 'check', 'className' => 'text-green-500 w-4 h-4'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('class="fas fa-check text-green-500 w-4 h-4"', $html);
    }

    /**
     * Icon에 aria-label과 role이 자동 생성됩니다.
     */
    public function test_icon_accessibility_attrs(): void
    {
        $components = [
            [
                'name' => 'Icon',
                'props' => ['name' => 'shopping-cart'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('aria-label="shopping cart"', $html);
        $this->assertStringContainsString('role="img"', $html);
    }

    /**
     * Icon name이 빈 문자열이면 변환하지 않습니다.
     */
    public function test_icon_empty_name_no_transform(): void
    {
        $components = [
            [
                'name' => 'Icon',
                'props' => ['name' => ''],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringNotContainsString('fas fa-', $html);
    }

    // =========================================================================
    // Header/Footer text_format 렌더 모드 테스트
    // =========================================================================

    /**
     * Header가 text_format으로 site_name을 렌더링합니다.
     */
    public function test_header_renders_site_name(): void
    {
        $components = [
            [
                'name' => 'Header',
                'props' => ['site_name' => 'My Site'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<header', $html);
        $this->assertStringContainsString('My Site', $html);
        $this->assertStringContainsString('</header>', $html);
    }

    /**
     * Header에 site_name 미전달 시 기본값 G7이 사용됩니다.
     */
    public function test_header_default_site_name(): void
    {
        $components = [
            [
                'name' => 'Header',
                'props' => [],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('G7', $html);
    }

    /**
     * Footer가 copyright 포맷으로 렌더링됩니다.
     */
    public function test_footer_renders_copyright(): void
    {
        $components = [
            [
                'name' => 'Footer',
                'props' => ['site_name' => 'My Site'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<footer', $html);
        $this->assertStringContainsString('©', $html);
        $this->assertStringContainsString('My Site', $html);
        $this->assertStringContainsString('</footer>', $html);
    }

    /**
     * Footer에 site_name 미전달 시 기본값 G7이 사용됩니다.
     */
    public function test_footer_default_site_name(): void
    {
        $components = [
            [
                'name' => 'Footer',
                'props' => [],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('© G7', $html);
    }

    // =========================================================================
    // 이미지 src download_url fallback 테스트
    // =========================================================================

    /**
     * url 필드가 비어있을 때 download_url로 fallback합니다.
     */
    public function test_image_gallery_download_url_fallback(): void
    {
        $components = [
            [
                'name' => 'ProductImageViewer',
                'props' => [
                    'images' => '{{product.images}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'images' => [
                    [
                        'url' => '',
                        'download_url' => '/api/modules/sirsoft-ecommerce/product-image/abc123',
                        'alt_text_current' => '상품 이미지 1',
                    ],
                    [
                        'url' => null,
                        'download_url' => '/api/modules/sirsoft-ecommerce/product-image/def456',
                        'alt_text_current' => '상품 이미지 2',
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        // download_url로 fallback (url()로 절대 경로 변환)
        $this->assertStringContainsString('/api/modules/sirsoft-ecommerce/product-image/abc123', $html);
        $this->assertStringContainsString('/api/modules/sirsoft-ecommerce/product-image/def456', $html);
        $this->assertStringContainsString('alt="상품 이미지 1"', $html);
        $this->assertStringContainsString('alt="상품 이미지 2"', $html);
    }

    /**
     * url 필드가 존재하면 download_url보다 우선합니다.
     */
    public function test_image_gallery_url_takes_priority(): void
    {
        $components = [
            [
                'name' => 'ProductImageViewer',
                'props' => [
                    'images' => '{{product.images}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'images' => [
                    [
                        'url' => 'https://cdn.example.com/image.jpg',
                        'download_url' => '/api/modules/sirsoft-ecommerce/product-image/abc123',
                        'alt_text_current' => '상품 이미지',
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        // url이 있으면 url 사용
        $this->assertStringContainsString('src="https://cdn.example.com/image.jpg"', $html);
        $this->assertStringNotContainsString('product-image/abc123', $html);
    }

    /**
     * alt_text_current 필드로 alt 속성이 렌더링됩니다.
     */
    public function test_image_gallery_alt_text_current_field(): void
    {
        $components = [
            [
                'name' => 'ImageGallery',
                'props' => [
                    'images' => '{{images}}',
                ],
            ],
        ];

        $context = [
            'images' => [
                [
                    'url' => 'https://example.com/img.jpg',
                    'alt_text_current' => '현재 로케일 대체 텍스트',
                ],
                [
                    'url' => 'https://example.com/img2.jpg',
                    'alt_text_current' => '',
                    'alt_text' => '기본 대체 텍스트',
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('alt="현재 로케일 대체 텍스트"', $html);
        $this->assertStringContainsString('alt="기본 대체 텍스트"', $html);
    }

    // =========================================================================
    // 헬퍼 메서드 — config 기반 설정 반환
    // =========================================================================

    /**
     * 기본 텍스트 추출 속성 목록을 반환합니다.
     *
     * @return array 텍스트 속성 목록
     */
    private function getDefaultTextProps(): array
    {
        return ['text', 'label', 'value', 'title'];
    }

    /**
     * 기본 속성명 매핑을 반환합니다.
     *
     * @return array 속성명 매핑 (JSX → HTML)
     */
    private function getDefaultAttrMap(): array
    {
        return [
            'className' => 'class',
            'htmlFor' => 'for',
        ];
    }

    /**
     * 기본 허용 속성 목록을 반환합니다.
     *
     * @return array 허용 HTML 속성 목록
     */
    private function getDefaultAllowedAttrs(): array
    {
        return [
            'class', 'id', 'href', 'src', 'alt', 'title', 'name', 'type',
            'placeholder', 'for', 'target', 'rel', 'width', 'height',
            'role', 'aria-label', 'aria-describedby', 'data-testid', 'style',
        ];
    }

    // =========================================================================
    // config 기반 text_props / attr_map / allowed_attrs 테스트 (8개)
    // =========================================================================

    /**
     * text_props 설정에 따라 텍스트를 추출합니다.
     */
    public function test_text_props_from_config(): void
    {
        $components = [
            [
                'name' => 'Span',
                'props' => ['text' => '텍스트 내용'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('텍스트 내용', $html);
    }

    /**
     * text_props가 빈 배열이면 텍스트를 추출하지 않습니다.
     */
    public function test_empty_text_props_no_text_output(): void
    {
        $this->mapper->setTextProps([]);

        $components = [
            [
                'name' => 'Span',
                'props' => ['text' => '보이면 안됨'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringNotContainsString('보이면 안됨', $html);
    }

    /**
     * text_props 순서가 텍스트 추출 우선순위를 결정합니다.
     */
    public function test_custom_text_props_order(): void
    {
        // label을 text보다 우선하도록 순서 변경
        $this->mapper->setTextProps(['label', 'text']);

        $components = [
            [
                'name' => 'Span',
                'props' => [
                    'text' => '텍스트',
                    'label' => '레이블',
                ],
            ],
        ];

        $html = $this->render($components);

        // label이 우선이므로 label 값이 출력됨
        $this->assertStringContainsString('레이블', $html);
    }

    /**
     * attr_map에 따라 className이 class로 변환됩니다.
     */
    public function test_attr_map_from_config(): void
    {
        $components = [
            [
                'name' => 'Div',
                'props' => ['className' => 'test-class'],
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '내용']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('class="test-class"', $html);
        $this->assertStringNotContainsString('className', $html);
    }

    /**
     * 커스텀 attr_map으로 추가 매핑이 가능합니다.
     */
    public function test_custom_attr_map(): void
    {
        $this->mapper->setAttrMap([
            'className' => 'class',
            'htmlFor' => 'for',
            'dataValue' => 'data-value',
        ]);
        // data-value를 허용 목록에 추가
        $this->mapper->setAllowedAttrs(array_merge($this->getDefaultAllowedAttrs(), ['data-value']));

        $components = [
            [
                'name' => 'Div',
                'props' => ['dataValue' => 'custom'],
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '내용']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('data-value="custom"', $html);
    }

    /**
     * allowed_attrs에 포함된 속성만 출력됩니다.
     */
    public function test_allowed_attrs_from_config(): void
    {
        $components = [
            [
                'name' => 'A',
                'props' => [
                    'href' => 'https://example.com',
                    'className' => 'link',
                    'onclick' => 'alert(1)',  // 허용 목록에 없음
                ],
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '링크']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('class="link"', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    /**
     * allowed_attrs가 빈 배열이면 속성을 출력하지 않습니다.
     */
    public function test_empty_allowed_attrs_no_attributes(): void
    {
        $this->mapper->setAllowedAttrs([]);

        $components = [
            [
                'name' => 'Div',
                'props' => [
                    'className' => 'test',
                    'id' => 'main',
                ],
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '내용']],
                ],
            ],
        ];

        $html = $this->render($components);

        // 태그는 있지만 속성은 없어야 함
        $this->assertStringContainsString('<div>', $html);
        $this->assertStringNotContainsString('class=', $html);
        $this->assertStringNotContainsString('id=', $html);
    }

    /**
     * 커스텀 속성(data-* 등)을 허용 목록에 추가할 수 있습니다.
     */
    public function test_extra_allowed_attrs(): void
    {
        $this->mapper->setAllowedAttrs(array_merge(
            $this->getDefaultAllowedAttrs(),
            ['data-custom', 'data-analytics']
        ));

        $components = [
            [
                'name' => 'Div',
                'props' => [
                    'data-custom' => 'value1',
                    'data-analytics' => 'value2',
                ],
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '내용']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('data-custom="value1"', $html);
        $this->assertStringContainsString('data-analytics="value2"', $html);
    }

    // ========================
    // navigate/openWindow 링크 자동 생성 테스트
    // ========================

    /**
     * Button + 정적 path navigate → <a href="/login"> 변환을 검증합니다.
     */
    public function test_navigate_button_static_path(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '로그인',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/login']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="/login"', $html);
        $this->assertStringContainsString('로그인</a>', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    /**
     * Div + navigate → <a>로 래핑을 검증합니다.
     */
    public function test_navigate_div_wrapping(): void
    {
        $components = [
            [
                'name' => 'Div',
                'props' => ['className' => 'card'],
                'children' => [
                    ['name' => 'Span', 'text' => '상품명'],
                ],
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/shop']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="/shop"><div class="card">', $html);
        $this->assertStringContainsString('</div></a>', $html);
    }

    /**
     * 동적 path (데이터소스 컨텍스트) 해석을 검증합니다.
     */
    public function test_navigate_dynamic_path_from_context(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '상세보기',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/posts/{{post.slug}}']],
                ],
            ],
        ];

        $context = ['post' => ['slug' => 'hello-world']];
        $html = $this->render($components, $context);

        $this->assertStringContainsString('<a href="/posts/hello-world"', $html);
    }

    /**
     * navigate + query params → 쿼리스트링 빌드를 검증합니다.
     */
    public function test_navigate_with_query_params(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '검색',
                'actions' => [
                    [
                        'handler' => 'navigate',
                        'params' => [
                            'path' => '/search',
                            'query' => ['q' => 'test', 'page' => '1'],
                        ],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="/search?q=test&amp;page=1"', $html);
    }

    /**
     * replace: true인 navigate → 링크 미생성을 검증합니다.
     */
    public function test_navigate_replace_true_skipped(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '필터',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/shop', 'replace' => true]],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<button', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * sequence 내 navigate 추출을 검증합니다.
     */
    public function test_navigate_in_sequence(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '이동',
                'actions' => [
                    [
                        'handler' => 'sequence',
                        'actions' => [
                            ['handler' => 'setState', 'params' => ['target' => 'local', 'key' => 'loading', 'value' => true]],
                            ['handler' => 'navigate', 'params' => ['path' => '/dashboard']],
                        ],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="/dashboard"', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    /**
     * 미해석 _global 표현식 → 링크 생성 skip을 검증합니다.
     */
    public function test_navigate_unresolved_global_skipped(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '이동',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '{{_global.unknownBase}}/page']],
                ],
            ],
        ];

        $html = $this->render($components);

        // globalResolver 미설정 → _global 미해석 → 원본 태그 유지
        $this->assertStringContainsString('<button', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * globalResolver로 _global.shopBase 해석을 검증합니다.
     */
    public function test_navigate_global_resolver(): void
    {
        $this->mapper->setGlobalResolver(function (string $expr): ?string {
            if (str_contains($expr, 'shopBase')) {
                return '/shop';
            }

            return null;
        });

        $components = [
            [
                'name' => 'Button',
                'text' => '상품 보기',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '{{_global.shopBase}}/products/123']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="/shop/products/123"', $html);
    }

    /**
     * A 컴포넌트 + 기존 href 있음 → navigate 무시(스킵)를 검증합니다.
     */
    public function test_navigate_a_tag_existing_href_skipped(): void
    {
        $components = [
            [
                'name' => 'A',
                'props' => ['href' => '/existing-link'],
                'text' => '기존 링크',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/new-link']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('href="/existing-link"', $html);
        $this->assertStringNotContainsString('/new-link', $html);
    }

    /**
     * A 컴포넌트 + href 없음 + navigate → href 주입을 검증합니다.
     */
    public function test_navigate_a_tag_no_href_inject(): void
    {
        $components = [
            [
                'name' => 'A',
                'props' => ['className' => 'link'],
                'text' => '링크',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/about']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="/about"', $html);
        $this->assertStringContainsString('class="link"', $html);
    }

    /**
     * click 외 이벤트(keydown) → navigate 무시를 검증합니다.
     */
    public function test_navigate_non_click_event_ignored(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '버튼',
                'actions' => [
                    ['type' => 'keydown', 'handler' => 'navigate', 'params' => ['path' => '/page']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<button', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * iteration 내 navigate → 각 아이템별 다른 href 생성을 검증합니다.
     */
    public function test_navigate_in_iteration(): void
    {
        $components = [
            [
                'name' => 'Button',
                'iteration' => [
                    'source' => 'categories',
                    'item_var' => 'cat',
                ],
                'text' => '{{cat.name}}',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/category/{{cat.slug}}']],
                ],
            ],
        ];

        $context = [
            'categories' => [
                ['name' => '전자기기', 'slug' => 'electronics'],
                ['name' => '의류', 'slug' => 'clothing'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<a href="/category/electronics"', $html);
        $this->assertStringContainsString('<a href="/category/clothing"', $html);
        $this->assertStringContainsString('전자기기</a>', $html);
        $this->assertStringContainsString('의류</a>', $html);
    }

    /**
     * Button 변환 시 class 보존을 검증합니다.
     */
    public function test_navigate_button_preserves_class(): void
    {
        $components = [
            [
                'name' => 'Button',
                'props' => ['className' => 'btn btn-primary'],
                'text' => '이동',
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/page']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="/page"', $html);
        $this->assertStringContainsString('class="btn btn-primary"', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    /**
     * self-closing 태그(Img) + navigate → <a>로 래핑을 검증합니다.
     */
    public function test_navigate_self_closing_wrapping(): void
    {
        $components = [
            [
                'name' => 'Img',
                'props' => ['src' => '/banner.jpg', 'alt' => '배너'],
                'actions' => [
                    ['handler' => 'navigate', 'params' => ['path' => '/promotions']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="/promotions"><img', $html);
        $this->assertStringContainsString('</a>', $html);
    }

    /**
     * openWindow → target="_blank" 자동 부여를 검증합니다.
     */
    public function test_open_window_target_blank(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '외부 링크',
                'actions' => [
                    ['handler' => 'openWindow', 'params' => ['path' => 'https://example.com']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="https://example.com"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    /**
     * sequence 내 openWindow 추출을 검증합니다.
     */
    public function test_open_window_in_sequence(): void
    {
        $components = [
            [
                'name' => 'Button',
                'text' => '새 창',
                'actions' => [
                    [
                        'handler' => 'sequence',
                        'actions' => [
                            ['handler' => 'setState', 'params' => ['target' => 'local', 'key' => 'clicked', 'value' => true]],
                            ['handler' => 'openWindow', 'params' => ['path' => 'https://docs.example.com']],
                        ],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<a href="https://docs.example.com"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    // ========================================
    // fields 렌더 모드 테스트
    // ========================================

    /**
     * fields 모드: ProductCard에서 상품 객체의 주요 필드가 HTML로 렌더링되는지 확인합니다.
     */
    public function test_fields_mode_renders_product_card_basic_fields(): void
    {
        // seoVars 설정 (shopBase 해석용)
        $this->mapper->setSeoVars(['shopBase' => 'shop']);

        $components = [
            [
                'name' => 'Div',
                'iteration' => [
                    'source' => '{{products.data}}',
                    'item_var' => 'product',
                ],
                'children' => [
                    [
                        'type' => 'composite',
                        'name' => 'ProductCard',
                        'props' => [
                            'product' => '{{product}}',
                        ],
                    ],
                ],
            ],
        ];

        $context = [
            'products' => [
                'data' => [
                    [
                        'id' => 1,
                        'name' => 'Test Product',
                        'name_localized' => '테스트 상품',
                        'thumbnail_url' => '/storage/products/thumb1.jpg',
                        'selling_price_formatted' => '10,000원',
                        'list_price_formatted' => '15,000원',
                        'discount_rate' => 33,
                        'primary_category' => '의류',
                        'brand_name' => '나이키',
                        'sales_status' => 'on_sale',
                        'sales_status_label' => '판매중',
                        'labels' => [
                            ['name' => '베스트', 'color' => '#ff0000'],
                            ['name' => '무료배송', 'color' => '#00ff00'],
                        ],
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        // link 래핑
        $this->assertStringContainsString('<a href="/shop/products/1">', $html);

        // 썸네일 이미지
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('thumb1.jpg', $html);
        $this->assertStringContainsString('alt="테스트 상품"', $html);

        // 상품명
        $this->assertStringContainsString('<h3>테스트 상품</h3>', $html);

        // 카테고리 & 브랜드 (children 그룹 내)
        $this->assertStringContainsString('<p><span>의류</span><span>나이키</span></p>', $html);

        // 가격 (children 그룹 내)
        $this->assertStringContainsString('<span>10,000원</span>', $html);
        $this->assertStringContainsString('<del>15,000원</del>', $html);
        $this->assertStringContainsString('<span>33%</span>', $html);

        // 라벨
        $this->assertStringContainsString('<span>베스트</span>', $html);
        $this->assertStringContainsString('<span>무료배송</span>', $html);

        // 판매 상태
        $this->assertStringContainsString('판매중', $html);
    }

    /**
     * fields 모드: 할인율 없는 상품에서 원가/할인율이 렌더링되지 않는지 확인합니다.
     */
    public function test_fields_mode_conditional_fields_hidden_when_empty(): void
    {
        // seoVars 설정 (shopBase 해석용)
        $this->mapper->setSeoVars(['shopBase' => 'shop']);

        $components = [
            [
                'type' => 'composite',
                'name' => 'ProductCard',
                'props' => [
                    'product' => '{{product}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'id' => 2,
                'name' => 'No Discount',
                'name_localized' => '정가 상품',
                'thumbnail_url' => '/storage/products/thumb2.jpg',
                'selling_price_formatted' => '20,000원',
                'list_price_formatted' => '20,000원',
                'discount_rate' => 0,
                'primary_category' => '',
                'brand_name' => '',
                'sales_status' => '',
                'sales_status_label' => '',
                'labels' => [],
            ],
        ];

        $html = $this->render($components, $context);

        // link 래핑
        $this->assertStringContainsString('<a href="/shop/products/2">', $html);

        // 상품명/가격은 렌더링
        $this->assertStringContainsString('<h3>정가 상품</h3>', $html);
        $this->assertStringContainsString('20,000원', $html);

        // discount_rate=0 → if 조건 falsy → del/할인율 미렌더링
        $this->assertStringNotContainsString('<del>', $html);
        $this->assertStringNotContainsString('%', $html);

        // 빈 카테고리/브랜드 → children 모두 빈값 → <p> 태그 자체 미출력
        $this->assertStringNotContainsString('<p><span></span>', $html);

        // labels 빈 배열 → <p> 미렌더링
    }

    /**
     * fields 모드: name_localized가 없으면 name으로 fallback되는지 확인합니다.
     */
    public function test_fields_mode_field_pattern_fallback(): void
    {
        $components = [
            [
                'type' => 'composite',
                'name' => 'ProductCard',
                'props' => [
                    'product' => '{{product}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'name' => 'English Name',
                'name_localized' => '',
                'thumbnail_url' => '/thumb.jpg',
                'selling_price_formatted' => '5,000원',
                'discount_rate' => 0,
                'labels' => [],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<h3>English Name</h3>', $html);
        $this->assertStringContainsString('alt="English Name"', $html);
    }

    /**
     * fields 모드: 소스 객체가 빈 경우 빈 article 태그만 렌더링되는지 확인합니다.
     */
    public function test_fields_mode_empty_source_renders_empty(): void
    {
        $components = [
            [
                'type' => 'composite',
                'name' => 'ProductCard',
                'props' => [
                    'product' => '{{nonexistent}}',
                ],
            ],
        ];

        $html = $this->render($components);

        // fields 모드에서 source가 null → innerHtml 빈 문자열
        $this->assertStringContainsString('<article>', $html);
    }

    /**
     * fields 모드: 여러 상품이 iteration으로 반복 렌더링되는지 확인합니다.
     */
    public function test_fields_mode_multiple_products_via_iteration(): void
    {
        // seoVars 설정 (shopBase 해석용)
        $this->mapper->setSeoVars(['shopBase' => 'shop']);

        $components = [
            [
                'name' => 'Div',
                'iteration' => [
                    'source' => '{{products.data}}',
                    'item_var' => 'product',
                ],
                'children' => [
                    [
                        'type' => 'composite',
                        'name' => 'ProductCard',
                        'props' => [
                            'product' => '{{product}}',
                        ],
                    ],
                ],
            ],
        ];

        $context = [
            'products' => [
                'data' => [
                    [
                        'id' => 1,
                        'name_localized' => '상품A',
                        'thumbnail_url' => '/a.jpg',
                        'selling_price_formatted' => '1,000원',
                        'discount_rate' => 0,
                        'labels' => [],
                    ],
                    [
                        'id' => 2,
                        'name_localized' => '상품B',
                        'thumbnail_url' => '/b.jpg',
                        'selling_price_formatted' => '2,000원',
                        'discount_rate' => 10,
                        'list_price_formatted' => '2,200원',
                        'labels' => [['name' => '신상']],
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        // 각 상품별 다른 링크 URL
        $this->assertStringContainsString('<a href="/shop/products/1">', $html);
        $this->assertStringContainsString('<a href="/shop/products/2">', $html);

        $this->assertStringContainsString('상품A', $html);
        $this->assertStringContainsString('상품B', $html);
        $this->assertStringContainsString('1,000원', $html);
        $this->assertStringContainsString('2,000원', $html);
        $this->assertStringContainsString('<span>신상</span>', $html);
        $this->assertEquals(2, substr_count($html, '<article'));
    }

    /**
     * fields 모드에서 각 필드 항목 사이에 줄바꿈이 삽입됩니다.
     */
    public function test_fields_mode_entries_separated_by_newlines(): void
    {
        $components = [
            [
                'type' => 'composite',
                'name' => 'ProductCard',
                'props' => [
                    'product' => '{{product}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'name_localized' => '테스트 상품',
                'thumbnail_url' => '/test.jpg',
                'selling_price_formatted' => '5,000원',
                'discount_rate' => 0,
                'labels' => [],
            ],
        ];

        $html = $this->render($components, $context);

        // 줄바꿈으로 분리된 필드 엔트리 확인
        $lines = array_filter(explode("\n", $html), fn ($line) => trim($line) !== '');
        $this->assertGreaterThan(1, count($lines), 'fields 모드 출력에 여러 줄이 있어야 합니다');

        // 주요 필드가 별도 줄에 있는지 확인
        $this->assertStringContainsString('테스트 상품', $html);
        $this->assertStringContainsString('5,000원', $html);
    }

    // ========================================
    // fields 모드 — children 그룹핑 + link 래핑 테스트
    // ========================================

    /**
     * fields 모드: children 그룹핑 — <p> 래퍼 내 <span> 2개 정상 렌더링
     */
    public function test_fields_mode_children_grouping(): void
    {
        $this->mapper->setRenderModes([
            'test_children' => [
                'type' => 'fields',
                'source' => '$props_source',
                'fields' => [
                    [
                        'tag' => 'p',
                        'children' => [
                            ['tag' => 'span', 'content' => '{category}'],
                            ['tag' => 'span', 'content' => '{brand}'],
                        ],
                    ],
                ],
            ],
        ]);
        $this->mapper->setComponentMap([
            'TestComp' => ['tag' => 'div', 'render' => 'test_children', 'props_source' => 'item'],
        ]);

        $components = [
            ['type' => 'composite', 'name' => 'TestComp', 'props' => ['item' => '{{item}}']],
        ];
        $context = ['item' => ['category' => '의류', 'brand' => '나이키']];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<p><span>의류</span><span>나이키</span></p>', $html);
    }

    /**
     * fields 모드: children 그룹핑 — 모든 자식이 조건부+빈값이면 <p> 태그 미출력
     */
    public function test_fields_mode_children_all_empty(): void
    {
        $this->mapper->setRenderModes([
            'test_children_empty' => [
                'type' => 'fields',
                'source' => '$props_source',
                'fields' => [
                    ['tag' => 'h3', 'content' => '{name}'],
                    [
                        'tag' => 'p',
                        'children' => [
                            ['tag' => 'span', 'content' => '{category}', 'if' => '{category}'],
                            ['tag' => 'span', 'content' => '{brand}', 'if' => '{brand}'],
                        ],
                    ],
                ],
            ],
        ]);
        $this->mapper->setComponentMap([
            'TestComp' => ['tag' => 'div', 'render' => 'test_children_empty', 'props_source' => 'item'],
        ]);

        $components = [
            ['type' => 'composite', 'name' => 'TestComp', 'props' => ['item' => '{{item}}']],
        ];
        $context = ['item' => ['name' => '상품', 'category' => '', 'brand' => '']];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<h3>상품</h3>', $html);
        // 빈 children → <p> 미출력
        $this->assertStringNotContainsString('<p>', $html);
    }

    /**
     * fields 모드: link 래핑 — <a href="/products/42"> 전체 필드 래핑
     */
    public function test_fields_mode_link_wrapping(): void
    {
        $this->mapper->setRenderModes([
            'test_link' => [
                'type' => 'fields',
                'source' => '$props_source',
                'link' => ['href' => '/products/{id}'],
                'fields' => [
                    ['tag' => 'h3', 'content' => '{name}'],
                ],
            ],
        ]);
        $this->mapper->setComponentMap([
            'TestComp' => ['tag' => 'div', 'render' => 'test_link', 'props_source' => 'item'],
        ]);

        $components = [
            ['type' => 'composite', 'name' => 'TestComp', 'props' => ['item' => '{{item}}']],
        ];
        $context = ['item' => ['id' => 42, 'name' => '테스트']];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<a href="/products/42">', $html);
        $this->assertStringContainsString('<h3>테스트</h3>', $html);
        $this->assertStringContainsString('</a>', $html);
    }

    /**
     * fields 모드: link + $var:shopBase → /shop/products/1
     */
    public function test_fields_mode_link_with_var_base_url(): void
    {
        $this->mapper->setSeoVars(['shopBase' => 'shop']);

        $this->mapper->setRenderModes([
            'test_link_var' => [
                'type' => 'fields',
                'source' => '$props_source',
                'link' => ['href' => '/products/{id}', 'base_url' => '$var:shopBase'],
                'fields' => [
                    ['tag' => 'h3', 'content' => '{name}'],
                ],
            ],
        ]);
        $this->mapper->setComponentMap([
            'TestComp' => ['tag' => 'div', 'render' => 'test_link_var', 'props_source' => 'item'],
        ]);

        $components = [
            ['type' => 'composite', 'name' => 'TestComp', 'props' => ['item' => '{{item}}']],
        ];
        $context = ['item' => ['id' => 1, 'name' => '테스트']];

        $html = $this->render($components, $context);

        // $var:shopBase → 'shop' → '/shop' (/ 자동 접두사) → /shop/products/1
        $this->assertStringContainsString('<a href="/shop/products/1">', $html);
    }

    /**
     * fields 모드: seoVars 미설정 시 $var: base_url은 빈 문자열, href만 사용
     */
    public function test_fields_mode_link_no_var_fallback(): void
    {
        // seoVars 미설정 → $var:shopBase 해석 불가 → base_url 빈 문자열

        $this->mapper->setRenderModes([
            'test_link_no_var' => [
                'type' => 'fields',
                'source' => '$props_source',
                'link' => ['href' => '/products/{id}', 'base_url' => '$var:shopBase'],
                'fields' => [
                    ['tag' => 'h3', 'content' => '{name}'],
                ],
            ],
        ]);
        $this->mapper->setComponentMap([
            'TestComp' => ['tag' => 'div', 'render' => 'test_link_no_var', 'props_source' => 'item'],
        ]);

        $components = [
            ['type' => 'composite', 'name' => 'TestComp', 'props' => ['item' => '{{item}}']],
        ];
        $context = ['item' => ['id' => 5, 'name' => '상품']];

        $html = $this->render($components, $context);

        // base_url 해석 불가 → href만 사용
        $this->assertStringContainsString('<a href="/products/5">', $html);
    }

    /**
     * fields 모드: base_url="/" → 이중 슬래시 방지 → /products/1
     */
    public function test_fields_mode_link_base_url_slash(): void
    {
        $this->mapper->setRenderModes([
            'test_link_slash' => [
                'type' => 'fields',
                'source' => '$props_source',
                'link' => ['href' => '/products/{id}', 'base_url' => '/'],
                'fields' => [
                    ['tag' => 'h3', 'content' => '{name}'],
                ],
            ],
        ]);
        $this->mapper->setComponentMap([
            'TestComp' => ['tag' => 'div', 'render' => 'test_link_slash', 'props_source' => 'item'],
        ]);

        $components = [
            ['type' => 'composite', 'name' => 'TestComp', 'props' => ['item' => '{{item}}']],
        ];
        $context = ['item' => ['id' => 1, 'name' => '상품']];

        $html = $this->render($components, $context);

        // base_url="/" → 빈 문자열 변환 → 이중 슬래시 방지
        $this->assertStringContainsString('<a href="/products/1">', $html);
        $this->assertStringNotContainsString('//products', $html);
    }

    /**
     * fields 모드: id 필드 없으면 link 미생성, 필드만 출력
     */
    public function test_fields_mode_link_missing_id(): void
    {
        $this->mapper->setRenderModes([
            'test_link_no_id' => [
                'type' => 'fields',
                'source' => '$props_source',
                'link' => ['href' => '/products/{id}'],
                'fields' => [
                    ['tag' => 'h3', 'content' => '{name}'],
                ],
            ],
        ]);
        $this->mapper->setComponentMap([
            'TestComp' => ['tag' => 'div', 'render' => 'test_link_no_id', 'props_source' => 'item'],
        ]);

        $components = [
            ['type' => 'composite', 'name' => 'TestComp', 'props' => ['item' => '{{item}}']],
        ];
        // id 키 미포함
        $context = ['item' => ['name' => '상품']];

        $html = $this->render($components, $context);

        // <a> 태그 미생성
        $this->assertStringNotContainsString('<a ', $html);
        // 필드는 정상 렌더링
        $this->assertStringContainsString('<h3>상품</h3>', $html);
    }

    /**
     * fields 모드: link/children 없는 기존 flat 설정 → 동일 동작 (하위 호환)
     */
    public function test_fields_mode_backward_compatible_flat(): void
    {
        $this->mapper->setRenderModes([
            'test_flat' => [
                'type' => 'fields',
                'source' => '$props_source',
                'fields' => [
                    ['tag' => 'h3', 'content' => '{name}'],
                    ['tag' => 'span', 'content' => '{price}'],
                    ['tag' => 'span', 'content' => '{category}', 'if' => '{category}'],
                ],
            ],
        ]);
        $this->mapper->setComponentMap([
            'TestComp' => ['tag' => 'div', 'render' => 'test_flat', 'props_source' => 'item'],
        ]);

        $components = [
            ['type' => 'composite', 'name' => 'TestComp', 'props' => ['item' => '{{item}}']],
        ];
        $context = ['item' => ['name' => '상품', 'price' => '1,000원', 'category' => '의류']];

        $html = $this->render($components, $context);

        // link/children 없음 → <a> 미생성, flat 렌더링
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('<h3>상품</h3>', $html);
        $this->assertStringContainsString('<span>1,000원</span>', $html);
        $this->assertStringContainsString('<span>의류</span>', $html);
    }

    // =========================================================================
    // seoVars (meta.seo.vars → format 모드 변수 주입) 테스트
    // =========================================================================

    /**
     * seoVars 설정 시 Header의 {site_name}이 seoVars 값으로 치환됩니다.
     */
    public function test_format_mode_seo_vars_override_defaults(): void
    {
        $this->mapper->setSeoVars(['site_name' => 'My Store']);

        $components = [
            [
                'name' => 'Header',
                'props' => [],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<header', $html);
        $this->assertStringContainsString('My Store', $html);
        $this->assertStringNotContainsString('G7', $html);
    }

    /**
     * seoVars 설정 시 Footer의 {site_name}도 seoVars 값으로 치환됩니다.
     */
    public function test_format_mode_seo_vars_footer(): void
    {
        $this->mapper->setSeoVars(['site_name' => '테스트 사이트']);

        $components = [
            [
                'name' => 'Footer',
                'props' => [],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('© 테스트 사이트', $html);
        $this->assertStringNotContainsString('G7', $html);
    }

    /**
     * props가 seoVars보다 우선합니다.
     */
    public function test_format_mode_props_override_seo_vars(): void
    {
        $this->mapper->setSeoVars(['site_name' => 'Vars Site']);

        $components = [
            [
                'name' => 'Header',
                'props' => ['site_name' => 'Props Site'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('Props Site', $html);
        $this->assertStringNotContainsString('Vars Site', $html);
    }

    /**
     * seoVars 미설정 시 기존대로 defaults가 사용됩니다.
     */
    public function test_format_mode_no_seo_vars_uses_defaults(): void
    {
        // setSeoVars 호출하지 않음
        $components = [
            [
                'name' => 'Header',
                'props' => [],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('G7', $html);
    }

    /**
     * seoVars에 빈 문자열이면 defaults로 폴백합니다.
     */
    public function test_format_mode_empty_seo_vars_falls_back_to_defaults(): void
    {
        $this->mapper->setSeoVars(['site_name' => '']);

        $components = [
            [
                'name' => 'Header',
                'props' => [],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('G7', $html);
    }

    /**
     * seoVars는 format 모드에서만 작동하며 일반 태그 렌더링에는 영향 없습니다.
     */
    public function test_seo_vars_do_not_affect_normal_tag_rendering(): void
    {
        $this->mapper->setSeoVars(['site_name' => 'Should Not Appear']);

        $components = [
            [
                'name' => 'Div',
                'props' => ['className' => 'test'],
                'text' => 'Normal Content',
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('Normal Content', $html);
        $this->assertStringNotContainsString('Should Not Appear', $html);
    }

    /**
     * StarRating의 props가 seoVars보다 우선하여 렌더링됩니다.
     */
    public function test_format_mode_star_rating_props_over_seo_vars(): void
    {
        $this->mapper->setSeoVars(['max' => '10']);

        $components = [
            [
                'name' => 'StarRating',
                'props' => ['value' => '4.5', 'max' => '5'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('4.5 / 5', $html);
        $this->assertStringNotContainsString('4.5 / 10', $html);
    }

    // ==========================================
    // text_format dot notation (객체 prop에서 중첩 필드 추출)
    // ==========================================

    /**
     * Avatar 컴포넌트: author 객체에서 nickname을 추출합니다.
     */
    public function test_avatar_renders_author_nickname(): void
    {
        $context = [
            'comment' => [
                'author' => ['id' => 1, 'nickname' => '홍길동', 'profile_photo_url' => '/avatar.jpg'],
            ],
        ];

        $components = [
            [
                'name' => 'Avatar',
                'props' => ['author' => '{{comment?.author}}'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<span>', $html);
        $this->assertStringContainsString('홍길동', $html);
    }

    /**
     * UserInfo 컴포넌트: author nickname + subText(날짜)를 렌더링합니다.
     */
    public function test_userinfo_renders_author_and_date(): void
    {
        $context = [
            'comment' => [
                'author' => ['id' => 1, 'nickname' => '김철수'],
                'created_at' => '2026-03-19 10:00:00',
            ],
        ];

        $components = [
            [
                'name' => 'UserInfo',
                'props' => [
                    'author' => '{{comment?.author}}',
                    'subText' => '{{comment?.created_at}}',
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('김철수', $html);
        $this->assertStringContainsString('2026-03-19 10:00:00', $html);
        $this->assertStringContainsString('·', $html);
    }

    /**
     * text_format dot notation: author가 null이면 빈 문자열을 출력합니다.
     */
    public function test_text_format_dot_notation_null_object(): void
    {
        $context = [];

        $components = [
            [
                'name' => 'Avatar',
                'props' => ['author' => '{{missing?.author}}'],
            ],
        ];

        $html = $this->render($components, $context);

        // nickname 없으면 빈 span
        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * text_format dot notation: 깊은 경로도 해석합니다.
     */
    public function test_text_format_deep_dot_notation(): void
    {
        // 커스텀 컴포넌트맵으로 깊은 dot notation 테스트
        $componentMap = $this->getDefaultComponentMap();
        $componentMap['DeepTest'] = [
            'tag' => 'span',
            'render' => 'text_format',
            'format' => '{data.nested.name}',
        ];
        $this->mapper->setComponentMap($componentMap);

        $context = [
            'item' => [
                'nested' => ['name' => '깊은값'],
            ],
        ];

        $components = [
            [
                'name' => 'DeepTest',
                'props' => ['data' => '{{item}}'],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('깊은값', $html);
    }

    // ===================================================================
    // pagination 렌더 모드 테스트
    // ===================================================================

    /**
     * Pagination — 여러 페이지가 있으면 페이지 링크 생성
     */
    public function test_pagination_renders_page_links(): void
    {
        $components = [
            [
                'type' => 'composite',
                'name' => 'Pagination',
                'props' => [
                    'currentPage' => '{{posts?.data?.pagination?.current_page ?? 1}}',
                    'totalPages' => '{{posts?.data?.pagination?.last_page ?? 1}}',
                ],
            ],
        ];
        $context = [
            'posts' => ['data' => ['pagination' => ['current_page' => 1, 'last_page' => 5]]],
            'route' => ['path' => '/board/free'],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('<span>1</span>', $html);
        $this->assertStringContainsString('<a href="/board/free?page=2">2</a>', $html);
        $this->assertStringContainsString('<a href="/board/free?page=5">5</a>', $html);
    }

    /**
     * Pagination — 1 페이지만 있으면 빈 nav
     */
    public function test_pagination_single_page_renders_empty(): void
    {
        $components = [
            [
                'type' => 'composite',
                'name' => 'Pagination',
                'props' => [
                    'currentPage' => '1',
                    'totalPages' => '1',
                ],
            ],
        ];
        $context = ['route' => ['path' => '/board/free']];

        $html = $this->render($components, $context);

        $this->assertStringNotContainsString('<a href', $html);
    }

    /**
     * Pagination — 현재 페이지는 span, 나머지는 a 태그
     */
    public function test_pagination_current_page_is_span(): void
    {
        $components = [
            [
                'type' => 'composite',
                'name' => 'Pagination',
                'props' => [
                    'currentPage' => '3',
                    'totalPages' => '5',
                ],
            ],
        ];
        $context = ['route' => ['path' => '/board/gallery']];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<span>3</span>', $html);
        $this->assertStringContainsString('<a href="/board/gallery?page=1">1</a>', $html);
        $this->assertStringContainsString('<a href="/board/gallery?page=4">4</a>', $html);
    }

    // =========================================================================
    // $all_props source + item_attrs 테스트
    // =========================================================================

    /**
     * fields 모드에서 source: $all_props 사용 시 모든 props를 해석하여 데이터로 사용합니다.
     */
    public function test_fields_mode_all_props_renders_site_name(): void
    {
        $this->mapper->setRenderModes(array_merge($this->getDefaultRenderModes(), [
            'header_nav' => [
                'type' => 'fields',
                'source' => '$all_props',
                'fields' => [
                    ['tag' => 'a', 'attrs' => ['href' => '/'], 'content' => '{siteName}'],
                ],
            ],
        ]));
        $this->mapper->setComponentMap(array_merge($this->getDefaultComponentMap(), [
            'Header' => ['tag' => 'header', 'render' => 'header_nav'],
        ]));

        $html = $this->render([
            [
                'name' => 'Header',
                'props' => [
                    'siteName' => '{{_global.settings.general.site_name}}',
                ],
            ],
        ], [
            '_global' => ['settings' => ['general' => ['site_name' => '테스트사이트']]],
        ]);

        $this->assertStringContainsString('<header>', $html);
        $this->assertStringContainsString('<a href="/">테스트사이트</a>', $html);
        $this->assertStringContainsString('</header>', $html);
    }

    /**
     * fields 모드에서 source: $all_props + iterate로 boards 네비게이션을 렌더링합니다.
     */
    public function test_fields_mode_all_props_renders_boards_navigation(): void
    {
        $this->mapper->setRenderModes(array_merge($this->getDefaultRenderModes(), [
            'header_nav' => [
                'type' => 'fields',
                'source' => '$all_props',
                'fields' => [
                    ['tag' => 'a', 'attrs' => ['href' => '/'], 'content' => '{siteName}'],
                    [
                        'tag' => 'nav',
                        'iterate' => 'boards',
                        'item_tag' => 'a',
                        'item_content' => '{name}',
                        'item_attrs' => ['href' => '/board/{slug}'],
                    ],
                ],
            ],
        ]));
        $this->mapper->setComponentMap(array_merge($this->getDefaultComponentMap(), [
            'Header' => ['tag' => 'header', 'render' => 'header_nav'],
        ]));

        $html = $this->render([
            [
                'name' => 'Header',
                'props' => [
                    'siteName' => '{{_global.settings.general.site_name}}',
                    'boards' => '{{boards.data}}',
                ],
            ],
        ], [
            '_global' => ['settings' => ['general' => ['site_name' => 'G7']]],
            'boards' => [
                'data' => [
                    ['name' => '공지사항', 'slug' => 'notice'],
                    ['name' => '자유게시판', 'slug' => 'free'],
                ],
            ],
        ]);

        $this->assertStringContainsString('<a href="/">G7</a>', $html);
        $this->assertStringContainsString('<a href="/board/notice">공지사항</a>', $html);
        $this->assertStringContainsString('<a href="/board/free">자유게시판</a>', $html);
    }

    /**
     * fields 모드에서 source: $all_props + 중첩 객체 (socialLinks) dot notation if 조건을 지원합니다.
     */
    public function test_fields_mode_all_props_renders_footer_with_social_links(): void
    {
        $this->mapper->setRenderModes(array_merge($this->getDefaultRenderModes(), [
            'footer_nav' => [
                'type' => 'fields',
                'source' => '$all_props',
                'fields' => [
                    ['tag' => 'p', 'content' => '{siteDescription}', 'if' => '{siteDescription}'],
                    [
                        'tag' => 'nav',
                        'children' => [
                            ['tag' => 'a', 'attrs' => ['href' => '{socialLinks.github}'], 'content' => 'GitHub', 'if' => '{socialLinks.github}'],
                            ['tag' => 'a', 'attrs' => ['href' => '{socialLinks.twitter}'], 'content' => 'Twitter', 'if' => '{socialLinks.twitter}'],
                            ['tag' => 'a', 'attrs' => ['href' => '{socialLinks.discord}'], 'content' => 'Discord', 'if' => '{socialLinks.discord}'],
                        ],
                    ],
                    ['tag' => 'p', 'content' => '© {siteName}'],
                ],
            ],
        ]));
        $this->mapper->setComponentMap(array_merge($this->getDefaultComponentMap(), [
            'Footer' => ['tag' => 'footer', 'render' => 'footer_nav'],
        ]));

        $html = $this->render([
            [
                'name' => 'Footer',
                'props' => [
                    'siteName' => '{{_global.settings.general.site_name}}',
                    'siteDescription' => '{{_global.settings.general.site_description}}',
                    'socialLinks' => [
                        'github' => '{{_global.settings.social.github}}',
                        'twitter' => '{{_global.settings.social.twitter}}',
                        'discord' => '{{_global.settings.social.discord}}',
                    ],
                ],
            ],
        ], [
            '_global' => [
                'settings' => [
                    'general' => ['site_name' => 'G7', 'site_description' => '오픈소스 CMS'],
                    'social' => ['github' => 'https://github.com/g7', 'twitter' => '', 'discord' => 'https://discord.gg/g7'],
                ],
            ],
        ]);

        $this->assertStringContainsString('<footer>', $html);
        $this->assertStringContainsString('<p>오픈소스 CMS</p>', $html);
        $this->assertStringContainsString('<a href="https://github.com/g7">GitHub</a>', $html);
        $this->assertStringNotContainsString('Twitter', $html); // twitter가 빈 문자열이므로 미렌더링
        $this->assertStringContainsString('<a href="https://discord.gg/g7">Discord</a>', $html);
        $this->assertStringContainsString('<p>© G7</p>', $html);
        $this->assertStringContainsString('</footer>', $html);
    }

    /**
     * iterate에서 item_attrs를 지원하여 아이템별 동적 속성을 렌더링합니다.
     */
    public function test_field_iterate_with_item_attrs(): void
    {
        $this->mapper->setRenderModes(array_merge($this->getDefaultRenderModes(), [
            'nav_links' => [
                'type' => 'fields',
                'source' => '$props_source',
                'fields' => [
                    [
                        'tag' => 'nav',
                        'iterate' => 'items',
                        'item_tag' => 'a',
                        'item_content' => '{title}',
                        'item_attrs' => ['href' => '{url}'],
                    ],
                ],
            ],
        ]));
        $this->mapper->setComponentMap(array_merge($this->getDefaultComponentMap(), [
            'NavLinks' => ['tag' => 'div', 'render' => 'nav_links', 'props_source' => 'data'],
        ]));

        $html = $this->render([
            [
                'name' => 'NavLinks',
                'props' => [
                    'data' => '{{links}}',
                ],
            ],
        ], [
            'links' => [
                'items' => [
                    ['title' => '홈', 'url' => '/'],
                    ['title' => '소개', 'url' => '/about'],
                ],
            ],
        ]);

        $this->assertStringContainsString('<a href="/">홈</a>', $html);
        $this->assertStringContainsString('<a href="/about">소개</a>', $html);
    }

    /**
     * fields 모드에서 $t: 번역 키를 content에서 해석합니다.
     */
    public function test_fields_mode_translates_t_keys_in_content(): void
    {
        // 번역 사전 설정
        $this->evaluator->setTranslations([
            'nav' => ['home' => '홈', 'popular' => '인기', 'shop' => '쇼핑'],
            'footer' => ['community' => '커뮤니티', 'info' => '정보'],
        ]);

        $this->mapper->setRenderModes(array_merge($this->getDefaultRenderModes(), [
            'header_nav' => [
                'type' => 'fields',
                'source' => '$all_props',
                'fields' => [
                    ['tag' => 'a', 'attrs' => ['href' => '/'], 'content' => '{siteName}'],
                    [
                        'tag' => 'nav',
                        'children' => [
                            ['tag' => 'a', 'attrs' => ['href' => '/'], 'content' => '$t:nav.home'],
                            ['tag' => 'a', 'attrs' => ['href' => '/boards/popular'], 'content' => '$t:nav.popular'],
                            ['tag' => 'a', 'attrs' => ['href' => '/shop/products'], 'content' => '$t:nav.shop'],
                        ],
                    ],
                ],
            ],
        ]));
        $this->mapper->setComponentMap(array_merge($this->getDefaultComponentMap(), [
            'Header' => ['tag' => 'header', 'render' => 'header_nav'],
        ]));

        $html = $this->render([
            [
                'name' => 'Header',
                'props' => [
                    'siteName' => '{{_global.settings.general.site_name}}',
                    'boards' => '{{boards.data}}',
                ],
            ],
        ], [
            '_global' => ['settings' => ['general' => ['site_name' => 'G7']]],
            'boards' => ['data' => []],
        ]);

        $this->assertStringContainsString('<a href="/">G7</a>', $html);
        $this->assertStringContainsString('<a href="/">홈</a>', $html);
        $this->assertStringContainsString('<a href="/boards/popular">인기</a>', $html);
        $this->assertStringContainsString('<a href="/shop/products">쇼핑</a>', $html);
    }

    /**
     * fields 모드에서 $t: 번역 키를 children 내 중첩 구조에서 해석합니다.
     */
    public function test_fields_mode_translates_t_keys_in_footer_link_groups(): void
    {
        $this->evaluator->setTranslations([
            'footer' => [
                'community' => '커뮤니티',
                'info' => '정보',
                'policy' => '정책',
                'all_boards' => '전체 게시판',
                'about' => '회사소개',
                'terms' => '이용약관',
            ],
            'nav' => ['home' => '홈', 'popular' => '인기'],
        ]);

        $this->mapper->setRenderModes(array_merge($this->getDefaultRenderModes(), [
            'footer_nav' => [
                'type' => 'fields',
                'source' => '$all_props',
                'fields' => [
                    ['tag' => 'p', 'content' => '{siteName}'],
                    [
                        'tag' => 'div',
                        'children' => [
                            [
                                'tag' => 'div',
                                'children' => [
                                    ['tag' => 'h4', 'content' => '$t:footer.community'],
                                    ['tag' => 'a', 'attrs' => ['href' => '/'], 'content' => '$t:nav.home'],
                                    ['tag' => 'a', 'attrs' => ['href' => '/boards/popular'], 'content' => '$t:nav.popular'],
                                    ['tag' => 'a', 'attrs' => ['href' => '/boards'], 'content' => '$t:footer.all_boards'],
                                ],
                            ],
                            [
                                'tag' => 'div',
                                'children' => [
                                    ['tag' => 'h4', 'content' => '$t:footer.info'],
                                    ['tag' => 'a', 'attrs' => ['href' => '/page/about'], 'content' => '$t:footer.about'],
                                ],
                            ],
                            [
                                'tag' => 'div',
                                'children' => [
                                    ['tag' => 'h4', 'content' => '$t:footer.policy'],
                                    ['tag' => 'a', 'attrs' => ['href' => '/page/terms'], 'content' => '$t:footer.terms'],
                                ],
                            ],
                        ],
                    ],
                    ['tag' => 'p', 'content' => '© {siteName}. All rights reserved.'],
                    ['tag' => 'p', 'content' => 'Powered by G7'],
                ],
            ],
        ]));
        $this->mapper->setComponentMap(array_merge($this->getDefaultComponentMap(), [
            'Footer' => ['tag' => 'footer', 'render' => 'footer_nav'],
        ]));

        $html = $this->render([
            [
                'name' => 'Footer',
                'props' => [
                    'siteName' => '{{_global.settings.general.site_name}}',
                ],
            ],
        ], [
            '_global' => ['settings' => ['general' => ['site_name' => 'G7']]],
        ]);

        $this->assertStringContainsString('<footer>', $html);
        $this->assertStringContainsString('<p>G7</p>', $html);
        $this->assertStringContainsString('<h4>커뮤니티</h4>', $html);
        $this->assertStringContainsString('<a href="/">홈</a>', $html);
        $this->assertStringContainsString('<a href="/boards/popular">인기</a>', $html);
        $this->assertStringContainsString('<a href="/boards">전체 게시판</a>', $html);
        $this->assertStringContainsString('<h4>정보</h4>', $html);
        $this->assertStringContainsString('<a href="/page/about">회사소개</a>', $html);
        $this->assertStringContainsString('<h4>정책</h4>', $html);
        $this->assertStringContainsString('<a href="/page/terms">이용약관</a>', $html);
        $this->assertStringContainsString('<p>© G7. All rights reserved.</p>', $html);
        $this->assertStringContainsString('<p>Powered by G7</p>', $html);
        $this->assertStringContainsString('</footer>', $html);
    }

    // =========================================================================
    // classMap (조건부 CSS 클래스) 테스트
    // =========================================================================

    /**
     * classMap의 key 표현식이 variants와 매칭되면 해당 클래스가 적용됩니다.
     */
    public function test_classmap_resolves_matching_variant(): void
    {
        $components = [[
            'type' => 'basic',
            'name' => 'Span',
            'classMap' => [
                'base' => 'px-2 py-1 rounded-full text-xs',
                'variants' => [
                    'active' => 'bg-green-100 text-green-800',
                    'inactive' => 'bg-gray-100 text-gray-600',
                ],
                'key' => '{{product.status}}',
                'default' => 'bg-gray-100',
            ],
            'text' => '활성',
        ]];

        $context = ['product' => ['status' => 'active']];

        $html = $this->mapper->render($components, $context, $this->evaluator);

        $this->assertStringContainsString('px-2 py-1 rounded-full text-xs bg-green-100 text-green-800', $html);
    }

    /**
     * classMap의 key가 variants에 없으면 default 클래스가 적용됩니다.
     */
    public function test_classmap_falls_back_to_default(): void
    {
        $components = [[
            'type' => 'basic',
            'name' => 'Span',
            'classMap' => [
                'base' => 'px-2 py-1',
                'variants' => [
                    'active' => 'bg-green-100',
                ],
                'key' => '{{product.status}}',
                'default' => 'bg-gray-100',
            ],
            'text' => '상태',
        ]];

        $context = ['product' => ['status' => 'unknown']];

        $html = $this->mapper->render($components, $context, $this->evaluator);

        $this->assertStringContainsString('px-2 py-1 bg-gray-100', $html);
        $this->assertStringNotContainsString('bg-green-100', $html);
    }

    /**
     * classMap과 기존 className이 함께 사용되면 클래스가 병합됩니다.
     */
    public function test_classmap_merges_with_existing_classname(): void
    {
        $components = [[
            'type' => 'basic',
            'name' => 'Span',
            'props' => [
                'className' => 'cursor-pointer hover:opacity-80',
            ],
            'classMap' => [
                'base' => 'px-2',
                'variants' => [
                    'active' => 'bg-blue-500',
                ],
                'key' => '{{status}}',
            ],
            'text' => 'test',
        ]];

        $context = ['status' => 'active'];

        $html = $this->mapper->render($components, $context, $this->evaluator);

        // className + classMap base + variant 순서로 병합
        $this->assertStringContainsString('cursor-pointer hover:opacity-80 px-2 bg-blue-500', $html);
    }

    /**
     * classMap에 base가 없어도 variant 클래스만 적용됩니다.
     */
    public function test_classmap_without_base(): void
    {
        $components = [[
            'type' => 'basic',
            'name' => 'Span',
            'classMap' => [
                'variants' => [
                    'success' => 'text-green-500',
                    'error' => 'text-red-500',
                ],
                'key' => '{{result}}',
            ],
            'text' => 'ok',
        ]];

        $context = ['result' => 'success'];

        $html = $this->mapper->render($components, $context, $this->evaluator);

        $this->assertStringContainsString('text-green-500', $html);
        $this->assertStringNotContainsString('text-red-500', $html);
    }

    /**
     * classMap의 key가 빈 문자열이면 default가 적용됩니다.
     */
    public function test_classmap_empty_key_uses_default(): void
    {
        $components = [[
            'type' => 'basic',
            'name' => 'Span',
            'classMap' => [
                'base' => 'base-class',
                'variants' => [
                    'active' => 'active-class',
                ],
                'key' => '{{missing_field}}',
                'default' => 'default-class',
            ],
            'text' => 'test',
        ]];

        $context = [];

        $html = $this->mapper->render($components, $context, $this->evaluator);

        $this->assertStringContainsString('base-class default-class', $html);
    }
}
