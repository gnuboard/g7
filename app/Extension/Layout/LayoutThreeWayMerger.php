<?php

namespace App\Extension\Layout;

use App\Extension\Layout\DTO\LayoutMergeResult;
use App\Support\LayoutMarkup\LayoutPartialOrigin;

/**
 * 레이아웃 3자 병합기 — 원본(base)·운영자 판(ours)·새 판(theirs)을 **조각 파일 단위 + 화면 최상위 키 단위**로 비교한다.
 *
 * git 의 3-way merge 를 "줄" 대신 "조각" 으로 하는 것이다. 운영자가 손대지 않은 단위는 새 판으로, 고친 단위는
 * 운영자 판으로, 양쪽이 같은 단위를 고쳤으면 운영자 판을 남기고 충돌로 알린다.
 *
 * 단위를 가르는 근거는 원본·새 판에만 있는 조각 출처 표식({@see LayoutPartialOrigin})이다. 운영자 판(DB `content`)에는
 * 표식이 없으므로 원본의 단위를 운영자 판 위에 **투영**해서 찾는다 — 새 판으로 바꿔치기하는 경우는 원본과 완전히
 * 같은 노드를 찾았을 때뿐이라, 엉뚱한 노드에 새 판이 붙지 않는다.
 *
 * 단계: 0 지름길 → 1 분해 → 2 운영자 판 투영 → 3 단위별 판정 → 4 고아 방지 → 5 조립·검증.
 * 판정표는 계획서 #727 §5.5 를 그대로 옮긴 것이다(각 분기 주석 참조).
 */
final class LayoutThreeWayMerger
{
    /**
     * 병합 중 조각 자리를 가리키는 자리표시 키 (`{"__g7_unit": "단위 키"}`).
     */
    public const UNIT_KEY = '__g7_unit';

    /**
     * 화면 자체(루트) 단위 키.
     */
    public const ROOT = 'root';

    /**
     * 반영은 하되 "자동 반영 N곳" 개수에 세지 않는 최상위 키 — 주석·버전 문자열 변경이 개수를 부풀리지 않게.
     */
    private const UNCOUNTED_KEYS = ['comment', 'version', 'layout_name'];

    /**
     * 경로에 값이 없음을 뜻하는 표식.
     */
    private const MISSING = "\0missing";

    /** @var list<array{unit: string, kind: string}> */
    private array $applied = [];

    /** @var list<array{unit: string, reason: string}> */
    private array $conflicts = [];

