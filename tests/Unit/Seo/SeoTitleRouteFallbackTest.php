<?php

namespace Tests\Unit\Seo;

use App\Seo\ExpressionEvaluator;
use App\Seo\SeoMetaResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SEO 렌더 제목의 사이트 이름 중복 제거와 라우트 meta.title 대체 테스트
 *
 * 인기글·게시판 목록 화면의 봇 렌더 `<title>` 이 「사이트 이름 | 사이트 이름」 으로 나가고,
 * 회원 프로필 화면은 화면 제목 없이 사이트 이름만 나가던 결함의 회귀 가드다.
 */
class SeoTitleRouteFallbackTest extends TestCase
{
    private SeoMetaResolver $resolver;

    /**
     * 해석기와 코어 SEO 설정(접미사, 사이트 이름)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SeoMetaResolver(new ExpressionEvaluator);

        Config::set('g7_settings.core.seo.meta_title_suffix', ' | 그누보드7 쇼핑몰');
        Config::set('g7_settings.core.seo.meta_description', '');
        Config::set('g7_settings.core.seo.meta_keywords', '');
        Config::set('g7_settings.core.seo.og_default_site_name', '');
        Config::set('g7_settings.core.general.site_name', '그누보드7 쇼핑몰');
    }

    /**
     * 확장 제목 틀이 사이트 이름만 내면(게시판 목록 기본값 `{site_name}`) 레이아웃 og 제목으로 넘깁니다 — 인기글 화면.
     *
     * @scenario title_tier=extension_site_name_only, fallback=layout_og_title
     *
     * @effects site_name_only_extension_title_falls_through_to_layout_og_title
     *
     * @return void 반환값 없음
     */
    public function test_site_name_only_extension_title_falls_through_to_layout_og_title(): void
    {
        $seoConfig = [
            'page_type' => 'boards',
            'og' => ['title' => '인기 게시글'],
        ];
        $context = ['_seo' => ['boards' => ['title' => '그누보드7 쇼핑몰', 'description' => '']]];

        $result = $this->resolver->resolve($seoConfig, $context, 'sirsoft-board', null, [], '게시판 인기글');

        $this->assertSame('인기 게시글', $result['title']);
        $this->assertSame('인기 게시글', $result['og']['title']);
    }

    /**
     * 확장 제목 틀이 사이트 이름만 내고 레이아웃 og 제목도 없으면 라우트 meta.title 을 씁니다 — 게시판 목록 화면.
     *
     * @scenario title_tier=extension_site_name_only, fallback=route_title
     *
     * @effects site_name_only_extension_title_falls_through_to_route_title
     *
     * @return void 반환값 없음
     */
    public function test_site_name_only_extension_title_falls_through_to_route_title(): void
    {
        $context = ['_seo' => ['boards' => ['title' => '그누보드7 쇼핑몰', 'description' => '']]];

        $result = $this->resolver->resolve(['page_type' => 'boards'], $context, 'sirsoft-board', null, [], '게시판 목록');

        $this->assertSame('게시판 목록', $result['title']);
        $this->assertSame('게시판 목록', $result['og']['title']);
        $this->assertSame(
            '게시판 목록 | 그누보드7 쇼핑몰',
            $result['title'].SeoMetaResolver::composeTitleSuffix($result['title'], $result['titleSuffix'])
        );
    }

    /**
     * 사이트 이름 설정과 달라도 접미사에서 구분자를 뗀 이름과 같으면 사이트 이름만 되풀이하는 제목으로 봅니다.
     *
     * @scenario title_tier=extension_suffix_name_only, fallback=route_title
     *
     * @effects suffix_site_name_match_treated_as_site_name_only
     *
     * @return void 반환값 없음
     */
    public function test_title_equal_to_suffix_site_name_is_treated_as_site_name_only(): void
    {
        Config::set('g7_settings.core.general.site_name', '다른 이름');
        Config::set('g7_settings.core.seo.meta_title_suffix', '| 그누보드7 쇼핑몰');
        $context = ['_seo' => ['boards' => ['title' => '그누보드7 쇼핑몰', 'description' => '']]];

        $result = $this->resolver->resolve(['page_type' => 'boards'], $context, 'sirsoft-board', null, [], '게시판 목록');

        $this->assertSame('게시판 목록', $result['title']);
    }

