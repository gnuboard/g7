<?php

namespace Tests\Feature\Extension;

use App\Http\Controllers\Concerns\ServesRewritableCssAssets;
use App\Services\ExtensionBundleService;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 확장 CSS 를 내보내는 **모든 경로**가 상대 참조 치환을 거친다는 계약.
 *
 * 왜 행위 테스트만으로 부족한가:
 *   치환이 빠진 경로는 예외도 로그도 남기지 않는다 — 글꼴이 기본 서체로 대체되고 아이콘이
 *   빈칸이 될 뿐이며, 서버에는 정상 404 로 기록된다. 그래서 어느 한 경로에서 치환이 빠져도
 *   그 사실이 어디에도 드러나지 않는다.
 *
 *   그리고 확장 자산 서빙은 템플릿·모듈·플러그인 **세 경로**가 같은 트레이트를 공유하는데,
 *   실제 왕복을 재는 테스트는 템플릿 한 곳뿐이었다. 그 상태에서 모듈이나 플러그인 컨트롤러가
 *   종전 `fileResponse()` 로 되돌아가도 저장소의 테스트는 전부 초록이었다.
 *
 * 그래서 모집단을 **저장소에서 도출한다** — 손으로 적은 목록은 네 번째 경로가 생기는 순간
 * 조용히 낡는다. 라우트 이름 규약(`api.public.{type}.assets`, `api.public.{type}.bundle.css`)
 * 으로 등록된 것 전부를 훑고, 각 경로가 치환 지점을 경유하는지 본다.
 *
 * 선례: DevtoolsRouteGateContractTest(등록 계약 + 모집단 가드).
 */
class ExtensionAssetCssRewriteContractTest extends TestCase
{
    /** 개별 자산 서빙이 CSS 를 내보낼 때 반드시 거쳐야 하는 지점 */
    private const SERVING_ENTRYPOINT = 'rewritableAssetResponse';

    /** 병합 번들이 CSS 를 내보낼 때 반드시 거쳐야 하는 지점 */
    private const REWRITER = 'AssetCssUrlRewriter::rewrite';

    /**
     * 확장 자산 서빙 라우트 전부가 치환 경로를 거쳐야 한다.
     *
     * 모집단은 `api.public.*.assets` 로 등록된 라우트에서 도출한다 — 지금은 템플릿·모듈·
     * 플러그인 셋이지만, 네 번째가 추가되면 열거를 고치지 않아도 자동으로 검사 대상이 된다.
     */
    public function test_every_extension_asset_route_serves_css_through_the_rewriter(): void
    {
        $actions = $this->actionsForRouteNameSuffix('.assets');

        // 모집단 가드 — 하나도 안 잡히면 아래 foreach 는 공허참이 된다.
        $this->assertNotEmpty(
            $actions,
            '확장 자산 서빙 라우트가 하나도 잡히지 않았습니다 — 이 테스트가 공허하게 통과하고 있습니다 '
            .'(routes/api.php 의 Route::dualAsset(...)->name(\'api.public.*.assets\') 등록을 확인하세요).'
        );

        // 지금 알려진 세 확장 유형이 실제로 모집단에 들어왔는지 — 이름 규약이 바뀌어 일부가
        // 조용히 빠지는 것을 막는다.
        foreach (['templates', 'modules', 'plugins'] as $type) {
            $this->assertNotEmpty(
                array_filter(array_keys($actions), static fn (string $name): bool => str_contains($name, ".{$type}.")),
                "api.public.{$type}.assets 라우트가 모집단에 없습니다 — 이름 규약이 바뀌었거나 라우트가 사라졌습니다."
            );
        }

        foreach ($actions as $routeName => $action) {
            $source = $this->methodSource($action[0], $action[1]);

            $this->assertStringContainsString(
                self::SERVING_ENTRYPOINT,
                $source,
                "{$routeName} ({$action[0]}::{$action[1]}) 이 ".self::SERVING_ENTRYPOINT.'() 를 거치지 않습니다. '
                .'CSS 안의 상대 참조가 그대로 나가면 확장자 없는 URL 모드에서 글꼴·아이콘이 404 가 되는데, '
                .'그 실패는 예외도 서버 로그 흔적도 남기지 않습니다.'
            );
        }
    }

