<?php

namespace App\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Services\TemplateService;

class TemplateRouteResolver
{
    public function __construct(
        private readonly TemplateService $templateService,
        private readonly TemplateManagerInterface $templateManager,
        private readonly ModuleManagerInterface $moduleManager,
        private readonly PluginManagerInterface $pluginManager,
    ) {}

    /**
     * URL을 템플릿 레이아웃으로 매핑합니다.
     *
     * @param  string  $url  요청 URL 경로
     * @return array|null { templateIdentifier, layoutName, routeParams, moduleIdentifier? } 또는 null
     */
    public function resolve(string $url): ?array
    {
        $activeTemplate = $this->templateManager->getActiveTemplate('user');
        if (! $activeTemplate) {
            return null;
        }

        $templateIdentifier = $activeTemplate['identifier'] ?? null;
        if (! $templateIdentifier) {
            return null;
        }

        $routesResult = $this->templateService->getRoutesDataWithModules($templateIdentifier);
        if (! ($routesResult['success'] ?? false) || empty($routesResult['data']['routes'])) {
            return null;
        }

        $routes = $routesResult['data']['routes'];

        foreach ($routes as $route) {
            // auth_required 라우트 제외
            if ($route['auth_required'] ?? false) {
                continue;
            }

            // guest_only 라우트 제외
            if ($route['guest_only'] ?? false) {
                continue;
            }

            $routePath = $route['path'] ?? '';
            $routePath = $this->resolveRoutePath($routePath);

            $params = $this->matchRoute($routePath, $url);
            if ($params !== null) {
                $layoutName = $route['layout'] ?? '';

                // 확장 식별자 추출 (레이아웃명에 '.' 포함 시)
                $moduleIdentifier = null;
                $pluginIdentifier = null;
                if (str_contains($layoutName, '.')) {
                    [$extensionId, $layoutName] = explode('.', $layoutName, 2);

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

                return [
                    'templateIdentifier' => $templateIdentifier,
                    'layoutName' => $layoutName,
                    'routeParams' => $params,
                    'moduleIdentifier' => $moduleIdentifier,
                    'pluginIdentifier' => $pluginIdentifier,
                    'routeMeta' => $route['meta'] ?? [],
                ];
            }
        }

        return null;
    }

    /**
     * 라우트 경로의 동적 표현식을 해석합니다.
     *
     * @param  string  $routePath  라우트 경로
     * @return string 해석된 경로
     */
    private function resolveRoutePath(string $routePath): string
    {
        // 와일드카드 '*' 제거 (prefix 표시용)
        $routePath = ltrim($routePath, '*/');
        if (! str_starts_with($routePath, '/')) {
            $routePath = '/'.$routePath;
        }

        // 템플릿 표현식 해석: {{_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'}}
        $routePath = (string) preg_replace_callback('/\{\{(.+?)\}\}/', function ($matches) {
            $expr = trim($matches[1]);

            return $this->resolveRouteExpression($expr);
        }, $routePath);

        // 이중 슬래시 정규화 (빈 표현식 결과 시 발생)
        $routePath = preg_replace('#/+#', '/', $routePath);

        return $routePath;
    }

    /**
     * 라우트 경로 내 표현식을 해석합니다.
     *
     * @param  string  $expr  표현식
     * @return string 해석된 값
     */
    private function resolveRouteExpression(string $expr): string
    {
        // 삼항 연산자 패턴: condition ? '' : (modules?.['id']?.key ?? 'default')
        // no_route 플래그가 true면 빈 문자열, 아니면 route_path 또는 기본값 반환
        if (preg_match("/\.no_route\s*\?/", $expr)) {
            // 삼항 else 절(: 이후)에서 모듈 설정 추출
            $elseClause = preg_match("/:\s*(.+)$/", $expr, $elseMatch) ? $elseMatch[1] : '';
            if ($elseClause !== '' && preg_match("/modules\?\.\['([^']+)'\]\?\.([\\w?.]+)\s*\?\?\s*'(.+?)'/", $elseClause, $matches)) {
                $moduleId = $matches[1];
                // optional chaining 제거: basic_info?.route_path → basic_info.route_path
                $settingKey = str_replace('?.', '.', $matches[2]);
                $default = $matches[3];

                // no_route 설정 확인
                $noRoute = g7_module_settings($moduleId, 'basic_info.no_route');
                if ($noRoute) {
                    return '';
                }

                return g7_module_settings($moduleId, $settingKey) ?? $default;
            }
        }

        // _global.modules?.['module-id']?.key ?? 'default' 패턴 처리 (optional chaining 포함)
        if (preg_match("/modules\?\.\['([^']+)'\]\?\.([\\w?.]+)\s*\?\?\s*'(.+?)'/", $expr, $matches)) {
            $moduleId = $matches[1];
            $settingKey = str_replace('?.', '.', $matches[2]);
            $default = $matches[3];

            return g7_module_settings($moduleId, $settingKey) ?? $default;
        }

        // 단순 null coalescing
        if (str_contains($expr, '??')) {
            $parts = array_map('trim', explode('??', $expr, 2));

            return trim($parts[1], " '\"()");
        }

        return $expr;
    }

    /**
     * URL이 라우트 패턴에 매칭되는지 확인합니다.
     *
     * @param  string  $routePath  라우트 패턴 (예: /shop/products/:id)
     * @param  string  $url  실제 URL (예: /shop/products/123)
     * @return array|null 매칭된 파라미터 또는 null
     */
    private function matchRoute(string $routePath, string $url): ?array
    {
        // 정규식으로 변환
        $pattern = preg_replace('/:(\w+)/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^'.$pattern.'$#';

        if (preg_match($pattern, $url, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return $params;
        }

        return null;
    }
}
