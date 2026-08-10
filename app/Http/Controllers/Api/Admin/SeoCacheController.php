<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\SitemapGenerationMode;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\SeoCacheClearRequest;
use App\Jobs\GenerateSitemapJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheStatsService;
use App\Seo\SitemapManager;
use App\Seo\SitemapProgress;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * SEO 캐시 관리 컨트롤러
 *
 * SEO 캐시 통계 조회, 캐시 삭제, 워밍업, 캐시된 URL 목록을 제공합니다.
 */
class SeoCacheController extends AdminBaseController
{
    public function __construct(
        private SeoCacheStatsService $statsService,
        private SeoCacheManagerInterface $cacheManager,
        private SitemapManager $sitemapManager,
        private SitemapProgress $sitemapProgress
    ) {
        parent::__construct();
    }

    /**
     * SEO 캐시 통계를 조회합니다.
     *
     * 전체 통계, 레이아웃별 통계, 모듈별 통계를 반환합니다.
     *
     * @return JsonResponse 캐시 통계 데이터를 포함한 JSON 응답
     */
    public function stats(): JsonResponse
    {
        try {
            $since = Carbon::now()->subDays(7);

            $data = [
                'overall' => $this->statsService->getStats($since),
                'by_layout' => $this->statsService->getStatsByLayout($since),
                'by_module' => $this->statsService->getStatsByModule($since),
            ];

            return $this->success('common.success', $data);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, $e->getMessage());
        }
    }

    /**
     * SEO 캐시를 삭제합니다.
     *
     * 레이아웃 또는 모듈 지정 시 해당 캐시만, 미지정 시 전체 캐시를 삭제합니다.
     *
     * @param  SeoCacheClearRequest  $request  캐시 삭제 요청
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function clearCache(SeoCacheClearRequest $request): JsonResponse
    {
        try {
            $layout = $request->validated('layout');

            if ($layout) {
                $count = $this->cacheManager->invalidateByLayout($layout);

                return $this->success('common.success', ['cleared' => $count]);
            }

            $this->cacheManager->clearAll();

            return $this->success('common.success', ['cleared' => 'all']);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, $e->getMessage());
        }
    }

    /**
     * SEO 캐시 워밍업을 실행합니다.
     *
     * 모든 SEO 레이아웃을 사전 렌더링합니다. (Phase 5에서 구현 예정)
     *
     * @return JsonResponse 워밍업 시작 결과를 포함한 JSON 응답
     */
    public function warmup(): JsonResponse
    {
        try {
            // Phase 5의 SeoDeclarationCollector 구현 후 실제 워밍업 로직 추가 예정
            return $this->success('common.success', [
                'status' => 'dispatched',
                'message' => __('seo.warmup_dispatched'),
            ]);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, $e->getMessage());
        }
    }

    /**
     * Sitemap XML 재생성을 큐에 예약합니다.
     *
     * 대용량(수백만 URL) 사이트에서 요청 스레드 동기 생성은 메모리/타임아웃 붕괴를 일으키므로
     * 큐 잡으로 위임합니다. 응답은 예약 시점의 상태이며, 실제 완료 시각은 이후 갱신됩니다.
     *
     * @return JsonResponse 예약 결과 JSON 응답
     */
    public function regenerateSitemap(): JsonResponse
    {
        if (! (bool) g7_core_settings('seo.sitemap_enabled', true)) {
            return $this->error('seo.sitemap_disabled', 400);
        }

        // 관리자 수동 재생성은 항상 전체(Full) — 현 생성 상태와 무관하게 전량 재생성 (D7)
        // 실행한 관리자 ID 를 함께 실어, 완료/실패 시 그 관리자에게만 알림이 발송되게 한다.
        GenerateSitemapJob::dispatch(SitemapGenerationMode::Full, Auth::id());

        // 큐 대기 중에도 UI 가 'queued' 를 표시하도록 즉시 기록
        $this->sitemapProgress->start(SitemapGenerationMode::Full->value);

        return $this->success('seo.sitemap_regenerate_dispatched', $this->sitemapManager->getStatus());
    }

    /**
     * Sitemap 재생성 진행상황과 실시간 연결 가능 여부를 조회합니다.
     *
     * SEO 탭 진입 시 초기 로드용이며, 폴링(Reverb OFF)일 때 주기적으로 재조회됩니다.
     *
     * @return JsonResponse 진행상황(progress) + last_updated_at + realtime_enabled 를 포함한 JSON 응답
     */
    public function sitemapStatus(): JsonResponse
    {
        return $this->success('messages.success', $this->sitemapManager->getStatus());
    }

    /**
     * 캐시된 URL 목록을 조회합니다.
     *
     * SeoCacheManager의 인덱스에서 현재 캐시된 URL 목록을 반환합니다.
     *
     * @return JsonResponse 캐시된 URL 목록을 포함한 JSON 응답
     */
    public function cachedUrls(): JsonResponse
    {
        try {
            $urls = $this->cacheManager->getCachedUrls();

            return $this->success('common.success', ['urls' => $urls, 'count' => count($urls)]);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, $e->getMessage());
        }
    }
}
