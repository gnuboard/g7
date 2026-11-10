<?php

namespace Plugins\Sirsoft\CdnCloudfront\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\CdnCloudfront\Http\Requests\Admin\PurgeStatusRequest;
use Plugins\Sirsoft\CdnCloudfront\Http\Requests\Admin\TestConnectionRequest;
use Plugins\Sirsoft\CdnCloudfront\Services\PurgeService;

/**
 * CloudFront 플러그인 설정 화면 보조 API — 무효화 대기 현황·연결 시험
 *
 * 코어 플러그인 설정 API 에는 계산값을 덧붙일 자리가 없어 플러그인 자체 경로로 둔다.
 * 권한 축은 설정 화면과 같다(조회 core.plugins.read, 시험 core.plugins.update).
 */
class CloudFrontAdminController extends AdminBaseController
{
    /**
     * 무효화 서비스를 주입받습니다.
     *
     * @param  PurgeService  $purges  무효화 서비스
     */
    public function __construct(private readonly PurgeService $purges)
    {
        parent::__construct();
    }

    /**
     * 무효화 대기 현황을 반환합니다.
     *
     * @param  PurgeStatusRequest  $request  요청
     * @return JsonResponse 현황
     */
    public function purgeStatus(PurgeStatusRequest $request): JsonResponse
    {
        return ResponseHelper::success('common.success', $this->purges->status());
    }

    /**
     * 저장된 설정으로 배포 조회(GetDistribution)를 보내 연결을 시험합니다.
     *
     * 자격이 비어 있으면 422, CloudFront 가 거절하면 422 에 사유를 싣는다 — 둘 다 운영자가 설정을 고쳐야
     * 하는 상황이라 서버 오류(5xx)로 보이지 않게 한다.
     *
     * @param  TestConnectionRequest  $request  요청
     * @return JsonResponse 시험 결과
     */
    public function testConnection(TestConnectionRequest $request): JsonResponse
    {
        $result = $this->purges->testConnection();

        if (! $result['ok']) {
            return ResponseHelper::error(
                'sirsoft-cdn_cloudfront::messages.test_connection.'.$result['reason'],
                422,
                ['reason' => $result['reason'], 'error' => $result['error']],
                ['error' => (string) $result['error']],
            );
        }

        return ResponseHelper::success('sirsoft-cdn_cloudfront::messages.test_connection.success', $result);
    }
}