    /**
     * 세 판을 병합합니다.
     *
     * @param  array<string, mixed>  $base  원본 (조각 출처 표식 포함)
     * @param  array<string, mixed>  $ours  운영자 판 (DB 본문, 표식 없음)
     * @param  array<string, mixed>  $theirs  새 판 (조각 출처 표식 포함)
     * @return LayoutMergeResult 병합 결과
     */
    public function merge(array $base, array $ours, array $theirs): LayoutMergeResult
    {
        $this->applied = [];
        $this->conflicts = [];

        $plainTheirs = LayoutPartialOrigin::strip($theirs);

        // 0단계 — 지름길
        if (LayoutContentComparator::sameDocument($ours, $base)) {
            return new LayoutMergeResult($plainTheirs, 'took_theirs');
        }
        if (LayoutContentComparator::sameDocument($theirs, $base)) {
            return new LayoutMergeResult($ours, 'kept');
        }
        if (LayoutContentComparator::sameDocument($ours, $theirs)) {
            return new LayoutMergeResult($plainTheirs, 'took_theirs');
        }

        // 문서 루트가 객체가 아니면 단위를 가를 수 없다 — 운영자 판을 남기고 충돌로 본다.
        if (array_is_list($base) || array_is_list($ours) || array_is_list($theirs)) {
            return new LayoutMergeResult($ours, 'conflict', [], [['unit' => self::ROOT, 'reason' => 'both_changed']]);
        }

        // 1단계 — 분해
        $baseUnits = $this->collectUnits($base);
        $theirsUnits = $this->collectUnits($theirs);
        $baseBodies = $this->carve($base, $this->pathsOf($baseUnits));
        $theirsBodies = $this->carve($theirs, $this->pathsOf($theirsUnits));

        // 2단계 — 운영자 판 투영
        [$oursLoc, $oursState] = $this->project($ours, $base, $baseUnits);
        $oursPaths = $oursLoc;
        unset($oursPaths[self::ROOT]);
        $oursBodies = $this->carve($ours, $oursPaths);
        $oursContainer = $this->containers($oursPaths);
        $theirsContainer = $this->containers($this->pathsOf($theirsUnits));

        // 3단계 — 단위별 판정
        [$forcedUnits, $forcedRootKeys, $forcedParents] = $this->instanceCountGuard($baseUnits, $theirsUnits, $oursState);

        $choice = [];
        $added = [];
        $unitKeys = array_values(array_unique(array_merge(array_keys($baseUnits), array_keys($theirsUnits))));

        foreach ($unitKeys as $key) {
            if ($key === self::ROOT) {
                continue;
            }

            $b = $baseBodies[$key] ?? null;
            $o = ($oursState[$key] ?? 'absent') !== 'absent' ? ($oursBodies[$key] ?? null) : null;
            $t = $theirsBodies[$key] ?? null;

            if (isset($forcedUnits[$key])) {
                $choice[$key] = $o !== null ? 'o' : 'none';

                continue;
            }

            if ($b === null && $o === null && $t !== null) {
                $choice[$key] = 't';
                $added[$key] = true;

                continue;
            }

            $choice[$key] = $this->decide($key, 'partial', $b, $o, $t);
        }

        foreach ($forcedParents as $parent => $_) {
            if ($parent !== self::ROOT && isset($choice[$parent]) && $choice[$parent] !== 'none') {
                $choice[$parent] = 'o';
            }
        }

        $rootChoice = [];
        $dataSources = null;
        $baseRoot = $baseBodies[self::ROOT];
        $oursRoot = $oursBodies[self::ROOT];
        $theirsRoot = $theirsBodies[self::ROOT];
        $rootKeys = array_values(array_unique(array_merge(array_keys($oursRoot), array_keys($theirsRoot), array_keys($baseRoot))));

        foreach ($rootKeys as $rootKey) {
            if ($rootKey === LayoutPartialOrigin::KEY) {
                continue;
            }

            $b = array_key_exists($rootKey, $baseRoot) ? $baseRoot[$rootKey] : null;
            $o = array_key_exists($rootKey, $oursRoot) ? $oursRoot[$rootKey] : null;
            $t = array_key_exists($rootKey, $theirsRoot) ? $theirsRoot[$rootKey] : null;

            if (isset($forcedRootKeys[$rootKey])) {
                $rootChoice[$rootKey] = $o !== null ? 'o' : 'none';

                continue;
            }

            if ($rootKey === 'data_sources' && $this->itemizable([$b, $o, $t])) {
                $dataSources = $this->mergeDataSources($b ?? [], $o ?? [], $t ?? []);
                $rootChoice[$rootKey] = 'items';

                continue;
            }

            $rootChoice[$rootKey] = $this->decide('key:'.$rootKey, 'key', $b, $o, $t, in_array($rootKey, self::UNCOUNTED_KEYS, true));
        }

        // 4단계 — 고아 방지: 운영자 것으로 남긴 단위가 조립에서 빠지지 않게 그 자리를 품은 몸통을 운영자 판으로.
        $this->preventOrphans($choice, $rootChoice, $oursContainer, $oursPaths, $baseBodies, $oursBodies, $theirsBodies);

        // 새 판에만 있는 단위는 그 자리를 품은 몸통이 새 판을 골랐을 때만 들어간다.
        foreach (array_keys($added) as $key) {
            if ($this->chosenContains($theirsContainer[$key] ?? self::ROOT, $key, $choice, $rootChoice, $oursBodies, $theirsBodies, $theirsUnits[$key]['path'] ?? [])) {
                $this->applied[] = ['unit' => $key, 'kind' => 'partial'];
            } else {
                $this->conflicts[] = ['unit' => $key, 'reason' => 'added_but_parent_kept'];
            }
        }

        // 5단계 — 조립·검증
        $bodies = ['o' => $oursBodies, 't' => $theirsBodies];
        $merged = [];
        foreach ($rootKeys as $rootKey) {
            $pick = $rootChoice[$rootKey] ?? 'none';

            if ($pick === 'items') {
                $merged[$rootKey] = $dataSources;
            } elseif ($pick === 'o') {
                $merged[$rootKey] = $oursRoot[$rootKey];
            } elseif ($pick === 't') {
                $merged[$rootKey] = $theirsRoot[$rootKey];
            }
        }

        $emitted = [];
        $merged = $this->resolve($merged, $choice, $bodies, $emitted, 0);
        $merged = LayoutPartialOrigin::strip($merged);

        if ($this->containsKey($merged, self::UNIT_KEY)) {
            return new LayoutMergeResult($ours, 'kept_invalid', [], $this->conflicts);
        }

        $verdict = $this->applied !== [] ? 'merged' : ($this->conflicts !== [] ? 'conflict' : 'kept');

        return new LayoutMergeResult($merged, $verdict, $this->applied, $this->conflicts);
    }

