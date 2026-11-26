<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Resources\FrontendConfigResource;
use App\Services\FrontendConfigService;
use Illuminate\Http\JsonResponse;

/**
 * 관리자 공개 설정 API
 *
 * G7 관리자 셸의 `window.G7Config` 와 같은 설정을 외부 관리자 화면이 받게 한다. 관리자 인증이 필요하다 —
 * 로그인 전 단계(관리자 로그인 화면)는 사용자 공개 설정 API 를 쓴다.
 */
class FrontendConfigController extends AdminBaseController
{
    /**
     * @param  FrontendConfigService  $frontendConfigService  공개 설정 조립
     */
    public function __construct(
        private readonly FrontendConfigService $frontendConfigService,
    ) {
        parent::__construct();
    }

    /**
     * 관리자 공개 설정을 반환합니다. GET /api/admin/frontend-config
     *
     * @return JsonResponse 공개 설정
     */
    public function show(): JsonResponse
    {
        return $this->success(
            'common.success',
            new FrontendConfigResource($this->frontendConfigService->build(FrontendConfigService::SCOPE_ADMIN)),
        );
    }
}
