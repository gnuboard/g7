<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Admin\AdminLogListRequest;
use Plugins\Sirsoft\SocialLogin\Services\DiagnosticsService;

/**
 * 관리자 진단 화면 — 요약 카드, 최근 시도 기록, 웹훅 수신 이력.
 *
 * @since 1.0.0
 */
class DiagnosticsController extends AdminBaseController
{
    /**
     * @param  DiagnosticsService  $diagnostics  진단 서비스
     */
    public function __construct(private readonly DiagnosticsService $diagnostics)
    {
        parent::__construct();
    }

    /**
     * 진단 요약을 반환합니다.
     *
     * @return JsonResponse providers·queue·mail·mock·members·webhooks
     */
    public function index(): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.diagnostics.loaded', $this->diagnostics->summary());
    }

    /**
     * 시도 기록을 최신순으로 반환합니다 (기본: 실패만 보려면 `outcome=failed`).
     *
     * @param  AdminLogListRequest  $request  검증된 요청
     * @return JsonResponse `{data, pagination}`
     */
    public function attempts(AdminLogListRequest $request): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.diagnostics.attempts', $this->diagnostics->attempts($request->validated()));
    }

    /**
     * 웹훅 수신 이력을 최신순으로 반환합니다 (본문은 비밀을 뺀 요약).
     *
     * @param  AdminLogListRequest  $request  검증된 요청
     * @return JsonResponse `{data, pagination}`
     */
    public function webhookEvents(AdminLogListRequest $request): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.diagnostics.webhook_events', $this->diagnostics->webhookEvents($request->validated()));
    }
}
