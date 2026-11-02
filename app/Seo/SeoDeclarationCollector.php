<?php

namespace App\Seo;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Extension\HookManager;
use App\Services\LayoutService;
use App\Services\TemplateService;
use App\Support\LayoutEmptyObjects;

/**
 * SEO 선언 수집기
 *
 * 모든 등록된 레이아웃에서 meta.seo.enabled=true인 레이아웃을 수집하고
 * 확장별로 그룹핑합니다.
 */
class SeoDeclarationCollector
{
    /**
     * 수집에 필요한 서비스와 라우트 레이아웃 해석기를 주입합니다.
     *
     * @param  TemplateService  $templateService  템플릿 서비스 (활성 템플릿 조회)
     * @param  LayoutService  $layoutService  레이아웃 서비스 (병합 레이아웃 로드)
     * @param  TemplateManagerInterface  $templateManager  템플릿 관리자
     * @param  TemplateRouteResolver  $routeResolver  라우트 레이아웃 이름 해석기 (봇 렌더와 같은 판별)
     */
    public function __construct(
        private readonly TemplateService $templateService,
        private readonly LayoutService $layoutService,
        private readonly TemplateManagerInterface $templateManager,
        private readonly TemplateRouteResolver $routeResolver,
    ) {}

    /**
     * SEO가 활성화된 레이아웃 선언 목록을 수집합니다.
     *
     * @return array<int, array{
     *     layoutName: string,
     *     storedLayoutName: string,
     *     templateIdentifier: string,
     *     moduleIdentifier: string|null,
     *     pluginIdentifier: string|null,
     *     seo: array,
     *     routePath: string|null
     * }>
     */
    public function collect(): array
    {
        $activeTemplate = $this->templateManager->getActiveTemplate('user');
        if (! $activeTemplate) {
            return [];
        }

        $templateIdentifier = $activeTemplate['identifier'] ?? null;
        if (! $templateIdentifier) {
            return [];
        }

        $routesResult = $this->templateService->getRoutesDataWithModules($templateIdentifier);
        if (! ($routesResult['success'] ?? false) || empty($routesResult['data']['routes'])) {
            return [];
        }

        $declarations = [];
        $routes = $routesResult['data']['routes'];

        foreach ($routes as $route) {
            // auth_required/guest_only는 SEO 대상이 아님
            if ($route['auth_required'] ?? false) {
                continue;
            }
            if ($route['guest_only'] ?? false) {
                continue;
            }

            $layoutName = $route['layout'] ?? '';
            if (empty($layoutName)) {
                continue;
            }

            // 확장 식별자·전체 이름 해석 — 봇 렌더(TemplateRouteResolver::resolve)와 같은 판별을 쓴다
            $described = $this->routeResolver->describeRouteLayout($layoutName);

            try {
                $layout = LayoutEmptyObjects::toArrays($this->layoutService->getLayout($templateIdentifier, $layoutName));
                $seo = $layout['meta']['seo'] ?? null;

                if (! $seo || ! ($seo['enabled'] ?? false)) {
                    continue;
                }

                $declarations[] = [
                    'layoutName' => $described['layoutName'],
                    'storedLayoutName' => $described['storedLayoutName'],
                    'templateIdentifier' => $templateIdentifier,
                    'moduleIdentifier' => $described['moduleIdentifier'],
                    'pluginIdentifier' => $described['pluginIdentifier'],
                    'seo' => $seo,
                    'routePath' => $route['path'] ?? null,
                ];
            } catch (\Throwable $e) {
                // 오류로 보고하고 그 라우트만 건너뛴다 — 사이트맵·봇 화면의 나머지 선언 수집은 계속한다(#155)
                report(new \RuntimeException('[SEO] SeoDeclarationCollector layout load failed: '.$layoutName, 0, $e));
            }
        }

        // 필터 훅: 코드 기반 SEO 레이아웃 등록 지원
        $declarations = HookManager::applyFilters('core.seo.register_layouts', $declarations);

        return $declarations;
    }

    /**
     * SEO 선언을 확장별로 그룹핑합니다.
     *
     * @return array<string, array> 확장 식별자를 키로 하는 그룹핑된 선언 목록
     */
    public function collectGroupedByExtension(): array
    {
        $declarations = $this->collect();
        $grouped = [];

        foreach ($declarations as $declaration) {
            $key = $declaration['moduleIdentifier'] ?? $declaration['pluginIdentifier'] ?? 'core';
            $grouped[$key][] = $declaration;
        }

        return $grouped;
    }
}
