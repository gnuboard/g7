<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\PurgeRequest;
use App\Services\Analytics\PurgeService;
use App\Services\Analytics\StorageStatsService;
use Illuminate\Http\JsonResponse;

/**
 * 방문자 통계 데이터 API
 *
 * 저장 용량 현황을 보이고, 운영자가 「처음부터 다시 모으겠다」고 정했을 때 전부
 * 지웁니다. 되돌릴 수 없으므로 확인 없이는 통과하지 않습니다.
 */
class DataController extends AdminBaseController
{
    /**
     * 데이터 컨트롤러를 생성합니다.
     *
     * @param  PurgeService  $purge  전체 삭제 서비스
     * @param  StorageStatsService  $storage  저장 용량 현황 서비스
     */
    public function __construct(
        private readonly PurgeService $purge,
        private readonly StorageStatsService $storage,
    ) {
        parent::__construct();
    }

    /**
     * 저장 용량 현황을 조회합니다.
     *
     * @return JsonResponse 현황 응답
     */
    public function show(): JsonResponse
    {
        return $this->success(
            'analytics.data.fetch_success',
            $this->storage->stats(AnalyticsConfig::fromSettings()),
        );
    }

    /**
     * 방문자 통계 기록을 모두 지웁니다.
     *
     * @param  PurgeRequest  $request  전체 삭제 요청
     * @return JsonResponse 삭제 응답
     */
    public function destroy(PurgeRequest $request): JsonResponse
    {
        return $this->success('analytics.data.purge_success', [
            'deleted' => $this->purge->purgeAll(),
        ]);
    }
}
