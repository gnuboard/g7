<?php

namespace Tests\Unit\Seo;

use App\Extension\Storage\CoreStorageDriver;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapWriter;
use App\Seo\SitemapXmlRenderer;
use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * SitemapFileStore 단위 테스트
 *
 * 읽기측 API(exists/getManifest/indexResponse/childResponse/lastGeneratedAt)와
 * 경로 SSoT(childFilename/childUrl)를 검증합니다. 이 API 는 sitemap 라우트가
 * 그대로 소비하는 표면이므로 단위 수준에서 계약을 고정합니다.
 */
class SitemapFileStoreTest extends TestCase
{
    private CoreStorageDriver $storage;

    private SitemapFileStore $store;

    /**
     * 테스트 초기화 - 가짜 로컬 디스크를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);
        // `<loc>` 의 오리진은 설정(`app.url`)이 우선이다 (`AssetUrl::siteAbsolute()`). 개발 사이트의
        // `app.url` 이 흘러들면 `url()` 기대값(요청 호스트 http://localhost)과 갈리므로 고정한다.
        Config::set('app.url', 'http://localhost');

        Storage::fake('local');

        $this->storage = new CoreStorageDriver('local');
        $this->store = new SitemapFileStore($this->storage);
    }

    /**
     * 커밋된 sitemap 세트를 생성합니다.
     *
     * @param  int  $urlCount  생성할 URL 수
     * @param  int  $urlsPerFile  파일당 URL 수
     * @param  bool  $gzip  gzip 압축 여부
     * @return array 커밋 결과 메타
     */
    private function commitSet(int $urlCount = 3, int $urlsPerFile = 10, bool $gzip = false): array
    {
        $writer = new SitemapWriter(
            $this->storage,
            SitemapXmlRenderer::fromConfig(),
            $urlsPerFile,
            $gzip,
        );

        $writer->open();
        for ($i = 1; $i <= $urlCount; $i++) {
            // writer 는 절대 URL(loc) 을 받는다 — 상대 경로(url) 는 Generator 가 정규화하는 형식이다.
            // 생산 코드(`SitemapGenerator::normalizeEntry`)와 같은 단일 지점으로 만든다.
            $writer->addUrl(['loc' => AssetUrl::siteAbsolute("/page/{$i}")]);
        }
        $writer->close();

        return $writer->commit();
    }

    /**
     * StreamedResponse 본문을 문자열로 수집합니다.
     *
     * @param  StreamedResponse  $response  스트리밍 응답
     * @return string 응답 본문
     */
    private function streamBody(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * childFilename: 자식 파일명 규칙이 고정된다 (경로 SSoT)
     *
     * @effects sitemap_child_paths_and_urls_have_one_owner
     *
     * @return void 반환값 없음
     */
    public function test_child_filename_follows_naming_rule(): void
    {
        $this->assertSame('sitemap-1.xml', SitemapFileStore::childFilename(1));
        $this->assertSame('sitemap-7.xml', SitemapFileStore::childFilename(7));
        $this->assertSame('sitemap-1.xml.gz', SitemapFileStore::childFilename(1, true));
    }

    /**
     * childUrl: 자식 sitemap 의 공개 절대 URL 을 반환한다
     *
     * @effects sitemap_child_paths_and_urls_have_one_owner
     *
     * @return void 반환값 없음
     */
    public function test_child_url_returns_absolute_url(): void
    {
        $this->assertSame(url('/sitemap-1.xml'), SitemapFileStore::childUrl(1));
        $this->assertSame(url('/sitemap-2.xml.gz'), SitemapFileStore::childUrl(2, true));
    }

    /**
     * childUrl: 확장자 없는 모드에서는 세그먼트 형태를 <loc> 에 적는다
     *
     * 그 모드를 고른 사이트는 정적 최적화 블록이 `.xml` 을 가로채는 서버다 — 확장자
     * 형태를 그대로 적으면 봇이 따라간 자식이 전부 404 가 되고 색인이 조용히 빈다.
     * gzip 여부는 manifest 가 정하므로 두 형태가 같은 주소로 모인다.
     *
     * @scenario asset_url_mode=extensionless
     *
     * @effects sitemap_child_paths_and_urls_have_one_owner
     *
     * @return void 반환값 없음
     */
    public function test_child_url_uses_segment_form_in_extensionless_mode(): void
    {
        AssetUrl::forceMode('extensionless');

        try {
            $this->assertSame(url('/sitemap-1/xml'), SitemapFileStore::childUrl(1));
            $this->assertSame(url('/sitemap-2/xml'), SitemapFileStore::childUrl(2, true));
        } finally {
            AssetUrl::forceMode(null);
        }
    }

    /**
     * 그 <loc> 값이 실제로 등록된 라우트에 도달한다
     *
     * 규칙과 주소 생성이 어긋나면 증상이 「봇 화면에서만 404」라 사람이 볼 통로가 없다.
     * 두 모드의 <loc> 를 라우터에 직접 물어 확인한다.
     *
     * @effects sitemap_child_paths_and_urls_have_one_owner
     *
     * @return void 반환값 없음
     */
    public function test_child_url_matches_a_registered_route_in_both_modes(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => '/'.ltrim($route->uri(), '/'))
            ->all();

        $this->assertContains('/sitemap-{n}.xml', $routes);
        $this->assertContains('/sitemap-{n}/xml', $routes);
        $this->assertContains('/sitemap.xml', $routes);
        $this->assertContains('/sitemap/xml', $routes);

        // 확장자를 떼어낸 **맨 이름**은 선점하지 않는다 — 그 주소를 쓰던 사용자 페이지가
        // 도달 불가가 되기 때문이다(`dualSuffix` 가 아니라 `dualSuffixSegment` 를 쓴 이유).
        $this->assertNotContains('/sitemap', $routes);
    }

