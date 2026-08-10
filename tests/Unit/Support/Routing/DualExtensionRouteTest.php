<?php

namespace Tests\Unit\Support\Routing;

use App\Support\Routing\DualRouteProxy;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 자산 URL 이중 모드 라우트 등록 가드 (이슈 #486 단위 A).
 *
 * 정적 최적화 블록(`location ~* \.(js|css|json)$`)이 있는 서버에서 동적 응답이
 * nginx 에 가로채이지 않도록, 모든 확장자 붙은 동적 엔드포인트는 확장자 없는
 * 형태를 함께 제공해야 한다. 한쪽이 조용히 누락되면 그 화면만 죽으므로
 * "전수 등록" 자체를 테스트로 못박는다.
 */
class DualExtensionRouteTest extends TestCase
{
    /**
     * 이중 등록이 요구되는 전체 라우트 이름 (계획서 #486 §1 의 23개 + 프로브).
     *
     * 신규 확장자 엔드포인트를 추가하면 이 목록에도 추가되어야 한다.
     *
     * 데이터 프로바이더 대신 평면 목록으로 두고 각 테스트가 내부에서 순회한다.
     * 프로바이더로 케이스를 분리하면 케이스마다 애플리케이션이 부팅되어
     * DB 커넥션이 케이스 수만큼 열리고, 스위트 동시 실행 시 커넥션이 고갈된다.
     *
     * @return array<int, string>
     */
    private static function dualRouteNames(): array
    {
        return [
            // Public — 템플릿
            'api.public.templates.routes',
            'api.public.templates.config',
            'api.public.templates.assets',
            'api.public.templates.components',
            'api.public.templates.language',
            // Public — 레이아웃
            'api.public.layouts.preview.serve',
            'api.public.layouts.serve',
            // Public — 모듈
            'api.public.modules.bundle.js',
            'api.public.modules.bundle.css',
            'api.public.modules.assets',
            'api.public.modules.components',
            // Public — 플러그인
            'api.public.plugins.bundle.js',
            'api.public.plugins.bundle.css',
            'api.public.plugins.assets',
            'api.public.plugins.components',
            // Public — 감지 프로브
            'api.public.system.asset-probe',
            // Admin — 레이아웃 편집기
            'api.admin.templates.editor-components',
            'api.admin.templates.editor-routes',
            'api.admin.templates.editor-spec',
            'api.admin.templates.editor-lang',
            'api.admin.templates.editor-permission-candidates',
            'api.admin.templates.editor-css',
            'api.admin.templates.editor-seo-candidates',
            'api.admin.templates.editor-broadcast-catalog',
        ];
    }

    /**
     * 모든 대상 엔드포인트가 두 형태로 등록되어 있어야 한다.
     */
    public function test_엔드포인트가_확장자_형태와_확장자_없는_형태로_모두_등록된다(): void
    {
        foreach (self::dualRouteNames() as $name) {
            $extension = Route::getRoutes()->getByName($name);
            $extensionless = Route::getRoutes()->getByName($name.DualRouteProxy::EXTENSIONLESS_NAME_SUFFIX);

            $this->assertNotNull($extension, "확장자 형태 라우트 미등록: {$name}");
            $this->assertNotNull(
                $extensionless,
                "확장자 없는 형태 라우트 미등록: {$name} — 정적 최적화 블록이 있는 서버에서 이 엔드포인트가 죽는다"
            );
        }
    }

    /**
     * 두 형태는 동일한 컨트롤러 액션과 미들웨어로 들어가야 한다.
     *
     * 한쪽에만 permission 미들웨어가 붙으면 확장자 없는 형태가 권한 우회 통로가 된다.
     */
    public function test_두_형태의_액션과_미들웨어가_동일하다(): void
    {
        foreach (self::dualRouteNames() as $name) {
            $extension = Route::getRoutes()->getByName($name);
            $extensionless = Route::getRoutes()->getByName($name.DualRouteProxy::EXTENSIONLESS_NAME_SUFFIX);

            $this->assertSame(
                $extension->getActionName(),
                $extensionless->getActionName(),
                "두 형태의 컨트롤러 액션 불일치: {$name}"
            );

            $this->assertSame(
                $extension->gatherMiddleware(),
                $extensionless->gatherMiddleware(),
                "두 형태의 미들웨어 불일치: {$name} — 확장자 없는 형태가 권한 가드를 우회할 수 있다"
            );
        }
    }

    /**
     * 확장자 형태 URI 는 실제로 해당 확장자로 끝나야 한다.
     */
    public function test_확장자_형태_주소가_정적_확장자로_끝난다(): void
    {
        $suffixed = [
            'api.public.templates.routes' => '.json',
            'api.public.layouts.serve' => '.json',
            'api.public.modules.bundle.js' => '.js',
            'api.public.plugins.bundle.css' => '.css',
            'api.admin.templates.editor-css' => '.css',
            'api.public.system.asset-probe' => '.js',
        ];

        foreach ($suffixed as $name => $suffix) {
            $uri = Route::getRoutes()->getByName($name)->uri();
            $this->assertStringEndsWith($suffix, $uri, "확장자 형태 URI 가 {$suffix} 로 끝나지 않음: {$name}");
        }
    }

    /**
     * 확장자 없는 형태 URI 의 마지막 세그먼트에는 정적 확장자가 남아있지 않아야 한다.
     *
     * 이것이 이번 이슈의 본질이다 — 확장자가 남아있으면 nginx 정적 블록이 그대로 가로챈다.
     */
    public function test_확장자_없는_형태_주소에_정적_확장자가_없다(): void
    {
        foreach (self::dualRouteNames() as $name) {
            $uri = Route::getRoutes()
                ->getByName($name.DualRouteProxy::EXTENSIONLESS_NAME_SUFFIX)
                ->uri();

            $lastSegment = basename($uri);

            $this->assertDoesNotMatchRegularExpression(
                '/\.(js|mjs|css|json|map|png|jpe?g|svg|webp|gif|ico|woff2?|ttf|otf|eot)$/i',
                $lastSegment,
                "확장자 없는 형태에 정적 확장자가 남아있음: {$name} → {$uri}"
            );
        }
    }

    /**
     * `.json` 요청은 확장자 형태 라우트로 매칭되어야 한다 (등록 순서 가드).
     *
     * `layouts/{templateIdentifier}/{layoutName}` 의 layoutName 정규식은 `.` 를 포함해
     * greedy 하므로, 확장자 없는 형태가 먼저 등록되면 `.json` 요청까지 삼킨다.
     * 매크로가 확장자 형태를 먼저 등록한다는 불변식을 실제 매칭으로 검증한다.
     */
    public function test_json_요청이_확장자_없는_라우트에_삼켜지지_않는다(): void
    {
        $cases = [
            '/api/layouts/sirsoft-basic/home.json' => 'api.public.layouts.serve',
            '/api/templates/sirsoft-basic/routes.json' => 'api.public.templates.routes',
            '/api/templates/sirsoft-basic/components.json' => 'api.public.templates.components',
            '/api/modules/bundle.js' => 'api.public.modules.bundle.js',
        ];

        foreach ($cases as $uri => $expectedName) {
            $matched = $this->matchRouteName('GET', $uri);

            $this->assertSame(
                $expectedName,
                $matched,
                "{$uri} 가 확장자 형태가 아닌 다른 라우트에 매칭됨 (등록 순서 역전 의심)"
            );
        }
    }

    /**
     * 확장자 없는 요청은 확장자 없는 라우트로 매칭되어야 한다.
     */
    public function test_확장자_없는_요청이_확장자_없는_라우트로_매칭된다(): void
    {
        $cases = [
            '/api/layouts/sirsoft-basic/home' => 'api.public.layouts.serve.extensionless',
            '/api/layouts/preview/'.str_repeat('a', 8).'-aaaa-aaaa-aaaa-'.str_repeat('a', 12) => 'api.public.layouts.preview.serve.extensionless',
            '/api/templates/sirsoft-basic/routes' => 'api.public.templates.routes.extensionless',
            '/api/modules/bundle/js' => 'api.public.modules.bundle.js.extensionless',
            '/api/plugins/bundle/css' => 'api.public.plugins.bundle.css.extensionless',
            '/api/templates/assets/sirsoft-basic' => 'api.public.templates.assets.extensionless',
            '/api/system/asset-probe' => 'api.public.system.asset-probe.extensionless',
        ];

        foreach ($cases as $uri => $expectedName) {
            $this->assertSame($expectedName, $this->matchRouteName('GET', $uri), "매칭 불일치: {$uri}");
        }
    }

    /**
     * 주어진 요청에 매칭되는 라우트의 이름을 반환합니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  요청 URI
     * @return string|null 매칭된 라우트 이름 (미매칭 시 null)
     */
    private function matchRouteName(string $method, string $uri): ?string
    {
        $request = Request::create($uri, $method);

        /** @var RoutingRoute $route */
        $route = Route::getRoutes()->match($request);

        return $route->getName();
    }
}
