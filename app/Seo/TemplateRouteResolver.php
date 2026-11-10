<?php

namespace App\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Services\TemplateService;
use App\Support\RequestPath;

class TemplateRouteResolver
{
    /**
     * 템플릿 routes.json 이 아닌 **코어가 직접 소유하는** admin 경로 접두사.
     *
     * 레이아웃 편집기는 코어 기능이라 어느 템플릿의 routes.json 에도 선언되지 않는다.
     * 템플릿 라우트만 보고 판정하면 실제로 동작하는 화면이 HTTP 404 로 서빙되므로
     * (본문은 SPA 셸이라 화면은 뜨지만 상태 코드만 404), 존재하는 경로로 인정한다.
     *
     * @var array<string>
     */
    private const CORE_ADMIN_ROUTE_PREFIXES = [
        '/admin/layout-editor',
    ];

    /**
     * 라우트 판정에 필요한 템플릿·확장 의존성을 주입받습니다.
     *
     * @param  TemplateService  $templateService  병합 routes 조회
     * @param  TemplateManagerInterface  $templateManager  활성 템플릿 조회
     * @param  ModuleManagerInterface  $moduleManager  레이아웃 접두 확장의 모듈 판별
     * @param  PluginManagerInterface  $pluginManager  레이아웃 접두 확장의 플러그인 판별
     */
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
     * @return array|null { templateIdentifier, layoutName, storedLayoutName, routeParams, moduleIdentifier, pluginIdentifier, routeMeta } 또는 null
     */
    public function resolve(string $url): ?array
    {
        // 끝·연속 슬래시는 정규형으로 판정한다 — `/about/` 과 `/about` 은 같은 화면 (공개#156)
        $url = RequestPath::normalize($url);

        $loaded = $this->loadActiveRoutes('user');
        if ($loaded === null) {
            return null;
        }

        [$templateIdentifier, $routes] = $loaded;

        foreach ($routes as $route) {
            // auth_required 라우트 제외
            if ($route['auth_required'] ?? false) {
                continue;
            }

            // guest_only 라우트 제외
            if ($route['guest_only'] ?? false) {
                continue;
            }

            // 코어 시스템 라우트(미리보기 등)는 템플릿 레이아웃이 아니다 — 매핑하면 렌더러가 읽다 실패해 오류 로그만 남는다
            if ($route['meta']['is_system_route'] ?? false) {
                continue;
            }

            $routePath = $route['path'] ?? '';
            $routePath = $this->resolveRoutePath($routePath);

            $params = $this->matchRoute($routePath, $url);
            if ($params !== null) {
                $described = $this->describeRouteLayout($route['layout'] ?? '');

                return [
                    'templateIdentifier' => $templateIdentifier,
                    'layoutName' => $described['layoutName'],
                    'storedLayoutName' => $described['storedLayoutName'],
                    'routeParams' => $params,
                    'moduleIdentifier' => $described['moduleIdentifier'],
                    'pluginIdentifier' => $described['pluginIdentifier'],
                    'routeMeta' => $route['meta'] ?? [],
                ];
            }
        }

        return null;
    }

    /**
     * 라우트의 `layout` 값을 저장 이름·뗀 이름·소속 확장으로 풀어 냅니다.
     *
     * 확장 레이아웃은 라우트·DB(`template_layouts.name`)·편집기에서 `{확장식별자}.{레이아웃}` 전체
     * 이름으로 다룬다. 레이아웃을 조회하거나 캐시 항목에 이름을 남길 때는 반드시 전체 이름
     * (`storedLayoutName`)을 써야 한다 — 뗀 이름으로 찾으면 없거나(SPA 폴백), 같은 이름의 템플릿
     * 레이아웃을 집는다(공개#154). 뗀 이름(`layoutName`)은 공개 훅 컨텍스트 호환용이다.
     *
     * 판별 순서: 모듈 → 플러그인 → 둘 다 아니면 모듈로 간주(기존 동작).
     *
     * @param  string  $routeLayout  라우트의 layout 값 (예: `gnuboard7-hello_module.user_memo_list`, `home`)
     * @return array{layoutName: string, storedLayoutName: string, moduleIdentifier: string|null, pluginIdentifier: string|null}
     */
    public function describeRouteLayout(string $routeLayout): array
    {
        $described = [
            'layoutName' => $routeLayout,
            'storedLayoutName' => $routeLayout,
            'moduleIdentifier' => null,
            'pluginIdentifier' => null,
        ];

        if (! str_contains($routeLayout, '.')) {
            return $described;
        }

        [$extensionId, $described['layoutName']] = explode('.', $routeLayout, 2);

        if ($this->moduleManager->getModule($extensionId) !== null) {
            $described['moduleIdentifier'] = $extensionId;
        } elseif ($this->pluginManager->getPlugin($extensionId) !== null) {
            $described['pluginIdentifier'] = $extensionId;
        } else {
            // fallback: 기존 동작 유지 (모듈로 간주)
            $described['moduleIdentifier'] = $extensionId;
        }

        return $described;
    }

