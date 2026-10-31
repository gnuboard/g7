<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Admin\StatsRequest;
use Plugins\Sirsoft\SocialLogin\Services\StatsService;

/**
 * 관리자 통계 화면 — 기간별 제공자 × 가입·로그인·실패·연결.
 *
 * @since 1.0.0
 */
class StatsController extends AdminBaseController
{
    /**
     * @param  StatsService  $stats  통계 서비스
     */
    public function __construct(private readonly StatsService $stats)
    {
        parent::__construct();
    }

    /**
     * 통계를 반환합니다.
     *
     * @param  StatsRequest  $request  검증된 요청 (`days` 7·30·90, 기본 30)
     * @return JsonResponse days·since·rows·totals
     */
    public function index(StatsRequest $request): JsonResponse
    {
        return ResponseHelper::pluginSuccess(
            'sirsoft-social_login',
            'messages.admin.stats.loaded',
            $this->stats->forPeriod((int) ($request->validated('days') ?? 30)),
        );
    }
}
