<?php

namespace Tests\Support\Concerns;

use ReflectionClass;

/**
 * 화면 정렬 옵션 ↔ 게이트 허용 집합 회귀 가드 (#492 D-19).
 *
 * 정렬 제한은 세 계층으로 겹쳐 있다.
 *
 *   화면 정렬 옵션 ⊆ FormRequest 게이트 ⊆ Repository 화이트리스트
 *
 * 아래 두 계층은 `SortWhitelistGateParityTest` 가 본다. 이 테스트는 **맨 위 계층**을 본다.
 *
 * 레이아웃의 정렬 셀렉트가 게이트에 없는 컬럼을 제공하면, 그 옵션을 고르는 순간 422 가 나고
 * 목록은 직전 결과 그대로 남는다. 셀렉트 라벨만 새 값으로 바뀌므로 운영자는 정렬이 적용된
 * 것으로 읽는다 — 토스트도 뜨지 않는다. 실제로 이 형태로 두 화면이 깨져 있었다
 * (쿠폰 목록 `valid_to`, 주문 목록 `shipped_at`).
 *
 * 대상 쌍을 손으로 나열하지 않는다. 레이아웃에서 정렬 셀렉트를 찾고, 같은 레이아웃이
 * 조회하는 엔드포인트를 라우트에서 역추적해 그 FormRequest 를 읽는다. 새 목록 화면이
 * 추가돼도 자동으로 검사된다. 레이아웃 → 라우트 → FormRequest 역추적은
 * `ResolvesLayoutEndpointFormRequest` 가 맡는다(쿼리 키 가드와 공용).
 */
trait AssertsLayoutSortOptionGateParity
{
    use ResolvesLayoutEndpointFormRequest;

    /**
     * FormRequest 클래스 → 허용 정렬 컬럼 메모. 같은 게이트를 여러 화면이 공유한다.
     *
     * @var array<string, array<int, string>>
     */
    private array $gateCache = [];

    /**
     * 엔드포인트 문자열 → 허용 정렬 컬럼 메모. 같은 엔드포인트가 여러 레이아웃에 반복된다.
     *
     * @var array<string, array<int, string>>
     */
    private array $endpointCache = [];

    /**
     * 레이아웃이 제공하는 정렬 컬럼은 그 화면이 호출하는 엔드포인트의 게이트가 허용해야 한다.
     *
     * @param  array<int, string>  $roots  레이아웃 탐색 루트
     * @return void 반환값 없음
     */
    protected function assertLayoutSortOptionsWithinGate(array $roots): void
    {
        $surfaces = $this->collectSortSurfaces($roots);

        $this->assertNotEmpty($surfaces, '정렬 셀렉트를 가진 레이아웃을 하나도 찾지 못했습니다 — 수집기가 깨졌을 수 있습니다.');

        $failures = [];
        $unlinked = [];
        $gatedCount = 0;

        foreach ($surfaces as $surface) {
            $allowed = [];
            $routeMatched = false;

            foreach ($surface['endpoints'] as $endpoint) {
                $resolved = $this->resolveEndpoint($endpoint);
                $routeMatched = $routeMatched || $resolved['route_matched'];
                $allowed = array_merge($allowed, $resolved['columns']);
            }

            // 어떤 엔드포인트도 라우트에 닿지 않으면 대조가 성립하지 않은 것이다.
            // 조용히 건너뛰면 이 테스트는 초록인 채로 아무것도 보지 않게 된다.
            if (! $routeMatched) {
                $unlinked[] = sprintf('%s (엔드포인트: %s)', $surface['file'], implode(',', $surface['endpoints']) ?: '없음');

                continue;
            }

            if ($allowed === []) {
                // 라우트는 찾았으나 그 엔드포인트가 정렬을 게이트하지 않는 경우 — 대조 대상 아님
                continue;
            }

            $gatedCount++;
            $missing = array_values(array_diff($surface['columns'], array_unique($allowed)));

            if ($missing !== []) {
                $failures[] = sprintf(
                    "%s\n    화면 옵션: %s\n    게이트 허용: %s\n    게이트에 없음: %s",
                    $surface['file'],
                    implode(',', $surface['columns']),
                    implode(',', array_unique($allowed)),
                    implode(',', $missing)
                );
            }
        }

        $this->assertSame(
            [],
            $unlinked,
            '정렬 셀렉트가 있는 화면인데 그 화면이 조회하는 엔드포인트를 라우트에서 찾지 못했습니다 — '
            ."대조가 성립하지 않아 이 화면은 검사되지 않습니다(초록이 곧 안전이 아닙니다).\n  ".implode("\n  ", $unlinked)
        );

        $this->assertGreaterThan(
            0,
            $gatedCount,
            '게이트에 도달한 화면이 하나도 없습니다 — 수집기나 라우트 역추적이 깨져 테스트가 공허하게 통과하고 있습니다.'
        );

        $this->assertSame(
            [],
            $failures,
            '화면이 제공하는 정렬 옵션을 게이트가 거절합니다 — 그 옵션을 고르면 422 가 나고 목록은 '
            ."직전 결과 그대로 남아 정렬이 적용된 것처럼 보입니다.\n\n".implode("\n\n", $failures)
        );
    }

