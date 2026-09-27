<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\RealtimeRequest;
use App\Services\Analytics\RealtimeService;
use Illuminate\Http\JsonResponse;

/**
 * 현재 접속자 API
 *
 * 최근 몇 분 안에 움직인 방문자를 보여 줍니다. 주소는 개인정보 권한을 가진 사람에게만
 * 채워 보냅니다 — 권한이 없으면 그 칸이 비어서 나갑니다.
 */
class RealtimeController extends AdminBaseController
{
    /**
     * 현재 접속자 컨트롤러를 생성합니다.
     *
     * @param  RealtimeService  $realtime  현재 접속자 서비스
     */
    public function __construct(
        private readonly RealtimeService $realtime,
    ) {
        parent::__construct();
    }

    /**
     * 현재 접속자 현황을 조회합니다.
     *
     * @param  RealtimeRequest  $request  현재 접속자 요청
     * @return JsonResponse 현황 응답
     */
    public function index(RealtimeRequest $request): JsonResponse
    {
        $config = AnalyticsConfig::fromSettings();

        // 주소를 볼 권한은 목록을 볼 권한과 다르다 — 화면 하나에서 두 권한이 갈린다.
        $includeIp = (bool) $request->user()?->hasPermission('core.analytics.privacy');

        return $this->success('analytics.realtime.fetch_success', $this->realtime->snapshot($config, [
            'minutes' => $request->input('minutes'),
            'include_ip' => $includeIp,
        ]));
    }
}
