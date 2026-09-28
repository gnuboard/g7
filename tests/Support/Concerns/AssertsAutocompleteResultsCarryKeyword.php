<?php

namespace Tests\Support\Concerns;

/**
 * 원시 자동완성 목록의 늦은 응답 덮어쓰기 회귀 가드.
 *
 * `type: "input"` 액션이 검색 API 를 부르고 `onSuccess` 가 결과를 상태에 그대로 넣으면,
 * 응답 도착 순서가 요청 순서와 달라지는 순간 **화면의 키워드와 목록이 서로 다른 것을 가리킨다**.
 * 넓은 키워드(`m`)의 무거운 응답이 좁은 키워드(`meas`)의 가벼운 응답보다 늦게 오는 흔한 경우다.
 *
 * 증상이 조용한 것이 이 결함의 본질이다. 오류도 토스트도 없고, 입력칸에는 운영자가 친 전체
 * 문자열이 그대로 보이며, 목록도 정상적으로 그려진다. 다만 그 목록이 **다른 검색의 결과**라서
 * 운영자가 첫 행을 고르면 엉뚱한 회원이 선택된다. 마일리지 지급·쿠폰 발급처럼 값이 움직이는
 * 화면에서는 남의 계정으로 돈이 나간다.
 *
 * 2026-09-15 #599 세션3 실측에서 실제 키 입력으로 재현됐다. `keyword=m` 이 19,025ms 에 출발해
 * 24,787ms 에 도착하는 동안 `keyword=meas` 는 23,016→23,482ms 로 이미 끝나 있었고, 최종 상태는
 * `memberSearchKeyword="meas"` 인데 목록은 `m` 의 160건(첫 행 `관리자`)이었다.
 *
 * 그래서 계약은 **결과를 넣을 때 그 결과를 만든 키워드를 함께 적는 것**이다. 화면은 두 값이
 * 같을 때만 목록을 그리므로, 늦게 온 응답은 목록을 잠시 감출 뿐 잘못된 행을 보여주지 않는다.
 *
 * `SearchableDropdown` 처럼 컴포넌트가 `searchTerm` 으로 다시 거르는 경로(`event: "onSearch"`)는
 * 이 문제에 노출되지 않으므로 대상이 아니다 — 여기서 보는 것은 `type: "input"` 으로 직접
 * 목록을 그리는 형태뿐이다.
 */
trait AssertsAutocompleteResultsCarryKeyword
{
    /**
     * 검색 결과를 상태에 넣는 setState 는 그 결과를 만든 키워드도 함께 넣어야 한다.
     *
     * @param  array<int, string>  $roots
     */
    protected function assertAutocompleteResultsCarryKeyword(array $roots): void
    {
        $writes = $this->collectRawAutocompleteWrites($roots);

        $this->assertNotEmpty(
            $writes,
            '원시 자동완성(type: input + apiCall) 을 하나도 찾지 못했습니다 — 수집기가 깨졌을 수 있습니다.'
        );

        $unguarded = [];

        foreach ($writes as $write) {
            if ($write['keywordKey'] !== null) {
                continue;
            }

            $unguarded[] = sprintf('%s — %s', $write['file'], $write['resultsKey']);
        }

        $this->assertSame(
            [],
            $unguarded,
            "자동완성 결과를 저장하면서 그 결과를 만든 키워드를 함께 저장하지 않습니다.\n".
            "같은 setState 에 `<결과키>Keyword` 를 추가하고, 목록은 그 값이 현재 입력값과 같을 때만\n".
            "그리세요. 그러지 않으면 늦게 도착한 옛 키워드의 응답이 목록을 조용히 덮어씁니다:\n  ".
            implode("\n  ", $unguarded)
        );
    }

    /**
     * `type: "input"` 액션이 부르는 apiCall 의 onSuccess 가 쓰는 `*SearchResults` 키를 모은다.
     *
     * @param  array<int, string>  $roots
     * @return array<int, array{file: string, resultsKey: string, keywordKey: string|null}>
     */
    private function collectRawAutocompleteWrites(array $roots): array
    {
        $writes = [];

        foreach ($this->autocompleteLayoutFiles($roots) as $file) {
            $json = json_decode(file_get_contents($file), true);

            if (! is_array($json)) {
                continue;
            }

            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            $this->walkForInputActions($json, $relative, $writes);
        }

        return $writes;
    }

    /**
     * @param  array<int, array{file: string, resultsKey: string, keywordKey: string|null}>  $writes
     */
    private function walkForInputActions(mixed $node, string $file, array &$writes): void
    {
        if (! is_array($node)) {
            return;
        }

        if (($node['type'] ?? null) === 'input') {
            foreach ($this->apiCallsWithin($node) as $apiCall) {
                foreach ($this->successSetStates($apiCall) as $params) {
                    $this->recordResultWrites($params, $file, $writes);
                }
            }
        }

        foreach ($node as $child) {
            $this->walkForInputActions($child, $file, $writes);
        }
    }

    /**
     * 액션 정의 안에 중첩된(sequence 포함) apiCall 을 모두 찾는다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function apiCallsWithin(mixed $node, array $found = []): array
    {
        if (! is_array($node)) {
            return $found;
        }

        if (($node['handler'] ?? null) === 'apiCall' && isset($node['onSuccess'])) {
            $found[] = $node;
        }

        foreach ($node as $child) {
            $found = $this->apiCallsWithin($child, $found);
        }

        return $found;
    }

    /**
     * onSuccess 안의 setState params 들.
     *
     * @return array<int, array<string, mixed>>
     */
    private function successSetStates(array $apiCall): array
    {
        $actions = $apiCall['onSuccess'];
        $actions = array_is_list($actions) ? $actions : [$actions];

        $params = [];

        foreach ($actions as $action) {
            if (is_array($action) && ($action['handler'] ?? null) === 'setState' && is_array($action['params'] ?? null)) {
                $params[] = $action['params'];
            }
        }

        return $params;
    }

    /**
     * `…SearchResults` 로 끝나는 키마다 짝이 되는 `…SearchResultsKeyword` 가 있는지 본다.
     *
     * @param  array<string, mixed>  $params
     * @param  array<int, array{file: string, resultsKey: string, keywordKey: string|null}>  $writes
     */
    private function recordResultWrites(array $params, string $file, array &$writes): void
    {
        foreach (array_keys($params) as $key) {
            if (! str_ends_with((string) $key, 'SearchResults')) {
                continue;
            }

            $companion = $key.'Keyword';

            $writes[] = [
                'file' => $file,
                'resultsKey' => (string) $key,
                'keywordKey' => array_key_exists($companion, $params) ? $companion : null,
            ];
        }
    }

    /**
     * @param  array<int, string>  $roots
     * @return array<int, string>
     */
    private function autocompleteLayoutFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    fn ($current) => ! in_array($current->getFilename(), ['node_modules', 'dist', 'vendor', '.git'], true)
                )
            );

            foreach ($iterator as $entry) {
                $path = str_replace('\\', '/', $entry->getPathname());

                if ($entry->isFile() && str_ends_with($path, '.json')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return $files;
    }
}
