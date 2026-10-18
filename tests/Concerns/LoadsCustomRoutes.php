<?php

namespace Tests\Concerns;

use App\Support\Custom\CustomRoutes;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;

/**
 * 시험에서 `routes/custom.php` 를 운영 순서(G7 catch-all 보다 먼저)로 등록합니다 (#792).
 */
trait LoadsCustomRoutes
{
    /**
     * 라우트 집합을 비우고 custom 라우트를 먼저, 기존 라우트를 뒤에 다시 등록합니다.
     *
     * @param  string  $source  routes/custom.php 내용
     * @return void 반환값 없음
     */
    protected function loadCustomRoutesSource(string $source): void
    {
        $dir = storage_path('framework/testing/custom_routes_'.uniqid());
        File::ensureDirectoryExists($dir);
        $file = $dir.'/custom.php';
        File::put($file, $source);

        CustomRoutes::flushState();
        $router = app('router');
        $existing = $router->getRoutes()->getRoutes();
        $router->setRoutes(new RouteCollection);

        try {
            CustomRoutes::load($router, $file);
        } finally {
            File::deleteDirectory($dir);
        }

        $collection = $router->getRoutes();
        foreach ($existing as $route) {
            // 프로젝트의 실제 routes/custom.php(개발자 자리)는 빼고 시험 원본만 남긴다 — 개발자가 custom 라우트를
            // 둔 사이트에서도 코어 시험 결과가 같아야 한다
            if (in_array(CustomRoutes::MIDDLEWARE_GROUP, (array) $route->middleware(), true)) {
                continue;
            }
            $collection->add($route);
        }
        $collection->refreshNameLookups();
        $collection->refreshActionLookups();
    }
}
