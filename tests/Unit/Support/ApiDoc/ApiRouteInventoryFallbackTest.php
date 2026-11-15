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
    /**
     * 설치되지 않은 모듈 범위는 번들 라우트 파일을 읽어 라우트를 모은다.
     *
     * @scenario case=uninstalled_fallback
     *
     * @effects uninstalled_scope_falls_back_to_bundled_route_files
     *
     * @return void 반환값 없음
     */
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

    /**
     * 폴백으로 모은 라우트도 라우트 프로바이더와 같은 주소·이름·소유·도메인 규약을 따른다.
     *
     * @scenario case=fallback_url_convention
     *
     * @effects fallback_routes_follow_provider_url_convention
     *
     * @return void 반환값 없음
     */
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

    /**
     * 관리자 화면 web 라우트는 빼고, 관리자 JSON API 는 문서 대상으로 모은다.
     *
     * @scenario case=fallback_admin_web_excluded
     *
     * @effects admin_web_screens_excluded_admin_api_included
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function web_admin_라우트는_ap_i_문서_대상에서_제외된다(): void
    {
        // 확장 web 라우트 중 PG 콜백·웹훅은 문서 대상이지만(machine-facing), 사람이 브라우저로 여는 관리자
        // 화면(web.modules.{id}.admin.*)은 제외된다. hello_module 은 관리자 CRUD 를 JSON API
        // (routes/api.php 의 admin/memos 그룹)로 옮겨 web 라우트가 없다 — 그 관리자 API 는 다른 모듈의
        // 관리자 API 와 같이 문서 대상이다.
        $routes = app(ApiRouteInventory::class)->collect('module:gnuboard7-hello_module');

        foreach ($routes as $route) {
            $this->assertStringStartsWith('/api/', $route['uri']);
        }

        $names = array_column($routes, 'name');
        $this->assertContains('api.modules.gnuboard7-hello_module.admin.memos.store', $names);
        $this->assertNotContains('web.modules.gnuboard7-hello_module.admin.memos.store', $names);
    }

    /**
     * 라우트 파일이 없는 범위는 빈 목록을 돌려준다.
     *
     * @scenario case=no_route_file
     *
     * @effects scope_without_route_file_returns_empty
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 라우트_파일이_없는_범위는_빈_배열을_반환한다(): void
    {
        $routes = app(ApiRouteInventory::class)->collect('module:nonexistent-module-xyz');

        $this->assertSame([], $routes);
    }
}