    /**
     * 다른 제목 단계가 모두 비면 라우트 meta.title 을 씁니다 — SEO 제목 선언이 없는 회원 프로필 화면.
     *
     * @scenario title_tier=none, fallback=route_title
     *
     * @effects route_title_used_when_no_other_title
     *
     * @return void 반환값 없음
     */
    public function test_route_title_is_used_when_no_other_title_resolves(): void
    {
        $result = $this->resolver->resolve([], [], null, null, ['userId' => 'u-1'], '사용자 프로필');

        $this->assertSame('사용자 프로필', $result['title']);
        $this->assertSame('사용자 프로필', $result['og']['title']);
    }

    /**
     * 확장 제목 틀이 화면 정보(게시판 이름)를 담으면 라우트 meta.title 보다 앞섭니다.
     *
     * @scenario title_tier=extension_page_info, fallback=route_title
     *
     * @effects extension_title_with_page_info_wins_over_route_title
     *
     * @return void 반환값 없음
     */
    public function test_extension_title_with_page_info_wins_over_route_title(): void
    {
        $context = ['_seo' => ['board' => ['title' => '자유게시판', 'description' => '']]];

        $result = $this->resolver->resolve(['page_type' => 'board'], $context, 'sirsoft-board', null, [], '게시판');

        $this->assertSame('자유게시판', $result['title']);
    }

    /**
     * 대신할 제목이 없으면 사이트 이름만 되풀이하던 제목을 비워 접미사 쪽 사이트 이름만 한 번 남깁니다.
     *
     * @scenario title_tier=extension_site_name_only, fallback=none
     *
     * @effects site_name_only_title_without_fallback_leaves_single_site_name
     *
     * @return void 반환값 없음
     */
    public function test_site_name_only_title_without_fallback_leaves_single_site_name(): void
    {
        $context = ['_seo' => ['boards' => ['title' => '그누보드7 쇼핑몰', 'description' => '']]];

        $result = $this->resolver->resolve(['page_type' => 'boards'], $context, 'sirsoft-board', null, []);

        $this->assertSame('', $result['title']);
        $this->assertSame('그누보드7 쇼핑몰', SeoMetaResolver::composeTitleSuffix($result['title'], $result['titleSuffix']));
    }

    /**
     * 확장 제목이 없고 레이아웃 og 제목이 있으면 라우트 meta.title 보다 레이아웃 og 제목이 앞섭니다.
     *
     * @scenario title_tier=none, fallback=layout_og_title
     *
     * @effects layout_og_title_wins_over_route_title
     *
     * @return void 반환값 없음
     */
    public function test_layout_og_title_wins_over_route_title(): void
    {
        $result = $this->resolver->resolve(['og' => ['title' => '이용약관']], [], null, null, [], '페이지');

        $this->assertSame('이용약관', $result['title']);
    }

    /**
     * 제목 단계가 모두 비고 라우트 제목도 넘어오지 않으면(사이트 첫 화면) 제목이 비어 사이트 이름만 나갑니다.
     *
     * @scenario title_tier=none, fallback=none
     *
     * @effects empty_title_leaves_site_name_only
     *
     * @return void 반환값 없음
     */
    public function test_empty_title_without_route_title_leaves_site_name_only(): void
    {
        $result = $this->resolver->resolve([], [], null, null, []);

        $this->assertSame('', $result['title']);
        $this->assertSame('그누보드7 쇼핑몰', SeoMetaResolver::composeTitleSuffix($result['title'], $result['titleSuffix']));
    }
}
