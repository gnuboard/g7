<?php

namespace Tests\Concerns;

use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;

/**
 * 라우트 캐시가 있는 머신에서 런타임 등록 라우트의 이름 조회를 살리는 테스트 트레이트.
 *
 * `bootstrap/cache/routes-v7.php` 가 있으면 테스트 앱의 라우트 컬렉션이
 * `CompiledRouteCollection` 이다. 그 위에 확장 테스트 케이스가 fluent `->name()` 으로
 * 등록한 라우트는 add 시점에 이름이 비어 있고 `refreshNameLookups()` 가 no-op 이라,
 * URI 매칭은 되는데 `route()`/`URL::temporarySignedRoute()` 는 `Route [name] not defined`
 * 를 던진다 — 캐시에 있던 옛 이름(설치본)은 찾히므로 「새 라우트만 안 된다」로 나타난다.
 * 등록된 라우트를 새 `RouteCollection` 으로 옮겨 이름 조회를 다시 만든다. 캐시가 없는
 * 머신에서는 아무 일도 하지 않는다.
 *
 * 종전에는 sirsoft-page `ModuleTestCase` 한 곳에만 있었고 형제 셋(board·ecommerce·
 * hello_module)에는 없었다 — 그 셋의 `route()` 를 쓰는 테스트는 캐시가 있는 머신에서만
 * 실패해, 「내 머신에서는 초록」으로 드러나지 않았다 (G3 회차 15 → 16 A3). 공유 trait 으로
 * 올려 네 확장이 같은 한 곳을 부른다.
 */
trait RebuildsRouteNameLookups
{
    /**
     * 컴파일된 라우트 컬렉션을 일반 컬렉션으로 옮겨 이름·액션 조회를 재구성합니다.
     *
     * 확장 테스트 케이스의 `registerModuleRoutes()` 끝에서 부른다.
     *
     * @return void 반환값 없음
     */
    protected function rebuildRouteNameLookups(): void
    {
        $router = $this->app['router'];
        $current = $router->getRoutes();

        if (! $current instanceof CompiledRouteCollection) {
            return;
        }

        $collection = new RouteCollection;

        foreach ($current->getRoutes() as $route) {
            $collection->add($route);
        }

        $collection->refreshNameLookups();
        $collection->refreshActionLookups();

        $router->setRoutes($collection);
        $this->app->instance('routes', $collection);
    }
}
