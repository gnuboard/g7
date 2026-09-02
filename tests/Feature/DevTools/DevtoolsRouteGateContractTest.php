<?php

namespace Tests\Feature\DevTools;

use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * DevTools(`_boost`) 라우트의 **게이트 부착 계약** 테스트 (이슈 #128).
 *
 * 왜 행위 테스트만으로 부족한가:
 *   게이트가 빠진 라우트는 예외도 로그도 남기지 않는다 — 그 엔드포인트가 정상 응답하는 것이
 *   유일한 증상이다. 게다가 GET 4종은 `routes/web.php` 의 User SPA catch-all 이 등록 순서상
 *   앞서 가려 주고 있어서, 제외 패턴이 되돌아가면 행위 테스트조차 "SPA 200" 을 받고 무엇이
 *   빠졌는지 말해 주지 못한다. 그래서 **등록 시점의 미들웨어 부착 자체**를 계약으로 고정한다.
 *
 * 두 축:
 *   1. 등록 계약 — 모든 `_boost` 라우트의 `gatherMiddleware()` 에 `debug.gate` 가 있다.
 *      새 라우트를 `routes/devtools.php` 에 추가해도 그룹 미들웨어가 자동으로 덮으므로,
 *      이 단언이 깨지는 것은 부착 지점(`bootstrap/app.php` devtools 래퍼)이 훼손된 경우다.
 *   2. 소스 계약 — `routes/devtools.php` 에 `DebugGate::isEnabled()` 가 다시 나타나지 않는다.
 *      개별 게이트가 재유입되면 "어떤 라우트는 붙고 어떤 라우트는 안 붙는" 상태로 되돌아간다.
 *
 * 선례: IdentityPermissionContractTest(name → gatherMiddleware 계약),
 *       ExtensionRouteActiveGateTest(모집단 가드), ExtensionRouteCacheInvalidationTest(stripComments).
 */
class DevtoolsRouteGateContractTest extends TestCase
{
    /** 그룹 게이트 미들웨어 별칭 (`bootstrap/app.php` alias 등록) */
    private const GATE_ALIAS = 'debug.gate';

    /**
     * `_boost` 로 시작하는 모든 라우트에 그룹 게이트가 붙어 있어야 한다.
     */
    public function test_every_boost_route_carries_the_debug_gate_middleware(): void
    {
        $boostRoutes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (str_starts_with($route->uri(), '_boost')) {
                $boostRoutes[$route->uri().' ['.implode('|', $route->methods()).']'] = $route->gatherMiddleware();
            }
        }

        // 모집단 가드 — `_boost` 라우트가 하나도 안 잡히면 아래 foreach 는 공허참이 된다.
        $this->assertNotEmpty(
            $boostRoutes,
            '_boost 라우트가 하나도 등록되지 않았습니다 — 이 테스트가 공허하게 통과하고 있습니다 '
            .'(bootstrap/app.php 의 devtools 그룹 등록을 확인하세요).'
        );

        foreach ($boostRoutes as $label => $middleware) {
            $this->assertContains(
                self::GATE_ALIAS,
                $middleware,
                "{$label} 에 ".self::GATE_ALIAS.' 가 붙어 있지 않습니다. 게이트 없는 DevTools 라우트는 '
                .'production 에서도 미인증 접근을 허용하며, 예외·로그를 남기지 않아 정상 응답이 '
                .'유일한 증상입니다. 게이트는 개별 라우트가 아니라 bootstrap/app.php 의 devtools '
                .'래퍼(Route::middleware([\'api\', \'debug.gate\'])) 가 단일 지점에서 부여합니다.'
            );
        }
    }

    /**
     * 알려진 8개 엔드포인트가 실제로 등록되어 있어야 한다.
     *
     * 위 계약은 "등록된 것 전부" 를 보므로, 라우트가 통째로 사라져도 통과한다. 8개 URI 를
     * 명시해 두어 삭제·오타로 인한 소실을 함께 잡는다. GET 4종은 SPA catch-all shadow 때문에
     * 행위 테스트로 등록 여부를 구분할 수 없어서, 이 단언이 유일한 통로다.
     */
    public function test_known_devtools_endpoints_are_registered(): void
    {
        $expected = [
            '_boost/browser-logs' => 'POST',
            '_boost/g7-debug/dump-state' => 'POST',
            '_boost/g7-debug/log' => 'POST',
            '_boost/g7-debug/state' => 'GET',
            '_boost/g7-debug/actions' => 'GET',
            '_boost/g7-debug/cache' => 'GET',
            '_boost/g7-debug/change-detection' => 'GET',
            '_boost/g7-debug/clear' => 'DELETE',
        ];

        $registered = [];

        foreach (RouteFacade::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $registered[$route->uri()][] = $method;
            }
        }

        foreach ($expected as $uri => $method) {
            $this->assertArrayHasKey($uri, $registered, "DevTools 라우트가 등록되지 않았습니다: {$uri}");
            $this->assertContains(
                $method,
                $registered[$uri],
                "{$uri} 에 {$method} 메서드가 등록되지 않았습니다."
            );
        }
    }

    /**
     * `routes/devtools.php` 는 개별 게이트를 다시 들이지 않아야 한다.
     *
     * 주석·문자열은 걷어내고 실행 코드만 본다 — 이 파일의 헤더 docblock 은 "여기에
     * `DebugGate::isEnabled()` 를 적지 말라" 고 **설명하기 위해** 그 심볼을 언급하므로,
     * 원문 그대로 검사하면 정상 상태가 위반으로 잡힌다.
     */
    public function test_devtools_route_file_has_no_inline_debug_gate(): void
    {
        $source = file_get_contents(base_path('routes/devtools.php'));

        $this->assertIsString($source);

        $code = $this->stripCommentsAndStrings($source);

        $this->assertStringNotContainsString(
            'DebugGate::isEnabled',
            $code,
            'routes/devtools.php 에 개별 디버그 게이트가 다시 들어왔습니다. 게이트는 '
            .'bootstrap/app.php 의 devtools 그룹 미들웨어(debug.gate) 가 단일 지점에서 담당합니다 '
            .'— 핸들러마다 적으면 새 라우트에서 빠뜨리게 되고, 그 라우트만 조용히 열립니다.'
        );
    }

    /**
     * 소스에서 주석과 문자열 리터럴 본문을 제거합니다.
     *
     * 실행되는 코드만 판정 대상이다. 어휘 분석으로 토큰을 걷어낸다.
     *
     * @param  string  $source  대상 소스
     * @return string 주석·문자열이 제거된 소스
     */
    private function stripCommentsAndStrings(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                    continue;
                }

                $stripped .= $token[1];

                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }
}
