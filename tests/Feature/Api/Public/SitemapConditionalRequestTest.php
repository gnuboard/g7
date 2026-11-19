<?php

namespace Tests\Feature\Api\Public;

use App\Extension\Storage\CoreStorageDriver;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Responses\NotModifiedResponse;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapManager;
use App\Seo\SitemapWriter;
use App\Seo\SitemapXmlRenderer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * sitemap 두 통로(인덱스·자식)의 조건부 요청 계약 테스트.
 *
 * 종전에는 두 통로가 `StorageInterface::response()` 스트림을 그대로 돌려줘 검증자가 하나도
 * 없었다 — 봇이 재방문마다 전문(자식은 최대 50MB)을 받아 갔다. 링크가 되는 서버의 정적
 * 서빙은 같은 파일에 ETag·Last-Modified 를 붙이므로 링크 유무로 동작이 갈리는 상태였다.
 * 검증자는 manifest(커밋 시각 + 파일 이름)에서 만들어 파일 stat 없이 결정적이다.
 *
 * @scenario response_kind=streamed, conditional_request=none
 */
class SitemapConditionalRequestTest extends TestCase
{
    private CoreStorageDriver $storage;

    /**
     * sitemap 을 켜고 로컬 디스크를 가짜로 바꾼 뒤, 그 디스크 위의 파일 저장소를 컨테이너에 묶고 manifest 캐시를 비운다.
     *
     * @return void 반환값 없음
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
     * 커밋된 sitemap 세트를 디스크에 만들고 신선한 상태로 표시합니다.
     *
     * @param  bool  $gzip  gzip 압축 여부
     * @return array 커밋 결과 메타
     */
    private function commitFreshSet(bool $gzip = false): array
    {
        $writer = new SitemapWriter($this->storage, SitemapXmlRenderer::fromConfig(), 50000, $gzip);

        $writer->open();
        for ($i = 1; $i <= 3; $i++) {
            $writer->addUrl(['loc' => url("/page/{$i}")]);
        }
        $writer->close();

        $meta = $writer->commit();
        Cache::put('g7:core:'.SitemapManager::META_CACHE_KEY, $meta, 86400);

        return $meta;
    }

    /**
     * 304 가 본문 없는 전용 응답 객체이고 ETag, Last-Modified, Cache-Control, Expires, Vary 를 200 과 같은 값으로 싣는지 단언한다.
     *
     * @param  TestResponse  $full  같은 URL 의 200 응답
     * @param  TestResponse  $notModified  조건부 재요청의 304 응답
     * @return void 반환값 없음
     */
    private function assertNotModifiedMirrors200(TestResponse $full, TestResponse $notModified): void
    {
        $notModified->assertStatus(304);
        $this->assertInstanceOf(NotModifiedResponse::class, $notModified->baseResponse);
        $this->assertSame('', (string) $notModified->baseResponse->getContent(), '304 에 본문이 실렸다');

        foreach (['ETag', 'Last-Modified', 'Cache-Control', 'Expires', 'Vary'] as $header) {
            $this->assertNotNull($full->headers->get($header), "200 이 {$header} 를 싣지 않는다");
            $this->assertSame(
                $full->headers->get($header),
                $notModified->headers->get($header),
                "304 가 200 과 다른 {$header} 를 광고한다 (RFC 9110 §15.4.5)"
            );
        }
    }

    /**
     * 조건부 요청 계약을 잴 sitemap 두 통로(인덱스, 자식)의 URL 을 공급한다.
     *
     * @return array<string, array{string}> 케이스 이름별 요청 URL
     */
    public static function sitemapUrls(): array
    {
        return [
            'index' => ['/sitemap.xml'],
            'child' => ['/sitemap-1.xml'],
        ];
    }

