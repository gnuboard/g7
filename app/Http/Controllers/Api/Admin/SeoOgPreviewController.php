<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\SeoOgPreviewRequest;
use App\Seo\Editor\SeoOgPreviewService;
use Illuminate\Http\JsonResponse;

/**
 * OG/Twitter/구조화 미리보기 엔드포인트 — 편집기 [검색엔진] 탭.
 *
 * 편집기 전용 가드(`core.templates.layouts.edit`) 하에서만 노출(Bearer fetch). dirty meta.seo +
 * 샘플로 og/twitter cascade 를 실제 계산하고 필터 전/후 diff 로 출처·잠김을 산출한다. 탭 진입 /
 * page_type·extensions 변경 시 재호출(이전 기본값 무효화).
 */
class SeoOgPreviewController extends AdminBaseController
{
    public function __construct(
        private readonly SeoOgPreviewService $previewService,
    ) {
        parent::__construct();
    }

    /**
     * og/twitter/structured 미리보기를 반환합니다.
     *
     * @param  SeoOgPreviewRequest  $request  dirty meta.seo·샘플·route 검증된 입력
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 키별 cascade(값/출처/override/필터잠김) + structured 미리보기
     */
    public function show(SeoOgPreviewRequest $request, string $identifier): JsonResponse
    {
        $validated = $request->validated();

        $preview = $this->previewService->preview(
            $validated['seo'],
            $validated['seed_context'] ?? [],
            $validated['route_params'] ?? [],
            $validated['own_seo'] ?? null,
            app()->getLocale(),
        );

        return $this->success(
            'common.success',
            array_merge(['identifier' => $identifier], $preview),
        );
    }
}
