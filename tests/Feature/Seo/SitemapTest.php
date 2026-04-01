<?php

namespace Tests\Feature\Seo;

use App\Seo\SitemapGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * Sitemap XML 엔드포인트 기능 테스트
 *
 * GET /sitemap.xml 라우트가 올바른 XML 응답을 반환하고,
 * 캐싱/비활성화 설정이 올바르게 동작하는지 검증합니다.
 */
class SitemapTest extends TestCase
{
    /**
     * 테스트 종료 - Mockery 리소스를 정리합니다.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * sitemap.xml 요청이 application/xml Content-Type을 반환하는지 확인합니다.
     */
    public function test_sitemap_returns_xml_content_type(): void
    {
        // sitemap 활성화 설정
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        // 캐시에 XML 미리 저장하여 Generator 호출 방지
        $sampleXml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        Cache::put('seo:sitemap', $sampleXml, 86400);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
    }

    /**
     * sitemap_enabled가 false일 때 404를 반환하는지 확인합니다.
     */
    public function test_sitemap_returns_404_when_disabled(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', false);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(404);
    }

    /**
     * 캐시에 저장된 XML이 있으면 해당 내용을 반환하는지 확인합니다.
     */
    public function test_sitemap_returns_cached_content(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        $cachedXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://example.com/cached</loc></url>'
            .'</urlset>';

        Cache::put('seo:sitemap', $cachedXml, 86400);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertSee('https://example.com/cached');
        $this->assertSame($cachedXml, $response->getContent());
    }

    /**
     * 캐시가 없을 때 SitemapGenerator::generate()를 호출하고
     * 결과를 캐시에 저장하는지 확인합니다.
     */
    public function test_sitemap_generates_and_caches_when_not_cached(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', true);
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', 3600);

        // 캐시 비우기
        Cache::forget('seo:sitemap');

        $generatedXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://example.com/generated</loc></url>'
            .'</urlset>';

        // SitemapGenerator Mock
        $generatorMock = Mockery::mock(SitemapGenerator::class);
        $generatorMock->shouldReceive('generate')
            ->once()
            ->andReturn($generatedXml);

        // 컨테이너에 Mock 바인딩
        $this->app->instance(SitemapGenerator::class, $generatorMock);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $this->assertSame($generatedXml, $response->getContent());

        // 캐시에 저장되었는지 확인
        $this->assertSame($generatedXml, Cache::get('seo:sitemap'));
    }

    /**
     * sitemap_enabled가 null로 설정되면 (bool) 캐스팅에 의해 비활성으로 처리되는지 확인합니다.
     *
     * Config에 키가 null로 존재하면 Config::get()이 null을 반환하고,
     * (bool) null = false이므로 404가 됩니다.
     */
    public function test_sitemap_returns_404_when_enabled_is_null(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', null);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(404);
    }

    /**
     * sitemap_enabled가 true일 때 정상 동작하는지 확인합니다.
     */
    public function test_sitemap_works_when_explicitly_enabled(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        $sampleXml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        Cache::put('seo:sitemap', $sampleXml, 86400);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
    }

    /**
     * sitemap XML 응답 본문이 XML 형식인지 확인합니다.
     */
    public function test_sitemap_response_body_is_valid_xml(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        $validXml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .'  <url>'."\n"
            .'    <loc>https://example.com/</loc>'."\n"
            .'  </url>'."\n"
            .'</urlset>';

        Cache::put('seo:sitemap', $validXml, 86400);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);

        // XML 파싱이 성공하는지 확인
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'sitemap 응답이 유효한 XML이어야 합니다');
        $this->assertSame('urlset', $xml->getName());
    }
}
