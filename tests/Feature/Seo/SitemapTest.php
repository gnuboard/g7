<?php

namespace Tests\Feature\Seo;

use App\Extension\Storage\CoreStorageDriver;
use App\Jobs\GenerateSitemapJob;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapManager;
use App\Seo\SitemapWriter;
use App\Seo\SitemapXmlRenderer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sitemap XML 엔드포인트 기능 테스트
 *
 * 봇 요청 스레드에서 sitemap 을 생성하지 않고(대용량 OOM 원인),
 * 비공개 디스크에 커밋된 세트를 스트리밍 서빙하는 계약을 검증합니다.
 * 신선도가 만료되면 생성은 큐에 맡기고 기존 세트(stale)를 내보냅니다.
 */
class SitemapTest extends TestCase
{
    private CoreStorageDriver $storage;

    /**
     * 테스트 초기화 - 가짜 비공개 디스크와 활성화 설정을 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        Storage::fake('local');

        $this->storage = new CoreStorageDriver('local');
        $this->app->instance(SitemapFileStore::class, new SitemapFileStore($this->storage));

        Cache::forget('g7:core:'.SitemapManager::META_CACHE_KEY);
    }

    /**
     * 커밋된 sitemap 세트를 디스크에 만듭니다.
     *
     * @param  int  $urlCount  생성할 URL 수
     * @param  int  $urlsPerFile  파일당 URL 수
     * @param  bool  $gzip  gzip 압축 여부
     * @return array 커밋 결과 메타
     */
    private function commitSet(int $urlCount = 3, int $urlsPerFile = 50000, bool $gzip = false): array
    {
        $writer = new SitemapWriter(
            $this->storage,
            SitemapXmlRenderer::fromConfig(),
            $urlsPerFile,
            $gzip,
        );

        $writer->open();
        for ($i = 1; $i <= $urlCount; $i++) {
            $writer->addUrl(['loc' => url("/page/{$i}")]);
        }
        $writer->close();

        return $writer->commit();
    }

    /**
     * 메타 캐시를 신선한 상태로 만듭니다.
     *
     * @param  array  $meta  커밋 메타
     */
    private function markFresh(array $meta): void
    {
        Cache::put('g7:core:'.SitemapManager::META_CACHE_KEY, $meta, 86400);
    }

    /**
     * 응답 본문을 문자열로 수집합니다.
     *
     * @param  TestResponse  $response  테스트 응답
     * @return string 응답 본문
     */
    private function streamBody($response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * 신선한 세트가 있으면 디스크의 sitemapindex 를 XML 로 서빙하는지 확인합니다.
     */
    public function test_index_is_served_from_disk_when_fresh(): void
    {
        $meta = $this->commitSet();
        $this->markFresh($meta);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $body = $this->streamBody($response);
        $this->assertStringContainsString('<sitemapindex', $body);
        $this->assertStringContainsString('/sitemap-1.xml', $body);
    }

    /**
     * 신선한 세트를 서빙할 때는 생성 잡을 디스패치하지 않는지 확인합니다.
     */
    public function test_index_does_not_dispatch_job_when_fresh(): void
    {
        Queue::fake();

        $meta = $this->commitSet();
        $this->markFresh($meta);

        $this->get('/sitemap.xml')->assertStatus(200);

        Queue::assertNotPushed(GenerateSitemapJob::class);
    }

    /**
     * 메타 캐시가 만료되면 봇 요청 스레드에서 생성하지 않고 잡만 디스패치하는지 확인합니다.
     *
     * 이 회귀가 무너지면 1.4M 규모에서 봇 요청이 OOM/타임아웃을 유발합니다.
     */
    public function test_index_dispatches_job_and_serves_stale_on_cache_miss(): void
    {
        Queue::fake();

        $this->commitSet();
        // 메타 캐시 없음 = 신선도 만료 상태

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $this->assertStringContainsString('<sitemapindex', $this->streamBody($response));

        Queue::assertPushed(GenerateSitemapJob::class, 1);
    }

    /**
     * serve_stale_on_miss 가 false 면 만료 시 stale 을 내보내지 않고 503 을 반환하는지 확인합니다.
     */
    public function test_index_returns_503_on_miss_when_stale_serving_disabled(): void
    {
        Queue::fake();
        Config::set('g7_settings.core.seo.sitemap_serve_stale_on_miss', false);

        $this->commitSet();

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '120');
        Queue::assertPushed(GenerateSitemapJob::class, 1);
    }

