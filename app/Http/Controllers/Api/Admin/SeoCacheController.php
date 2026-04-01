<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\SeoCacheClearRequest;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheStatsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * SEO 캐시 관리 컨트롤러
 *
 * SEO 캐시 통계 조회, 캐시 삭제, 워밍업, 캐시된 URL 목록을 제공합니다.
 */
class SeoCacheController extends AdminBaseController
{
    public function __construct(
        private SeoCacheStatsService $statsService,
        private SeoCacheManagerInterface $cacheManager
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

            return $this->success('messages.success', $data);
        } catch (\Exception $e) {
            return $this->error('messages.error_occurred', 500, $e->getMessage());
        }
    }

    /**
     * SEO 캐시를 삭제합니다.
     *
     * 레이아웃 또는 모듈 지정 시 해당 캐시만, 미지정 시 전체 캐시를 삭제합니다.
     *
     * @param SeoCacheClearRequest $request 캐시 삭제 요청
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function clearCache(SeoCacheClearRequest $request): JsonResponse
    {
        try {
            $layout = $request->validated('layout');

            if ($layout) {
                $count = $this->cacheManager->invalidateByLayout($layout);

                return $this->success('messages.success', ['cleared' => $count]);
            }

            $this->cacheManager->clearAll();

            return $this->success('messages.success', ['cleared' => 'all']);
        } catch (\Exception $e) {
            return $this->error('messages.error_occurred', 500, $e->getMessage());
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
            return $this->success('messages.success', [
                'status' => 'dispatched',
                'message' => __('seo.warmup_dispatched'),
            ]);
        } catch (\Exception $e) {
            return $this->error('messages.error_occurred', 500, $e->getMessage());
        }
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

            return $this->success('messages.success', ['urls' => $urls, 'count' => count($urls)]);
        } catch (\Exception $e) {
            return $this->error('messages.error_occurred', 500, $e->getMessage());
        }
    }
}