    /**
     * 정렬 셀렉트를 가진 레이아웃을 수집합니다.
     *
     * @param  array<int, string>  $roots  레이아웃 탐색 루트
     * @return array<int, array{file: string, columns: array<int, string>, endpoints: array<int, string>}> 화면별 정렬 컬럼과 엔드포인트
     */
    private function collectSortSurfaces(array $roots): array
    {
        $surfaces = [];

        foreach ($this->layoutFiles($roots) as $file) {
            $json = json_decode(file_get_contents($file), true);

            if (! is_array($json)) {
                continue;
            }

            $columns = [];
            $this->walkForSortColumns($json, $columns);

            if ($columns === []) {
                continue;
            }

            $endpoints = [];
            $this->walkForEndpoints($json, $endpoints);

            // Partial 은 규정상 data_sources 를 갖지 않는다 — 부모 레이아웃의 엔드포인트를 쓴다.
            // 이 연결이 없으면 정렬 옵션이 partial 에 선언된 화면이 통째로 미검사가 된다.
            if ($endpoints === []) {
                $endpoints = $this->parentLayoutEndpoints($file);
            }

            $surfaces[] = [
                'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file),
                'columns' => array_values(array_unique($columns)),
                'endpoints' => array_values(array_unique($endpoints)),
            ];
        }

        return $surfaces;
    }

    /**
     * 정렬 셀렉트가 방출할 수 있는 정렬 컬럼을 재귀적으로 수집합니다.
     *
     * `{col}_{asc|desc}` 형태의 옵션 값에서 컬럼을 뽑고, 액션이 `sort_by` 에 리터럴을
     * 넣는 형태(활동 로그처럼 컬럼 고정 + 방향만 변경)도 함께 인정한다.
     *
     * @param  mixed  $node  탐색 노드
     * @param  array<int, string>  $columns  수집 결과 (참조)
     * @return void 반환값 없음
     */
    private function walkForSortColumns(mixed $node, array &$columns): void
    {
        if (! is_array($node)) {
            return;
        }

        $props = $node['props'] ?? null;

        if (is_array($props) && isset($props['name'], $props['options']) && is_array($props['options'])) {
            $isSortSelect = in_array($props['name'], ['sortBy', 'sort', 'sortOrder'], true);
            $emitsSortBy = str_contains(json_encode($node['actions'] ?? [], JSON_UNESCAPED_UNICODE), 'sort_by');

            if ($isSortSelect && $emitsSortBy) {
                $literal = $this->literalSortColumn($node['actions'] ?? []);

                foreach ($props['options'] as $option) {
                    $value = is_array($option) ? ($option['value'] ?? null) : null;

                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    $columns[] = $literal ?? $this->columnFromOptionValue($value);
                }
            }
        }

        foreach ($node as $child) {
            $this->walkForSortColumns($child, $columns);
        }
    }

