<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\SeoBotPreviewRequest;
use App\Seo\Editor\SeoBotPreviewService;
use App\Seo\TemplateRouteResolver;
use App\Support\Utf8;
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
    /**
     * SEO 봇 미리보기 서비스와 라우트 레이아웃 해석기를 주입받습니다.
     *
     * @param  SeoBotPreviewService  $previewService  미리보기 렌더 서비스
     * @param  TemplateRouteResolver  $routeResolver  레이아웃 전체 이름 → 소속 모듈·플러그인 해석기
     */
    public function __construct(
        private readonly SeoBotPreviewService $previewService,
        private readonly TemplateRouteResolver $routeResolver,
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

        // 편집기는 라우트의 레이아웃 전체 이름을 보낸다 — 명시 식별자가 없으면 운영 봇 렌더와 같은
        // 해석기로 소속 확장을 채워, 모듈 화면 미리보기가 운영 응답과 같은 기준으로 렌더되게 한다 (공개#154)
        $moduleId = $validated['module_id'] ?? null;
        $pluginId = $validated['plugin_id'] ?? null;
        $layoutName = $validated['layout_name'] ?? null;
        if ($moduleId === null && $pluginId === null && ! empty($layoutName)) {
            $described = $this->routeResolver->describeRouteLayout($layoutName);
            $moduleId = $described['moduleIdentifier'];
            $pluginId = $described['pluginIdentifier'];
        }

        // 운영 봇 렌더는 다른 제목 단계가 모두 비면 라우트 meta.title 을 쓴다 — 미리보기도 같은 라우트의 제목을
        // 넘긴다. 사이트 첫 화면은 운영과 같이 라우트 제목(「홈」)을 붙이지 않는다.
        $routeTitle = null;
        if (! empty($layoutName)) {
            $route = $this->routeResolver->findRouteByLayout($layoutName);
            if ($route !== null && $route['path'] !== '/') {
                $routeTitle = $route['meta']['title'] ?? null;
            }
        }

        $html = $this->previewService->render(
            $validated['layout'],
            $validated['route_params'] ?? [],
            $validated['url'] ?? '/',
            $validated['locale'] ?? app()->getLocale(),
            $identifier,
            $moduleId,
            $pluginId,
            $validated['seed_context'] ?? [],
            $routeTitle,
            $layoutName,
        );

        // JSON 직렬화 경계 — 미리보기 HTML 에 유효하지 않은 UTF-8 바이트가 있으면 JsonResponse 가
        // "Malformed UTF-8" 로 500 을 던진다(샘플 데이터/표현식 평가 잔여로 잘린 멀티바이트 가능).
        // 응답 직전 한 번 정화해 직렬화를 보장한다(산출물 내용 불변 — 깨진 바이트만 제거).
        if (is_string($html)) {
            $html = Utf8::scrub($html);
        }

        return $this->success('common.success', [
            'identifier' => $identifier,
            // null = meta.seo.enabled=false 또는 미렌더 → 편집기 "검색엔진 미노출" 안내.
            'enabled' => $html !== null,
            'html' => $html,
        ]);
    }
}