    /**
     * 각 통로가 manifest 기반 ETag(따옴표 친 32자 hex), 커밋 시각의 Last-Modified, Expires, immutable 없는 Cache-Control, Vary 를 싣는지 확인한다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects sitemap_validators_come_from_the_committed_manifest
     *
     * @param  string  $url  요청할 sitemap URL
     * @return void 반환값 없음
     */
    #[DataProvider('sitemapUrls')]
    public function test_sitemap_carries_manifest_based_validators(string $url): void
    {
        $meta = $this->commitFreshSet();

        $response = $this->get($url);

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', (string) $response->headers->get('ETag'));
        $this->assertSame(
            gmdate('D, d M Y H:i:s', strtotime($meta['generated_at'])).' GMT',
            $response->headers->get('Last-Modified'),
            'Last-Modified 는 manifest 의 커밋 시각이어야 한다 — 파일 stat 없이 결정적이다'
        );
        $this->assertNotNull($response->headers->get('Expires'));
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        // XML 은 압축 대상 유형이라 200 에 Vary 가 실린다 — 304 가 그것을 거울처럼 실어야 한다.
        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));
    }

    /**
     * 첫 응답의 ETag 를 `If-None-Match` 로 되보내면 200 의 캐시 축을 그대로 실은 304 가 나오는지 확인한다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @param  string  $url  요청할 sitemap URL
     * @return void 반환값 없음
     */
    #[DataProvider('sitemapUrls')]
    public function test_matching_etag_yields_304(string $url): void
    {
        $this->commitFreshSet();

        $first = $this->get($url);
        $first->assertOk();

        $second = $this->withHeaders(['If-None-Match' => (string) $first->headers->get('ETag')])->get($url);

        $this->assertNotModifiedMirrors200($first, $second);
    }

    /**
     * `If-Modified-Since` 만 보내도 200 의 캐시 축을 그대로 실은 304 가 나오는지 확인한다.
     *
     * @scenario response_kind=streamed, conditional_request=if_modified_since
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @param  string  $url  요청할 sitemap URL
     * @return void 반환값 없음
     */
    #[DataProvider('sitemapUrls')]
    public function test_if_modified_since_alone_yields_304(string $url): void
    {
        $this->commitFreshSet();

        $first = $this->get($url);
        $first->assertOk();

        $second = $this->withHeaders(['If-Modified-Since' => (string) $first->headers->get('Last-Modified')])->get($url);

        $this->assertNotModifiedMirrors200($first, $second);
    }

    /**
     * 현재 것과 다른 ETag 로 재요청하면 압축 없는 전문 200 이 나오는지 확인한다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects non_matching_validator_returns_the_full_representation
     *
     * @param  string  $url  요청할 sitemap URL
     * @return void 반환값 없음
     */
    #[DataProvider('sitemapUrls')]
    public function test_non_matching_validator_returns_the_full_document(string $url): void
    {
        $this->commitFreshSet();

        $stale = $this->withHeaders(['If-None-Match' => '"not-the-current-tag"'])->get($url);

        $stale->assertOk();
        $this->assertSame('', (string) $stale->headers->get('Content-Encoding', ''));
    }

    /**
     * 재생성되면 검증자가 바뀌어 옛 ETag 는 전문을 받는다 — 판정이 헐거우면 새 세트가 봇에 닿지 않는다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects sitemap_validators_come_from_the_committed_manifest
     *
     * @return void 반환값 없음
     */
    public function test_regenerated_set_invalidates_the_old_validator(): void
    {
        $this->commitFreshSet();
        $first = $this->get('/sitemap.xml');
        $first->assertOk();

        $this->travel(1)->minutes();
        $this->commitFreshSet();

        $after = $this->withHeaders(['If-None-Match' => (string) $first->headers->get('ETag')])->get('/sitemap.xml');
        $after->assertOk();
        $this->assertNotSame($first->headers->get('ETag'), $after->headers->get('ETag'));
    }

    /**
     * gzip 세트의 자식은 `Content-Encoding: gzip` 을 유지한 채 같은 계약을 갖고, `.xml` 표현과
     * 다른 ETag 를 갖는다(파일 이름이 검증자 소스에 든다).
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match, encoding=gzip
     *
     * @effects sitemap_validators_come_from_the_committed_manifest, conditional_response_carries_the_full_cache_axis_of_200
     *
     * @return void 반환값 없음
     */
    public function test_gzip_child_keeps_its_encoding_and_has_a_distinct_validator(): void
    {
        $this->commitFreshSet(gzip: true);

        $first = $this->get('/sitemap-1.xml.gz');
        $first->assertOk();
        $this->assertSame('gzip', $first->headers->get('Content-Encoding'));

        $second = $this->withHeaders(['If-None-Match' => (string) $first->headers->get('ETag')])->get('/sitemap-1.xml.gz');
        $this->assertNotModifiedMirrors200($first, $second);

        $index = $this->get('/sitemap.xml');
        $this->assertNotSame($index->headers->get('ETag'), $first->headers->get('ETag'));
    }

    /**
     * sitemap 통로는 압축 제외를 선언한다 — 자식 파일은 사이트의 URL 수에 비례해 유계가 아니다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects serving_paths_declare_compression_skip_on_the_request
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_declares_compression_skip(): void
    {
        $this->commitFreshSet();

        $this->withHeaders(['Accept-Encoding' => 'gzip'])->get('/sitemap-1.xml')->assertOk();

        $this->assertTrue($this->app['request']->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE));
    }

    /**
     * `public, max-age` 로 나가는 사이트맵 응답에 `Set-Cookie` 가 실리지 않는다.
     *
     * 세 라우트는 `routes/web.php` 라 `web` 그룹의 `StartSession` 이 매 응답에 세션 쿠키를
     * 실었다. `Set-Cookie` 가 붙은 `public` 응답은 공유 캐시가 저장하지 않거나 그 쿠키를 다른
     * 방문자에게 재사용한다 — 봇이 받는 응답이라 세션이 있을 이유도 없다.
     * 200 과 304 모두 잰다 — 304 도 같은 미들웨어 스택을 지난다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects sitemap_response_carries_no_session_cookie
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_responses_carry_no_session_cookie(): void
    {
        $this->commitFreshSet(gzip: true);

        foreach (['/sitemap.xml', '/sitemap-1.xml', '/sitemap-1.xml.gz', '/sitemap/xml', '/sitemap-1/xml'] as $url) {
            $full = $this->get($url);
            $full->assertOk();
            // `public, max-age` 자체는 production 환경에서만 나간다(비운영은 `no-cache`) — 여기서는
            // 쿠키 축만 잰다. 정책 문자열은 형제 `test_sitemap_carries_manifest_based_validators` 가 본다.
            $this->assertNull($full->headers->get('Set-Cookie'), "{$url} 의 200 에 세션 쿠키가 실렸다");
            $this->assertSame([], $full->headers->getCookies(), "{$url} 의 200 에 큐잉된 쿠키가 실렸다");

            $notModified = $this->withHeaders(['If-None-Match' => (string) $full->headers->get('ETag')])->get($url);
            $notModified->assertStatus(304);
            $this->assertNull($notModified->headers->get('Set-Cookie'), "{$url} 의 304 에 세션 쿠키가 실렸다");
            $this->assertSame([], $notModified->headers->getCookies());

            // `withHeaders()` 는 테스트 기본 헤더에 **누적**된다 — 비우지 않으면 다음 URL 의
            // 첫 요청이 앞 URL 의 `If-None-Match` 를 실어 304 를 받는다 (이 루프의 실측 결함).
            $this->flushHeaders();
        }
    }
}
