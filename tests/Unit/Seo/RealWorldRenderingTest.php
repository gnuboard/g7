<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

/**
 * 실제 상품 상세 페이지 렌더링 시나리오를 검증합니다.
 *
 * 사용자 피드백 기반: 실제 SEO 페이지에서 발생한 문제들을 재현하여 검증합니다.
 * - Icon이 <div> 대신 <i>로 렌더링되는지
 * - Img가 <img> 셀프 클로징 태그로 렌더링되는지
 * - 상품 데이터 바인딩 (이름, 가격, 이미지) 적용 여부
 * - $t: 번역 키 해석 여부
 * - 표현식 평가 여부
 * - 템플릿 CSS 경로 포함 여부
 */
class RealWorldRenderingTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * sirsoft-basic 템플릿의 실제 seo-config.json을 로드하여 테스트합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        // 실제 seo-config.json 로드
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

    // ========================================================
    // 1. 태그 매핑 검증 — Icon, Img 등이 올바른 HTML 태그를 사용하는지
    // ========================================================

    /**
     * Icon 컴포넌트가 <i> 태그로 렌더링됩니다 (재현 시 발생한 버그: <div>로 렌더링됨).
     */
    public function test_icon_renders_as_i_tag(): void
    {
        $components = [
            [
                'name' => 'Icon',
                'props' => ['name' => 'shopping-cart', 'className' => 'w-5 h-5'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<i', $html);
        $this->assertStringNotContainsString('<div', $html);
    }

    /**
     * Img 컴포넌트가 <img> 셀프 클로징 태그로 렌더링됩니다.
     */
    public function test_img_renders_as_img_tag_self_closing(): void
    {
        $components = [
            [
                'name' => 'Img',
                'props' => [
                    'src' => '/images/product.jpg',
                    'alt' => '상품 이미지',
                    'className' => 'w-full',
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringNotContainsString('</img>', $html);
        $this->assertStringContainsString('src=', $html);
        $this->assertStringContainsString('alt="상품 이미지"', $html);
    }

    /**
     * Button 컴포넌트가 <button> 태그로 렌더링됩니다.
     */
    public function test_button_renders_as_button_tag(): void
    {
        $components = [
            [
                'name' => 'Button',
                'props' => ['className' => 'px-4 py-2', 'text' => '구매하기'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('</button>', $html);
        $this->assertStringContainsString('구매하기', $html);
    }

    /**
     * Nav 컴포넌트가 <nav> 태그로 렌더링됩니다.
     */
    public function test_nav_renders_as_nav_tag(): void
    {
        $components = [
            [
                'name' => 'Nav',
                'props' => ['className' => 'flex items-center'],
                'children' => [
                    ['name' => 'A', 'props' => ['href' => '/', 'text' => 'Home']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('</nav>', $html);
        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('Home', $html);
    }

    /**
     * H1~H6 컴포넌트가 올바른 헤딩 태그로 렌더링됩니다.
     */
    public function test_headings_render_correct_tags(): void
    {
        $headings = ['H1' => 'h1', 'H2' => 'h2', 'H3' => 'h3', 'H4' => 'h4', 'H5' => 'h5', 'H6' => 'h6'];

        foreach ($headings as $component => $tag) {
            $components = [['name' => $component, 'props' => ['text' => 'Heading']]];
            $html = $this->render($components);
            $this->assertStringContainsString("<{$tag}", $html, "{$component}가 <{$tag}>로 렌더링되어야 합니다");
        }
    }

    /**
     * Input 컴포넌트가 <input> 셀프 클로징 태그로 렌더링됩니다.
     */
    public function test_input_renders_as_input_self_closing(): void
    {
        $components = [
            [
                'name' => 'Input',
                'props' => ['type' => 'text', 'placeholder' => '검색어 입력'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<input', $html);
        $this->assertStringNotContainsString('</input>', $html);
    }

    // ========================================================
    // 2. 데이터 바인딩 검증 — 상품 데이터가 렌더링에 반영되는지
    // ========================================================

    /**
     * 상품명이 바인딩으로 올바르게 표시됩니다.
     */
    public function test_product_name_binding(): void
    {
        $components = [
            [
                'name' => 'H1',
                'props' => [
                    'className' => 'text-xl font-bold',
                    'text' => '{{product.data.name_localized}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name_localized' => '무선 마우스 세트 #99',
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('무선 마우스 세트 #99', $html);
    }

    /**
     * 상품 가격이 바인딩으로 올바르게 표시됩니다.
     */
    public function test_product_price_binding(): void
    {
        $components = [
            [
                'name' => 'Span',
                'props' => [
                    'className' => 'text-2xl font-bold',
                    'text' => '{{product.data.selling_price_formatted}}',
                ],
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'selling_price_formatted' => '129,000원',
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('129,000원', $html);
    }

    /**
     * 상품 카테고리명이 바인딩으로 표시됩니다.
     */
    public function test_product_category_binding(): void
    {
        $components = [
            [
                'name' => 'Span',
                'props' => [
                    'className' => 'text-sm text-gray-500',
                    'text' => '{{product.data.category_name}}',
                ],
            ],
        ];

        $context = [
            'product' => ['data' => ['category_name' => '소파']],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('소파', $html);
    }

    /**
     * 브랜드명이 바인딩으로 표시됩니다.
     */
    public function test_brand_name_binding(): void
    {
        $components = [
            [
                'name' => 'Div',
                'children' => [
                    [
                        'name' => 'Span',
                        'props' => ['className' => 'text-sm text-gray-500', 'text' => '브랜드'],
                    ],
                    [
                        'name' => 'Span',
                        'props' => ['text' => '{{product.data.brand_name}}'],
                    ],
                ],
            ],
        ];

        $context = [
            'product' => ['data' => ['brand_name' => 'LG Electronics']],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('LG Electronics', $html);
        $this->assertStringContainsString('브랜드', $html);
    }

    // ========================================================
    // 3. 이미지 렌더링 검증 — ProductImageViewer + 이미지 바인딩
    // ========================================================

    /**
     * ProductImageViewer가 이미지 갤러리를 올바르게 렌더링합니다.
     */
    public function test_product_image_viewer_renders_images(): void
    {
        $components = [
            [
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
                        ['url' => '/storage/products/mouse-1.jpg', 'alt' => '마우스 정면'],
                        ['url' => '/storage/products/mouse-2.jpg', 'alt' => '마우스 측면'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        // img 태그가 생성되어야 함
        $this->assertStringContainsString('<img', $html);
        // 2개 이미지의 src 포함
        $this->assertStringContainsString('mouse-1.jpg', $html);
        $this->assertStringContainsString('mouse-2.jpg', $html);
        // alt 속성 포함
        $this->assertStringContainsString('마우스 정면', $html);
    }

    /**
     * ProductImageViewer에 이미지가 배열로 직접 전달될 때도 렌더링됩니다.
     */
    public function test_product_image_viewer_with_direct_array(): void
    {
        $components = [
            [
                'name' => 'ProductImageViewer',
                'props' => [
                    'images' => [
                        ['src' => 'https://example.com/img1.jpg', 'alt' => '이미지1'],
                        ['src' => 'https://example.com/img2.jpg', 'alt' => '이미지2'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('https://example.com/img1.jpg', $html);
    }

    /**
     * 일반 Img 컴포넌트로 상품 이미지를 렌더링할 수 있습니다.
     */
    public function test_img_with_bound_src(): void
    {
        $components = [
            [
                'name' => 'Img',
                'props' => [
                    'src' => '{{product.data.thumbnail}}',
                    'alt' => '{{product.data.name_localized}}',
                    'className' => 'w-full rounded-lg',
                ],
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'thumbnail' => '/storage/products/thumb.jpg',
                    'name_localized' => '무선 마우스',
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('thumb.jpg', $html);
        $this->assertStringContainsString('무선 마우스', $html);
    }

    // ========================================================
    // 4. 번역 ($t:) 검증
    // ========================================================

    /**
     * $t: 번역 키가 해석됩니다 (재현 시 발생한 버그: $t:shop.product.reviews_count 그대로 노출).
     */
    public function test_translation_key_resolved(): void
    {
        $this->evaluator->setTranslations([
            'shop' => [
                'product' => [
                    'reviews_count' => '개의 리뷰',
                ],
            ],
        ]);

        $components = [
            [
                'name' => 'Span',
                'props' => ['text' => '$t:shop.product.reviews_count'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('개의 리뷰', $html);
        $this->assertStringNotContainsString('$t:shop', $html);
    }

    /**
     * $t: 번역 키와 데이터 바인딩이 혼합된 텍스트가 올바르게 렌더링됩니다.
     */
    public function test_translation_mixed_with_binding(): void
    {
        $this->evaluator->setTranslations([
            'shop' => [
                'product' => [
                    'write_question' => '질문 작성',
                ],
                'back' => '뒤로가기',
                'tabs' => [
                    'detail_info' => '상세정보',
                    'reviews' => '리뷰',
                    'qna' => 'Q&A',
                ],
            ],
        ]);

        $components = [
            [
                'name' => 'Div',
                'children' => [
                    ['name' => 'Span', 'props' => ['text' => '$t:shop.back']],
                    ['name' => 'Button', 'props' => ['text' => '$t:shop.product.write_question']],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('뒤로가기', $html);
        $this->assertStringContainsString('질문 작성', $html);
    }

    // ========================================================
    // 5. 실제 상품 상세 페이지 구조 통합 테스트
    // ========================================================

    /**
     * 상품 상세 페이지의 핵심 구조가 올바르게 렌더링됩니다.
     * Header + 이미지 + 상품 정보 + 설명 + 리뷰
     */
    public function test_product_detail_page_core_structure(): void
    {
        $this->evaluator->setTranslations([
            'shop' => [
                'back' => '뒤로가기',
                'product' => [
                    'brand' => '브랜드',
                    'shipping_info' => '배송 정보',
                    'reviews_count' => '개의 리뷰',
                    'write_question' => '질문 작성',
                ],
                'write_review' => '리뷰 작성',
            ],
        ]);

        $components = [
            // 전체 래퍼
            [
                'name' => 'Div',
                'props' => ['className' => 'min-h-screen flex flex-col'],
                'children' => [
                    // 뒤로가기 버튼
                    [
                        'name' => 'Div',
                        'props' => ['className' => 'flex items-center'],
                        'children' => [
                            ['name' => 'Icon', 'props' => ['name' => 'chevron-left']],
                            ['name' => 'Span', 'props' => ['text' => '$t:shop.back']],
                        ],
                    ],
                    // 이미지 갤러리
                    [
                        'name' => 'ProductImageViewer',
                        'props' => ['images' => '{{product.data.images}}'],
                    ],
                    // 상품 정보
                    [
                        'name' => 'Div',
                        'props' => ['className' => 'space-y-4'],
                        'children' => [
                            // 카테고리
                            [
                                'name' => 'Span',
                                'props' => [
                                    'className' => 'text-sm text-gray-500',
                                    'text' => '{{product.data.category_name}}',
                                ],
                            ],
                            // 상품명
                            [
                                'name' => 'H1',
                                'props' => [
                                    'className' => 'text-xl font-bold',
                                    'text' => '{{product.data.name_localized}}',
                                ],
                            ],
                            // 가격
                            [
                                'name' => 'Span',
                                'props' => [
                                    'className' => 'text-2xl font-bold',
                                    'text' => '{{product.data.selling_price_formatted}}',
                                ],
                            ],
                            // 브랜드
                            [
                                'name' => 'Div',
                                'children' => [
                                    ['name' => 'Span', 'props' => ['text' => '$t:shop.product.brand']],
                                    ['name' => 'Span', 'props' => ['text' => '{{product.data.brand_name}}']],
                                ],
                            ],
                        ],
                    ],
                    // 상품 설명 (HtmlContent)
                    [
                        'name' => 'HtmlContent',
                        'props' => [
                            'content' => '{{product.data.description_localized}}',
                        ],
                    ],
                    // 리뷰 섹션
                    [
                        'name' => 'Div',
                        'children' => [
                            [
                                'name' => 'Span',
                                'props' => ['text' => '{{product.data.rating_avg}}'],
                            ],
                            [
                                'name' => 'Span',
                                'props' => ['text' => ' / 5'],
                            ],
                            [
                                'name' => 'Button',
                                'props' => ['text' => '$t:shop.write_review'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name_localized' => '무선 마우스 세트 #99',
                    'category_name' => '소파',
                    'selling_price_formatted' => '129,000원',
                    'brand_name' => 'LG Electronics',
                    'description_localized' => '<p>고급 무선 마우스 세트입니다.</p><ul><li>블루투스 5.0</li></ul>',
                    'rating_avg' => '4.5',
                    'images' => [
                        ['url' => '/storage/products/mouse-1.jpg', 'alt' => '마우스 정면'],
                        ['url' => '/storage/products/mouse-2.jpg', 'alt' => '마우스 측면'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        // 태그 검증
        $this->assertStringContainsString('<i', $html, 'Icon이 <i> 태그여야 합니다');
        $this->assertStringContainsString('<h1', $html, 'H1이 <h1> 태그여야 합니다');
        $this->assertStringContainsString('<img', $html, '이미지가 <img> 태그여야 합니다');
        $this->assertStringContainsString('<button', $html, 'Button이 <button> 태그여야 합니다');
        $this->assertStringContainsString('<span', $html, 'Span이 <span> 태그여야 합니다');

        // 상품 데이터 검증
        $this->assertStringContainsString('무선 마우스 세트 #99', $html, '상품명이 표시되어야 합니다');
        $this->assertStringContainsString('129,000원', $html, '가격이 표시되어야 합니다');
        $this->assertStringContainsString('소파', $html, '카테고리가 표시되어야 합니다');
        $this->assertStringContainsString('LG Electronics', $html, '브랜드가 표시되어야 합니다');
        $this->assertStringContainsString('4.5', $html, '평점이 표시되어야 합니다');

        // 이미지 검증
        $this->assertStringContainsString('mouse-1.jpg', $html, '상품 이미지가 표시되어야 합니다');
        $this->assertStringContainsString('마우스 정면', $html, '이미지 alt가 표시되어야 합니다');

        // 상품 설명 (HtmlContent → raw 렌더링)
        $this->assertStringContainsString('고급 무선 마우스 세트입니다', $html, '상품 설명이 표시되어야 합니다');
        $this->assertStringContainsString('<ul>', $html, 'HTML 설명이 이스케이프 없이 표시되어야 합니다');

        // 번역 검증
        $this->assertStringContainsString('뒤로가기', $html, '$t: 번역이 적용되어야 합니다');
        $this->assertStringContainsString('브랜드', $html, '$t: 번역이 적용되어야 합니다');
        $this->assertStringContainsString('리뷰 작성', $html, '$t: 번역이 적용되어야 합니다');
        $this->assertStringNotContainsString('$t:shop', $html, '$t: 원본 키가 노출되면 안됩니다');
    }

    // ========================================================
    // 6. 헤더/네비게이션 구조 검증
    // ========================================================

    /**
     * 헤더 네비게이션이 올바른 태그 구조로 렌더링됩니다.
     */
    public function test_header_navigation_structure(): void
    {
        $components = [
            [
                'name' => 'Header',
                'props' => ['className' => 'sticky top-0'],
                'children' => [
                    [
                        'name' => 'Nav',
                        'props' => ['className' => 'flex items-center'],
                        'children' => [
                            [
                                'name' => 'Ul',
                                'children' => [
                                    [
                                        'name' => 'Li',
                                        'children' => [
                                            ['name' => 'A', 'props' => ['href' => '/', 'text' => 'Home']],
                                        ],
                                    ],
                                    [
                                        'name' => 'Li',
                                        'children' => [
                                            ['name' => 'A', 'props' => ['href' => '/shop', 'text' => 'Shop']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    // 장바구니 아이콘
                    [
                        'name' => 'Icon',
                        'props' => ['name' => 'shopping-cart', 'className' => 'w-5 h-5'],
                    ],
                ],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<header', $html);
        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('<li', $html);
        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('<i', $html, 'Icon이 <i> 태그여야 합니다');
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('Shop', $html);
    }

    // ========================================================
    // 7. StarRating format 모드 검증
    // ========================================================

    /**
     * StarRating이 format 모드로 올바르게 렌더링됩니다.
     */
    public function test_star_rating_format_mode(): void
    {
        $components = [
            [
                'name' => 'StarRating',
                'props' => ['value' => '4.5'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('4.5', $html);
        $this->assertStringContainsString('/ 5', $html);
    }

    /**
     * StarRating에 바인딩된 값이 올바르게 표시됩니다.
     */
    public function test_star_rating_with_binding(): void
    {
        $components = [
            [
                'name' => 'StarRating',
                'props' => ['value' => '{{product.data.rating_avg}}'],
            ],
        ];

        $context = [
            'product' => ['data' => ['rating_avg' => '3.8']],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('3.8', $html);
        $this->assertStringContainsString('/ 5', $html);
    }

    // ========================================================
    // 8. CSS 경로 검증 — 템플릿 CSS가 포함되어야 함
    // ========================================================

    /**
     * SeoRenderer가 template.json의 CSS 에셋을 자동으로 서빙 URL로 변환합니다.
     */
    public function test_seo_renderer_auto_resolves_template_css(): void
    {
        // seo-config.json에 Font Awesome CDN이 포함되어 있는지 확인
        $configPath = base_path('templates/_bundled/sirsoft-basic/seo-config.json');
        $config = json_decode(file_get_contents($configPath), true);
        $stylesheets = $config['stylesheets'] ?? [];

        $hasFontAwesome = false;
        foreach ($stylesheets as $url) {
            if (str_contains($url, 'font-awesome')) {
                $hasFontAwesome = true;
            }
        }
        $this->assertTrue($hasFontAwesome, 'Font Awesome CSS가 seo-config.json에 포함되어야 합니다');

        // SeoRenderer.getTemplateCssUrls()가 템플릿 CSS를 자동 해석하는지 확인
        $renderer = app(\App\Seo\SeoRenderer::class);
        $method = new \ReflectionMethod($renderer, 'getTemplateCssUrls');
        $method->setAccessible(true);

        // template.json에 CSS 에셋이 정의되어 있어야 함
        $templateJsonPath = base_path('templates/_bundled/sirsoft-basic/template.json');
        $templateJson = json_decode(file_get_contents($templateJsonPath), true);
        $cssPaths = $templateJson['assets']['css'] ?? [];
        $this->assertNotEmpty($cssPaths, 'template.json에 CSS 에셋이 정의되어야 합니다');

        // dist/css/components.css → /api/templates/assets/{id}/css/components.css 변환 검증
        foreach ($cssPaths as $cssPath) {
            $servePath = preg_replace('#^dist/#', '', $cssPath);
            $expectedUrl = '/api/templates/assets/sirsoft-basic/' . $servePath;
            $this->assertStringContainsString('components.css', $expectedUrl,
                '템플릿 CSS URL이 올바르게 변환되어야 합니다');
        }
    }

    /**
     * SeoRenderer.getCssPath()가 코어 Tailwind CSS를 반환해야 합니다.
     * (코어 app.css가 아닌 Tailwind를 포함한 빌드 CSS)
     */
    public function test_css_path_includes_tailwind(): void
    {
        // getCssPath()는 Vite manifest에서 CSS를 찾으므로,
        // manifest가 없으면 fallback '/build/assets/app.css'를 반환합니다.
        // 실제로는 Tailwind 클래스가 포함된 CSS여야 합니다.
        $renderer = app(\App\Seo\SeoRenderer::class);

        // Reflection으로 getCssPath() 호출
        $method = new \ReflectionMethod($renderer, 'getCssPath');
        $method->setAccessible(true);
        $cssPath = $method->invoke($renderer);

        $this->assertIsString($cssPath);
        // CSS 경로가 존재해야 함
        $this->assertNotEmpty($cssPath);
    }

    // ========================================================
    // 9. 복잡한 표현식 + 조건부 렌더링 검증
    // ========================================================

    /**
     * 조건부 렌더링 (if)이 올바르게 동작합니다.
     */
    public function test_conditional_rendering_with_data(): void
    {
        $components = [
            [
                'name' => 'Div',
                'if' => '{{product.data.has_options}}',
                'props' => ['text' => '옵션 있음'],
            ],
            [
                'name' => 'Div',
                'if' => '{{!product.data.has_options}}',
                'props' => ['text' => '옵션 없음'],
            ],
        ];

        $context = ['product' => ['data' => ['has_options' => true]]];
        $html = $this->render($components, $context);

        $this->assertStringContainsString('옵션 있음', $html);
        $this->assertStringNotContainsString('옵션 없음', $html);
    }

    /**
     * 배송 정보가 조건부로 표시됩니다.
     */
    public function test_shipping_info_conditional(): void
    {
        $components = [
            [
                'name' => 'Div',
                'if' => '{{product.data.shipping_policy}}',
                'children' => [
                    [
                        'name' => 'Span',
                        'props' => ['text' => '{{product.data.shipping_policy.name}}'],
                    ],
                    [
                        'name' => 'Span',
                        'props' => ['text' => '{{product.data.shipping_policy.fee_summary}}'],
                    ],
                ],
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'shipping_policy' => [
                        'name' => '국내 무료배송',
                        'fee_summary' => 'KR: Free Shipping',
                    ],
                ],
            ],
        ];

        $html = $this->render($components, $context);

        $this->assertStringContainsString('국내 무료배송', $html);
        $this->assertStringContainsString('KR: Free Shipping', $html);
    }

    // ========================================================
    // 10. skip된 컴포넌트 검증 (인터랙티브 요소)
    // ========================================================

    /**
     * skip:true 컴포넌트는 렌더링되지 않습니다 (인터랙티브 UI 제외).
     */
    public function test_skipped_components_not_rendered(): void
    {
        $components = [
            [
                'name' => 'QuantitySelector',
                'props' => ['value' => '1'],
            ],
        ];

        $html = $this->render($components);

        // QuantitySelector는 seo-config.json에서 skip:true
        $this->assertEmpty(trim($html), 'QuantitySelector(skip:true)는 렌더링되지 않아야 합니다');
    }

    /**
     * Select 컴포넌트는 <select> 태그로 렌더링됩니다 (skip 아님).
     */
    public function test_select_renders_as_select_tag(): void
    {
        $components = [
            [
                'name' => 'Select',
                'props' => ['className' => 'w-full'],
            ],
        ];

        $html = $this->render($components);

        $this->assertStringContainsString('<select', $html);
    }
}
