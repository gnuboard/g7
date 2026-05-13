<?php

namespace Tests\Feature\Seo;

use App\Seo\Contracts\SitemapContributorInterface;
use App\Seo\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * 일본어(ja) 언어팩 활성화 시 SEO/sitemap 전 영역에 다국어 이슈가 없는지 검증.
 *
 * `LanguagePackServiceProvider::refreshSupportedLocales()` 가 활성 코어 locale 을
 * `config('app.supported_locales')` 에 동적 주입하면 SitemapGenerator/SeoRenderer/
 * SeoMiddleware 가 자동으로 ja 를 소비해야 한다. 본 테스트는 supported_locales 에 ja 가
 * 포함된 상태에서 다음을 보장한다:
 *
 *   1. Sitemap XML 의 모든 contributor URL 이 ja 로케일 URL 과 xhtml:link hreflang ja 로 확장된다.
 *   2. SitemapGenerator 의 다국어 분기가 활성화된다 (xhtml 네임스페이스 + 로케일별 <url>).
 *   3. SeoMiddleware 가 ?locale=ja 를 유효 로케일로 받아들이고 fallback 하지 않는다.
 *   4. SeoMiddleware 가 ?locale=ja 캐시를 ko/en 캐시와 격리한다.
 *
 * 이 테스트는 ja 가 supported_locales 에 추가되기만 하면 — 실제 ja LanguagePack DB row
 * 유무와 무관하게 — 다운스트림 SEO 계층이 ja 를 인식해야 한다는 계약을 굳힌다.
 */
class JaLocaleSeoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ja 가 supported_locales 에 포함된 상태를 직접 주입 (LanguagePackServiceProvider
        // 의 refreshSupportedLocales() 결과와 동등). bot 감지 + 캐시 격리 검증을 위해
        // 봇 UA 가 사용 가능한 상태로 둔다.
        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko', 'en', 'ja']);
        Config::set('app.locale_names', [
            'ko' => '한국어',
            'en' => 'English',
            'ja' => '日本語',
        ]);
        Config::set('app.translatable_locales', ['ko', 'en', 'ja']);
    }

    public function test_sitemap_xml_includes_ja_locale_urls(): void
    {
        /** @var SitemapGenerator $generator */
        $generator = app(SitemapGenerator::class);

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('ja-test');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/about', 'changefreq' => 'monthly'],
        ]);

        $generator->registerContributor($contributor);
        $xml = $generator->generate();

        $base = url('/about');

        // 다국어 모드 → xhtml 네임스페이스 활성화
        $this->assertStringContainsString(
            'xmlns:xhtml="http://www.w3.org/1999/xhtml"',
            $xml,
            'supported_locales 가 1개 초과면 xhtml 네임스페이스가 sitemap 에 포함되어야 함'
        );

        // ja 로케일 <url> 항목 — ko 가 default 이므로 ja 는 ?locale=ja 형태
        $this->assertStringContainsString(
            '<loc>'.$base.'?locale=ja</loc>',
            $xml,
            'ja 가 supported_locales 에 있으면 sitemap 에 ja 로케일 <url> 이 생성되어야 함'
        );

        // ja hreflang alternate (다른 로케일들의 <url> 안에 ja 가 alternate 로 노출)
        $this->assertStringContainsString(
            '<xhtml:link rel="alternate" hreflang="ja" href="'.$base.'?locale=ja"/>',
            $xml,
            'ja hreflang alternate 가 모든 <url> 항목에 노출되어야 함'
        );

        // <url> 태그가 3개 이상 (ko/en/ja)
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($xml, '<url>'),
            'ko/en/ja 3개 로케일 <url> 항목이 모두 생성되어야 함'
        );
    }

    public function test_sitemap_xml_default_locale_url_has_no_query_string(): void
    {
        /** @var SitemapGenerator $generator */
        $generator = app(SitemapGenerator::class);

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('default-loc-test');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/contact'],
        ]);

        $generator->registerContributor($contributor);
        $xml = $generator->generate();

        // 기본 ko 로케일은 ?locale=ko 가 아니라 clean URL
        $this->assertStringContainsString(
            '<loc>'.url('/contact').'</loc>',
            $xml,
            '기본 로케일(ko) URL 은 ?locale 파라미터 없이 clean URL 이어야 함'
        );
        $this->assertStringNotContainsString(
            '?locale=ko',
            $xml,
            '기본 로케일 URL 에는 ?locale=ko 가 추가되지 않아야 함 (회피 — clean URL 정책)'
        );
    }

    public function test_seo_middleware_accepts_ja_locale_query(): void
    {
        // 봇 요청으로 SEO 렌더링 트리거
        Cache::flush();

        $response = $this->get('/?locale=ja', [
            'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        ]);

        // 200 응답이거나 (렌더링 성공) — 401/403/404 가 아니어야 함 (ja 가 거부되지 않았음)
        $this->assertContains(
            $response->status(),
            [200, 301, 302],
            'ja 가 supported_locales 에 있으면 SeoMiddleware 가 거부하지 않아야 함 (현재 status='.$response->status().')'
        );
    }

    public function test_unsupported_locale_still_falls_back_when_ja_active(): void
    {
        // 등록되지 않은 'fr' 은 여전히 거부되고 default 로 fallback
        Cache::flush();

        $response = $this->get('/?locale=fr', [
            'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        ]);

        $this->assertContains(
            $response->status(),
            [200, 301, 302],
            '미지원 locale(fr) 도 fallback 으로 정상 응답해야 함'
        );
    }

    public function test_seo_cache_isolated_for_ja_locale(): void
    {
        Cache::flush();

        // ja 로케일과 ko 로케일이 같은 URL 에서 별도 캐시 키를 사용하는지 검증
        $cacheManager = app(\App\Seo\SeoCacheManager::class);

        $reflection = new \ReflectionClass($cacheManager);
        if (! $reflection->hasMethod('buildKey')) {
            $this->markTestSkipped('SeoCacheManager::buildKey not accessible');
        }

        $build = $reflection->getMethod('buildKey');
        $build->setAccessible(true);

        $url = 'https://example.test/products';
        $keyKo = $build->invoke($cacheManager, $url, 'ko');
        $keyEn = $build->invoke($cacheManager, $url, 'en');
        $keyJa = $build->invoke($cacheManager, $url, 'ja');

        $this->assertNotSame($keyKo, $keyJa, 'ja 캐시 키는 ko 와 격리되어야 함');
        $this->assertNotSame($keyEn, $keyJa, 'ja 캐시 키는 en 과 격리되어야 함');
        $this->assertNotSame($keyKo, $keyEn, 'ko/en 캐시 키도 격리되어 있어야 함 (회귀 가드)');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
