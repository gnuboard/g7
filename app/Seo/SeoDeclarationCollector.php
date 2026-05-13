<?php

namespace App\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Extension\HookManager;
use App\Services\LayoutService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Log;

/**
 * SEO 선언 수집기
 *
 * 모든 등록된 레이아웃에서 meta.seo.enabled=true인 레이아웃을 수집하고
 * 확장별로 그룹핑합니다.
 */
class SeoDeclarationCollector
{
    public function __construct(
        private readonly TemplateService $templateService,
        private readonly LayoutService $layoutService,
        private readonly TemplateManagerInterface $templateManager,
        private readonly ModuleManagerInterface $moduleManager,
        private readonly PluginManagerInterface $pluginManager,
    ) {}

    /**
     * SEO가 활성화된 레이아웃 선언 목록을 수집합니다.
     *
     * @return array<int, array{
     *     layoutName: string,
     *     templateIdentifier: string,
     *     moduleIdentifier: string|null,
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

            // 확장 식별자 추출 (레이아웃명에 '.' 포함 시)
            $moduleIdentifier = null;
            $pluginIdentifier = null;
            $actualLayoutName = $layoutName;
            if (str_contains($layoutName, '.')) {
                [$extensionId, $actualLayoutName] = explode('.', $layoutName, 2);

                // 모듈/플러그인 판별
                if ($this->moduleManager->getModule($extensionId) !== null) {
                    $moduleIdentifier = $extensionId;
                } elseif ($this->pluginManager->getPlugin($extensionId) !== null) {
                    $pluginIdentifier = $extensionId;
                } else {
                    // fallback: 기존 동작 유지 (모듈로 간주)
                    $moduleIdentifier = $extensionId;
                }
            }

            try {
                $layout = $this->layoutService->getLayout($templateIdentifier, $layoutName);
                $seo = $layout['meta']['seo'] ?? null;

                if (! $seo || ! ($seo['enabled'] ?? false)) {
                    continue;
                }

                $declarations[] = [
                    'layoutName' => $actualLayoutName,
                    'templateIdentifier' => $templateIdentifier,
                    'moduleIdentifier' => $moduleIdentifier,
                    'pluginIdentifier' => $pluginIdentifier,
                    'seo' => $seo,
                    'routePath' => $route['path'] ?? null,
                ];
            } catch (\Exception $e) {
                Log::warning('[SEO] 레이아웃 로드 실패 (SeoDeclarationCollector)', [
                    'layout' => $layoutName,
                    'error' => $e->getMessage(),
                ]);
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
