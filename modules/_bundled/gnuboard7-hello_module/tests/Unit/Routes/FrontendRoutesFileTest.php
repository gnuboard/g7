<?php

namespace Modules\Gnuboard7\HelloModule\Tests\Unit\Routes;

use Modules\Gnuboard7\HelloModule\Tests\ModuleTestCase;

/**
 * Hello 모듈 프론트엔드 라우트 파일 구조 테스트.
 *
 * 7.1.0 전수 실측 M3: 공개 메모 목록(`*\/memos` → `user_memo_list`)을 레거시 단일 파일
 * `{모듈}/resources/routes.json` 에 적어 두어, 코어가 그 파일을 관리자 템플릿에만 병합하는 탓에
 * 사용자 화면에서 `/memos` 가 404 였다(사용자 템플릿 routes 응답 51개에 memos 0건). 또 관리자
 * 라우트를 읽을 때마다 「레거시 위치」 경고 로그가 남았다. 학습용 샘플이므로 문서화된 분할 구조
 * (`resources/routes/admin.json`, `resources/routes/user.json`)로 둔다.
 *
 * @scenario-manifest tests/scenarios/frontend-routes.yaml
 */
class FrontendRoutesFileTest extends ModuleTestCase
{
    /**
     * 모듈 루트 경로.
     *
     * @return string 모듈 디렉토리 절대 경로
     */
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 라우트 파일을 읽어 routes 배열을 돌려줍니다.
     *
     * @param  string  $relative  모듈 루트 기준 경로
     * @return array<int, array<string, mixed>> 라우트 목록
     */
    private function routesOf(string $relative): array
    {
        $path = $this->moduleRoot().'/'.$relative;
        $this->assertFileExists($path, "{$relative} 가 있어야 한다");

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, "{$relative} 는 JSON 이어야 한다");

        return $data['routes'] ?? [];
    }

    /**
     * 레거시 단일 파일이 남아 있지 않다.
     *
     * @scenario file=legacy_single
     *
     * @effects legacy_routes_json_absent
     *
     * @return void 반환값 없음
     */
    public function test_legacy_single_routes_file_is_absent(): void
    {
        $this->assertFileDoesNotExist($this->moduleRoot().'/resources/routes.json');
    }

    /**
     * 공개 메모 목록은 사용자 템플릿 라우트 파일에 있다.
     *
     * @scenario file=user
     *
     * @effects public_memo_list_route_in_user_routes
     *
     * @return void 반환값 없음
     */
    public function test_public_memo_list_route_is_declared_for_user_templates(): void
    {
        $routes = $this->routesOf('resources/routes/user.json');

        $paths = array_column($routes, 'layout', 'path');
        $this->assertSame(['*/memos' => 'user_memo_list'], $paths);
        $this->assertFalse($routes[0]['auth_required'] ?? null);
    }

    /**
     * 관리자 메모 화면 3개는 관리자 템플릿 라우트 파일에 있고 로그인이 필요하다.
     *
     * @scenario file=admin
     *
     * @effects admin_memo_routes_in_admin_routes
     *
     * @return void 반환값 없음
     */
    public function test_admin_memo_routes_are_declared_for_admin_templates(): void
    {
        $routes = $this->routesOf('resources/routes/admin.json');

        $this->assertSame(
            [
                '*/admin/memos' => 'admin_memo_list',
                '*/admin/memos/create' => 'admin_memo_form',
                '*/admin/memos/:id/edit' => 'admin_memo_form',
            ],
            array_column($routes, 'layout', 'path')
        );
        foreach ($routes as $route) {
            $this->assertTrue($route['auth_required'] ?? null, "{$route['path']} 는 로그인이 필요하다");
        }
    }
}
