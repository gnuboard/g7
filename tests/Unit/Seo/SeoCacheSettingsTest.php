<?php

namespace Tests\Unit\Seo;

use App\Seo\SeoCacheSettings;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SeoCacheSettings 단위 테스트 (결정 D19)
 *
 * 우선순위 규칙:
 *   고급 탭(`cache.*`) = 메인 / SEO 탭(`seo.*`)에 별도 지정이 있으면 오버라이드.
 *   "별도 지정" 판정은 null 여부 — null 이면 미설정으로 보고 고급 탭 값을 따른다.
 *
 * 회귀 배경: 과거에는 `cache.*` 가 **항상** 이겨 SEO 탭 입력이 조용히 무시되었다.
 * 이 저장소의 `seo.json` 에 `cache_ttl=3600` 이 저장돼 있었으나 실제로는
 * `cache.seo_ttl=7200` 으로 동작하던 것이 실증 사례다.
 */
class SeoCacheSettingsTest extends TestCase
{
    /**
     * 테스트 초기화 - 두 카테고리를 모두 미설정 상태로 비웁니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('g7_settings.core.seo', []);
        Config::set('g7_settings.core.cache', []);
    }

    /**
     * pageCacheTtl: SEO 탭이 미설정이면 고급 탭 값을 따른다
     */
    public function test_page_cache_ttl_follows_advanced_tab_when_seo_is_unset(): void
    {
        Config::set('g7_settings.core.cache.seo_ttl', 7200);
        Config::set('g7_settings.core.seo.cache_ttl', null);

        $this->assertSame(7200, SeoCacheSettings::pageCacheTtl());
    }

    /**
     * pageCacheTtl: SEO 탭에 값이 있으면 고급 탭을 오버라이드한다 (회귀 — 과거엔 무시됨)
     */
    public function test_page_cache_ttl_seo_override_wins_over_advanced_tab(): void
    {
        Config::set('g7_settings.core.cache.seo_ttl', 7200);
        Config::set('g7_settings.core.seo.cache_ttl', 3600);

        $this->assertSame(
            3600,
            SeoCacheSettings::pageCacheTtl(),
            'SEO 탭에 지정한 값이 고급 탭보다 우선해야 합니다.'
        );
    }

    /**
     * pageCacheTtl: 양쪽 다 미설정이면 최종 기본값
     */
    public function test_page_cache_ttl_falls_back_to_default_when_both_unset(): void
    {
        $this->assertSame(7200, SeoCacheSettings::pageCacheTtl());
    }

    /**
     * pageCacheEnabled: SEO 탭이 미설정이면 고급 탭 값을 따른다
     */
    public function test_page_cache_enabled_follows_advanced_tab_when_seo_is_unset(): void
    {
        Config::set('g7_settings.core.cache.seo_enabled', false);
        Config::set('g7_settings.core.seo.cache_enabled', null);

        $this->assertFalse(SeoCacheSettings::pageCacheEnabled());
    }

    /**
     * pageCacheEnabled: SEO 탭의 false 는 미설정이 아니라 오버라이드다
     *
     * null 이 아닌 falsy 값(false)을 "미설정" 으로 잘못 판정하면, 운영자가 SEO 캐시를
     * 끄려 해도 고급 탭의 true 가 이겨 캐시가 계속 켜진다.
     */
    public function test_page_cache_enabled_treats_false_as_explicit_override(): void
    {
        Config::set('g7_settings.core.cache.seo_enabled', true);
        Config::set('g7_settings.core.seo.cache_enabled', false);

        $this->assertFalse(
            SeoCacheSettings::pageCacheEnabled(),
            'false 는 미설정이 아니라 명시적 오버라이드여야 합니다.'
        );
    }

    /**
     * pageCacheEnabled: SEO 탭의 true 도 오버라이드로 동작한다
     */
    public function test_page_cache_enabled_true_overrides_disabled_advanced_tab(): void
    {
        Config::set('g7_settings.core.cache.seo_enabled', false);
        Config::set('g7_settings.core.seo.cache_enabled', true);

        $this->assertTrue(SeoCacheSettings::pageCacheEnabled());
    }

    /**
     * sitemapCacheTtl: SEO 탭이 미설정이면 고급 탭 값을 따른다
     */
    public function test_sitemap_cache_ttl_follows_advanced_tab_when_seo_is_unset(): void
    {
        Config::set('g7_settings.core.cache.seo_sitemap_ttl', 86400);
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', null);

        $this->assertSame(86400, SeoCacheSettings::sitemapCacheTtl());
    }

    /**
     * sitemapCacheTtl: SEO 탭에 값이 있으면 고급 탭을 오버라이드한다 (회귀)
     */
    public function test_sitemap_cache_ttl_seo_override_wins_over_advanced_tab(): void
    {
        Config::set('g7_settings.core.cache.seo_sitemap_ttl', 86400);
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', 3600);

        $this->assertSame(3600, SeoCacheSettings::sitemapCacheTtl());
    }

    /**
     * sitemapCacheTtl: 양쪽 다 미설정이면 최종 기본값
     */
    public function test_sitemap_cache_ttl_falls_back_to_default_when_both_unset(): void
    {
        $this->assertSame(86400, SeoCacheSettings::sitemapCacheTtl());
    }

    /**
     * 각 영역은 서로 독립이다 — SEO 페이지 캐시 지정이 sitemap 에 새지 않는다
     */
    public function test_domains_are_independent(): void
    {
        Config::set('g7_settings.core.cache.seo_ttl', 7200);
        Config::set('g7_settings.core.cache.seo_sitemap_ttl', 86400);
        Config::set('g7_settings.core.seo.cache_ttl', 60);

        $this->assertSame(60, SeoCacheSettings::pageCacheTtl());
        $this->assertSame(
            86400,
            SeoCacheSettings::sitemapCacheTtl(),
            'SEO 페이지 캐시 오버라이드가 sitemap 캐시에 영향을 주면 안 됩니다.'
        );
    }

    /**
     * 문자열로 저장된 값도 정수로 정규화된다 (폼 입력은 문자열로 올 수 있음)
     */
    public function test_string_values_are_normalized_to_int(): void
    {
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', '3600');

        $this->assertSame(3600, SeoCacheSettings::sitemapCacheTtl());
    }
}
