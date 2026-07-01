<?php

namespace App\Seo\Editor;

use App\Seo\Contracts\SeoRendererInterface;

/**
 * 봇 HTML 미리보기 서비스 — 편집기 [검색엔진] 탭 봇 미리보기.
 *
 * 운영 SeoRenderer 와 **동일 메타 해석 경로**(`renderFromResolved`)로 dirty 레이아웃 + 편집기
 * 샘플 데이터를 처리하되, **`seoOnly=true`** 로 호출해 검색엔진 탭·레이아웃·모듈/코어 SEO 설정이
 * **산출한 데이터만**(title/description/keywords/canonical/hreflang/og/twitter/JSON-LD/소유권
 * 확인 메타) 담긴 미리보기 HTML 을 받는다(body 컴포넌트 마크업·CSS·시스템 기본
 * 메타는 SEO 설정 산출물이 아니므로 미포함). 종전 `extractSeoMarkup` 후처리(완성 HTML 을 태그/
 * 속성 휴리스틱으로 가지치기)는 폐기 — 위치(head/body)나 태그명으로 넘겨짚지 않고 렌더 파이프라인이
 * 산출한 데이터의 정체성으로 식별한다. SEO 캐시 우회. 실 API fetch 없이 샘플(`seedContext`) 주입.
 *
 * `meta.seo.enabled=false` 면 render null 과 일치하게 null 을 반환한다(편집기가 "검색엔진
 * 미노출" 안내). Controller 가 가드(`core.templates.layouts.edit`)·응답을 담당.
 *
 * @since 7.0.0-beta.?
 */
class SeoBotPreviewService
{
    public function __construct(
        private readonly SeoRendererInterface $renderer,
    ) {}

    /**
     * dirty 레이아웃 + 샘플로 봇 HTML 미리보기를 렌더합니다.
     *
     * @param  array  $layout  편집 중(dirty) 병합 레이아웃 JSON (meta.seo 포함)
     * @param  array  $routeParams  샘플 라우트 파라미터
     * @param  string  $url  미리보기 URL 경로(canonical/hreflang 생성)
     * @param  string  $locale  렌더 로케일
     * @param  string  $templateIdentifier  편집 대상 템플릿 식별자
     * @param  string|null  $moduleId  소속 모듈 식별자
     * @param  string|null  $pluginId  소속 플러그인 식별자
     * @param  array  $seedContext  편집기 샘플 컨텍스트(sampleData/sampleGlobal._local 등)
     * @return string|null 완성 HTML, SEO 비활성 시 null
     */
    public function render(
        array $layout,
        array $routeParams,
        string $url,
        string $locale,
        string $templateIdentifier,
        ?string $moduleId,
        ?string $pluginId,
        array $seedContext,
    ): ?string {
        $layoutName = $layout['layout_name'] ?? ($layout['name'] ?? '');

        // 컨텍스트 시드 — 편집기 샘플 풀 + route/query 보강(운영 render() 의 컨텍스트 구성 대응).
        $context = $this->buildSeedContext($seedContext, $routeParams, $url);

        // seoOnly=true — 검색엔진 탭/레이아웃/모듈/코어 SEO 설정의 산출물(title/description/
        // keywords/canonical/hreflang/og/twitter/JSON-LD/소유권 확인 메타)만 담긴 미리보기 HTML.
        // 렌더 파이프라인이 분리해 둔 산출물 부분을 SEO 전용 블레이드로 조립하므로, 완성 HTML 을
        // 태그/속성 휴리스틱으로 후처리하지 않는다(body/CSS/시스템 메타는 SEO 산출물 아님).
        return $this->renderer->renderFromResolved(
            $layout,
            $routeParams,
            $url,
            $locale,
            $templateIdentifier,
            $layoutName,
            $moduleId,
            $pluginId,
            $context,
            null,
            true,
        );
    }

    /**
     * 편집기 샘플 풀에 route/query 를 보강해 렌더 컨텍스트를 만듭니다.
     *
     * 운영 render() 가 data_sources fetch 결과 + route + query + _global + _local 로 컨텍스트를
     * 구성하는 것과 대응 — 미리보기는 실 fetch 대신 편집기 샘플(`seedContext`)을 그대로 쓴다.
     *
     * @param  array  $seedContext  편집기 샘플 컨텍스트
     * @param  array  $routeParams  샘플 라우트 파라미터
     * @param  string  $url  현재 URL 경로
     * @return array 렌더 컨텍스트
     */
    private function buildSeedContext(array $seedContext, array $routeParams, string $url): array
    {
        $context = $seedContext;

        // route 보강(샘플에 명시 안 됐으면 routeParams + path).
        $context['route'] = array_merge($routeParams, ['path' => $url], (array) ($seedContext['route'] ?? []));
        $context['query'] = (array) ($seedContext['query'] ?? []);
        // _global/_local 은 샘플에 있으면 그대로, 없으면 빈 객체(엔진 평가 안전).
        $context['_global'] = (array) ($seedContext['_global'] ?? []);
        $context['_local'] = (array) ($seedContext['_local'] ?? []);

        return $context;
    }
}
