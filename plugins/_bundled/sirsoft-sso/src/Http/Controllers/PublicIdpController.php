<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Http\Requests\PublicIdpListRequest;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;

/**
 * 로그인 화면 버튼용 공개 IdP 목록 — 키, 라벨, 버튼 색, 시작 주소만 (설정, 비밀값 없음).
 *
 * @since 1.0.0
 */
class PublicIdpController extends PublicBaseController
{
    /**
     * @param  LoginFlowService  $flow  왕복 서비스
     */
    public function __construct(private readonly LoginFlowService $flow)
    {
        parent::__construct();
    }

    /**
     * 공개 IdP 목록을 반환합니다.
     *
     * @param  PublicIdpListRequest  $request  요청 (`guard=user|admin`, `redirect`)
     * @return JsonResponse 목록
     */
    public function index(PublicIdpListRequest $request): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-sso', 'messages.idps.listed', $this->flow->publicIdps($request->guardName(), $request->redirectPath()));
    }
}
