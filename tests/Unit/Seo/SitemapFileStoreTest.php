<?php

namespace Tests\Unit\Seo;

use App\Extension\Storage\CoreStorageDriver;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapWriter;
use App\Seo\SitemapXmlRenderer;
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
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);

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
            $writer->addUrl(['loc' => url("/page/{$i}")]);
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
     */
    public function test_child_filename_follows_naming_rule(): void
    {
        $this->assertSame('sitemap-1.xml', SitemapFileStore::childFilename(1));
        $this->assertSame('sitemap-7.xml', SitemapFileStore::childFilename(7));
        $this->assertSame('sitemap-1.xml.gz', SitemapFileStore::childFilename(1, true));
    }

    /**
     * childUrl: 자식 sitemap 의 공개 절대 URL 을 반환한다
     */
    public function test_child_url_returns_absolute_url(): void
    {
        $this->assertSame(url('/sitemap-1.xml'), SitemapFileStore::childUrl(1));
        $this->assertSame(url('/sitemap-2.xml.gz'), SitemapFileStore::childUrl(2, true));
    }

    /**
     * exists: 커밋된 세트가 없으면 false
     */
    public function test_exists_returns_false_without_committed_set(): void
    {
        $this->assertFalse($this->store->exists());
    }

    /**
     * exists: 커밋 후 true
     */
    public function test_exists_returns_true_after_commit(): void
    {
        $this->commitSet();

        $this->assertTrue($this->store->exists());
    }

    /**
     * getManifest: 세트가 없으면 null
     */
    public function test_get_manifest_returns_null_without_committed_set(): void
    {
        $this->assertNull($this->store->getManifest());
    }

    /**
     * getManifest: 커밋된 메타를 반환한다
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
     */
    public function test_index_response_returns_null_without_committed_set(): void
    {
        $this->assertNull($this->store->indexResponse());
    }

    /**
     * indexResponse: sitemapindex 를 XML Content-Type 으로 스트리밍한다
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
     */
    public function test_child_response_returns_null_without_committed_set(): void
    {
        $this->assertNull($this->store->childResponse(1));
    }

    /**
     * childResponse: 자식 sitemap 본문을 스트리밍한다
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
     */
    public function test_child_response_returns_null_for_out_of_range_number(): void
    {
        $this->commitSet(urlCount: 2, urlsPerFile: 10);

        $this->assertNull($this->store->childResponse(2), '자식이 1개뿐이면 2번은 없어야 합니다.');
    }

    /**
     * childResponse: 0 이하 번호는 null (경로 조작 방어)
     */
    public function test_child_response_returns_null_for_non_positive_number(): void
    {
        $this->commitSet();

        $this->assertNull($this->store->childResponse(0));
        $this->assertNull($this->store->childResponse(-1));
    }

    /**
     * childResponse: gzip 세트는 Content-Encoding 헤더를 붙인다
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
     */
    public function test_last_generated_at_returns_null_without_committed_set(): void
    {
        $this->assertNull($this->store->lastGeneratedAt());
    }

    /**
     * lastGeneratedAt: manifest 의 생성 시각을 반환한다
     */
    public function test_last_generated_at_returns_manifest_timestamp(): void
    {
        $meta = $this->commitSet();

        $this->assertNotNull($this->store->lastGeneratedAt());
        $this->assertSame($meta['generated_at'], $this->store->lastGeneratedAt());
    }
}
