<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\LayoutPreviewService;
use App\Services\LayoutService;
use Illuminate\Http\JsonResponse;

/**
 * 공개 레이아웃 미리보기 API 컨트롤러
 *
 * 토큰 기반으로 미리보기 레이아웃 JSON을 서빙합니다.
 * 인증 불필요 (토큰이 보안 메커니즘).
 */
class LayoutPreviewController extends PublicBaseController
{
    /**
     * LayoutPreviewService 주입
     *
     * @param  LayoutPreviewService  $layoutPreviewService  미리보기 서비스
     */
    public function __construct(
        private LayoutPreviewService $layoutPreviewService
    ) {
        parent::__construct();
    }

    /**
     * 미리보기 레이아웃 JSON 서빙
     *
     * 토큰으로 편집 중인 레이아웃을 조회하고, 상속 병합 및 extension 적용 후 반환합니다.
     *
     * @param  string  $token  미리보기 토큰 (UUID)
     * @return JsonResponse
     */
    public function serve(string $token): JsonResponse
    {
        $layout = $this->layoutPreviewService->getPreviewLayout($token);

        if (! $layout) {
            return $this->notFound(__('templates.layout_not_found'));
        }

        // 실제 서빙(PublicLayoutController)과 같이 컴포넌트 permissions 를 요청자 기준으로 걸러 낸다 —
        // 미리보기만 걸러지지 않으면 편집자가 보는 화면이 실제와 다르고 권한 식별자가 응답에 남는다.
        $layout = app(LayoutService::class)->filterComponentsByPermissions($layout, request()->user());

        // audit:allow response-helper-bypass reason: 미리보기 서빙은 엔진이 읽는 레이아웃 JSON 원형을 그대로 낸다(공개 서빙과 같은 계약 — 봉투 없음)
        return response()->json($layout);
    }
}
