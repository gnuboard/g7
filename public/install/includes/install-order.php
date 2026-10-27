<?php

/**
 * 인스톨러 — 같은 유형 확장의 설치 순서 정렬
 *
 * 설치 작업(task-runner `buildTaskList`)은 `selected_extensions.modules`·`plugins` 배열 순서대로
 * install → activate 를 이어 붙인다. Step 4 에서 의존 대상이 자동 선택되면 배열 끝에 붙기 때문에,
 * 모듈 A 가 모듈 B 에 의존하는데 B 가 A 뒤에 오면 A 의 설치·활성화가 의존 미충족으로 실패할 수 있다.
 * 그래서 저장할 때 같은 유형 배열을 `dependencies` 기준으로 위상 정렬한다.
 *
 * - 정렬은 안정적이다: 의존 관계가 없는 항목끼리는 사용자가 고른 순서를 지킨다.
 * - 순환 의존이면 원래 순서를 그대로 돌려주고 콜백으로 알린다(설치를 막지는 않는다 — 막는 것은 사전 검증의 몫).
 * - 선택되지 않은 확장·다른 유형 확장은 정렬에 영향이 없다.
 *
 * Laravel 부팅 전에 도는 파일이라 앱 클래스를 쓰지 않는다. 함수만 정의하고 부수효과가 없다.
 */
if (! function_exists('installerSameTypeDependencyIds')) {
    /**
     * 매니페스트 `dependencies` 에서 지정 유형의 의존 식별자만 뽑습니다.
     *
     * 객체형 `{"modules": {"a": ">=1"}}` 은 키, 유형별 목록형 `{"modules": ["a"]}` 은 값이 식별자다.
     * 유형을 알 수 없는 옛 배열형 `["a", "b"]` 은 전부 돌려준다(같은 유형에 없는 식별자는 정렬에서 무시된다).
     *
     * @param  mixed  $dependencies  매니페스트 dependencies 값
     * @param  string  $type  유형 키 (modules|plugins)
     * @return array<int, string> 의존 식별자 목록
     */
    function installerSameTypeDependencyIds(mixed $dependencies, string $type): array
    {
        if (! is_array($dependencies)) {
            return [];
        }

        $isTyped = isset($dependencies['modules']) || isset($dependencies['plugins']);
        $entries = $isTyped ? ($dependencies[$type] ?? []) : $dependencies;
        if (! is_array($entries)) {
            return [];
        }

        $ids = [];
        foreach ($entries as $key => $value) {
            $id = is_int($key) ? $value : $key;
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return array_values(array_unique($ids));
    }
}

if (! function_exists('installerLoadBundledDependencyMap')) {
    /**
     * `_bundled` 의 같은 유형 매니페스트를 읽어 「식별자 → 같은 유형 의존 식별자 목록」을 만듭니다.
     *
     * @param  string  $basePath  프로젝트 루트 경로
     * @param  string  $type  유형 키 (modules|plugins)
     * @return array<string, array<int, string>> 식별자별 같은 유형 의존 목록
     */
    function installerLoadBundledDependencyMap(string $basePath, string $type): array
    {
        $manifestName = match ($type) {
            'modules' => 'module.json',
            'plugins' => 'plugin.json',
            default => null,
        };
        if ($manifestName === null) {
            return [];
        }

        $bundleDir = rtrim($basePath, '/\\').'/'.$type.'/_bundled';
        if (! is_dir($bundleDir)) {
            return [];
        }

        $map = [];
        foreach (scandir($bundleDir) ?: [] as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $manifestPath = $bundleDir.'/'.$dir.'/'.$manifestName;
            if (! is_file($manifestPath)) {
                continue;
            }

            $data = json_decode((string) @file_get_contents($manifestPath), true);
            if (! is_array($data)) {
                continue;
            }

            $identifier = is_string($data['identifier'] ?? null) ? $data['identifier'] : $dir;
            $map[$identifier] = installerSameTypeDependencyIds($data['dependencies'] ?? [], $type);
        }

        return $map;
    }
}

if (! function_exists('installerSortSameTypeByDependencies')) {
    /**
     * 같은 유형 식별자 배열을 의존 대상이 먼저 오도록 안정 위상 정렬합니다.
     *
     * 원래 순서대로 훑으며 각 항목의 (선택된) 의존 대상을 먼저 내보내는 깊이 우선 방식이라,
     * 의존 관계가 없는 항목끼리의 상대 순서는 바뀌지 않는다.
     * 순환을 만나면 원래 배열을 그대로 돌려주고 `$onCycle` 에 순환 경로를 넘긴다.
     *
     * @param  array<int, string>  $identifiers  선택된 같은 유형 식별자 (사용자·자동 선택 순서)
     * @param  array<string, array<int, string>>  $dependencyMap  식별자 → 같은 유형 의존 식별자 목록
     * @param  callable(array<int, string>): void|null  $onCycle  순환 발견 시 호출 (순환 경로 식별자 목록)
     * @return array<int, string> 정렬된 식별자 목록
     */
    function installerSortSameTypeByDependencies(array $identifiers, array $dependencyMap, ?callable $onCycle = null): array
    {
        $identifiers = array_values($identifiers);
        $selected = array_flip($identifiers);

        $sorted = [];
        $done = [];
        $visiting = [];
        $path = [];
        $cycle = null;

        $visit = function (string $id) use (&$visit, &$sorted, &$done, &$visiting, &$path, &$cycle, $selected, $dependencyMap): bool {
            if (isset($done[$id])) {
                return true;
            }
            if (isset($visiting[$id])) {
                $start = array_search($id, $path, true);
                $cycle = array_merge(array_slice($path, $start === false ? 0 : $start), [$id]);

                return false;
            }

            $visiting[$id] = true;
            $path[] = $id;

            foreach ($dependencyMap[$id] ?? [] as $depId) {
                if ($depId === $id || ! isset($selected[$depId])) {
                    continue;
                }
                if (! $visit($depId)) {
                    return false;
                }
            }

            array_pop($path);
            unset($visiting[$id]);
            $done[$id] = true;
            $sorted[] = $id;

            return true;
        };

        foreach ($identifiers as $id) {
            if (! $visit($id)) {
                if ($onCycle !== null) {
                    $onCycle($cycle ?? []);
                }

                return $identifiers;
            }
        }

        return $sorted;
    }
}
