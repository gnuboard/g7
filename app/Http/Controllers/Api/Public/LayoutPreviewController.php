<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\LayoutPreviewService;
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
     * @param LayoutPreviewService $layoutPreviewService 미리보기 서비스
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
     * @param string $token 미리보기 토큰 (UUID)
     * @return JsonResponse
     */
    public function serve(string $token): JsonResponse
    {
        $layout = $this->layoutPreviewService->getPreviewLayout($token);

        if (! $layout) {
            return $this->notFound(__('templates.layout_not_found'));
        }

        return response()->json($layout);
    }
}
