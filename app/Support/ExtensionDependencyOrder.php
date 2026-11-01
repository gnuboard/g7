<?php

namespace App\Support;

/**
 * 여러 확장을 한 번에 처리할 때의 의존 순서 — 종류를 넘는 의존까지 반영한 안정 위상 정렬
 *
 * 노드 키는 `종류:식별자`(`module:sirsoft-page`, `plugin:sirsoft-ckeditor5`)다. 기본 순서(호출자가 넘긴 목록 순서)대로
 * 깊이 우선으로 돌며 목록 안의 의존을 먼저 놓으므로, 의존이 없는 구성은 순서가 바뀌지 않는다. 목록 밖 의존은 무시한다
 * (설치·버전 판정은 각 매니저가 따로 한다). 순환이면 그 구간의 노드끼리는 원래 순서로 두고 `cycles` 에 경로를 남긴다.
 *
 * 설치 마법사는 Laravel 부팅 전이라 같은 규칙을 `public/install/includes/extension-selection.php`(+ JS 사본)로 따로 들고 있다.
 * 끄기처럼 의존하는 쪽부터 처리해야 하면 결과를 {@see self::reverse()} 로 뒤집어 쓴다.
 *
 * @since 7.1.4
 */
class ExtensionDependencyOrder
{
    /** manifest 의존 유형 → 노드 종류 */
    private const TYPE_PREFIX = ['modules' => 'module', 'plugins' => 'plugin'];

    /**
     * 노드 목록을 의존 대상이 먼저 오도록 정렬합니다.
     *
     * @param  array<int, array<string, mixed>>  $nodes  노드 목록 (`key` = `종류:식별자`, 그 밖의 값은 그대로 보존) — 기본 순서
     * @param  callable(string): array<int, string>  $dependenciesOf  노드 키 → 의존 노드 키 목록
     * @return array{ordered: array<int, array<string, mixed>>, cycles: array<int, array<int, string>>} 정렬된 노드와 순환 경로 목록
     */
    public static function order(array $nodes, callable $dependenciesOf): array
    {
        $byKey = [];
        foreach ($nodes as $index => $node) {
            $key = (string) ($node['key'] ?? '');
            $byKey[$key !== '' ? $key : "#{$index}"] = $node;
        }

        // 목록 안의 의존만, 자기 자신 의존은 순환이 아니라 잘못 적은 선언이라 뺀다
        $edges = [];
        foreach (array_keys($byKey) as $key) {
            $key = (string) $key;
            $edges[$key] = array_values(array_unique(array_filter(
                array_map('strval', $dependenciesOf($key)),
                fn (string $dep): bool => $dep !== $key && isset($byKey[$dep])
            )));
        }

        // 1차 — 순환 찾기
        $cycles = [];
        $inCycle = [];
        $state = []; // 1 = 방문 중, 2 = 완료
        $find = function (string $key, array $path) use (&$find, &$state, &$cycles, &$inCycle, $edges): void {
            if (($state[$key] ?? 0) === 2) {
                return;
            }
            if (($state[$key] ?? 0) === 1) {
                $cycle = array_slice($path, (int) array_search($key, $path, true));
                $cycle[] = $key;
                $cycles[] = $cycle;
                foreach ($cycle as $member) {
                    $inCycle[$member] = true;
                }

                return;
            }
            $state[$key] = 1;
            foreach ($edges[$key] as $dep) {
                $find($dep, [...$path, $key]);
            }
            $state[$key] = 2;
        };
        foreach (array_keys($byKey) as $key) {
            $find((string) $key, []);
        }

        // 2차 — 정렬. 순환 구성원끼리의 간선은 무시해 그 구간을 원래 순서로 둔다
        $sorted = [];
        $done = [];
        $visit = function (string $key) use (&$visit, &$sorted, &$done, $edges, $inCycle, $byKey): void {
            if (isset($done[$key])) {
                return;
            }
            $done[$key] = true;
            foreach ($edges[$key] as $dep) {
                if (isset($inCycle[$key], $inCycle[$dep])) {
                    continue;
                }
                $visit($dep);
            }
            $sorted[] = $byKey[$key];
        };
        foreach (array_keys($byKey) as $key) {
            $visit((string) $key);
        }

        return ['ordered' => $sorted, 'cycles' => $cycles];
    }

    /**
     * 정렬 결과를 뒤집습니다 — 의존하는 쪽부터 처리해야 하는 경로(비활성화 등)에 씁니다.
     *
     * @param  array<int, array<string, mixed>>  $ordered  {@see self::order()} 의 `ordered`
     * @return array<int, array<string, mixed>> 뒤집은 목록
     */
    public static function reverse(array $ordered): array
    {
        return array_reverse(array_values($ordered));
    }

    /**
     * manifest `dependencies` 선언을 노드 키 목록으로 바꿉니다(모듈·플러그인 의존만 — 템플릿은 의존 대상이 아니다).
     *
     * @param  mixed  $rawDependencies  manifest 의 dependencies 값 (객체형·목록형 모두)
     * @return array<int, string> `module:…`·`plugin:…` 키 목록
     */
    public static function dependencyKeys(mixed $rawDependencies): array
    {
        $constraints = ExtensionDependencies::constraints($rawDependencies);
        $keys = [];
        foreach (self::TYPE_PREFIX as $type => $prefix) {
            foreach (array_keys($constraints[$type]) as $identifier) {
                $keys[] = $prefix.':'.$identifier;
            }
        }

        return $keys;
    }

    /**
     * 번들 일괄 업데이트의 모듈·플러그인 항목을 한 목록으로 정렬합니다(기본 순서 모듈 → 플러그인, 의존만 앞당김).
     *
     * 프로세스 분리 경로(`core:execute-bundled-updates`)와 같은 프로세스 경로(`proc_open` 없음)가 함께 씁니다.
     * 의존 선언은 호출자가 읽어 넘긴다 — 일괄 업데이트는 새 배포본 manifest 를 따라야 한다.
     *
     * @param  array<int, array<string, mixed>>  $modules  모듈 항목 (`identifier` 필수)
     * @param  array<int, array<string, mixed>>  $plugins  플러그인 항목 (`identifier` 필수)
     * @param  callable(string): array<int, string>  $dependenciesOf  노드 키 → 의존 노드 키 목록
     * @return array{ordered: array<int, array{key: string, type: string, entry: array<string, mixed>}>, cycles: array<int, array<int, string>>} 정렬 결과
     */
    public static function orderModulesAndPlugins(array $modules, array $plugins, callable $dependenciesOf): array
    {
        $nodes = [];
        foreach (['module' => $modules, 'plugin' => $plugins] as $type => $entries) {
            foreach ($entries as $entry) {
                $identifier = (string) ($entry['identifier'] ?? '');
                if ($identifier === '') {
                    continue;
                }
                $nodes[] = ['key' => "{$type}:{$identifier}", 'type' => $type, 'entry' => $entry];
            }
        }

        return self::order($nodes, $dependenciesOf);
    }
}