    /**
     * 액션이 `sort_by` 에 고정 리터럴을 넣는 경우 그 컬럼명을 반환합니다.
     *
     * @param  array  $actions  액션 배열
     * @return string|null 고정 컬럼명 (표현식이면 null)
     */
    private function literalSortColumn(array $actions): ?string
    {
        if (preg_match('/"sort_by"\s*:\s*"([^"{}]+)"/', json_encode($actions, JSON_UNESCAPED_UNICODE), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * 옵션 값에서 정렬 컬럼을 뽑습니다 (`valid_to_asc` → `valid_to`).
     *
     * @param  string  $value  옵션 값
     * @return string 정렬 컬럼
     */
    private function columnFromOptionValue(string $value): string
    {
        $parts = explode('_', $value);

        if (count($parts) > 1 && in_array(end($parts), ['asc', 'desc'], true)) {
            array_pop($parts);
        }

        return implode('_', $parts);
    }

    /**
     * 엔드포인트에 대응하는 라우트의 FormRequest 가 허용하는 정렬 컬럼을 반환합니다.
     *
     * @param  string  $endpoint  레이아웃에 선언된 엔드포인트
     * @return array{route_matched: bool, columns: array<int, string>} 라우트 매칭 여부와 허용 정렬 컬럼
     */
    private function resolveEndpoint(string $endpoint): array
    {
        if (array_key_exists($endpoint, $this->endpointCache)) {
            return $this->endpointCache[$endpoint];
        }

        $actions = $this->matchingRouteActions($endpoint);
        $columns = [];

        foreach ($actions as $action) {
            $columns = $this->gateColumnsForAction($action);

            if ($columns !== []) {
                break;
            }
        }

        return $this->endpointCache[$endpoint] = ['route_matched' => $actions !== [], 'columns' => $columns];
    }

    /**
     * 컨트롤러 액션의 FormRequest 에서 `sort_by` 허용 목록을 읽습니다.
     *
     * rules() 를 실행하지 않고 선언 소스를 읽는다 — 훅 필터/라우트 바인딩에 의존하는 규칙이
     * 있어 단위 테스트 환경에서 실행하면 부작용이 생긴다 (SortWhitelistGateParityTest 와 동일).
     *
     * @param  string  $actionName  `App\Http\Controllers\Foo@index` 형태
     * @return array<int, string> 허용 정렬 컬럼
     */
    private function gateColumnsForAction(string $actionName): array
    {
        foreach ($this->formRequestClassesForAction($actionName) as $requestClass) {
            $columns = $this->readGateSortColumns($requestClass);

            if ($columns !== []) {
                return $columns;
            }
        }

        return [];
    }

    /**
     * FormRequest 소스에서 `sort_by` 의 in: / Rule::in 허용 목록을 읽습니다.
     *
     * @param  string  $class  FormRequest 클래스명
     * @return array<int, string> 허용 정렬 컬럼
     */
    private function readGateSortColumns(string $class): array
    {
        if (array_key_exists($class, $this->gateCache)) {
            return $this->gateCache[$class];
        }

        return $this->gateCache[$class] = $this->parseGateSortColumns($class);
    }

    /**
     * FormRequest 소스를 실제로 읽어 허용 정렬 컬럼을 파싱합니다.
     *
     * @param  string  $class  FormRequest 클래스명
     * @return array<int, string> 허용 정렬 컬럼
     */
    private function parseGateSortColumns(string $class): array
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        if (preg_match("/'sort_by'\s*=>\s*'[^']*\bin:([^'|]+)/", $source, $m)) {
            return $this->splitColumns($m[1]);
        }

        if (preg_match("/'sort_by'\s*=>\s*\[[^\]]*'in:([^']+)'/", $source, $m)) {
            return $this->splitColumns($m[1]);
        }

        if (preg_match("/'sort_by'\s*=>\s*\[.*?Rule::in\(\s*\[(.*?)\]/s", $source, $m)) {
            return $this->splitColumns(str_replace("'", '', $m[1]));
        }

        return [];
    }

    /**
     * 쉼표 구분 컬럼 문자열을 배열로 변환합니다.
     *
     * @param  string  $raw  쉼표 구분 문자열
     * @return array<int, string> 정리된 컬럼 목록
     */
    private function splitColumns(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
