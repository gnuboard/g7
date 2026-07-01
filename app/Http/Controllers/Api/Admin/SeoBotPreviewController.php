<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\SeoBotPreviewRequest;
use App\Seo\Editor\SeoBotPreviewService;
use Illuminate\Http\JsonResponse;

/**
 * 봇 HTML 실시간 미리보기 엔드포인트 — 편집기 [검색엔진] 탭.
 *
 * 편집기 전용 가드(`core.templates.layouts.edit`) 하에서만 노출(Bearer fetch). dirty 레이아웃
 * + 편집기 샘플 데이터로 운영과 동일 코드 경로(`renderFromResolved`)를 거쳐 완성 HTML 을
 * 반환한다(SEO 캐시 우회). 설정 변경 디바운스 후 재호출로 실시간 갱신된다.
 */
class SeoBotPreviewController extends AdminBaseController
{
    public function __construct(
        private readonly SeoBotPreviewService $previewService,
    ) {
        parent::__construct();
    }

    /**
     * dirty 레이아웃 + 샘플로 봇 HTML 미리보기를 반환합니다.
     *
     * @param  SeoBotPreviewRequest  $request  dirty 레이아웃·샘플·route·locale 검증된 입력
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 완성 HTML 또는 SEO 미노출 안내
     */
    public function show(SeoBotPreviewRequest $request, string $identifier): JsonResponse
    {
        $validated = $request->validated();

        $html = $this->previewService->render(
            $validated['layout'],
            $validated['route_params'] ?? [],
            $validated['url'] ?? '/',
            $validated['locale'] ?? app()->getLocale(),
            $identifier,
            $validated['module_id'] ?? null,
            $validated['plugin_id'] ?? null,
            $validated['seed_context'] ?? [],
        );

        // JSON 직렬화 경계 — 미리보기 HTML 에 유효하지 않은 UTF-8 바이트가 있으면 JsonResponse 가
        // "Malformed UTF-8" 로 500 을 던진다(샘플 데이터/표현식 평가 잔여로 잘린 멀티바이트 가능).
        // 응답 직전 한 번 정화해 직렬화를 보장한다(산출물 내용 불변 — 깨진 바이트만 제거).
        if (is_string($html)) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $html);
            $html = $clean === false ? mb_convert_encoding($html, 'UTF-8', 'UTF-8') : $clean;
        }

        return $this->success('common.success', [
            'identifier' => $identifier,
            // null = meta.seo.enabled=false 또는 미렌더 → 편집기 "검색엔진 미노출" 안내.
            'enabled' => $html !== null,
            'html' => $html,
        ]);
    }
}