    /**
     * 판정표 한 행을 적용합니다 (b·o·t = 각 판의 몸통, null = 없음). 반영·충돌 기록을 남기고 고른 판을 돌려줍니다.
     *
     * @param  string  $unit  단위 키 (보고용)
     * @param  string  $kind  단위 종류 (`partial`·`key`·`item`)
     * @param  mixed  $b  원본 몸통
     * @param  mixed  $o  운영자 몸통
     * @param  mixed  $t  새 판 몸통
     * @param  bool  $uncounted  반영해도 개수에 세지 않을지
     * @return string `o`·`t`·`none`
     */
    private function decide(string $unit, string $kind, mixed $b, mixed $o, mixed $t, bool $uncounted = false): string
    {
        $same = fn ($x, $y) => LayoutContentComparator::sameFragment($x, $y);
        $apply = function () use ($unit, $kind, $uncounted) {
            if (! $uncounted) {
                $this->applied[] = ['unit' => $unit, 'kind' => $kind];
            }
        };
        $conflict = function (string $reason) use ($unit) {
            $this->conflicts[] = ['unit' => $unit, 'reason' => $reason];
        };

        if ($b !== null) {
            if ($o !== null && $same($o, $b)) {
                if ($t === null) {           // X | =b | ⊥ → 지움 (applied removed)
                    $apply();

                    return 'none';
                }
                if ($same($t, $b)) {         // X | =b | =b → 변화 없음
                    return 'o';
                }
                $apply();                    // X | =b | Y → 새 판

                return 't';
            }

            if ($o !== null) {
                if ($t !== null && ($same($t, $b) || $same($t, $o))) {   // 운영자 것 / 같은 변경
                    return 'o';
                }
                $conflict($t === null ? 'removed_in_new_version' : 'both_changed');

                return 'o';
            }

            // 운영자가 지움
            if ($t !== null && ! $same($t, $b)) {
                $conflict('removed_by_operator');
            }

            return 'none';
        }

        if ($o === null) {
            if ($t === null) {
                return 'none';
            }
            $apply();                        // ⊥ | ⊥ | Y → 추가

            return 't';
        }

        if ($t !== null && ! $same($o, $t)) {
            $conflict('both_added');         // ⊥ | Z | Y(≠Z) → 운영자 것
        }

        return 'o';                          // ⊥ | Z | ⊥ 또는 같은 추가
    }

    /**
     * 문서에서 조각 단위를 전위 순회로 모읍니다 — 키는 `{부모 단위} > {origin} @ {k}`.
     *
     * @param  array<string, mixed>  $doc  문서 (표식 포함)
     * @return array<string, array{origin: string|null, parent: string|null, path: list<string|int>, relPath: list<string|int>}> 단위 목록 (순서 = 전위)
     */
    private function collectUnits(array $doc): array
    {
        $units = [self::ROOT => ['origin' => null, 'parent' => null, 'path' => [], 'relPath' => []]];
        $counters = [];
        $this->walkUnits($doc, [], self::ROOT, [], $units, $counters);

        return $units;
    }

