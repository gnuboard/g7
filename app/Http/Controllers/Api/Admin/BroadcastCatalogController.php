<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Seo\Editor\BroadcastCatalogService;
use Illuminate\Http\JsonResponse;

/**
 * 웹소켓 채널/이벤트 카탈로그 엔드포인트 — 데이터소스 websocket 후보.
 *
 * 편집기 전용 가드(`core.templates.layouts.edit`) 하에서만 노출(권한/SEO 후보 엔드포인트와
 * 동일 패턴 — admin 전역 broadcast 회피, Bearer fetch). 등록 채널 + (동적) 이벤트 목록을
 * 반환한다. 미응답/빈 목록 시 편집기는 자유 텍스트 폴백.
 */
class BroadcastCatalogController extends AdminBaseController
{
    public function __construct(
        private readonly BroadcastCatalogService $catalogService,
    ) {
        parent::__construct();
    }

    /**
     * 채널/이벤트 카탈로그를 반환합니다.
     *
     * @param  string  $identifier  템플릿 식별자(라우트 일관성 — 카탈로그는 설치본 전역)
     * @return JsonResponse channels / events 응답
     */
    public function index(string $identifier): JsonResponse
    {
        $catalog = $this->catalogService->collect();

        return $this->success(
            'common.success',
            array_merge(['identifier' => $identifier], $catalog),
        );
    }
}