    /**
     * 커밋된 세트가 전혀 없으면 503 + Retry-After 를 반환하는지 확인합니다.
     */
    public function test_index_returns_503_with_retry_after_when_no_set_exists(): void
    {
        Queue::fake();

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '120');
        Queue::assertPushed(GenerateSitemapJob::class, 1);
    }

    /**
     * 자식 sitemap 라우트가 디스크 파일을 스트리밍하는지 확인합니다.
     */
    public function test_child_route_streams_child_sitemap(): void
    {
        $this->commitSet(urlCount: 3, urlsPerFile: 2);

        $response = $this->get('/sitemap-1.xml');

        $response->assertStatus(200);
        $body = $this->streamBody($response);
        $this->assertStringContainsString('<urlset', $body);
        $this->assertStringContainsString('/page/1', $body);
    }

    /**
     * 인덱스가 가리키는 모든 자식 경로가 실제로 서빙되는지 확인합니다.
     *
     * 인덱스의 <loc> 규칙(SitemapFileStore::childUrl)과 라우트 경로가 어긋나면
     * 검색엔진에 404 를 가리키는 인덱스를 내보내게 됩니다.
     */
    public function test_index_child_locs_are_all_routable(): void
    {
        $meta = $this->commitSet(urlCount: 5, urlsPerFile: 2);

        $this->assertSame(3, $meta['child_count']);

        for ($n = 1; $n <= $meta['child_count']; $n++) {
            $this->get(SitemapFileStore::childUrl($n))->assertStatus(200);
        }
    }

    /**
     * 존재하지 않는 자식 번호는 404 를 반환하는지 확인합니다.
     */
    public function test_child_route_returns_404_for_unknown_child(): void
    {
        $this->commitSet(urlCount: 1, urlsPerFile: 50000);

        $this->get('/sitemap-99.xml')->assertStatus(404);
    }

    /**
     * gzip 세트는 .xml.gz 경로에서 Content-Encoding 과 함께 서빙되는지 확인합니다.
     */
    public function test_child_route_serves_gzip_set(): void
    {
        $this->commitSet(urlCount: 2, urlsPerFile: 50000, gzip: true);

        $response = $this->get('/sitemap-1.xml.gz');

        $response->assertStatus(200);
        $response->assertHeader('Content-Encoding', 'gzip');
        $this->assertStringContainsString('<urlset', (string) gzdecode($this->streamBody($response)));
    }

    /**
     * sitemap_enabled 가 false 이면 인덱스/자식 모두 404 를 반환하는지 확인합니다.
     */
    public function test_routes_return_404_when_disabled(): void
    {
        $meta = $this->commitSet();
        $this->markFresh($meta);

        Config::set('g7_settings.core.seo.sitemap_enabled', false);

        $this->get('/sitemap.xml')->assertStatus(404);
        $this->get('/sitemap-1.xml')->assertStatus(404);
    }

    /**
     * sitemap_enabled 가 null 이면 (bool) 캐스팅으로 비활성 처리되는지 확인합니다.
     */
    public function test_index_returns_404_when_enabled_is_null(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', null);

        $this->get('/sitemap.xml')->assertStatus(404);
    }

    /**
     * 서빙된 인덱스/자식이 유효한 XML 인지 확인합니다.
     */
    public function test_served_documents_are_valid_xml(): void
    {
        $meta = $this->commitSet(urlCount: 3, urlsPerFile: 2);
        $this->markFresh($meta);

        $index = simplexml_load_string($this->streamBody($this->get('/sitemap.xml')));
        $this->assertNotFalse($index, 'sitemapindex 응답이 유효한 XML 이어야 합니다');
        $this->assertSame('sitemapindex', $index->getName());

        $child = simplexml_load_string($this->streamBody($this->get('/sitemap-1.xml')));
        $this->assertNotFalse($child, '자식 sitemap 응답이 유효한 XML 이어야 합니다');
        $this->assertSame('urlset', $child->getName());
    }
}
