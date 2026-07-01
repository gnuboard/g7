<?php

namespace App\Seo\Contracts;

use Illuminate\Http\Request;

interface SeoRendererInterface
{
    /**
     * SEO HTML을 렌더링합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 렌더링된 HTML 또는 null (렌더링 불가 시)
     */
    public function render(Request $request): ?string;

    /**
     * 해석-후 파이프라인으로 SEO HTML 을 렌더링합니다.
     *
     * `render(Request)` 가 URL→라우트 해석·레이아웃 로드·실데이터 fetch 를 끝낸 뒤
     * 호출하는 내부 파이프라인(운영 경로). 편집기 봇 미리보기는 dirty 레이아웃 +
     * 편집기 샘플 데이터로 동일하게 호출해 운영과 같은 코드 경로로 완성 HTML 을
     * 만든다(캐시 우회). 입력 `$context` 는 data_sources/route/query/_global/_local
     * 까지 채워진 컨텍스트 시드여야 한다.
     *
     * @param  array  $mergedLayout  병합된 레이아웃 JSON (meta.seo 포함)
     * @param  array  $routeParams  라우트 동적 파라미터
     * @param  string  $url  현재 URL 경로
     * @param  string  $locale  렌더 로케일
     * @param  string  $templateIdentifier  편집 대상 템플릿 식별자
     * @param  string  $layoutName  레이아웃명
     * @param  string|null  $moduleId  소속 모듈 식별자
     * @param  string|null  $pluginId  소속 플러그인 식별자
     * @param  array  $context  해석된 컨텍스트 시드
     * @param  string|null  $defaultLocale  canonical 기본 로케일
     * @param  bool  $seoOnly  true 면 SEO 설정 산출물(title/description/keywords/canonical/
     *                         hreflang/og/twitter/jsonLd/검증 메타)만 담은 미리보기 HTML 을 렌더한다
     *  (편집기 봇 미리보기 — body 컴포넌트 마크업·CSS·시스템 기본
     *                         메타는 SEO 설정 산출물이 아니므로 미포함, bodyHtml 계산도 생략). 기본
     *                         false 는 운영 완성 HTML(종전 동작 동일).
     * @return string|null 렌더링된 HTML 또는 null
     */
    public function renderFromResolved(
        array $mergedLayout,
        array $routeParams,
        string $url,
        string $locale,
        string $templateIdentifier,
        string $layoutName,
        ?string $moduleId,
        ?string $pluginId,
        array $context,
        ?string $defaultLocale = null,
        bool $seoOnly = false,
    ): ?string;
}
