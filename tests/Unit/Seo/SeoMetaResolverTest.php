<?php

namespace Tests\Unit\Seo;

use App\Seo\ExpressionEvaluator;
use App\Seo\SeoMetaResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SeoMetaResolver 단위 테스트
 *
 * 3계층 캐스케이드(리소스 → 모듈 → 코어) SEO 메타 해석 기능을 테스트합니다.
 * g7_core_settings, g7_module_settings는 Config 파사드 기반이므로 Config::set()으로 모킹합니다.
 */
class SeoMetaResolverTest extends TestCase
{
    private SeoMetaResolver $resolver;

    /**
     * 테스트 초기화 - SeoMetaResolver 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SeoMetaResolver(new ExpressionEvaluator);

        // 코어 기본 설정 초기화
        Config::set('g7_settings.core.seo.meta_title_suffix', ' | 그누보드7 쇼핑몰');
        Config::set('g7_settings.core.seo.meta_description', '코어 기본 설명');
        Config::set('g7_settings.core.seo.meta_keywords', '코어,키워드');
        Config::set('g7_settings.core.seo.google_analytics_id', 'GA-12345');
        Config::set('g7_settings.core.seo.google_site_verification', '');
        Config::set('g7_settings.core.seo.naver_site_verification', '');
        Config::set('g7_settings.core.general.site_name', '그누보드7 쇼핑몰');
    }

    /**
     * 상품에 meta_title이 있으면 상품의 meta_title + 코어 suffix를 사용합니다.
     */
    public function test_product_with_meta_title_uses_product_meta_title_and_suffix(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'name' => '에어맥스',
                    'meta_title' => '에어맥스 한정판',
                    'meta_description' => '나이키 에어맥스 한정판 상품',
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('에어맥스 한정판', $result['title']);
        $this->assertSame(' | 그누보드7 쇼핑몰', $result['titleSuffix']);
    }

