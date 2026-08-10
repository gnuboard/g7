<?php

namespace App\Http\Controllers\Api\Public;

use App\Contracts\Extension\CacheInterface;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateSitemapJob;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sitemap XML 컨트롤러
 *
 * 비공개 디스크에 커밋된 sitemap 인덱스/자식 파일을 스트리밍으로 서빙합니다.
 * 봇 요청 스레드에서 sitemap 을 생성하지 않으며(대용량에서 OOM/타임아웃 원인),
 * 신선도가 만료되면 생성 잡만 예약하고 기존 세트(stale)를 그대로 내보냅니다.
 */
class SitemapController extends Controller
{
    /**
     * 세트가 없어 503 을 반환할 때 봇에 안내할 재시도 간격 (초)
     */
    private const RETRY_AFTER_SECONDS = 120;

    /**
     * sitemapindex 를 반환합니다.
     *
     * 커밋된 세트가 있으면 스트리밍하고, 신선도(메타 캐시)가 만료되었으면
     * 생성 잡을 예약합니다. 세트가 전혀 없으면 503 + Retry-After 를 반환합니다.
     *
     * @param  SitemapFileStore  $store  sitemap 파일 저장소 (읽기측)
     * @param  CacheInterface  $cache  코어 캐시 드라이버
     * @return StreamedResponse sitemapindex 스트림
     */
    public function index(SitemapFileStore $store, CacheInterface $cache): StreamedResponse
    {
        $this->assertSitemapEnabled();

        $isFresh = $cache->get(SitemapManager::META_CACHE_KEY) !== null;

        if ($isFresh) {
            $response = $store->indexResponse();
            if ($response !== null) {
                return $response;
            }
        }

        // 신선도 만료 또는 세트 부재 — 생성은 큐에 맡긴다 (ShouldBeUnique 로 중복 디스패치 방지).
        GenerateSitemapJob::dispatch();

        $serveStale = (bool) g7_core_settings('seo.sitemap_serve_stale_on_miss', true);

        if ($serveStale) {
            $response = $store->indexResponse();
            if ($response !== null) {
                return $response;
            }
        }

        abort(503, __('seo.sitemap_not_ready'), ['Retry-After' => (string) self::RETRY_AFTER_SECONDS]);
    }

    /**
     * 자식 sitemap 을 반환합니다.
     *
     * gzip 여부는 manifest 가 결정하므로 `.xml` / `.xml.gz` 요청을 같은 액션에서 처리합니다.
     *
     * @param  int  $n  자식 번호 (1부터)
     * @param  SitemapFileStore  $store  sitemap 파일 저장소 (읽기측)
     * @return StreamedResponse 자식 sitemap 스트림
     */
    public function child(int $n, SitemapFileStore $store): StreamedResponse
    {
        $this->assertSitemapEnabled();

        $response = $store->childResponse($n);

        if ($response === null) {
            abort(404);
        }

        return $response;
    }

    /**
     * Sitemap 기능이 비활성화되어 있으면 404 로 중단합니다.
     */
    private function assertSitemapEnabled(): void
    {
        if (! (bool) g7_core_settings('seo.sitemap_enabled', true)) {
            abort(404);
        }
    }
}
