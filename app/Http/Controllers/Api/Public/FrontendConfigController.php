<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Resources\FrontendConfigResource;
use App\Services\FrontendConfigService;
use Illuminate\Http\JsonResponse;

/**
 * 사용자 공개 설정 API (#792 D8)
 *
 * G7 사용자 셸이 `window.G7Config` 로 내려 주는 설정과 같은 값을 외부 화면(Next.js·HTML+jQuery·Blade)도
 * 받게 한다. 노출 범위는 셸과 같고(frontend_schema 가 거른다), 엔진 전용 값은 싣지 않는다.
 */
class FrontendConfigController extends PublicBaseController
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
     * 사용자 공개 설정을 반환합니다. GET /api/frontend-config
     *
     * @return JsonResponse 공개 설정
     */
    public function show(): JsonResponse
    {
        return $this->success(
            'common.success',
            new FrontendConfigResource($this->frontendConfigService->build(FrontendConfigService::SCOPE_USER)),
        );
    }
}