    /**
     * {@see collectUnits()} 의 재귀 본문.
     *
     * @param  array<string|int, mixed>  $node  현재 노드
     * @param  list<string|int>  $path  문서 루트부터의 경로
     * @param  string  $unit  현재 노드를 품은 단위 키
     * @param  list<string|int>  $unitPath  그 단위의 경로
     * @param  array<string, array<string, mixed>>  $units  모은 단위 (참조)
     * @param  array<string, array<string, int>>  $counters  부모·origin 별 인스턴스 번호 (참조)
     * @return void 반환값 없음
     */
    private function walkUnits(array $node, array $path, string $unit, array $unitPath, array &$units, array &$counters): void
    {
        foreach ($node as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            $childPath = [...$path, $key];

            if (! array_is_list($child) && isset($child[LayoutPartialOrigin::KEY]) && is_string($child[LayoutPartialOrigin::KEY])) {
                $origin = $child[LayoutPartialOrigin::KEY];
                $index = $counters[$unit][$origin] ?? 0;
                $counters[$unit][$origin] = $index + 1;
                $childUnit = "{$unit} > {$origin} @ {$index}";

                $units[$childUnit] = [
                    'origin' => $origin,
                    'parent' => $unit,
                    'path' => $childPath,
                    'relPath' => array_slice($childPath, count($unitPath)),
                ];

                $this->walkUnits($child, $childPath, $childUnit, $childPath, $units, $counters);

                continue;
            }

            $this->walkUnits($child, $childPath, $unit, $unitPath, $units, $counters);
        }
    }

    /**
     * 단위 목록에서 루트를 뺀 경로 표를 만듭니다.
     *
     * @param  array<string, array<string, mixed>>  $units  단위 목록
     * @return array<string, list<string|int>> 단위 키 => 경로
     */
    private function pathsOf(array $units): array
    {
        $paths = [];
        foreach ($units as $key => $unit) {
            if ($key !== self::ROOT) {
                $paths[$key] = $unit['path'];
            }
        }

        return $paths;
    }

    /**
     * 단위 자리를 자리표시로 바꿔 단위별 "몸통" 을 만듭니다 — 깊은 단위부터 도려내 바깥 몸통에 안쪽 자리표시가 남게.
     *
     * @param  array<string, mixed>  $doc  문서
     * @param  array<string, list<string|int>>  $paths  단위 키 => 경로 (루트 제외)
     * @return array<string, mixed> 단위 키 => 몸통 (루트 포함)
     */
    private function carve(array $doc, array $paths): array
    {
        uasort($paths, fn ($a, $b) => count($b) <=> count($a));
        $bodies = [];

        foreach ($paths as $key => $path) {
            $node = $this->get($doc, $path);
            if ($node === self::MISSING) {
                continue;
            }
            $bodies[$key] = $node;
            $doc = $this->set($doc, $path, [self::UNIT_KEY => $key]);
        }

        $bodies[self::ROOT] = $doc;

        return $bodies;
    }

    /**
     * 각 단위의 자리표시를 품은 바깥 단위(가장 가까운 조상 경로)를 구합니다.
     *
     * @param  array<string, list<string|int>>  $paths  단위 키 => 경로 (루트 제외)
     * @return array<string, string> 단위 키 => 바깥 단위 키
     */
    private function containers(array $paths): array
    {
        $containers = [];

        foreach ($paths as $key => $path) {
            $best = self::ROOT;
            $bestLength = -1;

            foreach ($paths as $other => $otherPath) {
                $length = count($otherPath);
                if ($other !== $key && $length < count($path) && $length > $bestLength && array_slice($path, 0, $length) === $otherPath) {
                    $best = $other;
                    $bestLength = $length;
                }
            }

            $containers[$key] = $best;
        }

        return $containers;
    }

