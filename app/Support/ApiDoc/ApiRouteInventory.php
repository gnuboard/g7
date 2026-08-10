<?php

namespace App\Support\ApiDoc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * API 라우트 인벤토리
 *
 * 등록된 라우트를 전수 수집하여 API 문서 생성에 필요한 메타데이터
 * (메서드·URI·라우트명·소유 확장·권한·컨트롤러 액션)로 정규화합니다.
 */
class ApiRouteInventory
{
    /**
     * API 라우트를 전수 수집하여 정규화된 배열로 반환합니다.
     *
     * @param  string|null  $scope  범위 필터 (null=전체, 'core', 'module:{id}', 'plugin:{id}')
     * @return array<int, array<string, mixed>> 정규화된 라우트 메타데이터 목록
     */
    public function collect(?string $scope = null): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            $name = $route->getName() ?? '';
            $owner = $this->resolveOwner($name, $uri);

            if (! $this->isDocumentable($uri, $name, $owner)) {
                continue;
            }

            if ($scope !== null && ! $this->matchesScope($owner, $scope)) {
                continue;
            }

            foreach ($this->normalizeRoute($route, $uri, $name, $owner) as $entry) {
                $routes[] = $entry;
            }
        }

        // route:list 는 활성 확장만 노출한다. 비활성/미설치 확장을 명시 범위로 지정하면
        // (module:{id} / plugin:{id}) 등록된 라우트가 0건이 되므로, 그 확장의 번들 라우트
        // 파일을 프로바이더와 동일한 prefix/name 규약으로 임시 라우터에 로드해 폴백 수집한다.
        if ($routes === [] && $scope !== null && $scope !== 'core' && $scope !== 'all') {
            $routes = $this->collectFromBundledFiles($scope);
        }

        usort($routes, fn ($a, $b) => [$a['owner']['key'], $a['uri'], $a['method']] <=> [$b['owner']['key'], $b['uri'], $b['method']]);

        return $routes;
    }

    /**
     * 라우트가 API 레퍼런스 문서 대상인지 판별합니다.
     *
     * 두 부류를 수집한다:
     *  1. `api/` prefix 라우트 (코어 + 확장의 src/routes/api.php)
     *  2. 확장 소유가 확정된 web 라우트 중 관리자 화면(admin 컨텍스트)이 아닌 것
     *
     * (2)가 필요한 이유: PG 결제 콜백·웹훅은 외부 시스템(PG사 서버, 브라우저 리다이렉트)이
     * 호출하는 machine-facing 엔드포인트라 API 레퍼런스 대상이지만, CSRF 토큰 부재·세션 특성상
     * `api.php` 가 아니라 `web.php` 에 등록된다. `api/` 만 수집하면 이들이 영구히 무문서가 된다.
     *
     * 제외 대상:
     *  - 코어 web 라우트 (블레이드 화면·SPA 진입점 등) — 소유가 'core'
     *  - 확장의 관리자 화면 web 라우트 (`web.{dir}.{id}.admin.*`) — 사람이 브라우저로 여는
     *    화면이라 API 레퍼런스 대상이 아니다. 코어 admin API 는 `api/` prefix 라 영향 없다.
     *
     * @param  string  $uri  라우트 URI
     * @param  string  $name  라우트명
     * @param  array{type: string, id: string|null, key: string}  $owner  소유 주체
     * @return bool 문서 대상 여부
     */
    private function isDocumentable(string $uri, string $name, array $owner): bool
    {
        if (Str::startsWith($uri, 'api/')) {
            return true;
        }

        // 코어 web 라우트(화면)는 대상 아님
        if ($owner['type'] === 'core') {
            return false;
        }

        // 확장 web 라우트 중 관리자 화면(admin 컨텍스트)은 대상 아님
        return ! preg_match('/^web\.(?:modules|plugins)\.[^.]+\.admin\./', $name);
    }

    /**
     * 하나의 라우트를 HTTP 메서드별 정규화 엔트리 배열로 변환합니다.
     *
     * @param  Route  $route  라우트 인스턴스
     * @param  string  $uri  라우트 URI ('api/' prefix 포함)
     * @param  string  $name  라우트명
     * @param  array{type: string, id: string|null, key: string}  $owner  소유 주체
     * @return array<int, array<string, mixed>> 정규화된 엔트리 목록
     */
    private function normalizeRoute(Route $route, string $uri, string $name, array $owner): array
    {
        $entries = [];

        $middleware = $this->gatherMiddleware($route);

        foreach ($this->httpMethods($route) as $method) {
            $entries[] = [
                'method' => $method,
                'uri' => '/'.ltrim($uri, '/'),
                'name' => $name,
                'owner' => $owner,
                'action' => $route->getActionName(),
                'controller' => $this->controllerClass($route),
                'controller_method' => $this->controllerMethod($route),
                'middleware' => $middleware,
                'permission' => $this->resolvePermission($middleware),
                'path_params' => $this->pathParams($uri),
                'path_bindings' => $this->pathBindings($route),
                'domain_group' => $this->domainGroup($name, $uri, $owner),
            ];
        }

        return $entries;
    }

    /**
     * 라우트의 미들웨어 목록을 안전하게 수집합니다.
     *
     * `gatherMiddleware()` 는 컨트롤러 정의 미들웨어를 읽기 위해 컨트롤러를 인스턴스화한다.
     * 비활성/미설치 확장은 서비스 바인딩이 부팅되지 않아 인스턴스화가 실패할 수 있으므로,
     * 실패 시 라우트에 직접 부여된 정적 미들웨어(`middleware()`)로 폴백한다.
     *
     * @param  Route  $route  라우트 인스턴스
     * @return array<int, string> 미들웨어 목록
     */
    private function gatherMiddleware(Route $route): array
    {
        try {
            return $route->gatherMiddleware();
        } catch (\Throwable) {
            return $route->middleware();
        }
    }

    /**
     * 비활성/미설치 확장의 번들 라우트 파일을 임시 라우터에 로드해 수집합니다.
     *
     * 프로바이더(`ModuleRouteServiceProvider`/`PluginRouteServiceProvider`)와 동일한
     * prefix·name·미들웨어 규약으로 번들 라우트 파일을 라우터에 로드한다:
     *  - `src/routes/api.php` → prefix `api/{dir}/{id}`, name `api.{dir}.{id}.`, middleware `api`
     *  - `src/routes/web.php` → prefix `{dir}/{id}`, name `web.{dir}.{id}.`, middleware `web`
     *
     * web 라우트를 함께 로드하는 이유: PG 결제 콜백·웹훅은 CSRF/세션 특성상 web.php 에
     * 등록되지만 외부 시스템이 호출하는 machine-facing 엔드포인트라 API 문서 대상이다.
     * 관리자 화면용 web 라우트는 문서에 섞이지만, 확장 소유 라우트는 모두 문서 대상이라는
     * 활성 경로(`isDocumentable`)의 판정과 일관된다.
     *
     * 라우트 파일은 `use Illuminate\Support\Facades\Route` 로 전역 파사드에 등록하므로,
     * 등록 후 uri/name prefix 로 이 확장 소유 라우트만 골라낸다. 이 커맨드는 CLI 단발
     * 프로세스라 전역 라우트 테이블 추가가 웹 요청에 영향을 주지 않는다.
     *
     * @param  string  $scope  범위 필터 (`module:{id}` / `plugin:{id}`)
     * @return array<int, array<string, mixed>> 정규화된 라우트 메타데이터 목록
     */
    private function collectFromBundledFiles(string $scope): array
    {
        if (! preg_match('/^(module|plugin):(.+)$/', $scope, $m)) {
            return [];
        }

        [$type, $id] = [$m[1], $m[2]];
        $dir = $type === 'module' ? 'modules' : 'plugins';

        $groups = [
            ['file' => base_path("{$dir}/_bundled/{$id}/src/routes/api.php"), 'prefix' => "api/{$dir}/{$id}", 'name' => "api.{$dir}.{$id}.", 'middleware' => 'api'],
            ['file' => base_path("{$dir}/_bundled/{$id}/src/routes/web.php"), 'prefix' => "{$dir}/{$id}", 'name' => "web.{$dir}.{$id}.", 'middleware' => 'web'],
        ];

        $loaded = false;

        foreach ($groups as $group) {
            if (! is_file($group['file'])) {
                continue;
            }

            try {
                RouteFacade::prefix($group['prefix'])
                    ->name($group['name'])
                    ->middleware($group['middleware'])
                    ->group($group['file']);
                $loaded = true;
            } catch (\Throwable) {
                // 개별 라우트 파일 로드 실패는 그 파일만 건너뛴다 (나머지는 계속 수집).
                continue;
            }
        }

        if (! $loaded) {
            return [];
        }

        RouteFacade::getRoutes()->refreshNameLookups();

        // 이 확장 소유로 확정된 라우트를 전역 라우트 테이블에서 prefix(uri/name) 로 직접 골라낸다.
        // object-id 스냅샷 방식은 refreshNameLookups() 가 RouteCollection 을 재색인하며 객체를
        // 다시 만들어 dedup 이 어긋나므로(활성 확장 로드 순서·중복 로드에 취약) 쓰지 않는다.
        // 대상 확장은 uri/name 이 `{api/}{dir}/{id}/` · `{api|web}.{dir}.{id}.` 로 시작해
        // 결정적으로 식별된다.
        $seen = [];
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            $name = $route->getName() ?? '';
            $owner = $this->resolveOwner($name, $uri);

            if (! $this->isDocumentable($uri, $name, $owner)) {
                continue;
            }

            // 폴백 대상 확장 소유로 확정되지 않으면(코어·타 확장) 제외
            if ($owner['key'] !== $scope) {
                continue;
            }

            foreach ($this->normalizeRoute($route, $uri, $name, $owner) as $entry) {
                // 파일이 이미 한 번 로드돼 있으면 같은 (method, uri) 가 중복될 수 있어 dedup 한다.
                $dedupKey = $entry['method'].' '.$entry['uri'];
                if (isset($seen[$dedupKey])) {
                    continue;
                }
                $seen[$dedupKey] = true;
                $routes[] = $entry;
            }
        }

        return $routes;
    }

    /**
     * 라우트명/URI 로 소유 주체(코어/모듈/플러그인)를 판별합니다.
     *
     * @param  string  $name  라우트명
     * @param  string  $uri  라우트 URI
     * @return array{type: string, id: string|null, key: string} 소유 주체 정보
     */
    private function resolveOwner(string $name, string $uri): array
    {
        // 라우트명 규약: api 라우트는 `api.{modules|plugins}.{id}.*`,
        // web 라우트(PG 콜백/웹훅)는 `web.{modules|plugins}.{id}.*`
        if (preg_match('/^(?:api|web)\.modules\.([^.]+)\./', $name, $m) && $this->isRealExtensionId($m[1])) {
            return ['type' => 'module', 'id' => $m[1], 'key' => 'module:'.$m[1]];
        }

        if (preg_match('/^(?:api|web)\.plugins\.([^.]+)\./', $name, $m) && $this->isRealExtensionId($m[1])) {
            return ['type' => 'plugin', 'id' => $m[1], 'key' => 'plugin:'.$m[1]];
        }

        // 라우트명이 비어도 URI prefix 로 보조 판별 (api: `api/{dir}/{id}/`, web: `{dir}/{id}/`)
        if (preg_match('#^(?:api/)?modules/([^/{]+)/#', $uri, $m) && $this->isRealExtensionId($m[1])) {
            return ['type' => 'module', 'id' => $m[1], 'key' => 'module:'.$m[1]];
        }

        if (preg_match('#^(?:api/)?plugins/([^/{]+)/#', $uri, $m) && $this->isRealExtensionId($m[1])) {
            return ['type' => 'plugin', 'id' => $m[1], 'key' => 'plugin:'.$m[1]];
        }

        // 코어가 제공하는 확장 메타/에셋 라우트(/api/modules/{identifier}/license,
        // /api/modules/bundle.js 등)는 실제 확장 소유가 아니라 코어 소유다.
        return ['type' => 'core', 'id' => null, 'key' => 'core'];
    }

    /**
     * 세그먼트가 실제 확장 식별자인지 판별합니다 (플레이스홀더/에셋 라우트 제외).
     *
     * @param  string  $segment  URI/라우트명 세그먼트
     * @return bool 실제 확장 식별자 여부
     */
    private function isRealExtensionId(string $segment): bool
    {
        // {identifier} 같은 라우트 파라미터, bundle/assets 같은 코어 에셋 라우트 제외.
        // 실제 확장 식별자는 vendor-name 형태로 하이픈을 포함한다.
        if (Str::startsWith($segment, '{') || in_array($segment, ['assets', 'bundle', 'bundle.js', 'bundle.css'], true)) {
            return false;
        }

        return Str::contains($segment, '-');
    }

    /**
     * 소유 주체가 지정된 범위 필터에 매칭되는지 확인합니다.
     *
     * @param  array{type: string, id: string|null, key: string}  $owner  소유 주체
     * @param  string  $scope  범위 필터
     * @return bool 매칭 여부
     */
    private function matchesScope(array $owner, string $scope): bool
    {
        if ($scope === 'all') {
            return true;
        }

        if ($scope === 'core') {
            return $owner['type'] === 'core';
        }

        return $owner['key'] === $scope;
    }

    /**
     * 라우트의 HTTP 메서드 목록을 반환합니다 (HEAD 제외).
     *
     * @param  Route  $route  라우트 인스턴스
     * @return array<int, string> HTTP 메서드 목록
     */
    private function httpMethods(Route $route): array
    {
        return array_values(array_filter(
            $route->methods(),
            fn ($m) => $m !== 'HEAD'
        ));
    }

    /**
     * 컨트롤러 클래스명을 반환합니다.
     *
     * @param  Route  $route  라우트 인스턴스
     * @return string|null 컨트롤러 FQCN (클로저면 null)
     */
    private function controllerClass(Route $route): ?string
    {
        $action = $route->getActionName();

        if ($action === 'Closure' || ! Str::contains($action, '@')) {
            return null;
        }

        return Str::before($action, '@');
    }

    /**
     * 컨트롤러 메서드명을 반환합니다.
     *
     * @param  Route  $route  라우트 인스턴스
     * @return string|null 메서드명 (클로저면 null)
     */
    private function controllerMethod(Route $route): ?string
    {
        $action = $route->getActionName();

        if ($action === 'Closure' || ! Str::contains($action, '@')) {
            return null;
        }

        return Str::after($action, '@');
    }

    /**
     * 미들웨어 목록에서 permission 식별자를 추출합니다.
     *
     * @param  array<int, string>  $middleware  미들웨어 목록
     * @return string|null permission 식별자 (예: core.users.read)
     */
    private function resolvePermission(array $middleware): ?string
    {
        foreach ($middleware as $mw) {
            if (! Str::contains($mw, 'PermissionMiddleware') && ! Str::startsWith($mw, 'permission:')) {
                continue;
            }

            $args = Str::after($mw, ':');
            // permission:admin,core.users.read,except:self:user → core.users.read
            foreach (explode(',', $args) as $part) {
                $part = trim($part);
                if (Str::contains($part, '.') && ! Str::startsWith($part, 'except')) {
                    return $part;
                }
            }
        }

        return null;
    }

    /**
     * URI 의 path 파라미터 목록을 추출합니다.
     *
     * @param  string  $uri  라우트 URI
     * @return array<int, string> path 파라미터명 목록
     */
    private function pathParams(string $uri): array
    {
        preg_match_all('/\{([^}?]+)\??\}/', $uri, $m);

        return $m[1] ?? [];
    }

    /**
     * path 파라미터명 → 바인딩된 Eloquent 모델 클래스 맵을 추출합니다.
     *
     * @param  Route  $route  라우트 인스턴스
     * @return array<string, string> 파라미터명 => 모델 FQCN
     */
    private function pathBindings(Route $route): array
    {
        $bindings = [];

        try {
            foreach ($route->signatureParameters() as $param) {
                $type = $param->getType();

                if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                    $class = $type->getName();

                    if (is_subclass_of($class, Model::class)) {
                        $bindings[$param->getName()] = $class;
                    }
                }
            }
        } catch (\Throwable) {
            // signatureParameters 실패 시 빈 맵
        }

        return $bindings;
    }

    /**
     * 문서 파일 그룹핑용 도메인 키를 산출합니다.
     *
     * @param  string  $name  라우트명
     * @param  string  $uri  라우트 URI
     * @param  array{type: string, id: string|null, key: string}  $owner  소유 주체
     * @return string 도메인 키 (예: users, products)
     */
    private function domainGroup(string $name, string $uri, array $owner): string
    {
        // 라우트명 구조: api.{context}.{resource}.{action} 또는
        //               api.modules.{ext-id}.{context}.{resource}.{action}
        // 도메인 = 앞쪽 연속된 context/소유 세그먼트를 걷어낸 뒤의 첫 세그먼트(리소스).
        // 위치 기반이므로 'auth' 같은 리소스가 skip 리스트에 걸려 소실되지 않는다.
        // 'modules'/'plugins' 는 확장 소유 prefix(api.modules.{id}.*)로도, 코어 확장관리
        // 리소스(api.admin.modules.*)로도 쓰인다. 소유 prefix 는 owner['id'] 스킵으로 이미
        // 걷히므로, leading 에는 순수 컨텍스트(api/admin/user/me/public)만 둔다.
        // 'web' 은 확장 PG 콜백/웹훅 라우트명(web.plugins.{id}.*)의 leading 컨텍스트다.
        $leading = ['api', 'web', 'admin', 'user', 'me', 'public'];
        $segments = array_values(array_filter(explode('.', $name), fn ($s) => $s !== ''));

        $i = 0;
        while ($i < count($segments)) {
            $seg = $segments[$i];
            // 확장 소유 prefix 세그먼트(modules/plugins + ext-id)만 스킵
            $isOwnerPrefix = in_array($seg, ['modules', 'plugins'], true) && isset($segments[$i + 1]) && $segments[$i + 1] === $owner['id'];
            $isLeading = in_array($seg, $leading, true) || $isOwnerPrefix || ($owner['id'] !== null && $seg === $owner['id']);

            if (! $isLeading) {
                break;
            }
            $i++;
        }

        // context(me 등) 직후가 REST 액션명이면 리소스가 아니라 그 컨텍스트가 도메인이다
        // (api.me.show → 'me', api.me.destroy → 'me'). 아니면 그 세그먼트가 리소스.
        $restActions = ['show', 'index', 'store', 'update', 'destroy', 'edit', 'create'];
        if (isset($segments[$i])) {
            if (in_array($segments[$i], $restActions, true) && $i > 0 && in_array($segments[$i - 1], $leading, true)) {
                return Str::slug($segments[$i - 1]) ?: 'misc';
            }

            return Str::slug($segments[$i]) ?: 'misc';
        }

        // 리소스 세그먼트가 없으면(context 직속) 마지막 컨텍스트를 도메인으로.
        if ($i > 0 && isset($segments[$i - 1]) && in_array($segments[$i - 1], $leading, true)) {
            return Str::slug($segments[$i - 1]) ?: 'misc';
        }

        // 라우트명이 비었으면 URI 에서 소유/컨텍스트 prefix 를 걷어낸 첫 리소스 세그먼트
        $parts = array_values(array_filter(explode('/', $uri), fn ($p) => $p !== '' && ! Str::startsWith($p, '{')));
        $uriLeading = ['api', 'admin', 'user', 'me', 'public'];
        foreach ($parts as $p) {
            if (in_array($p, $uriLeading, true) || ($owner['id'] !== null && $p === $owner['id'])) {
                continue;
            }
            if (in_array($p, ['modules', 'plugins'], true) && ($owner['id'] !== null)) {
                continue;
            }

            return Str::slug($p) ?: 'misc';
        }

        return 'misc';
    }
}