    /**
     * 레이아웃 전체 이름을 쓰는 활성 사용자 템플릿의 라우트를 찾습니다.
     *
     * 편집기 봇 미리보기는 URL 매핑을 거치지 않으므로, 운영 봇 렌더가 쓰는 라우트 `meta.title`
     * 대체를 같게 하려면 편집 중인 레이아웃의 라우트를 이름으로 찾아야 한다 (공개#154).
     * 이름은 라우트의 `layout` 값 그대로(확장 레이아웃은 전체 이름) 비교한다.
     *
     * @param  string  $storedLayoutName  레이아웃 전체 이름 (예: `gnuboard7-hello_module.user_memo_list`)
     * @return array{path: string, meta: array}|null 해석된 경로와 라우트 meta, 없으면 null
     */
    public function findRouteByLayout(string $storedLayoutName): ?array
    {
        $loaded = $this->loadActiveRoutes('user');
        if ($loaded === null) {
            return null;
        }

        foreach ($loaded[1] as $route) {
            if (($route['layout'] ?? '') === $storedLayoutName) {
                return [
                    'path' => RequestPath::normalize($this->resolveRoutePath($route['path'] ?? '')),
                    'meta' => $route['meta'] ?? [],
                ];
            }
        }

        return null;
    }

    /**
     * URL이 활성 템플릿(+모듈/플러그인 병합)의 등록된 라우트에 존재하는지 판별합니다.
     *
     * resolve()와 달리 auth_required/guest_only 라우트도 "존재"로 인정합니다.
     * 미등록 경로 catch-all 의 404 판별에 사용합니다 (공개#47).
     *
     * @param  string  $url  요청 URL 경로 (선행 '/' 포함)
     * @param  string  $type  템플릿 타입 ('user' | 'admin')
     * @return bool 등록 라우트면 true, 미등록이면 false. 템플릿 미설치/로드 실패 시 true(404 억제, 안전측).
     */
    public function routeExists(string $url, string $type = 'user'): bool
    {
        // 끝·연속 슬래시는 정규형으로 판정한다 — nginx 에서 `/admin/` 이 404 이던 원인 (공개#156)
        $url = RequestPath::normalize($url);

        // 코어 소유 admin 경로는 템플릿 routes.json 에 없어도 존재하는 경로다.
        if ($type === 'admin' && $this->isCoreAdminRoute($url)) {
            return true;
        }

        $loaded = $this->loadActiveRoutes($type);
        // 템플릿 미설치/로드 실패 시 전 경로 404 차단 — 안전측 폴백 (D-47-3).
        if ($loaded === null) {
            return true;
        }

        [, $routes] = $loaded;

        foreach ($routes as $route) {
            // auth_required/guest_only 검사 없이 path 만 매칭 (resolve()와 차이 — 핵심).
            $routePath = $this->resolveRoutePath($route['path'] ?? '');

            if ($this->matchRoute($routePath, $url) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * URL 이 코어가 직접 소유하는 admin 경로인지 판정합니다.
     *
     * 접두사 뒤에는 경로 구분자(`/`) 또는 문자열 끝만 허용한다 — `/admin/layout-editorX`
     * 같은 유사 경로가 통과하지 않도록 한다.
     *
     * @param  string  $url  요청 URL 경로
     * @return bool 코어 소유 경로면 true
     */
    private function isCoreAdminRoute(string $url): bool
    {
        $path = RequestPath::normalize(parse_url($url, PHP_URL_PATH) ?? $url);

        foreach (self::CORE_ADMIN_ROUTE_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 활성 템플릿($type)의 병합 routes 배열을 로드합니다.
     *
     * @param  string  $type  템플릿 타입 ('user' | 'admin')
     * @return array{0: string, 1: array}|null [templateIdentifier, routes] 또는 실패 시 null
     */
    private function loadActiveRoutes(string $type): ?array
    {
        $activeTemplate = $this->templateManager->getActiveTemplate($type);
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

        return [$templateIdentifier, $routesResult['data']['routes']];
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