    /**
     * 원본의 단위를 운영자 판 위에서 찾습니다 (2단계).
     *
     * 1. 부모 자리 + 상대 경로의 노드가 원본 서브트리와 같으면 "손 안 댐".
     * 2. 아니면 다른 단위가 차지하지 않은 곳에서 원본 서브트리와 같은 노드가 **정확히 1개** 면 "손 안 댐(옮겨짐)".
     * 3. 아니면 같은 자리 노드의 `name`·`id` 가 원본 단위 루트와 같으면 "고침".
     * 4. 아니면 "없음".
     *
     * @param  array<string, mixed>  $ours  운영자 판
     * @param  array<string, mixed>  $base  원본 (표식 포함)
     * @param  array<string, array<string, mixed>>  $units  원본 단위 목록 (전위 순서)
     * @return array{0: array<string, list<string|int>>, 1: array<string, string>} [단위 키 => 운영자 판 경로, 단위 키 => 상태]
     */
    private function project(array $ours, array $base, array $units): array
    {
        $loc = [self::ROOT => []];
        $state = [self::ROOT => 'modified'];
        $signatures = null;

        foreach ($units as $key => $unit) {
            if ($key === self::ROOT) {
                continue;
            }

            $target = LayoutPartialOrigin::strip($this->get($base, $unit['path']));
            $parentLoc = $loc[$unit['parent']] ?? null;
            $found = null;
            $status = 'absent';

            if ($parentLoc !== null) {
                $path = [...$parentLoc, ...$unit['relPath']];
                $node = $this->get($ours, $path);
                if (is_array($node) && ! $this->claimedByOther($path, $key, $loc, $units) && LayoutContentComparator::sameFragment($node, $target)) {
                    $found = $path;
                    $status = 'untouched';
                }
            }

            if ($found === null && is_array($target)) {
                $signatures ??= $this->signatureIndex($ours);
                $candidates = [];
                foreach ($signatures[$this->signature($target)] ?? [] as $path) {
                    if (! $this->claimedByOther($path, $key, $loc, $units) && LayoutContentComparator::sameFragment($this->get($ours, $path), $target)) {
                        $candidates[] = $path;
                    }
                }
                if (count($candidates) === 1) {
                    $found = $candidates[0];
                    $status = 'untouched';
                }
            }

            if ($found === null && $parentLoc !== null && is_array($target)) {
                $path = [...$parentLoc, ...$unit['relPath']];
                $node = $this->get($ours, $path);
                if (is_array($node) && ! array_is_list($node) && ! $this->claimedByOther($path, $key, $loc, $units) && $this->sameIdentity($node, $target)) {
                    $found = $path;
                    $status = 'modified';
                }
            }

            if ($found !== null) {
                $loc[$key] = $found;
            }
            $state[$key] = $status;
        }

        return [$loc, $state];
    }

