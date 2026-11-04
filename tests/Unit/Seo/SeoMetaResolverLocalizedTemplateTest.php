<?php

namespace Tests\Unit\Seo;

use App\Seo\ExpressionEvaluator;
use App\Seo\SeoMetaResolver;
use App\Seo\SeoTitleRule;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 모듈·플러그인 설정 제목 틀 다국어와 접미사가 빈 사이트의 제목 테스트 (공개#154)
 *
 * 모듈 설정 `seo.meta_{page_type}_title` 을 다국어 배열로 저장한 모듈 화면은 반환형 string 에 배열이 들어가
 * TypeError → 일반 화면(SPA)으로 나갔다. 기본 설치는 제목 접미사가 빈 값이라 「사이트 이름뿐인 제목 비우기」가
 * 봇 홈 제목을 `<title></title>` 로 만들었다.
 */
class SeoMetaResolverLocalizedTemplateTest extends TestCase
{
    private SeoMetaResolver $resolver;

    /**
     * 해석기와 코어 SEO 설정을 준비합니다(운영자가 저장한 로컬 설정이 단언에 섞이지 않게 고정).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SeoMetaResolver(new ExpressionEvaluator);

        Config::set('g7_settings.core.seo.meta_title_suffix', ' | 그누보드7 쇼핑몰');
        Config::set('g7_settings.core.seo.meta_description', '코어 기본 설명');
        Config::set('g7_settings.core.seo.meta_keywords', '');
        Config::set('g7_settings.core.seo.og_default_site_name', '');
        Config::set('g7_settings.core.general.site_name', '그누보드7 쇼핑몰');
    }

    /**
     * 모듈 설정 제목 틀을 다국어 배열로 저장해도 현재 로케일 문자열로 해석됩니다(TypeError 없음).
     *
     * @scenario surface=title_template_module
     *
     * @effects localized_extension_title_template_resolved
     *
     * @return void 반환값 없음
     */
    public function test_localized_module_title_template_resolves_to_current_locale(): void
    {
        app()->setLocale('ko');
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', [
            'ko' => '{product_name} 구매하기',
            'en' => 'Buy {product_name}',
        ]);
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_description', [
            'ko' => '한국어 상품 설명',
            'en' => 'English product description',
        ]);

        $seoConfig = [
            'page_type' => 'product',
            'vars' => ['product_name' => '에어맥스'],
        ];

        $result = $this->resolver->resolve($seoConfig, [], 'sirsoft-ecommerce', null, []);

        $this->assertSame('에어맥스 구매하기', $result['title']);
        $this->assertSame('한국어 상품 설명', $result['description']);

        app()->setLocale('en');
        $result = $this->resolver->resolve(['page_type' => 'product'], [], 'sirsoft-ecommerce', null, []);

        // vars 가 없으면 틀을 그대로 돌려주는 분기 — 이 분기도 문자열이어야 한다
        $this->assertSame('Buy {product_name}', $result['title']);
    }

    /**
     * 플러그인 설정 제목·설명 틀도 다국어 배열을 현재 로케일 문자열로 해석합니다.
     *
     * @scenario surface=title_template_plugin
     *
     * @effects localized_extension_title_template_resolved
     *
     * @return void 반환값 없음
     */
    public function test_localized_plugin_title_template_resolves_to_current_locale(): void
    {
        app()->setLocale('ko');
        Config::set('g7_settings.plugins.sirsoft-payment.seo.meta_checkout_title', [
            'ko' => '{page_name} - 결제',
            'en' => '{page_name} - Payment',
        ]);
        Config::set('g7_settings.plugins.sirsoft-payment.seo.meta_checkout_description', [
            'ko' => '결제 화면',
            'en' => 'Checkout screen',
        ]);

        $seoConfig = [
            'page_type' => 'checkout',
            'vars' => ['page_name' => '결제하기'],
        ];

        $result = $this->resolver->resolve($seoConfig, [], null, 'sirsoft-payment', []);

        $this->assertSame('결제하기 - 결제', $result['title']);
        $this->assertSame('결제 화면', $result['description']);
    }

    /**
     * 접미사가 빈 사이트에서도 사이트 이름뿐인 제목은 비우고, 기본 접미사(` - {사이트 이름}`)가 사이트 이름 하나만 남깁니다.
     * 기본 설치는 접미사가 빈 값이라 봇 홈 제목이 `<title></title>` 로 나가던 결함의 회귀 가드입니다(7.1.5 부터 기본 접미사로 막는다).
     *
     * @scenario surface=title_suffix_empty
     *
     * @effects empty_suffix_site_name_becomes_title
     *
     * @return void 반환값 없음
     */
    public function test_site_name_only_title_with_empty_suffix_keeps_site_name(): void
    {
        Config::set('g7_settings.core.seo.meta_title_suffix', '');
        Config::set('g7_settings.core.general.site_name', '그누보드7');
        // 게시판 목록 기본 틀처럼 사이트 이름만 내는 확장 제목 틀
        Config::set('g7_settings.modules.sirsoft-board.seo.meta_board_title', '그누보드7');

        $result = $this->resolver->resolve(['page_type' => 'board'], [], 'sirsoft-board', null, []);

        $this->assertSame('', $result['title']);
        $this->assertSame('', $result['titleSuffix']);
        $this->assertSame('그누보드7', $result['title'].SeoTitleRule::composeSuffix($result['title'], SeoTitleRule::assembleSuffix($result['titleSuffix'], '그누보드7'), '그누보드7'));
        $this->assertSame('그누보드7', $result['og']['title']);
    }

    /**
     * 접미사가 빈 사이트에서 어느 단계도 제목을 내지 못하면 사이트 이름을 제목으로 씁니다(다국어 사이트 이름 포함).
     *
     * @scenario surface=title_suffix_empty
     *
     * @effects empty_suffix_site_name_becomes_title
     *
     * @return void 반환값 없음
     */
    public function test_empty_title_with_empty_suffix_falls_back_to_localized_site_name(): void
    {
        app()->setLocale('ko');
        Config::set('g7_settings.core.seo.meta_title_suffix', '');
        Config::set('g7_settings.core.general.site_name', ['ko' => '그누보드7', 'en' => 'Gnuboard7']);

        $result = $this->resolver->resolve([], [], null, null, []);

        $this->assertSame('', $result['title']);
        $this->assertSame('그누보드7', SeoTitleRule::composeSuffix($result['title'], SeoTitleRule::assembleSuffix($result['titleSuffix'], '그누보드7'), '그누보드7'));
        $this->assertSame('그누보드7', $result['og']['title']);
    }

    /**
     * 접미사가 있는 사이트는 종전대로 사이트 이름뿐인 제목을 비워 「사이트 이름 | 사이트 이름」 중복을 막습니다.
     *
     * @scenario surface=title_suffix_present
     *
     * @effects suffix_present_site_name_title_stays_empty
     *
     * @return void 반환값 없음
     */
    public function test_site_name_only_title_with_suffix_stays_empty(): void
    {
        Config::set('g7_settings.core.seo.meta_title_suffix', ' | 그누보드7');
        Config::set('g7_settings.core.general.site_name', '그누보드7');
        Config::set('g7_settings.modules.sirsoft-board.seo.meta_board_title', '그누보드7');

        $result = $this->resolver->resolve(['page_type' => 'board'], [], 'sirsoft-board', null, []);

        $this->assertSame('', $result['title']);
        $this->assertSame(' | 그누보드7', $result['titleSuffix']);
    }
}
