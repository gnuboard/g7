<?php

namespace App\Support\Content;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/**
 * 본문 미디어 서빙 라우트 레지스트리 — 미디어 출처 허용 패턴을 실제 서빙 라우트에서 만든다 (#887)
 *
 * 확장(모듈·플러그인)의 본문 미디어 어댑터가 부팅 때 자기 서빙 라우트 이름(`api.modules.{id}.….show`·`api.plugins.{id}.….show`)을
 * 등록하면, `patterns()` 가 라우터에서 그 라우트의 URI 와 파라미터 제약(`where`)을 읽어 같은 출처 상대 주소 정규식으로 바꾼다.
 * 경로 이름 규약(`…/content-media/{hash}`)만 맞춘 가짜 경로는 등록된 라우트가 아니므로 통과하지 못한다.
 *
 * - 등록했지만 라우트가 없는 이름(비활성·미설치 확장)은 건너뛴다.
 * - 라우터가 없는 자리(부팅 전·라우터 없는 단위 시험)는 빈 목록이다 — 미디어 요소는 출처 검사에서 지워진다(안전한 쪽).
 * - 서명 주소(`?expires=…&signature=…`)를 받도록 쿼리 꼬리를 허용한다.
 *
 * 소비자: `ContentHtmlPolicy::mediaSourcePatterns()`(화면 `render_policy.media_src_patterns`·봇 정화·저장 정화).
 *
 * @since 7.1.5
 */
class MediaSourceRoutes
{
    /** 서명 쿼리 꼬리 — 따옴표·꺾쇠·공백이 없는 쿼리 문자열 */
    public const QUERY_TAIL = '(?:\?[^"\'<>\s]*)?';

    /** `where` 가 없는 파라미터의 기본 정규식 — 한 경로 조각(쿼리·조각 표시 제외) */
    public const DEFAULT_PARAMETER_PATTERN = '[^/?#]+';

    /**
     * 등록된 라우트 이름 (이름 => true, 등록 순서 유지)
     *
     * @var array<string, true>
     */
    private array $routeNames = [];

    /**
     * 이름 조회 색인을 다시 만든 라우트 묶음 표시 — 같은 묶음에서 반복해 다시 만들지 않는다
     */
    private ?string $refreshedFor = null;

    /**
     * 서빙 라우트 이름을 등록합니다(같은 이름은 한 번만).
     *
     * @param  string  $routeName  서빙 라우트 이름 (예: `api.modules.vendor-module.content-media.show`)
     * @return void 반환값 없음
     */
    public function register(string $routeName): void
    {
        $routeName = trim($routeName);

        if ($routeName !== '') {
            $this->routeNames[$routeName] = true;
        }
    }

    /**
     * 등록된 서빙 라우트 이름.
     *
     * @return array<int, string> 라우트 이름
     */
    public function routeNames(): array
    {
        return array_keys($this->routeNames);
    }

    /**
     * 등록된 라우트에서 만든 미디어 출처 허용 정규식(구분자 없음, PHP·JS 공용).
     *
     * @return array<int, string> 정규식 (`^/…$` + 서명 쿼리 꼬리)
     */
    public function patterns(): array
    {
        if ($this->routeNames === []) {
            return [];
        }

        try {
            $routes = Route::getRoutes();
        } catch (\Throwable) {
            return [];
        }

        $patterns = [];
        foreach ($this->routeNames() as $name) {
            $route = $this->findRoute($routes, $name);

            if ($route === null) {
                continue;
            }

            $pattern = self::patternFor($route);

            if ($pattern !== null) {
                $patterns[$pattern] = true;
            }
        }

        return array_keys($patterns);
    }

    /**
     * 라우트 하나를 출처 허용 정규식으로 바꿉니다.
     *
     * 고정 조각은 정규식 메타 문자만 벗기고(PHP `#` 구분자·JS `new RegExp` 모두에서 같은 뜻), 파라미터는 라우트 `where` 를
     * 비캡처 묶음으로 넣는다. 선택 파라미터(`{x?}`)는 앞 `/` 와 함께 선택으로 둔다.
     *
     * @param  RoutingRoute  $route  라우트
     * @return string|null 정규식 (URI 가 비면 null)
     */
    public static function patternFor(RoutingRoute $route): ?string
    {
        $uri = trim($route->uri(), '/');

        if ($uri === '') {
            return null;
        }

        $wheres = is_array($route->wheres) ? $route->wheres : [];
        $regex = '';
        $offset = 0;

        preg_match_all('/\{(\w+)(\?)?\}/', $uri, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $literal = substr($uri, $offset, $match[0][1] - $offset);
            $name = $match[1][0];
            $optional = isset($match[2]) && $match[2][0] === '?';
            $where = isset($wheres[$name]) && is_string($wheres[$name]) && $wheres[$name] !== ''
                ? $wheres[$name]
                : self::DEFAULT_PARAMETER_PATTERN;

            if ($optional && str_ends_with($literal, '/')) {
                $regex .= self::quote(substr($literal, 0, -1)).'(?:/(?:'.$where.'))?';
            } else {
                $regex .= self::quote($literal).'(?:'.$where.')'.($optional ? '?' : '');
            }

            $offset = $match[0][1] + strlen($match[0][0]);
        }

        $regex .= self::quote(substr($uri, $offset));

        return '^/'.$regex.self::QUERY_TAIL.'$';
    }

    /**
     * 이름으로 라우트를 찾습니다. 이름 색인이 늦게 붙은 이름(`->name()` 이 등록 뒤에 붙은 라우트)을 놓치지 않도록, 못 찾으면
     * 그 라우트 묶음에서 한 번만 색인을 다시 만든다.
     *
     * @param  mixed  $routes  라우트 묶음
     * @param  string  $name  라우트 이름
     * @return RoutingRoute|null 라우트
     */
    private function findRoute(mixed $routes, string $name): ?RoutingRoute
    {
        if (! is_object($routes) || ! method_exists($routes, 'getByName')) {
            return null;
        }

        $route = $routes->getByName($name);

        if ($route === null && $routes instanceof RouteCollection) {
            $marker = spl_object_id($routes).':'.$routes->count();

            if ($this->refreshedFor !== $marker) {
                $this->refreshedFor = $marker;
                $routes->refreshNameLookups();
                $route = $routes->getByName($name);
            }
        }

        return $route instanceof RoutingRoute ? $route : null;
    }

    /**
     * 고정 조각의 정규식 메타 문자를 벗깁니다(`-`·`/` 는 그대로 — JS 유니코드 모드에서도 오류가 없게).
     *
     * @param  string  $literal  고정 조각
     * @return string 벗긴 조각
     */
    private static function quote(string $literal): string
    {
        return addcslashes($literal, '.\\+*?[^]$(){}|');
    }
}