    /**
     * 경로가 조상이 아닌 다른 단위가 이미 차지한 서브트리 안(또는 그 자리)인지 판정합니다.
     *
     * @param  list<string|int>  $path  후보 경로
     * @param  string  $key  찾는 단위 키
     * @param  array<string, list<string|int>>  $loc  이미 찾은 자리
     * @param  array<string, array<string, mixed>>  $units  단위 목록
     * @return bool 차지됐으면 true
     */
    private function claimedByOther(array $path, string $key, array $loc, array $units): bool
    {
        $ancestors = [];
        for ($cursor = $units[$key]['parent'] ?? null; $cursor !== null; $cursor = $units[$cursor]['parent'] ?? null) {
            $ancestors[$cursor] = true;
        }

        foreach ($loc as $other => $otherPath) {
            if (isset($ancestors[$other]) || $other === $key) {
                continue;
            }
            $length = count($otherPath);
            if ($length <= count($path) && array_slice($path, 0, $length) === $otherPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * 운영자 판의 객체 노드를 `name`·`id` 서명별로 모읍니다 (전역 탐색 후보 줄이기).
     *
     * @param  array<string, mixed>  $doc  운영자 판
     * @return array<string, list<list<string|int>>> 서명 => 경로 목록
     */
    private function signatureIndex(array $doc): array
    {
        $index = [];
        $walk = function (array $node, array $path) use (&$walk, &$index): void {
            foreach ($node as $key => $child) {
                if (! is_array($child)) {
                    continue;
                }
                $childPath = [...$path, $key];
                if (! array_is_list($child)) {
                    $index[$this->signature($child)][] = $childPath;
                }
                $walk($child, $childPath);
            }
        };
        $walk($doc, []);

        return $index;
    }

    /**
     * 노드 서명 — `name`·`id`. 후보를 줄이는 데만 쓰고 같음 판정은 정규화 비교가 한다
     * (편집기 왕복이 키 집합을 바꿀 수 있어 키 목록은 서명에 넣지 않는다).
     *
     * @param  array<string|int, mixed>  $node  노드
     * @return string 서명
     */
    private function signature(array $node): string
    {
        return json_encode([$node['name'] ?? null, $node['id'] ?? null]);
    }

    /**
     * 같은 자리 노드가 원본 단위 루트와 같은 대상인지(`name`·`id`) 판정합니다.
     *
     * @param  array<string, mixed>  $node  운영자 판 노드
     * @param  array<string, mixed>  $target  원본 단위 서브트리
     * @return bool 같은 대상이면 true
     */
    private function sameIdentity(array $node, array $target): bool
    {
        $keys = array_values(array_filter(['name', 'id'], fn ($k) => isset($target[$k])));

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $k) {
            if (($node[$k] ?? null) !== $target[$k]) {
                return false;
            }
        }

        return true;
    }

    /**
     * 같은 부모 안에서 같은 조각의 인스턴스 수가 원본·새 판에서 다를 때를 막습니다.
     *
     * 인스턴스 번호로 짝을 짓기 때문에 수가 바뀌면 짝이 흔들린다. 그 인스턴스 중 하나라도 운영자가 고쳤으면(고침·없음)
     * 그 조각의 인스턴스 전부를 운영자 판으로 두고 부모 몸통도 운영자 판으로 둔다. 아무도 안 고쳤으면 부모 판정에 맡긴다.
     *
     * @param  array<string, array<string, mixed>>  $baseUnits  원본 단위
     * @param  array<string, array<string, mixed>>  $theirsUnits  새 판 단위
     * @param  array<string, string>  $oursState  운영자 판 투영 상태
     * @return array{0: array<string, true>, 1: array<string|int, true>, 2: array<string, true>} [강제 단위, 강제 루트 키, 강제 부모]
     */
    private function instanceCountGuard(array $baseUnits, array $theirsUnits, array $oursState): array
    {
        $group = function (array $units): array {
            $out = [];
            foreach ($units as $key => $unit) {
                if ($key !== self::ROOT) {
                    $out[$unit['parent']][$unit['origin']][] = $key;
                }
            }

            return $out;
        };

        $baseGroups = $group($baseUnits);
        $theirsGroups = $group($theirsUnits);
        $forcedUnits = [];
        $forcedRootKeys = [];
        $forcedParents = [];

        foreach (array_unique(array_merge(array_keys($baseGroups), array_keys($theirsGroups))) as $parent) {
            $origins = array_unique(array_merge(array_keys($baseGroups[$parent] ?? []), array_keys($theirsGroups[$parent] ?? [])));

            foreach ($origins as $origin) {
                $inBase = $baseGroups[$parent][$origin] ?? [];
                $inTheirs = $theirsGroups[$parent][$origin] ?? [];

                // 한쪽이 0개면 짝짓기가 흔들릴 일이 없다 — 추가·삭제는 판정표가 맡는다.
                if (count($inBase) === count($inTheirs) || $inBase === [] || $inTheirs === []) {
                    continue;
                }

                $touched = array_filter($inBase, fn ($k) => ($oursState[$k] ?? 'absent') !== 'untouched');
                if ($touched === []) {
                    continue;
                }

                foreach (array_merge($inBase, $inTheirs) as $k) {
                    $forcedUnits[$k] = true;
                    if ($parent === self::ROOT) {
                        $path = $baseUnits[$k]['path'] ?? $theirsUnits[$k]['path'] ?? [];
                        if ($path !== []) {
                            $forcedRootKeys[$path[0]] = true;
                        }
                    }
                }
                $forcedParents[$parent] = true;
                $this->conflicts[] = ['unit' => "{$parent} > {$origin}", 'reason' => 'instance_count_changed'];
            }
        }

        return [$forcedUnits, $forcedRootKeys, $forcedParents];
    }

    /**
     * 운영자 것(원본과 다름)으로 남긴 단위의 자리표시가 조립 결과에 남도록 바깥 몸통을 운영자 판으로 되돌립니다.
     *
     * @param  array<string, string>  $choice  단위 선택 (참조)
     * @param  array<string|int, string>  $rootChoice  루트 키 선택 (참조)
     * @param  array<string, string>  $oursContainer  운영자 판에서 단위를 품은 바깥 단위
     * @param  array<string, list<string|int>>  $oursPaths  운영자 판 경로
     * @param  array<string, mixed>  $baseBodies  원본 몸통
     * @param  array<string, mixed>  $oursBodies  운영자 몸통
     * @param  array<string, mixed>  $theirsBodies  새 판 몸통
     * @return void 반환값 없음
     */
    private function preventOrphans(array &$choice, array &$rootChoice, array $oursContainer, array $oursPaths, array $baseBodies, array $oursBodies, array $theirsBodies): void
    {
        for ($round = 0; $round < 50; $round++) {
            $changed = false;

            foreach ($choice as $key => $pick) {
                if ($pick !== 'o' || ! isset($oursPaths[$key])) {
                    continue;
                }
                $keptOperatorChange = ! LayoutContentComparator::sameFragment($oursBodies[$key] ?? null, $baseBodies[$key] ?? null);
                if (! $keptOperatorChange) {
                    continue;
                }

                $container = $oursContainer[$key] ?? self::ROOT;
                if ($this->chosenContains($container, $key, $choice, $rootChoice, $oursBodies, $theirsBodies, $oursPaths[$key])) {
                    continue;
                }

                if ($container === self::ROOT) {
                    $rootKey = $oursPaths[$key][0];
                    $rootChoice[$rootKey] = 'o';
                    $this->conflicts[] = ['unit' => 'key:'.$rootKey, 'reason' => 'child_kept_parent_changed'];
                } else {
                    $choice[$container] = 'o';
                    $this->conflicts[] = ['unit' => $container, 'reason' => 'child_kept_parent_changed'];
                }
                $this->dropApplied($container === self::ROOT ? 'key:'.$oursPaths[$key][0] : $container);
                $changed = true;
            }

            if (! $changed) {
                return;
            }
        }
    }

    /**
     * 바깥 몸통이 고른 판에 단위 자리표시가 들어 있는지 판정합니다.
     *
     * @param  string  $container  바깥 단위 키 (루트면 `root`)
     * @param  string  $unit  단위 키
     * @param  array<string, string>  $choice  단위 선택
     * @param  array<string|int, string>  $rootChoice  루트 키 선택
     * @param  array<string, mixed>  $oursBodies  운영자 몸통
     * @param  array<string, mixed>  $theirsBodies  새 판 몸통
     * @param  list<string|int>  $path  단위 경로 (루트 키를 고를 때 첫 세그먼트를 쓴다)
     * @return bool 들어 있으면 true
     */
    private function chosenContains(string $container, string $unit, array $choice, array $rootChoice, array $oursBodies, array $theirsBodies, array $path): bool
    {
        if ($container === self::ROOT) {
            $rootKey = $path[0] ?? null;
            $pick = $rootKey === null ? 'none' : ($rootChoice[$rootKey] ?? 'none');
            $body = match ($pick) {
                'o' => $oursBodies[self::ROOT][$rootKey] ?? null,
                't' => $theirsBodies[self::ROOT][$rootKey] ?? null,
                'items' => [$oursBodies[self::ROOT][$rootKey] ?? null, $theirsBodies[self::ROOT][$rootKey] ?? null],
                default => null,
            };
        } else {
            $pick = $choice[$container] ?? 'none';
            $body = match ($pick) {
                'o' => $oursBodies[$container] ?? null,
                't' => $theirsBodies[$container] ?? null,
                default => null,
            };
        }

        return $body !== null && $this->containsPlaceholder($body, $unit);
    }

    /**
     * 반영 목록에서 한 단위를 뺍니다 (고아 방지로 운영자 판으로 되돌린 몸통).
     *
     * @param  string  $unit  단위 키
     * @return void 반환값 없음
     */
    private function dropApplied(string $unit): void
    {
        $this->applied = array_values(array_filter($this->applied, fn ($entry) => $entry['unit'] !== $unit));
    }

    /**
     * `data_sources` 항목이 전부 `id` 를 가져 항목 단위로 병합할 수 있는지 판정합니다.
     *
     * @param  array<int, mixed>  $values  세 판의 값 (없으면 null)
     * @return bool 항목 단위 병합이 가능하면 true
     */
    private function itemizable(array $values): bool
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (! is_array($value) || ! array_is_list($value)) {
                return false;
            }
            foreach ($value as $item) {
                if (! is_array($item) || ! isset($item['id']) || ! is_scalar($item['id'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * `data_sources` 를 항목 `id` 단위로 병합합니다 — 순서는 새 판 순서, 그 뒤에 운영자에게만 있는 항목을 운영자 순서로.
     *
     * @param  list<array<string, mixed>>  $base  원본 항목
     * @param  list<array<string, mixed>>  $ours  운영자 항목
     * @param  list<array<string, mixed>>  $theirs  새 판 항목
     * @return list<array<string, mixed>> 병합된 항목
     */
    private function mergeDataSources(array $base, array $ours, array $theirs): array
    {
        $byId = fn (array $items) => array_column(array_map(fn ($i) => ['k' => (string) $i['id'], 'v' => $i], $items), 'v', 'k');
        $b = $byId($base);
        $o = $byId($ours);
        $t = $byId($theirs);

        $picked = [];
        foreach (array_unique(array_merge(array_keys($b), array_keys($o), array_keys($t))) as $id) {
            $id = (string) $id;
            $pick = $this->decide('item:data_sources/'.$id, 'item', $b[$id] ?? null, $o[$id] ?? null, $t[$id] ?? null);
            if ($pick === 'o') {
                $picked[$id] = $o[$id];
            } elseif ($pick === 't') {
                $picked[$id] = $t[$id];
            }
        }

        $result = [];
        foreach (array_keys($t) as $id) {
            if (isset($picked[(string) $id])) {
                $result[] = $picked[(string) $id];
                unset($picked[(string) $id]);
            }
        }
        foreach (array_keys($o) as $id) {
            if (isset($picked[(string) $id])) {
                $result[] = $picked[(string) $id];
                unset($picked[(string) $id]);
            }
        }

        return $result;
    }

    /**
     * 자리표시를 고른 몸통으로 채웁니다 (5단계). 없음(⊥)으로 정해진 단위는 목록에서 빼고 객체에서는 키를 지웁니다.
     *
     * @param  mixed  $value  조립할 값
     * @param  array<string, string>  $choice  단위 선택
     * @param  array{o: array<string, mixed>, t: array<string, mixed>}  $bodies  판별 몸통
     * @param  array<string, true>  $emitted  이미 채운 단위 (한 단위가 두 번 들어가지 않게 — 참조)
     * @param  int  $depth  재귀 깊이
     * @return mixed 채운 값
     */
    private function resolve(mixed $value, array $choice, array $bodies, array &$emitted, int $depth): mixed
    {
        if (! is_array($value) || $depth > 200) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $key => $child) {
            if (is_array($child) && count($child) === 1 && isset($child[self::UNIT_KEY]) && is_string($child[self::UNIT_KEY])) {
                $unit = $child[self::UNIT_KEY];
                $pick = $choice[$unit] ?? 'none';

                if ($pick === 'none' || isset($emitted[$unit]) || ! isset($bodies[$pick][$unit])) {
                    continue;
                }

                $emitted[$unit] = true;
                $child = $bodies[$pick][$unit];
            }

            $resolved = $this->resolve($child, $choice, $bodies, $emitted, $depth + 1);

            if ($isList) {
                $out[] = $resolved;
            } else {
                $out[$key] = $resolved;
            }
        }

        return $out;
    }

    /**
     * 값 안에 특정 단위의 자리표시가 있는지 판정합니다.
     *
     * @param  mixed  $value  값
     * @param  string  $unit  단위 키
     * @return bool 있으면 true
     */
    private function containsPlaceholder(mixed $value, string $unit): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if (($value[self::UNIT_KEY] ?? null) === $unit) {
            return true;
        }

        foreach ($value as $child) {
            if (is_array($child) && $this->containsPlaceholder($child, $unit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 값 안 어딘가에 키가 남았는지 판정합니다.
     *
     * @param  mixed  $value  값
     * @param  string  $key  키
     * @return bool 남았으면 true
     */
    private function containsKey(mixed $value, string $key): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if (array_key_exists($key, $value)) {
            return true;
        }

        foreach ($value as $child) {
            if (is_array($child) && $this->containsKey($child, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 경로의 값을 읽습니다.
     *
     * @param  mixed  $doc  문서
     * @param  list<string|int>  $path  경로
     * @return mixed 값, 없으면 {@see MISSING}
     */
    private function get(mixed $doc, array $path): mixed
    {
        foreach ($path as $segment) {
            if (! is_array($doc) || ! array_key_exists($segment, $doc)) {
                return self::MISSING;
            }
            $doc = $doc[$segment];
        }

        return $doc;
    }

    /**
     * 경로에 값을 씁니다 (사본을 돌려줍니다).
     *
     * @param  array<string|int, mixed>  $doc  문서
     * @param  list<string|int>  $path  경로 (비어 있으면 안 된다)
     * @param  mixed  $value  값
     * @return array<string|int, mixed> 새 문서
     */
    private function set(array $doc, array $path, mixed $value): array
    {
        $segment = array_shift($path);

        if ($path === []) {
            $doc[$segment] = $value;

            return $doc;
        }

        $doc[$segment] = $this->set(is_array($doc[$segment] ?? null) ? $doc[$segment] : [], $path, $value);

        return $doc;
    }
}