    /**
     * childUrl: 자식 `<loc>` 은 요청이 아니라 **설정된 사이트 주소**(`app.url`)를 따른다.
     *
     * 자식 항목의 `<loc>` 은 `AssetUrl::siteAbsolute()` 로 설정 오리진·접두사를 싣는데, 색인의
     * 자식 주소만 `url()` 로 만들면 요청 호스트(또는 콘솔의 `http://localhost`)를 실어 **한
     * 사이트맵 안에서 부모와 자식의 오리진이 갈린다**. 크롤러는 그것을 오류 없이 다른 사이트로
     * 따라간다.
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects sitemap_index_child_locations_follow_the_configured_site_url
     *
     * @return void 반환값 없음
     */
    public function test_child_url_follows_the_configured_site_url_not_the_request(): void
    {
        Config::set('app.url', 'https://example.com/g7');
        app()->instance('request', Request::create('http://localhost/sitemap.xml'));

        try {
            $this->assertSame('https://example.com/g7/sitemap-1.xml', SitemapFileStore::childUrl(1));
            $this->assertSame('https://example.com/g7/sitemap-2.xml.gz', SitemapFileStore::childUrl(2, true));

            AssetUrl::forceMode('extensionless');
            $this->assertSame('https://example.com/g7/sitemap-1/xml', SitemapFileStore::childUrl(1));
        } finally {
            AssetUrl::forceMode(null);
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * 색인(부모)의 `<loc>` 과 자식 sitemap 의 `<loc>` 이 같은 오리진·접두사를 갖는다.
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects sitemap_index_child_locations_follow_the_configured_site_url
     *
     * @return void 반환값 없음
     */
    public function test_index_and_child_locations_share_the_configured_origin_and_prefix(): void
    {
        Config::set('app.url', 'https://example.com/g7');
        app()->instance('request', Request::create('http://localhost/sitemap.xml'));

        try {
            $this->commitSet(urlCount: 12, urlsPerFile: 10);

            $index = $this->store->indexResponse();
            $child = $this->store->childResponse(1);
            ob_start();
            $index->sendContent();
            $child->sendContent();
            $body = ob_get_clean();

            preg_match_all('~<loc>([^<]+)</loc>~', $body, $m);
            $this->assertNotEmpty($m[1], '<loc> 모집단이 비었다');

            $offenders = array_values(array_filter($m[1], fn (string $loc) => ! str_starts_with($loc, 'https://example.com/g7/')));
            $this->assertSame([], $offenders, '설정된 사이트 주소 밖을 가리키는 <loc>');
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * exists: 커밋된 세트가 없으면 false
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_exists_returns_false_without_committed_set(): void
    {
        $this->assertFalse($this->store->exists());
    }

    /**
     * exists: 커밋 후 true
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_exists_returns_true_after_commit(): void
    {
        $this->commitSet();

        $this->assertTrue($this->store->exists());
    }

    /**
     * getManifest: 세트가 없으면 null
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_get_manifest_returns_null_without_committed_set(): void
    {
        $this->assertNull($this->store->getManifest());
    }

    /**
     * getManifest: 커밋된 메타를 반환한다
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_get_manifest_returns_committed_metadata(): void
    {
        $this->commitSet(urlCount: 3);

        $manifest = $this->store->getManifest();

        $this->assertIsArray($manifest);
        $this->assertSame(3, $manifest['url_count']);
        $this->assertSame(1, $manifest['child_count']);
        $this->assertCount(1, $manifest['children']);
        $this->assertSame('sitemap-1.xml', $manifest['children'][0]['file']);
    }

    /**
     * getManifest: manifest 가 손상되면 null 을 반환한다 (예외 전파 금지)
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_get_manifest_returns_null_when_manifest_is_corrupted(): void
    {
        $this->commitSet();
        $this->storage->put(
            SitemapFileStore::CATEGORY,
            SitemapFileStore::LIVE_DIR.'/'.SitemapFileStore::MANIFEST_FILE,
            '{ this is not json',
        );

        $this->assertNull($this->store->getManifest());
    }

    /**
     * indexResponse: 세트가 없으면 null
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_index_response_returns_null_without_committed_set(): void
    {
        $this->assertNull($this->store->indexResponse());
    }

    /**
     * indexResponse: sitemapindex 를 XML Content-Type 으로 스트리밍한다
     *
     * @effects sitemap_files_are_streamed_with_xml_content_type
     *
     * @return void 반환값 없음
     */
    public function test_index_response_streams_sitemap_index(): void
    {
        $this->commitSet(urlCount: 12, urlsPerFile: 10);

        $response = $this->store->indexResponse();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('application/xml', $response->headers->get('Content-Type'));

        $body = $this->streamBody($response);
        $this->assertStringContainsString('<sitemapindex', $body);
        $this->assertStringContainsString(url('/sitemap-1.xml'), $body);
        $this->assertStringContainsString(url('/sitemap-2.xml'), $body);
    }

    /**
     * childResponse: 세트가 없으면 null
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_child_response_returns_null_without_committed_set(): void
    {
        $this->assertNull($this->store->childResponse(1));
    }

    /**
     * childResponse: 자식 sitemap 본문을 스트리밍한다
     *
     * @effects sitemap_files_are_streamed_with_xml_content_type
     *
     * @return void 반환값 없음
     */
    public function test_child_response_streams_child_sitemap(): void
    {
        $this->commitSet(urlCount: 2);

        $response = $this->store->childResponse(1);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('application/xml', $response->headers->get('Content-Type'));
        $this->assertNull($response->headers->get('Content-Encoding'));

        $body = $this->streamBody($response);
        $this->assertStringContainsString('<urlset', $body);
        $this->assertStringContainsString(url('/page/1'), $body);
    }

    /**
     * childResponse: 존재하지 않는 자식 번호는 null
     *
     * @effects sitemap_child_number_outside_the_set_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_child_response_returns_null_for_out_of_range_number(): void
    {
        $this->commitSet(urlCount: 2, urlsPerFile: 10);

        $this->assertNull($this->store->childResponse(2), '자식이 1개뿐이면 2번은 없어야 합니다.');
    }

    /**
     * childResponse: 0 이하 번호는 null (경로 조작 방어)
     *
     * @effects sitemap_child_number_outside_the_set_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_child_response_returns_null_for_non_positive_number(): void
    {
        $this->commitSet();

        $this->assertNull($this->store->childResponse(0));
        $this->assertNull($this->store->childResponse(-1));
    }

    /**
     * childResponse: gzip 세트는 Content-Encoding 헤더를 붙인다
     *
     * @effects gzip_child_responses_declare_content_encoding_and_index_stays_plain
     *
     * @return void 반환값 없음
     */
    public function test_child_response_sets_gzip_content_encoding(): void
    {
        $this->commitSet(urlCount: 2, urlsPerFile: 10, gzip: true);

        $response = $this->store->childResponse(1);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
    }

    /**
     * indexResponse: gzip 세트여도 인덱스는 비압축이다 (D13)
     *
     * @effects gzip_child_responses_declare_content_encoding_and_index_stays_plain
     *
     * @return void 반환값 없음
     */
    public function test_index_response_is_never_gzip_encoded(): void
    {
        $this->commitSet(urlCount: 2, urlsPerFile: 10, gzip: true);

        $response = $this->store->indexResponse();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    /**
     * lastGeneratedAt: 세트가 없으면 null
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_last_generated_at_returns_null_without_committed_set(): void
    {
        $this->assertNull($this->store->lastGeneratedAt());
    }

    /**
     * lastGeneratedAt: manifest 의 생성 시각을 반환한다
     *
     * @effects sitemap_store_reads_only_a_committed_set
     *
     * @return void 반환값 없음
     */
    public function test_last_generated_at_returns_manifest_timestamp(): void
    {
        $meta = $this->commitSet();

        $this->assertNotNull($this->store->lastGeneratedAt());
        $this->assertSame($meta['generated_at'], $this->store->lastGeneratedAt());
    }
}