    /**
     * 상품에 meta_title이 없고 모듈 템플릿이 있으면 모듈 템플릿 + suffix를 사용합니다.
     */
    public function test_product_without_meta_title_uses_module_template_and_suffix(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} - {commerce_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '테스트몰');

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => '에어맥스',
                    'short_description' => '나이키 에어맥스',
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('에어맥스 - 테스트몰', $result['title']);
        $this->assertSame(' | 그누보드7 쇼핑몰', $result['titleSuffix']);
    }

    /**
     * 상품에 meta_title이 없고 모듈 템플릿도 없으면 코어 fallback + suffix를 사용합니다.
     */
    public function test_product_without_meta_title_no_module_template_uses_core_fallback(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', null);

        $context = [
            'product' => [
                'data' => [
                    'name' => '에어맥스',
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, 'sirsoft-ecommerce', null, []);

        // 타이틀은 빈 문자열 (resolveLayoutMetaTitle도 빈 seoConfig이므로 빈 결과)
        // description은 코어 fallback
        $this->assertSame('코어 기본 설명', $result['description']);
        $this->assertSame(' | 그누보드7 쇼핑몰', $result['titleSuffix']);
    }

    /**
     * 카테고리에 meta_title이 있으면 카테고리의 meta_title + suffix를 사용합니다.
     */
    public function test_category_with_meta_title_uses_category_meta_title_and_suffix(): void
    {
        $context = [
            'category' => [
                'data' => [
                    'name' => '신발',
                    'meta_title' => '신발 카테고리',
                    'meta_description' => '다양한 신발 모음',
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('신발 카테고리', $result['title']);
        $this->assertSame(' | 그누보드7 쇼핑몰', $result['titleSuffix']);
    }

    /**
     * 페이지에 seo_meta.title이 있으면 페이지의 seo_meta.title + suffix를 사용합니다.
     */
    public function test_page_with_seo_meta_title_uses_page_seo_meta_title_and_suffix(): void
    {
        $context = [
            'page' => [
                'data' => [
                    'title' => '회사 소개',
                    'seo_meta' => [
                        'title' => '회사 소개 - SEO 타이틀',
                        'description' => '회사 소개 SEO 설명',
                    ],
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, null, null, []);

        $this->assertSame('회사 소개 - SEO 타이틀', $result['title']);
        $this->assertSame(' | 그누보드7 쇼핑몰', $result['titleSuffix']);
    }

    /**
     * 페이지에 seo_meta.title이 없으면 코어 fallback + suffix를 사용합니다.
     */
    public function test_page_without_seo_meta_title_uses_core_fallback(): void
    {
        $context = [
            'page' => [
                'data' => [
                    'title' => '회사 소개',
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, null, null, []);

        // 모듈 식별자 null이므로 모듈 템플릿 skip → 코어 fallback
        $this->assertSame('코어 기본 설명', $result['description']);
        $this->assertSame(' | 그누보드7 쇼핑몰', $result['titleSuffix']);
    }

    /**
     * 코어 title suffix가 비어 있으면 suffix가 없습니다.
     */
    public function test_core_suffix_empty_no_suffix(): void
    {
        Config::set('g7_settings.core.seo.meta_title_suffix', '');

        $context = [
            'product' => [
                'data' => [
                    'meta_title' => '테스트 상품',
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('테스트 상품', $result['title']);
        $this->assertSame('', $result['titleSuffix']);
    }

    /**
     * 모듈 템플릿 변수 {product_name}이 실제 상품명으로 치환됩니다.
     */
    public function test_template_variable_substitution(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} 구매하기');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => '나이키 에어포스',
                    'short_description' => '나이키 에어포스 1',
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('나이키 에어포스 구매하기', $result['title']);
    }

    /**
     * 상품에 meta_keywords가 있으면 상품의 키워드를 사용합니다.
     */
    public function test_keywords_from_product(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'meta_keywords' => '나이키,에어맥스,운동화',
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('나이키,에어맥스,운동화', $result['keywords']);
    }

    /**
     * 상품 키워드가 배열인 경우 쉼표로 결합합니다.
     */
    public function test_keywords_array_from_product_joined(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'meta_keywords' => ['나이키', '에어맥스', '운동화'],
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('나이키,에어맥스,운동화', $result['keywords']);
    }

    /**
     * 상품 키워드가 없으면 코어 키워드를 사용합니다.
     */
    public function test_keywords_falls_back_to_core(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'name' => '테스트 상품',
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('코어,키워드', $result['keywords']);
    }

    /**
     * Google Analytics ID가 반환됩니다.
     */
    public function test_google_analytics_id_returned(): void
    {
        $result = $this->resolver->resolve([], [], null, null, []);

        $this->assertSame('GA-12345', $result['googleAnalyticsId']);
    }

    /**
     * OG 태그에 이미지가 있으면 og:image가 포함됩니다.
     */
    public function test_og_tags_with_image_includes_og_image(): void
    {
        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => '{{product.data.name}}',
                'description' => '{{product.data.short_description}}',
                'image' => '{{product.data.image_url}}',
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => '에어맥스',
                    'meta_title' => '에어맥스',
                    'short_description' => '나이키 에어맥스',
                    'image_url' => 'https://example.com/images/airmax.jpg',
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertStringContainsString('og:image', $result['ogTags']);
        $this->assertStringContainsString('https://example.com/images/airmax.jpg', $result['ogTags']);
        $this->assertStringContainsString('og:type', $result['ogTags']);
        $this->assertStringContainsString('product', $result['ogTags']);
    }

    /**
     * OG 태그에 이미지가 없으면 og:image가 포함되지 않습니다.
     */
    public function test_og_tags_without_image_excludes_og_image(): void
    {
        $seoConfig = [
            'og' => [
                'type' => 'website',
                'title' => '사이트 제목',
                'description' => '사이트 설명',
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, null, []);

        $this->assertStringNotContainsString('og:image', $result['ogTags']);
        $this->assertStringContainsString('og:type', $result['ogTags']);
    }

    /**
     * 구조화 데이터(JSON-LD)에 @context가 포함되어 생성됩니다.
     */
    public function test_structured_data_json_ld_generated_with_context(): void
    {
        $seoConfig = [
            'structured_data' => [
                '@type' => 'Product',
                'name' => '{{product.data.name}}',
                'description' => '{{product.data.short_description}}',
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => '에어맥스',
                    'short_description' => '나이키 에어맥스',
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertNotNull($result['jsonLd']);
        $jsonLd = json_decode($result['jsonLd'], true);
        $this->assertSame('https://schema.org', $jsonLd['@context']);
        $this->assertSame('Product', $jsonLd['@type']);
        $this->assertSame('에어맥스', $jsonLd['name']);
        $this->assertSame('나이키 에어맥스', $jsonLd['description']);
    }

    /**
     * 구조화 데이터가 없으면 jsonLd는 null입니다.
     */
    public function test_no_structured_data_returns_null_json_ld(): void
    {
        $result = $this->resolver->resolve([], [], null, null, []);

        $this->assertNull($result['jsonLd']);
    }

    /**
     * 모듈 SEO가 비활성화되면 모듈 템플릿을 사용하지 않습니다.
     */
    public function test_module_template_resolved_regardless_of_inline_enabled_flag(): void
    {
        // 모듈 SEO 활성화/비활성화는 SeoRenderer::isModuleSeoEnabled()에서
        // toggle_setting 기반으로 판단 (SeoMetaResolver는 관여하지 않음)
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} 상품');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', false);

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => '에어맥스',
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        // SeoMetaResolver는 enabled 체크 없이 템플릿 해석 수행
        // (활성화 체크는 SeoRenderer 단에서 toggle_setting 기반으로 수행)
        $this->assertSame('에어맥스 상품', $result['title']);
    }

    /**
     * 페이지의 seo_meta.keywords가 있으면 해당 키워드를 사용합니다.
     */
    public function test_page_seo_meta_keywords_used(): void
    {
        $context = [
            'page' => [
                'data' => [
                    'seo_meta' => [
                        'keywords' => '소개,회사,정보',
                    ],
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, null, null, []);

        $this->assertSame('소개,회사,정보', $result['keywords']);
    }

    /**
     * 페이지의 seo_meta.description이 있으면 해당 설명을 사용합니다.
     */
    public function test_page_seo_meta_description_used(): void
    {
        $context = [
            'page' => [
                'data' => [
                    'seo_meta' => [
                        'title' => '소개 페이지',
                        'description' => '회사 소개 페이지 설명입니다',
                    ],
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, null, null, []);

        $this->assertSame('회사 소개 페이지 설명입니다', $result['description']);
    }

    /**
     * OG 설정이 비어있어도 코어 설정 기반 기본 og:type/og:site_name 등이 항상 출력됩니다.
     *
     * (이전 회귀: og 미선언 시 빈 문자열 반환 — Slack/Facebook 미리보기 무시)
     * 신규 동작: og:type=website 기본값 + 코어 설정의 site_name fallback 으로 최소 보장.
     */
    public function test_empty_og_config_emits_default_type_and_site_name(): void
    {
        $result = $this->resolver->resolve([], [], null, null, []);

        // og 배열은 항상 채워짐
        $this->assertSame('website', $result['og']['type']);
        $this->assertSame('그누보드7 쇼핑몰', $result['og']['site_name']);
        // ogTags HTML 도 최소 og:type 은 포함
        $this->assertStringContainsString('og:type" content="website"', $result['ogTags']);
        $this->assertStringContainsString('og:site_name" content="그누보드7 쇼핑몰"', $result['ogTags']);
    }

    /**
     * 코어 설정값이 null(JSON에 null 저장)일 때도 정상 동작합니다.
     */
    public function test_null_core_settings_do_not_cause_type_error(): void
    {
        Config::set('g7_settings.core.seo.meta_title_suffix', null);
        Config::set('g7_settings.core.seo.meta_description', null);
        Config::set('g7_settings.core.seo.meta_keywords', null);

        $seoConfig = [
            'og' => [
                'type' => 'website',
                'title' => '사이트',
                'description' => '설명',
            ],
        ];

        // OG 태그의 string 타입힌트에 null이 전달되면 TypeError 발생
        // 이 테스트는 null 방어 로직이 정상 동작하는지 검증
        $result = $this->resolver->resolve($seoConfig, [], null, null, []);

        $this->assertIsString($result['title']);
        $this->assertIsString($result['description']);
        $this->assertIsString($result['ogTags']);
    }

    /**
     * OG 태그의 HTML이 제거되고 순수 텍스트만 남습니다.
     */
    public function test_og_tags_strip_html_from_description(): void
    {
        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => '{{product.data.name}}',
                'description' => '{{product.data.description}}',
                'image' => '{{product.data.thumbnail_url}}',
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => '무선 마우스',
                    'meta_title' => '무선 마우스',
                    'description' => '<p>인체공학 디자인의 <strong>무선 마우스</strong>입니다.</p>',
                    'thumbnail_url' => 'https://example.com/mouse.jpg',
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        // OG description에서 HTML 태그가 제거되어야 함
        $this->assertStringNotContainsString('<p>', $result['ogTags']);
        $this->assertStringNotContainsString('<strong>', $result['ogTags']);
        $this->assertStringContainsString('인체공학 디자인의 무선 마우스입니다.', $result['ogTags']);
    }

    /**
     * description이 비어있을 때 레이아웃 og.description에서 fallback합니다.
     */
    public function test_description_fallback_from_layout_og_description(): void
    {
        Config::set('g7_settings.core.seo.meta_description', '');

        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => '{{product.data.name}}',
                'description' => '{{product.data.description}}',
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => '무선 마우스',
                    'description' => '고급 무선 마우스',
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, null, null, []);

        // meta_description 없고 모듈 템플릿 없고 코어 설명 비어있어도
        // 레이아웃 og.description에서 fallback
        $this->assertSame('고급 무선 마우스', $result['description']);
    }

    /**
     * 모듈 템플릿 변수가 다국어 객체일 때 현재 로케일 값으로 치환됩니다.
     */
    public function test_template_variable_substitution_with_localized_value(): void
    {
        app()->setLocale('ko');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} 구매하기');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => ['ko' => '무선 마우스', 'en' => 'Wireless Mouse'],
                    'short_description' => ['ko' => '고급 마우스', 'en' => 'Premium mouse'],
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('무선 마우스 구매하기', $result['title']);
    }

    /**
     * 구조화 데이터의 description 필드에서 HTML이 제거됩니다.
     */
    public function test_structured_data_description_strips_html(): void
    {
        $seoConfig = [
            'structured_data' => [
                '@type' => 'Product',
                'name' => '{{product.data.name}}',
                'description' => '{{product.data.description}}',
            ],
        ];

        $context = [
            'product' => [
                'data' => [
                    'name' => '무선 마우스',
                    'description' => '<p>인체공학 디자인의 <strong>무선 마우스</strong>입니다.</p>',
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $jsonLd = json_decode($result['jsonLd'], true);
        $this->assertSame('무선 마우스', $jsonLd['name']);
        $this->assertStringNotContainsString('<p>', $jsonLd['description']);
        $this->assertStringNotContainsString('<strong>', $jsonLd['description']);
        $this->assertSame('인체공학 디자인의 무선 마우스입니다.', $jsonLd['description']);
    }

    /**
     * 결과 배열에 모든 필수 키가 포함됩니다.
     */
    public function test_result_contains_all_required_keys(): void
    {
        $result = $this->resolver->resolve([], [], null, null, []);

        $expectedKeys = [
            'title',
            'titleSuffix',
            'description',
            'keywords',
            'ogTags',
            'jsonLd',
            'googleAnalyticsId',
            'googleVerification',
            'naverVerification',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "결과에 '{$key}' 키가 존재해야 합니다");
        }
    }

    // =========================================================================
    // vars 해석 + page_type 선언적 설정 테스트 (10개)
    // =========================================================================

    /**
     * vars의 {{expression}} 바인딩이 context에서 해석됩니다.
     */
    public function test_resolve_vars_expression_binding(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} 상품');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
            ],
        ];

        $context = [
            'product' => [
                'data' => ['name' => '운동화'],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('운동화 상품', $result['title']);
    }

    /**
     * vars의 $module_settings: 접두사가 모듈 설정에서 값을 가져옵니다.
     */
    public function test_resolve_vars_module_settings(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} - {commerce_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '마이샵');

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];

        $context = [
            'product' => [
                'data' => ['name' => '가방'],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('가방 - 마이샵', $result['title']);
    }

    /**
     * vars의 $core_settings: 접두사가 코어 설정에서 값을 가져옵니다.
     */
    public function test_resolve_vars_core_settings(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} | {site_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
                'site_name' => '$core_settings:general.site_name',
            ],
        ];

        $context = [
            'product' => [
                'data' => ['name' => '시계'],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('시계 | 그누보드7 쇼핑몰', $result['title']);
    }

    /**
     * vars의 $query: 접두사가 쿼리 파라미터에서 값을 가져옵니다.
     */
    public function test_resolve_vars_query_param(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_search_title', '{keyword_name} 검색결과');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_search', true);

        // 쿼리 파라미터 시뮬레이션
        request()->merge(['q' => '나이키']);

        $seoConfig = [
            'page_type' => 'search',
            'vars' => [
                'keyword_name' => '$query:q',
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], 'sirsoft-ecommerce', null, []);

        $this->assertSame('나이키 검색결과', $result['title']);
    }

    /**
     * vars 선언으로 치환된 결과가 모듈 설정 title 템플릿에 적용됩니다.
     */
    public function test_substitute_vars_in_title_template(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_category_title', '{category_name} | {commerce_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_category', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '패션몰');

        $seoConfig = [
            'page_type' => 'category',
            'vars' => [
                'category_name' => "{{category.data.name ?? ''}}",
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];

        $context = [
            'category' => [
                'data' => ['name' => '신발'],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('신발 | 패션몰', $result['title']);
    }

    /**
     * page_type이 레이아웃에서 제공되면 해당 모듈 설정 키를 사용합니다.
     */
    public function test_page_type_from_layout(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} 상세');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
            ],
        ];

        $context = [
            'product' => [
                'data' => ['name' => '모자'],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        // page_type이 'product'이므로 meta_product_title 키 사용
        $this->assertSame('모자 상세', $result['title']);
    }

    /**
     * vars 미선언 시 치환 없이 템플릿 원본이 반환됩니다.
     */
    public function test_no_vars_no_substitution(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} 구매');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);

        $seoConfig = [
            'page_type' => 'product',
            // vars 미선언
        ];

        $context = [
            'product' => [
                'data' => ['name' => '가방'],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        // vars 없으므로 치환 안됨 → 템플릿 원본 반환
        $this->assertSame('{product_name} 구매', $result['title']);
    }

    /**
     * page_type 미선언 시 모듈 템플릿을 건너뜁니다.
     */
    public function test_no_page_type_skips_module_template(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} 상품');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);

        $seoConfig = [
            // page_type 미선언
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
            ],
        ];

        $context = [
            'product' => [
                'data' => ['name' => '운동화'],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        // page_type 없으므로 Tier 2 스킵 → 코어 fallback
        $this->assertNotSame('운동화 상품', $result['title']);
        $this->assertSame('코어 기본 설명', $result['description']);
    }

    /**
     * vars의 표현식에서 없는 context 경로를 참조하면 빈 문자열을 반환합니다.
     */
    public function test_vars_missing_context_returns_empty(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} 상품');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{nonexistent.data.name ?? ''}}",
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], 'sirsoft-ecommerce', null, []);

        // nonexistent 경로 → 빈 문자열 → '상품' (선두 공백 자동 trim — substituteVars cleanup)
        $this->assertSame('상품', $result['title']);
    }

    /**
     * vars의 다국어 객체가 현재 로케일 값으로 해석됩니다.
     */
    public function test_localized_value_in_expression_var(): void
    {
        app()->setLocale('ko');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_category_title', '{category_name} 카테고리');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_category', true);

        $seoConfig = [
            'page_type' => 'category',
            'vars' => [
                'category_name' => "{{category.data.name ?? ''}}",
            ],
        ];

        $context = [
            'category' => [
                'data' => [
                    'name' => ['ko' => '전자기기', 'en' => 'Electronics'],
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('전자기기 카테고리', $result['title']);
    }

    // =========================================================================
    // 플러그인 SEO 지원 테스트 (4개)
    // =========================================================================

    /**
     * 플러그인 Tier 2 타이틀 템플릿이 해석됩니다.
     */
    public function test_resolve_title_with_plugin_template(): void
    {
        Config::set('g7_settings.plugins.sirsoft-payment.seo.meta_checkout_title', '{page_name} - {payment_name}');
        Config::set('g7_settings.plugins.sirsoft-payment.basic.payment_name', '간편결제');

        $seoConfig = [
            'page_type' => 'checkout',
            'vars' => [
                'page_name' => '결제하기',
                'payment_name' => '$plugin_settings:basic.payment_name',
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, 'sirsoft-payment', []);

        $this->assertSame('결제하기 - 간편결제', $result['title']);
    }

    /**
     * 플러그인 Tier 2 설명 템플릿이 해석됩니다.
     */
    public function test_resolve_description_with_plugin_template(): void
    {
        Config::set('g7_settings.plugins.sirsoft-payment.seo.meta_checkout_description', '{page_name} 페이지');

        $seoConfig = [
            'page_type' => 'checkout',
            'vars' => [
                'page_name' => '결제하기',
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, 'sirsoft-payment', []);

        $this->assertSame('결제하기 페이지', $result['description']);
    }

    /**
     * $plugin_settings: 변수 표현식이 해석됩니다.
     */
    public function test_resolve_var_expression_with_plugin_settings(): void
    {
        Config::set('g7_settings.plugins.sirsoft-payment.seo.meta_info_title', '{gateway_name} 결제정보');
        Config::set('g7_settings.plugins.sirsoft-payment.gateway.name', '카카오페이');

        $seoConfig = [
            'page_type' => 'info',
            'vars' => [
                'gateway_name' => '$plugin_settings:gateway.name',
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, 'sirsoft-payment', []);

        $this->assertSame('카카오페이 결제정보', $result['title']);
    }

    /**
     * moduleIdentifier=null, pluginIdentifier=값일 때 플러그인 경로로 해석됩니다.
     */
    public function test_resolve_with_plugin_identifier_null_module(): void
    {
        Config::set('g7_settings.plugins.sirsoft-payment.seo.meta_checkout_title', '{page_name}');

        $seoConfig = [
            'page_type' => 'checkout',
            'vars' => [
                'page_name' => '주문결제',
            ],
        ];

        // moduleIdentifier=null, pluginIdentifier='sirsoft-payment'
        $result = $this->resolver->resolve($seoConfig, [], null, 'sirsoft-payment', []);

        $this->assertSame('주문결제', $result['title']);
        // 코어 suffix 적용 확인
        $this->assertSame(' | 그누보드7 쇼핑몰', $result['titleSuffix']);
    }

    // =========================================================================
    // 명시적 모듈 ID ($module_settings:MODULE_ID:key) 테스트
    // =========================================================================

    /**
     * 명시적 모듈 ID가 vars에서 올바르게 해석됩니다.
     * moduleIdentifier가 제공되면 Tier 2 템플릿에서 명시적 ID vars를 사용합니다.
     */
    public function test_resolve_vars_explicit_module_id_when_module_null(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_index_title', '{commerce_name} 쇼핑몰');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_index', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '테스트몰');

        $seoConfig = [
            'page_type' => 'index',
            'vars' => [
                // 명시적 모듈 ID 포맷: $module_settings:MODULE_ID:key
                'commerce_name' => '$module_settings:sirsoft-ecommerce:basic_info.shop_name',
            ],
        ];

        // moduleIdentifier 제공 — Tier 2 템플릿 해석 + 명시적 모듈 ID vars 사용
        $result = $this->resolver->resolve($seoConfig, [], 'sirsoft-ecommerce', null, []);

        $this->assertSame('테스트몰 쇼핑몰', $result['title']);
    }

    /**
     * 명시적 모듈 ID 없이 기존 moduleIdentifier 컨텍스트로 해석됩니다 (하위 호환).
     */
    public function test_resolve_vars_context_module_id_backward_compatible(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{product_name} - {commerce_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '패션몰');

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
                // 명시적 ID 없음 → moduleIdentifier='sirsoft-ecommerce' 사용
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];

        $context = [
            'product' => ['data' => ['name' => '운동화']],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('운동화 - 패션몰', $result['title']);
    }

    // =========================================================================
    // 구조화 데이터 빈 하위 객체 제거 테스트
    // =========================================================================

    /**
     * aggregateRating의 필수 값이 모두 비어있으면 JSON-LD에서 제거됩니다.
     */
    public function test_structured_data_removes_empty_aggregate_rating(): void
    {
        $seoConfig = [
            'structured_data' => [
                '@type' => 'Product',
                'name' => '{{product.data.name}}',
                'aggregateRating' => [
                    '@type' => 'AggregateRating',
                    'ratingValue' => '{{reviews.data.rating_stats.avg ?? \'\'}}',
                    'reviewCount' => '{{reviews.data.reviews.total ?? \'\'}}',
                    'bestRating' => '5',
                    'worstRating' => '1',
                ],
            ],
        ];

        // reviews 데이터가 없는 상황 (리뷰 0건)
        $context = [
            'product' => ['data' => ['name' => '에어맥스']],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $jsonLd = json_decode($result['jsonLd'], true);
        $this->assertSame('에어맥스', $jsonLd['name']);
        // aggregateRating은 ratingValue, reviewCount가 빈 문자열이므로 제거됨
        $this->assertArrayNotHasKey('aggregateRating', $jsonLd);
    }

    /**
     * aggregateRating에 유효한 값이 있으면 JSON-LD에 포함됩니다.
     */
    public function test_structured_data_keeps_valid_aggregate_rating(): void
    {
        $seoConfig = [
            'structured_data' => [
                '@type' => 'Product',
                'name' => '{{product.data.name}}',
                'aggregateRating' => [
                    '@type' => 'AggregateRating',
                    'ratingValue' => '{{reviews.data.rating_stats.avg}}',
                    'reviewCount' => '{{reviews.data.reviews.total}}',
                    'bestRating' => '5',
                    'worstRating' => '1',
                ],
            ],
        ];

        $context = [
            'product' => ['data' => ['name' => '에어맥스']],
            'reviews' => [
                'data' => [
                    'rating_stats' => ['avg' => '4.5'],
                    'reviews' => ['total' => '32'],
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $jsonLd = json_decode($result['jsonLd'], true);
        $this->assertSame('에어맥스', $jsonLd['name']);
        $this->assertArrayHasKey('aggregateRating', $jsonLd);
        $this->assertSame('AggregateRating', $jsonLd['aggregateRating']['@type']);
        $this->assertSame('4.5', $jsonLd['aggregateRating']['ratingValue']);
        $this->assertSame('32', $jsonLd['aggregateRating']['reviewCount']);
    }

    /**
     * @type이 없는 일반 하위 객체는 빈 값이어도 제거되지 않습니다.
     */
    public function test_structured_data_keeps_non_typed_empty_objects(): void
    {
        $seoConfig = [
            'structured_data' => [
                '@type' => 'Product',
                'name' => '{{product.data.name}}',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '{{product.data.selling_price}}',
                    'priceCurrency' => 'KRW',
                ],
            ],
        ];

        $context = [
            'product' => ['data' => ['name' => '에어맥스', 'selling_price' => '129000']],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $jsonLd = json_decode($result['jsonLd'], true);
        $this->assertArrayHasKey('offers', $jsonLd);
        $this->assertSame('129000', $jsonLd['offers']['price']);
    }

    // =========================================================================
    // _seo context 참조 테스트
    // =========================================================================

    /**
     * _seo.product.title이 context에 있으면 Tier 2로 사용됩니다.
     */
    public function test_seo_context_title_used_as_tier2(): void
    {
        $seoConfig = [
            'enabled' => true,
            'page_type' => 'product',
        ];

        $context = [
            '_seo' => [
                'product' => [
                    'title' => '테스트쇼핑몰 - 에어맥스',
                    'description' => '에어맥스 설명',
                ],
            ],
            'product' => ['data' => ['name' => '에어맥스']],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, null, null, []);

        $this->assertEquals('테스트쇼핑몰 - 에어맥스', $result['title']);
        $this->assertEquals('에어맥스 설명', $result['description']);
    }

    /**
     * Tier 3 (resource meta_title)가 _seo context보다 우선합니다.
     */
    public function test_resource_meta_title_overrides_seo_context(): void
    {
        $seoConfig = [
            'enabled' => true,
            'page_type' => 'product',
        ];

        $context = [
            '_seo' => [
                'product' => [
                    'title' => '테스트쇼핑몰 - 에어맥스',
                    'description' => '쇼핑몰 설명',
                ],
            ],
            'product' => ['data' => [
                'name' => '에어맥스',
                'meta_title' => '커스텀 SEO 타이틀',
                'meta_description' => '커스텀 SEO 설명',
            ]],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, null, null, []);

        // Tier 3가 Tier 2(_seo)보다 우선
        $this->assertEquals('커스텀 SEO 타이틀', $result['title']);
        $this->assertEquals('커스텀 SEO 설명', $result['description']);
    }

    /**
     * _seo context 없고 moduleIdentifier도 null이면 빈 타이틀로 fallback합니다.
     */
    public function test_no_seo_context_and_no_module_falls_through(): void
    {
        $seoConfig = [
            'enabled' => true,
            'page_type' => 'product',
        ];

        $context = [
            'product' => ['data' => ['name' => '에어맥스']],
        ];

        $result = $this->resolver->resolve($seoConfig, $context, null, null, []);

        // _seo도 없고 moduleIdentifier도 null → Tier 2 스킵 → 빈 문자열
        $this->assertEquals('', $result['title']);
    }

    /**
     * resolve()는 og 데이터를 배열로 반환합니다 (HTML 렌더 지연 패턴).
     */
    public function test_resolve_returns_og_data_as_array(): void
    {
        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => '{{product.data.name}}',
                'image' => 'https://example.com/p.jpg',
            ],
        ];
        $context = ['product' => ['data' => ['name' => '에어맥스']]];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertIsArray($result['og']);
        $this->assertSame('product', $result['og']['type']);
        $this->assertSame('에어맥스', $result['og']['title']);
        $this->assertSame('https://example.com/p.jpg', $result['og']['image']);
    }

    /**
     * og:image:width / og:image:height 가 코어 설정 fallback 으로 채워집니다.
     */
    public function test_og_image_dimensions_fallback_to_core_settings(): void
    {
        Config::set('g7_settings.core.seo.og_image_default_width', 1200);
        Config::set('g7_settings.core.seo.og_image_default_height', 630);

        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => 'p',
                'image' => 'https://example.com/p.jpg',
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, null, []);

        $this->assertSame(1200, $result['og']['image_width']);
        $this->assertSame(630, $result['og']['image_height']);
        $this->assertStringContainsString('og:image:width" content="1200"', $result['ogTags']);
        $this->assertStringContainsString('og:image:height" content="630"', $result['ogTags']);
    }

    /**
     * og:site_name 은 코어 설정 fallback (general.site_name) 을 사용합니다.
     */
    public function test_og_site_name_fallback_to_core_general_site_name(): void
    {
        Config::set('g7_settings.core.seo.og_default_site_name', '');
        Config::set('g7_settings.core.general.site_name', 'GNUBOARD.NET');

        $seoConfig = [
            'og' => [
                'type' => 'website',
                'title' => 'Home',
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, null, []);

        $this->assertSame('GNUBOARD.NET', $result['og']['site_name']);
        $this->assertStringContainsString('og:site_name" content="GNUBOARD.NET"', $result['ogTags']);
    }

    /**
     * Twitter 카드 데이터가 OG 데이터를 fallback 으로 사용합니다.
     */
    public function test_twitter_data_falls_back_to_og(): void
    {
        Config::set('g7_settings.core.seo.twitter_default_card', 'summary_large_image');

        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => '에어맥스',
                'description' => '나이키 에어맥스',
                'image' => 'https://example.com/p.jpg',
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, null, []);

        $this->assertSame('summary_large_image', $result['twitter']['card']);
        $this->assertSame('에어맥스', $result['twitter']['title']);
        $this->assertSame('https://example.com/p.jpg', $result['twitter']['image']);
        $this->assertStringContainsString('twitter:card" content="summary_large_image"', $result['twitterTags']);
        $this->assertStringContainsString('twitter:image" content="https://example.com/p.jpg"', $result['twitterTags']);
    }

    /**
     * og.extra 배열의 자유 메타태그가 출력에 포함됩니다.
     */
    public function test_og_extra_meta_tags_rendered(): void
    {
        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => 'p',
                'extra' => [
                    ['property' => 'product:price:amount', 'content' => '50000'],
                    ['property' => 'product:price:currency', 'content' => 'KRW'],
                ],
            ],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, null, []);

        $this->assertStringContainsString('product:price:amount" content="50000"', $result['ogTags']);
        $this->assertStringContainsString('product:price:currency" content="KRW"', $result['ogTags']);
    }

    /**
     * 회귀: 모듈 템플릿 "{commerce_name} - {product_name}" 에서
     * commerce_name 이 빈 값일 때 선두 "- " 가 남는 회귀를 방지합니다.
     *
     * issue#300: og:title="- 무선 마우스 #99" 슬랙/페이스북 미리보기 제목 깨짐
     */
    public function test_module_template_title_no_leading_dash_when_var_empty(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{commerce_name} - {product_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '');

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];
        $context = ['product' => ['data' => ['name' => '무선 마우스 #99']]];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('무선 마우스 #99', $result['title'], '선두 dash 가 제거되어야 합니다');
        $this->assertStringNotContainsString('- 무선 마우스', $result['title']);
    }

    /**
     * 회귀: 옵셔널 그룹 [{var} - ] 신택스 — var 채워지면 그룹 keep, 비면 통째 drop.
     */
    public function test_module_template_title_optional_group_filled(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '[{commerce_name} - ]{product_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '나이키몰');

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];
        $context = ['product' => ['data' => ['name' => '에어맥스']]];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('나이키몰 - 에어맥스', $result['title']);
    }

    public function test_module_template_title_optional_group_empty_var_dropped(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '[{commerce_name} - ]{product_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '');

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];
        $context = ['product' => ['data' => ['name' => '에어맥스']]];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('에어맥스', $result['title']);
    }

    /**
     * resolveStructuredDataArray 는 배열 형태로 반환하여 hook 변경이 가능합니다.
     */
    public function test_structured_data_returns_array_for_hook_modification(): void
    {
        $seoConfig = [
            'structured_data' => [
                '@type' => 'Product',
                'name' => '{{product.data.name}}',
            ],
        ];
        $context = ['product' => ['data' => ['name' => '에어맥스']]];

        $array = $this->resolver->resolveStructuredDataArray($seoConfig, $context);

        $this->assertIsArray($array);
        $this->assertSame('Product', $array['@type']);
        $this->assertSame('에어맥스', $array['name']);

        // hook 이 array 를 수정 후 다시 JSON 렌더 가능한지
        $array['offers'] = ['@type' => 'Offer', 'price' => '50000'];
        $json = $this->resolver->renderStructuredJson($array);
        $this->assertNotNull($json);
        $this->assertStringContainsString('"offers"', $json);
        $this->assertStringContainsString('@context', $json);
    }

    /**
     * 회귀: 코어 설정 site_name 이 다국어 JSON 배열로 저장된 경우에도
     * resolveOgData 가 'Array to string conversion' throw 없이 정상 동작.
     *
     * 운영자가 일반 설정 site_name 을 MultilingualInput 으로 저장하면
     * config 값이 ["ko" => "...", "en" => "..."] 배열이 됨.
     * 이전 회귀: SeoRenderer 가 봇 요청에서 항상 Array to string ErrorException → SPA fallback.
     */
    public function test_resolve_handles_multilingual_array_in_core_settings(): void
    {
        // site_name 이 다국어 JSON 배열 (운영자가 MultilingualInput 사용 시)
        Config::set('g7_settings.core.general.site_name', [
            'ko' => '한글몰',
            'en' => 'EnglishMall',
        ]);
        Config::set('g7_settings.core.seo.og_default_site_name', '');
        app()->setLocale('ko');

        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => 'Product',
            ],
        ];

        // throw 없이 정상 반환되어야 함
        $result = $this->resolver->resolve($seoConfig, [], null, null, []);

        $this->assertIsArray($result['og']);
        $this->assertSame('한글몰', $result['og']['site_name']);
        $this->assertStringContainsString('og:site_name" content="한글몰"', $result['ogTags']);
    }

    /**
     * 회귀: 데이터소스 컬럼(meta_title/meta_description/meta_keywords) 이 다국어 JSON array 인 경우에도 throw 없이 동작.
     *
     * Product/Post 의 DB 컬럼이 multilingual JSON ({"ko":..., "en":...}) 으로 저장된 환경에서
     * getResourceMetaField / getPageSeoMetaField / resolveKeywords 의 `(string) $value` 가
     * "Array to string conversion" → SPA fallback 회귀 차단.
     */
    public function test_resolve_handles_multilingual_array_in_resource_meta_columns(): void
    {
        app()->setLocale('ko');

        $context = [
            'product' => [
                'data' => [
                    'name' => '에어맥스',
                    'meta_title' => ['ko' => '에어맥스 한정판', 'en' => 'AirMax Limited'],
                    'meta_description' => ['ko' => '나이키 에어맥스 한정판 상품', 'en' => 'Limited edition'],
                    'meta_keywords' => ['ko' => '운동화,에어맥스,한정판', 'en' => 'sneakers,airmax'],
                ],
            ],
        ];

        // throw 없이 정상 반환
        $result = $this->resolver->resolve([], $context, null, null, []);

        $this->assertSame('에어맥스 한정판', $result['title']);
        $this->assertSame('나이키 에어맥스 한정판 상품', $result['description']);
        $this->assertSame('운동화,에어맥스,한정판', $result['keywords']);
    }

    /**
     * 회귀: 페이지 seo_meta 객체 안에 다국어 JSON array 가 있어도 throw 없이 동작.
     */
    public function test_resolve_handles_multilingual_array_in_page_seo_meta(): void
    {
        app()->setLocale('ko');

        $context = [
            'page' => [
                'data' => [
                    'seo_meta' => [
                        'title' => ['ko' => '회사 소개', 'en' => 'About'],
                        'description' => ['ko' => '저희 회사를 소개합니다', 'en' => 'About us'],
                        'keywords' => ['ko' => '회사,소개', 'en' => 'about,company'],
                    ],
                ],
            ],
        ];

        $result = $this->resolver->resolve([], $context, null, null, []);

        $this->assertSame('회사 소개', $result['title']);
        $this->assertSame('저희 회사를 소개합니다', $result['description']);
    }

    /**
     * 회귀: substituteVars 가 다국어 JSON 배열을 변수 값으로 받은 경우에도 throw 없이 동작.
     *
     * 운영자가 var 의 source(예: $module_settings:basic_info.shop_name) 가 다국어 array 를
     * 반환하는 환경에서, 기존 substituteVars 의 `(string) $resolvedVars[$matches[1]]` 가
     * "Array to string conversion" → SeoMiddleware catch → SPA fallback 회귀를 차단.
     *
     * (이 회귀는 #300 작업의 substituteVars 강화에서 도입됨 — 이전 str_replace 기반은
     *  array 받아도 fatal 안 났음.)
     */
    public function test_substitute_vars_handles_multilingual_array_value_without_throw(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{commerce_name} - {product_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);
        // resolveVars 결과에 다국어 array 가 들어가는 경로: 표현식이 직접 array 반환
        // (예: 다국어 객체 리터럴 또는 evaluator 가 반환한 값)
        app()->setLocale('ko');

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                // 표현식이 다국어 객체를 직접 반환 — substituteVars 까지 array 전달
                'product_name' => "{{product.data.name}}",
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];

        // product.data.name 자체를 다국어 array 로
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', [
            'ko' => '한글몰', 'en' => 'EnglishMall',
        ]);
        $context = ['product' => ['data' => ['name' => ['ko' => '에어맥스', 'en' => 'AirMax']]]];

        // throw 없이 정상 반환 (현재 로케일 ko)
        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('한글몰 - 에어맥스', $result['title']);
    }

    /**
     * 회귀: 모듈 설정값이 다국어 JSON 배열인 경우에도 vars 해석이 throw 없이 정상 동작.
     *
     * meta.seo.vars 에서 `$module_settings:key` 접두사로 모듈 설정을 참조하는데,
     * 운영자가 commerce_name(쇼핑몰 이름) 등을 MultilingualInput 으로 저장하면 array 가 됨.
     *
     * 이전 회귀: SeoMetaResolver.resolveVarExpression 의 `(string) g7_module_settings(...)`
     * 가 array → "Array to string conversion" → render() throw → SPA fallback.
     */
    public function test_resolve_vars_handles_multilingual_array_in_module_settings(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{commerce_name} - {product_name}');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product', true);
        // 다국어 JSON array 형태로 저장된 모듈 설정
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', [
            'ko' => '한글몰',
            'en' => 'EnglishMall',
        ]);
        app()->setLocale('ko');

        $seoConfig = [
            'page_type' => 'product',
            'vars' => [
                'product_name' => "{{product.data.name ?? ''}}",
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        ];
        $context = ['product' => ['data' => ['name' => '에어맥스']]];

        // throw 없이 정상 반환
        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-ecommerce', null, []);

        $this->assertSame('한글몰 - 에어맥스', $result['title']);
    }

    /**
     * 회귀: 레이아웃 og.title 이 직접 다국어 객체 리터럴 형식인 경우에도
     * resolveOgData 가 throw 없이 현재 로케일 값으로 변환.
     */
    public function test_resolve_handles_multilingual_array_in_layout_og_field(): void
    {
        app()->setLocale('ko');

        $seoConfig = [
            'og' => [
                'type' => 'product',
                'title' => ['ko' => '에어맥스', 'en' => 'AirMax'],
                'description' => ['ko' => '운동화', 'en' => 'Sneakers'],
                'image' => ['ko' => 'https://e.co/ko.jpg', 'en' => 'https://e.co/en.jpg'],
            ],
        ];

        // throw 없이 정상 반환
        $result = $this->resolver->resolve($seoConfig, [], null, null, []);

        $this->assertSame('에어맥스', $result['og']['title']);
        $this->assertSame('운동화', $result['og']['description']);
        $this->assertSame('https://e.co/ko.jpg', $result['og']['image']);
    }
}