    /**
     * 자산 서빙 컨트롤러는 치환 트레이트를 실제로 보유해야 한다.
     *
     * 위 단언은 호출문만 본다 — 트레이트가 빠지면 호출문이 남아 있어도 치명적 오류가 되므로,
     * 보유 여부를 함께 고정한다.
     */
    public function test_asset_serving_controllers_use_the_rewrite_trait(): void
    {
        $actions = $this->actionsForRouteNameSuffix('.assets');

        $this->assertNotEmpty($actions, '확장 자산 서빙 라우트가 하나도 잡히지 않았습니다.');

        foreach ($actions as $routeName => $action) {
            $this->assertContains(
                ServesRewritableCssAssets::class,
                array_values(class_uses_recursive($action[0])),
                "{$routeName} 의 컨트롤러 {$action[0]} 가 ServesRewritableCssAssets 트레이트를 쓰지 않습니다."
            );
        }
    }

    /**
     * 병합 CSS 번들도 같은 치환 규칙을 거쳐야 한다.
     *
     * 병합본의 주소(`/api/{type}/bundle.css`, 정적 게시본)는 어느 확장의 dist 디렉토리도
     * 아니므로 상대 해석이 반드시 어긋난다. 종전에는 상대 참조를 가진 CSS 를 번들에서
     * **제외**했는데, 번들 URL 이 내려오면 프론트는 개별 로딩을 아예 타지 않으므로
     * 제외 = 그 확장의 스타일이 하나도 적용되지 않음이었다.
     */
    public function test_css_bundle_route_builds_through_the_rewriter(): void
    {
        $actions = $this->actionsForRouteNameSuffix('.bundle.css');

        $this->assertNotEmpty(
            $actions,
            '병합 CSS 번들 라우트가 하나도 잡히지 않았습니다 — 이 테스트가 공허하게 통과하고 있습니다.'
        );

        $source = $this->methodSource(ExtensionBundleService::class, 'buildCssBundle');

        $this->assertStringContainsString(
            self::REWRITER,
            $source,
            'ExtensionBundleService::buildCssBundle() 이 '.self::REWRITER.' 를 거치지 않습니다. '
            .'개별 자산 서빙과 병합 번들이 서로 다른 규칙을 쓰면 한쪽만 고쳐진 채 남습니다.'
        );
    }

    /**
     * 라우트 이름 접미사로 컨트롤러 액션 모집단을 도출합니다.
     *
     * @param  string  $suffix  라우트 이름 접미사 (예: `.assets`)
     * @return array<string, array{0: class-string, 1: string}> 라우트명 => [컨트롤러, 메서드]
     */
    private function actionsForRouteNameSuffix(string $suffix): array
    {
        $actions = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || ! str_starts_with($name, 'api.public.') || ! str_ends_with($name, $suffix)) {
                continue;
            }

            $controller = $route->getAction('controller');

            if (! is_string($controller) || ! str_contains($controller, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $controller, 2);

            $actions[$name] = [$class, $method];
        }

        return $actions;
    }

    /**
     * 메서드 본문 소스를 읽습니다.
     *
     * @param  class-string  $class  클래스
     * @param  string  $method  메서드명
     * @return string 메서드 소스
     */
    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);

        $file = $reflection->getFileName();
        $this->assertIsString($file, "{$class}::{$method} 의 소스 파일을 찾을 수 없습니다.");

        $lines = file($file);
        $this->assertIsArray($lines, "{$file} 을 읽을 수 없습니다.");

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
