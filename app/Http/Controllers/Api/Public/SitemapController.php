<?php

namespace App\Http\Controllers\Api\Public;

use App\Contracts\Extension\CacheInterface;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Jobs\GenerateSitemapJob;
use App\Seo\SeoCacheSettings;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sitemap XML 컨트롤러
 *
 * 비공개 디스크에 커밋된 sitemap 인덱스/자식 파일을 스트리밍으로 서빙합니다.
 * 봇 요청 스레드에서 sitemap 을 생성하지 않으며(대용량에서 OOM/타임아웃 원인),
 * 신선도가 만료되면 생성 잡만 예약하고 기존 세트(stale)를 그대로 내보냅니다.
 *
 * 캐시 계약(검증자·조건부 요청·304 헤더 동반)은 코어 단일 지점
 * (`BaseApiController::streamedFileResponse()`)이 소유한다 — 그래서 `PublicBaseController`
 * 를 상속한다. 종전에는 두 통로에 검증자가 없어 봇이 매번 전문을 받아 갔다 (14차 G3 라운드).
 *
 * 세 통로 모두 `streamedFileResponseFromMeta()` 로 넘긴다 — 만료 기간은 세트의 신선도 기간
 * (`SeoCacheSettings::sitemapCacheTtl()`)과 같다(그보다 길면 재생성된 세트가 봇에 늦게 닿고,
 * 짧으면 같은 세트를 이유 없이 다시 묻는다). `immutable` 은 `false` — 같은 주소의 내용이
 * 재생성마다 교체된다. 압축은 선언으로 제외한다 — 자식 파일은 사이트의 URL 수에 비례해
 * 크기가 유계가 아니고(최대 50MB), gzip 세트는 이미 `Content-Encoding` 을 갖는다.
 *
 * 이 세 라우트는 `web` 그룹에 있지만 세션·쿠키 미들웨어를 뺀다(`routes/web.php`) —
 * `public, max-age` 응답에 `Set-Cookie` 가 실리면 공유 캐시가 그 응답을 저장하지 않거나
 * 세션 쿠키를 다른 방문자에게 재사용한다 (16차 G3 라운드 E-5).
 */
class SitemapController extends PublicBaseController
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
     * @return StreamedResponse|Response|JsonResponse sitemapindex 스트림, 304 또는 메타 누락 시 500
     */
    public function index(SitemapFileStore $store, CacheInterface $cache): StreamedResponse|Response|JsonResponse
    {
        $this->assertSitemapEnabled();

        $isFresh = $cache->get(SitemapManager::META_CACHE_KEY) !== null;

        if ($isFresh) {
            $info = $store->indexInfo();
            if ($info !== null) {
                return $this->streamedFileResponseFromMeta(
                    $info,
                    SeoCacheSettings::sitemapCacheTtl(),
                    immutable: false,
                    skipCompression: true,
                    logContext: ['sitemap' => 'index']
                );
            }
        }

        // 신선도 만료 또는 세트 부재 — 생성은 큐에 맡긴다 (ShouldBeUnique 로 중복 디스패치 방지).
        GenerateSitemapJob::dispatch();

        $serveStale = (bool) g7_core_settings('seo.sitemap_serve_stale_on_miss', true);

        if ($serveStale) {
            $info = $store->indexInfo();
            if ($info !== null) {
                return $this->streamedFileResponseFromMeta(
                    $info,
                    SeoCacheSettings::sitemapCacheTtl(),
                    immutable: false,
                    skipCompression: true,
                    logContext: ['sitemap' => 'index_stale']
                );
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
     * @return StreamedResponse|Response|JsonResponse 자식 sitemap 스트림, 304 또는 메타 누락 시 500
     */
    public function child(int $n, SitemapFileStore $store): StreamedResponse|Response|JsonResponse
    {
        $this->assertSitemapEnabled();

        $info = $store->childInfo($n);

        if ($info === null) {
            abort(404);
        }

        return $this->streamedFileResponseFromMeta(
            $info,
            SeoCacheSettings::sitemapCacheTtl(),
            immutable: false,
            skipCompression: true,
            logContext: ['sitemap' => 'child', 'n' => $n]
        );
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
