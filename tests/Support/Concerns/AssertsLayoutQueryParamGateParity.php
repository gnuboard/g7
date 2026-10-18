<?php

namespace Tests\Support\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;

/**
 * 화면 조회 조건 ↔ 서버 게이트 키 회귀 가드 (#17).
 *
 * 목록 화면의 검색·필터는 세 단계를 거쳐 서버에 닿는다.
 *
 *   필터 컨트롤 → navigate `query` → data_source `params` → FormRequest `rules()` → validated()
 *
 * 어느 한 단계에서 키 이름이 어긋나면 그 조건은 **조용히 버려진다**. 요청은 200 으로 끝나고
 * 목록은 조건 없이 그대로 나오므로 운영자는 검색이 된 것으로 읽는다. 실제로 이 형태로
 * 스케줄 목록(검색어 `search`·모듈 필터 `module`)과 알림 발송 로그(검색대상 `search_type`)가
 * 깨져 있었다.
 *
 * - **검사 A**: GET data_source 의 params 키와 endpoint 쿼리 문자열 키는 그 엔드포인트
 *   FormRequest `rules()` 의 최상위 키여야 한다(`validated()` 가 버리지 않는다).
 * - **검사 B**: 레이아웃(과 그 partial)이 자기 경로로 navigate 하며 싣는 `query` 키는
 *   같은 레이아웃의 어떤 data_source 가 `query.K` 로 읽어야 한다(URL 에만 남지 않는다).
 *
 * 예외는 사유를 단 allowlist 로 둔다. 대상 쌍을 손으로 나열하지 않는다 — 새 목록 화면이
 * 추가돼도 자동으로 검사된다.
 */
trait AssertsLayoutQueryParamGateParity
{
    use ResolvesLayoutEndpointFormRequest;

    /**
     * 게이트가 선언하지 않아도 서버가 직접 읽는 쿼리 키.
     *
     * `page` 는 Laravel 페이지네이터가 요청에서 직접 읽는다 — validated() 를 거치지 않는다.
     *
     * @var array<int, string>
     */
    private array $implicitQueryKeys = ['page'];

    /**
     * FormRequest 클래스 → 최상위 규칙 키 메모 (null = 규칙을 읽지 못함).
     *
     * @var array<string, array<int, string>|null>
     */
    private array $ruleKeyCache = [];

