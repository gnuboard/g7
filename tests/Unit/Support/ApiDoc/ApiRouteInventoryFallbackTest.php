<?php

namespace Tests\Unit\Support\ApiDoc;

use App\Support\ApiDoc\ApiRouteInventory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ApiRouteInventory 비활성/미설치 확장 폴백 수집 단위 테스트.
 *
 * route:list 는 활성 확장만 노출하므로, 명시 범위(module:{id}/plugin:{id})로
 * 지정한 확장이 미설치여서 등록 라우트가 0건일 때 번들 라우트 파일(src/routes/api.php)을
 * 프로바이더와 동일한 prefix/name 규약으로 로드해 수집하는지 검증한다.
 *
 * gnuboard7-hello_module 은 학습용 번들 샘플로 개발 DB 에 미설치 상태이므로,
 * route:list 에는 나타나지 않지만 번들 라우트 파일 폴백으로는 수집되어야 한다.
 */
class ApiRouteInventoryFallbackTest extends TestCase
{
    #[Test]
    public function 미설치_모듈_범위는_번들_라우트_파일에서_폴백_수집한다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('module:gnuboard7-hello_module');

        // api.php 의 공개 memos GET 2건(index/show)이 폴백으로 수집된다.
        $this->assertNotEmpty($routes, '미설치 모듈의 번들 라우트가 폴백으로 수집되어야 한다');

        $names = array_column($routes, 'name');
        $this->assertContains('api.modules.gnuboard7-hello_module.memos.index', $names);
        $this->assertContains('api.modules.gnuboard7-hello_module.memos.show', $names);
    }

    #[Test]
    public function 폴백_수집_라우트는_프로바이더와_동일한_ur_l_규약을_따른다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('module:gnuboard7-hello_module');

        $index = collect($routes)->firstWhere('name', 'api.modules.gnuboard7-hello_module.memos.index');

        $this->assertNotNull($index);
        $this->assertSame('GET', $index['method']);
        $this->assertSame('/api/modules/gnuboard7-hello_module/memos', $index['uri']);
        $this->assertSame('module', $index['owner']['type']);
        $this->assertSame('gnuboard7-hello_module', $index['owner']['id']);
        $this->assertSame('memos', $index['domain_group']);
        $this->assertSame(
            'Modules\\Gnuboard7\\HelloModule\\Http\\Controllers\\Api\\MemoController',
            $index['controller']
        );
    }

    #[Test]
    public function web_admin_라우트는_ap_i_문서_대상에서_제외된다(): void
    {
        // hello_module 의 admin CRUD 는 web.php(/modules/{id} prefix)라 api/ 로 시작하지 않아
        // API 문서 대상이 아니다. 폴백은 src/routes/api.php 만 로드한다.
        $routes = app(ApiRouteInventory::class)->collect('module:gnuboard7-hello_module');

        foreach ($routes as $route) {
            $this->assertStringStartsWith('/api/', $route['uri']);
        }

        $names = array_column($routes, 'name');
        $this->assertNotContains('api.modules.gnuboard7-hello_module.admin.memos.store', $names);
    }

    #[Test]
    public function 라우트_파일이_없는_범위는_빈_배열을_반환한다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('module:nonexistent-module-xyz');

        $this->assertSame([], $routes);
    }
}
