<?php

namespace Tests\Feature\Analytics\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 통계 API 표면 계약 — 등록된 라우트가 문서와 보호 장치를 빠짐없이 갖는지.
 *
 * 라우트를 더하고 문서 절을 빠뜨리면 문서 검사기(미채움 표시만 센다)는 초록인 채로 통과한다 —
 * 실제로 유입 주소 목록 라우트가 그렇게 문서에서 빠져 있었다. 모집단은 라우트 등록부에서 파생한다.
 */
class ApiSurfaceContractTest extends TestCase
{
    /**
     * 통계 라우트를 「메서드 경로」 목록으로 모읍니다.
     *
     * @return array<int, array{0: string, 1: \Illuminate\Routing\Route}> [「GET /api/...」, 라우트]
     */
    private function analyticsRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! preg_match('/^api\.(admin|public)\.analytics\./', (string) $route->getName())) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method.' /'.$route->uri(), $route];
            }
        }

        return $routes;
    }

    /**
     * 모든 통계 라우트가 API 문서의 절 제목에 「메서드 경로」로 있다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read
     *
     * @effects every_analytics_route_has_an_api_doc_section
     */
    public function test_every_route_has_an_api_doc_section(): void
    {
        $routes = $this->analyticsRoutes();
        $this->assertGreaterThanOrEqual(29, count($routes), '통계 라우트 모집단이 줄었다 — 파생이 깨졌는지 본다');

        $headings = array_filter(
            explode("\n", (string) file_get_contents(base_path('docs/backend/api/analytics.md'))),
            fn (string $line): bool => str_starts_with($line, '### '),
        );

        $missing = [];

        foreach ($routes as [$key]) {
            $found = false;

            foreach ($headings as $heading) {
                if (preg_match('#(^|[ ·])'.preg_quote($key, '#').'( |$)#u', substr($heading, 4)) === 1) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'API 문서(docs/backend/api/analytics.md)에 절이 없는 라우트');
    }

    /**
     * 공개 라우트 두 개는 요청 수 제한을 달고, 수집 라우트는 로그인 토큰을 선택적으로 읽는다.
     *
     * 공개 수집은 인증이 없어 누구나 부를 수 있다 — 제한이 빠지면 원장이 그대로 부푼다.
     *
     * @return void 없음
     *
     * @scenario endpoint=summary, role=superadmin
     *
     * @effects public_analytics_routes_are_throttled
     */
    public function test_public_routes_keep_their_guards(): void
    {
        $public = array_filter($this->analyticsRoutes(), fn (array $pair): bool => str_starts_with((string) $pair[1]->getName(), 'api.public.analytics.'));
        $this->assertCount(2, $public, '공개 통계 라우트는 수집·요약 두 개다');

        foreach ($public as [$key, $route]) {
            $middleware = implode(' ', array_map('strval', $route->gatherMiddleware()));
            $this->assertMatchesRegularExpression('/throttle:\d+,\d+/', $middleware, "{$key}: 요청 수 제한이 없다");

            if ($route->getName() === 'api.public.analytics.collect') {
                $this->assertStringContainsString('optional.sanctum', $middleware, '수집은 로그인 토큰을 선택적으로 읽어 회원 방문을 가려야 한다');
            }
        }
    }

    /**
     * 코어가 발행하는 `core.analytics.*` 훅이 모두 API 문서 「확장 지점」 표에 있다.
     *
     * 모집단은 `app/` 의 `applyFilters`·`doAction` 호출에서 파생한다. 표는 한 칸에 여러 훅을 묶어 적는다
     * (`a` / `b`, `core.analytics.{a·b}_validation_rules`) — 그 표기를 풀어 대조한다. 훅을 새로 쏘고
     * 문서를 빠뜨리면 확장 개발자는 그 자리를 알 방법이 없다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects every_emitted_analytics_hook_is_documented
     */
    public function test_every_emitted_hook_is_documented(): void
    {
        $emitted = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/(?:applyFilters|doAction)\(\s*'(core\.analytics\.[a-z_.]+)'/", (string) file_get_contents($file->getPathname()), $m);
            $emitted = array_merge($emitted, $m[1]);
        }

        $emitted = array_values(array_unique($emitted));
        sort($emitted);
        $this->assertGreaterThanOrEqual(40, count($emitted), '발행 훅 모집단이 줄었다 — 파생이 깨졌는지 본다');

        $doc = (string) file_get_contents(base_path('docs/backend/api/analytics.md'));
        $section = substr($doc, (int) strpos($doc, '## 확장 지점'));
        $documented = [];

        foreach (explode("\n", $section) as $line) {
            if (! str_starts_with($line, '| `')) {
                continue;
            }

            $cell = explode('|', $line)[1];
            preg_match_all('/`([^`]+)`/', $cell, $tokens);

            foreach ($tokens[1] as $token) {
                $name = str_starts_with($token, 'core.analytics.') ? $token : 'core.analytics.'.$token;

                if (preg_match('/^(.*)\{([^}]+)\}(.*)$/u', $name, $group) === 1) {
                    foreach (explode('·', $group[2]) as $part) {
                        $documented[] = $group[1].$part.$group[3];
                    }
                } else {
                    $documented[] = $name;
                }
            }
        }

        $this->assertSame([], array_values(array_diff($emitted, $documented)), 'API 문서 「확장 지점」 표에 없는 발행 훅');
    }
}