    /**
     * 패키지 루트 → (레이아웃명 → 화면 경로 목록) 메모.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $routePathCache = [];

    /**
     * 검사 A — data_source 가 보내는 쿼리 키는 엔드포인트 게이트가 받아야 한다.
     *
     * @param  array<int, string>  $roots  레이아웃 탐색 루트
     * @param  array<string, array<string, string>>  $allowlist  `상대경로 => [키 => 사유]`
     * @return void 반환값 없음
     */
    protected function assertDataSourceParamsWithinGate(array $roots, array $allowlist = []): void
    {
        $failures = [];
        $unreadable = [];
        $gatedCount = 0;

        foreach ($this->layoutFiles($roots) as $file) {
            $json = json_decode(file_get_contents($file), true);

            if (! is_array($json)) {
                continue;
            }

            $relative = $this->relativeLayoutPath($file);

            foreach ($this->getDataSources($json) as $source) {
                $keys = $this->dataSourceQueryKeys($source);

                if ($keys === []) {
                    continue;
                }

                $gate = $this->gateRuleKeysForEndpoint($source['endpoint']);

                if ($gate === null) {
                    continue;
                }

                if ($gate['keys'] === null) {
                    $unreadable[] = sprintf('%s → %s (%s)', $relative, $source['endpoint'], $gate['class']);

                    continue;
                }

                $gatedCount++;
                $missing = array_values(array_diff(
                    $keys,
                    $gate['keys'],
                    $this->implicitQueryKeys,
                    array_keys($allowlist[$relative] ?? [])
                ));

                if ($missing !== []) {
                    $failures[] = sprintf(
                        "%s\n    data_source: %s (%s)\n    게이트: %s\n    게이트가 버리는 키: %s",
                        $relative,
                        $source['id'] ?? '?',
                        $source['endpoint'],
                        $gate['class'],
                        implode(',', $missing)
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $unreadable,
            "FormRequest 의 rules() 를 읽지 못해 대조가 성립하지 않았습니다(초록이 곧 안전이 아닙니다).\n  ".implode("\n  ", $unreadable)
        );

        $this->assertGreaterThan(
            0,
            $gatedCount,
            '게이트에 도달한 data_source 가 하나도 없습니다 — 수집기나 라우트 역추적이 깨져 테스트가 공허하게 통과하고 있습니다.'
        );

        $this->assertSame(
            [],
            $failures,
            '화면이 보내는 조회 조건을 서버 게이트가 선언하지 않아 validated() 에서 버려집니다 — 요청은 200 으로 '
            ."끝나고 목록은 조건 없이 그대로 나와, 검색·필터가 적용된 것처럼 보입니다.\n\n".implode("\n\n", $failures)
        );
    }

    /**
     * 검사 B — 자기 경로로 navigate 하며 싣는 query 키는 같은 화면의 data_source 가 읽어야 한다.
     *
     * @param  array<int, string>  $roots  레이아웃 탐색 루트
     * @param  array<string, array<string, string>>  $allowlist  `부모 레이아웃 상대경로 => [키 => 사유]`
     * @return void 반환값 없음
     */
    protected function assertNavigateQueryKeysConsumed(array $roots, array $allowlist = []): void
    {
        $families = [];

        foreach ($this->layoutFiles($roots) as $file) {
            $owner = $this->parentLayoutFile($file) ?? $file;
            $families[str_replace('\\', '/', $owner)][] = $file;
        }

        $failures = [];
        $checkedCount = 0;

        foreach ($families as $owner => $files) {
            $ownerJson = is_file($owner) ? json_decode(file_get_contents($owner), true) : null;

            if (! is_array($ownerJson)) {
                continue;
            }

            $selfPaths = $this->screenPathsFor($owner);

            if ($selfPaths === []) {
                continue;
            }

            $consumed = $this->consumedQueryKeys($this->getDataSources($ownerJson));
            $relativeOwner = $this->relativeLayoutPath($owner);
            $navigated = [];

            foreach ($files as $file) {
                $json = json_decode(file_get_contents($file), true);

                if (is_array($json)) {
                    $this->walkForSelfNavigateKeys($json, $selfPaths, $navigated, $this->relativeLayoutPath($file));
                }
            }

            if ($navigated === []) {
                continue;
            }

            $checkedCount++;
            $unconsumed = array_diff_key(
                $navigated,
                array_flip($consumed),
                array_flip($this->implicitQueryKeys),
                $allowlist[$relativeOwner] ?? []
            );

            foreach ($unconsumed as $key => $sources) {
                $failures[] = sprintf(
                    "%s\n    query 키: %s\n    navigate 위치: %s\n    data_source 가 읽는 query 키: %s",
                    $relativeOwner,
                    $key,
                    implode(', ', array_unique($sources)),
                    implode(',', $consumed) ?: '없음'
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $checkedCount,
            '자기 경로 navigate 를 가진 화면을 하나도 찾지 못했습니다 — 수집기나 경로 해석이 깨졌을 수 있습니다.'
        );

        $this->assertSame(
            [],
            $failures,
            '필터 컨트롤이 URL 에 싣는 조건을 어떤 data_source 도 읽지 않습니다 — 주소창에는 조건이 남지만 '
            ."서버 요청에는 실리지 않아 필터가 동작하지 않습니다.\n\n".implode("\n\n", $failures)
        );
    }

    /**
     * 레이아웃의 data_sources 목록을 반환합니다.
     *
     * @param  array  $json  레이아웃 JSON
     * @return array<int, array> data_source 정의 목록
     */
    private function getDataSources(array $json): array
    {
        $sources = $json['data_sources'] ?? [];

        return is_array($sources) ? array_values(array_filter($sources, 'is_array')) : [];
    }

    /**
     * GET API data_source 가 서버로 보내는 쿼리 키를 반환합니다.
     *
     * @param  array  $source  data_source 정의
     * @return array<int, string> 쿼리 키 (대조 대상이 아니면 빈 배열)
     */
    private function dataSourceQueryKeys(array $source): array
    {
        $endpoint = $source['endpoint'] ?? null;
        $method = strtoupper((string) ($source['method'] ?? 'GET'));
        $type = $source['type'] ?? 'api';

        if (! is_string($endpoint) || ! str_starts_with($endpoint, '/api/') || $method !== 'GET' || $type !== 'api') {
            return [];
        }

        $keys = [];
        $params = $source['params'] ?? null;

        if (is_array($params) && ! array_is_list($params)) {
            $keys = array_keys($params);
        }

        $queryString = parse_url(preg_replace('/\{\{.*?\}\}/', 'x', $endpoint), PHP_URL_QUERY);

        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $parsed);
            $keys = array_merge($keys, array_keys($parsed));
        }

        return array_values(array_unique(array_map(fn ($key) => $this->baseQueryKey((string) $key), $keys)));
    }

    /**
     * 배열 표기 쿼리 키를 최상위 키로 줄입니다 (`status[]`·`filters[0][field]` → `status`·`filters`).
     *
     * @param  string  $key  쿼리 키
     * @return string 최상위 키
     */
    private function baseQueryKey(string $key): string
    {
        return preg_replace('/\[.*$/', '', $key);
    }

    /**
     * 엔드포인트 게이트(FormRequest)의 최상위 규칙 키를 반환합니다.
     *
     * @param  string  $endpoint  레이아웃에 선언된 엔드포인트
     * @return array{class: string, keys: array<int, string>|null}|null 게이트가 없으면(라우트 미일치·FormRequest 미사용) null
     */
    private function gateRuleKeysForEndpoint(string $endpoint): ?array
    {
        foreach ($this->matchingRouteActions($endpoint) as $action) {
            $classes = $this->formRequestClassesForAction($action);

            if ($classes === []) {
                // 가장 구체적인 라우트가 FormRequest 를 쓰지 않으면 게이트가 없는 엔드포인트다
                return null;
            }

            $keys = [];

            foreach ($classes as $class) {
                $classKeys = $this->ruleKeysFor($class);

                if ($classKeys === null) {
                    return ['class' => $class, 'keys' => null];
                }

                $keys = array_merge($keys, $classKeys);
            }

            return ['class' => implode('+', $classes), 'keys' => array_values(array_unique($keys))];
        }

        return null;
    }

    /**
     * FormRequest 의 최상위 규칙 키를 읽습니다 (`status.*` → `status`).
     *
     * 훅 필터로 확장되는 규칙까지 보려면 rules() 를 실제로 실행해야 한다. 라우트 바인딩·인증
     * 사용자에 기대는 규칙이 있으면 실행이 실패할 수 있으므로, 그때는 null 을 돌려 대조
     * 불성립으로 보고한다(조용히 건너뛰지 않는다).
     *
     * @param  class-string<FormRequest>  $class  FormRequest 클래스명
     * @return array<int, string>|null 최상위 키 목록 (읽지 못하면 null)
     */
    private function ruleKeysFor(string $class): ?array
    {
        if (array_key_exists($class, $this->ruleKeyCache)) {
            return $this->ruleKeyCache[$class];
        }

        if (! method_exists($class, 'rules') || (new ReflectionClass($class))->isAbstract()) {
            return $this->ruleKeyCache[$class] = [];
        }

        try {
            /** @var FormRequest $request */
            $request = $class::create('/', 'GET');
            $request->setContainer(app());
            $rules = app()->call([$request, 'rules']);
        } catch (\Throwable) {
            return $this->ruleKeyCache[$class] = null;
        }

        $keys = [];

        foreach (array_keys(is_array($rules) ? $rules : []) as $key) {
            $keys[] = explode('.', (string) $key, 2)[0];
        }

        return $this->ruleKeyCache[$class] = array_values(array_unique($keys));
    }

    /**
     * data_source 정의 전체에서 읽는 `query.K` 키를 수집합니다.
     *
     * @param  array<int, array>  $sources  data_source 정의 목록
     * @return array<int, string> 읽는 query 키
     */
    private function consumedQueryKeys(array $sources): array
    {
        $encoded = json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // `query.K` · `query?.K` · `query['K']` · `query['K[]']` (JSON 인코딩이라 따옴표가 `\"` 일 수 있다)
        preg_match_all('/\bquery\??\.([A-Za-z_][A-Za-z0-9_]*)|\bquery(?:\?\.)?\[\\\\?[\'"]([^\'"\\\\]+)\\\\?[\'"]\]/', $encoded, $m);

        $keys = array_filter(array_merge($m[1], $m[2]));

        return array_values(array_unique(array_map(fn ($key) => $this->baseQueryKey($key), $keys)));
    }

    /**
     * 자기 화면 경로로 향하는 navigate 의 query 키를 재귀적으로 수집합니다.
     *
     * @param  mixed  $node  탐색 노드
     * @param  array<int, string>  $selfPaths  이 화면의 경로 목록
     * @param  array<string, array<int, string>>  $keys  키 → 발견 파일 (참조)
     * @param  string  $file  현재 파일 상대 경로 (실패 메시지용)
     * @return void 반환값 없음
     */
    private function walkForSelfNavigateKeys(mixed $node, array $selfPaths, array &$keys, string $file): void
    {
        if (! is_array($node)) {
            return;
        }

        if (($node['handler'] ?? null) === 'navigate' && is_array($node['params'] ?? null)) {
            $path = $node['params']['path'] ?? null;
            $query = $node['params']['query'] ?? null;

            if (is_string($path) && ($path === '{{route.path}}' || in_array($path, $selfPaths, true))
                && is_array($query) && ! array_is_list($query)) {
                foreach (array_keys($query) as $key) {
                    $keys[$this->baseQueryKey((string) $key)][] = $file;
                }
            }
        }

        foreach ($node as $child) {
            $this->walkForSelfNavigateKeys($child, $selfPaths, $keys, $file);
        }
    }

    /**
     * 레이아웃이 그려지는 화면 경로 목록을 패키지 routes 정의에서 찾습니다.
     *
     * `*\/admin/schedules` 처럼 앞의 와일드카드는 떼고 비교한다.
     *
     * @param  string  $layoutFile  레이아웃 절대 경로
     * @return array<int, string> 화면 경로 목록 (없으면 빈 배열)
     */
    private function screenPathsFor(string $layoutFile): array
    {
        $path = str_replace('\\', '/', $layoutFile);

        if (! preg_match('#^(.*/(?:templates|modules|plugins)/_bundled/[^/]+)/#', $path, $m)
            && ! preg_match('#^(.*)/resources/layouts/#', $path, $m)) {
            return [];
        }

        $packageRoot = $m[1];
        $name = basename($path, '.json');

        if (! isset($this->routePathCache[$packageRoot])) {
            $map = [];
            $routeFiles = array_merge(
                glob($packageRoot.'/routes.json') ?: [],
                glob($packageRoot.'/resources/routes/*.json') ?: []
            );

            foreach ($routeFiles as $routeFile) {
                $routes = json_decode(file_get_contents($routeFile), true)['routes'] ?? [];

                foreach (is_array($routes) ? $routes : [] as $route) {
                    if (isset($route['layout'], $route['path']) && is_string($route['path'])) {
                        $map[$route['layout']][] = '/'.ltrim(preg_replace('#^\*#', '', $route['path']), '/');
                    }
                }
            }

            $this->routePathCache[$packageRoot] = $map;
        }

        return $this->routePathCache[$packageRoot][$name] ?? [];
    }
}
