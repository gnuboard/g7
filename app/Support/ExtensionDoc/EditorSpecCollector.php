<?php

namespace App\Support\ExtensionDoc;

use App\Extension\Helpers\EditorSpecAssembler;

/**
 * 확장 편집기 스펙 수집기
 *
 * 확장이 `editor-spec.json`(+ 분할 `editor-spec/*.json`)으로 레이아웃 편집기에 선언한
 * 표면을 실측합니다. 팔레트 항목·스타일 컨트롤·중첩 규칙·샘플 데이터·레시피가 각각 몇
 * 개이고 어떤 ID 를 갖는지가 산출물입니다.
 *
 * 이 축이 문서에 없으면 확장에 새 화면 요소를 추가해도 편집기 팔레트에 나타나지 않는
 * 상태가 오류도 경고도 없이 남습니다 — 편집기는 선언되지 않은 컴포넌트를 "없는 것" 으로
 * 다룰 뿐 실패를 보고하지 않기 때문입니다. 스펙을 **갖지 않은** 확장도 그 사실 자체를
 * 산출물로 돌려줍니다(`present => false`). 미보유는 정상 상태일 수 있고, 문서는 그
 * 정상 여부를 서술할 자리를 가져야 합니다.
 *
 * 합본은 런타임 서빙과 같은 경로(`EditorSpecAssembler`)를 씁니다. 수집기가 별도 병합
 * 규칙을 갖게 되면 문서가 말하는 스펙과 편집기가 읽는 스펙이 갈라집니다.
 */
class EditorSpecCollector
{
    /**
     * 블록별 **항목이 실제로 담긴 자리**.
     *
     * 블록 최상위 키를 그대로 세면 안 됩니다 — 블록들은 자기 항목을 `entries` / `groups` /
     * `byDataSourceId` 같은 하위 자리에 담고, 최상위에는 `comment` 같은 메타 키를 함께
     * 둡니다. 최상위를 세면 팔레트 79개가 3(comment·groups·entries)으로 집계되는데, 그
     * 숫자는 오류 없이 문서에 실려 "이 확장은 팔레트 항목이 3개" 라는 사실 주장이 됩니다.
     *
     * 값이 빈 배열인 블록은 최상위(메타 키 제외)가 곧 항목입니다.
     *
     * 선언 순서가 곧 문서 표의 행 순서입니다.
     *
     * @var array<string, array<int, string>> 블록 키 → 항목이 담긴 하위 키 목록
     */
    private const ITEM_PATHS = [
        'componentPalette' => ['entries', 'groups'],
        'controls' => [],
        'componentCapabilities' => [],
        'nesting' => ['draggable', 'containers'],
        'sampleData' => ['byDataSourceId', 'byEndpointPattern'],
        'sampleGlobal' => [],
        'states' => ['groups'],
        'stateLabels' => [],
        'actionRecipes' => [],
        'conditionRecipes' => ['operators'],
        'computedRecipes' => [],
        'errorRecipes' => [],
        'loadingComponents' => [],
        'actionChipCandidates' => [],
    ];

    /**
     * 항목이 아니라 설명인 키 — 개수에서 제외한다.
     *
     * **접두 규칙으로 넓히지 않는다.** `_` 로 시작하는 키를 일괄 배제하면 실제 항목까지
     * 삼킨다 — `sampleGlobal._local` 이 그 예이고, 그렇게 빠진 항목은 오류 없이 문서에
     * "1개 적은 수" 로 실린다. 설명 키는 실측으로 확인된 것만 이름으로 열거한다.
     *
     * @var array<int, string> 설명 키 목록
     */
    private const META_KEYS = ['comment', '$comment', '$schema', '_propControlsComment'];

    /**
     * @var array<int, string>|null 번들 템플릿이 커버하는 샘플 ID (프로세스 단위 메모)
     */
    private static ?array $fallbackSampleIds = null;

    /**
     * 확장의 편집기 스펙 표면을 수집합니다.
     *
     * @param  array<string, mixed>  $record  ExtensionInventory 레코드
     * @param  array<int, string>  $layoutRelFiles  이 확장의 레이아웃 파일(확장 루트 기준 상대 경로)
     * @return array<string, mixed> 편집기 스펙 인벤토리
     */
    public function collect(array $record, array $layoutRelFiles = []): array
    {
        $manifestPath = $record['path'].DIRECTORY_SEPARATOR.'editor-spec.json';

        if (! is_file($manifestPath)) {
            return $this->absent($record, $layoutRelFiles);
        }

        $spec = EditorSpecAssembler::assemble($manifestPath);

        if ($spec === null) {
            // manifest 가 있는데 디코드에 실패한 상태다. "스펙 없음" 과 구분해 보고한다 —
            // 뭉뚱그리면 깨진 JSON 이 "이 확장은 편집기 스펙을 두지 않는다" 로 읽힌다.
            return $this->absent($record, $layoutRelFiles, malformed: true);
        }

        $includes = $this->includeMap($manifestPath);

        return [
            'present' => true,
            'malformed' => false,
            'manifest' => $record['relPath'].'/editor-spec.json',
            'split' => $includes !== [],
            'includes' => $includes,
            'version' => $this->stringOrNull($spec['version'] ?? null),
            'description' => $this->stringOrNull($spec['description'] ?? null),
            'styleSystem' => $this->stringOrNull($spec['styleSystem'] ?? null),
            'darkMode' => $this->stringOrNull(($spec['darkMode']['strategy'] ?? null)),
            'blocks' => $this->blockSummaries($spec, $includes),
            'paletteGroups' => $this->paletteGroups($spec['componentPalette'] ?? null),
            'sampleDataIds' => $this->idsAt($spec['sampleData'] ?? null, 'byDataSourceId'),
            'sampleEndpointPatterns' => $this->idsAt($spec['sampleData'] ?? null, 'byEndpointPattern'),
            'stateScopes' => $this->idsAt($spec['states'] ?? null, 'groups'),
            'uncovered' => $this->uncoveredDataSources($record, $layoutRelFiles, $spec),
            'declaredPaths' => [
                'sampleData.byDataSourceId' => $this->hasPath($spec['sampleData'] ?? null, 'byDataSourceId'),
                'sampleData.byEndpointPattern' => $this->hasPath($spec['sampleData'] ?? null, 'byEndpointPattern'),
                'states.groups' => $this->hasPath($spec['states'] ?? null, 'groups'),
            ],
        ];
    }

    /**
     * 스펙 미보유(또는 손상) 상태의 산출물을 만듭니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  array<int, string>  $layoutRelFiles  레이아웃 파일 목록
     * @param  bool  $malformed  manifest 는 있으나 디코드에 실패했는지 여부
     * @return array<string, mixed> 인벤토리
     */
    private function absent(array $record, array $layoutRelFiles, bool $malformed = false): array
    {
        return [
            'present' => false,
            'malformed' => $malformed,
            'manifest' => null,
            'split' => false,
            'includes' => [],
            'version' => null,
            'description' => null,
            'styleSystem' => null,
            'darkMode' => null,
            'blocks' => [],
            'paletteGroups' => [],
            'sampleDataIds' => [],
            'sampleEndpointPatterns' => [],
            'stateScopes' => [],
            'uncovered' => $this->uncoveredDataSources($record, $layoutRelFiles, []),
            'declaredPaths' => [],
        ];
    }

    /**
     * manifest 의 `$include` 맵을 원본 그대로 읽습니다.
     *
     * 합본 결과에는 `$include` 가 남지 않으므로(assemble 이 벗겨낸다) 분할 여부와 블록
     * 파일 경로는 manifest 를 다시 읽어야 알 수 있습니다.
     *
     * @param  string  $manifestPath  manifest 절대 경로
     * @return array<string, string> 블록 키 → 상대 경로
     */
    private function includeMap(string $manifestPath): array
    {
        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($decoded) || ! is_array($decoded['$include'] ?? null)) {
            return [];
        }

        $map = [];

        foreach ($decoded['$include'] as $key => $relative) {
            if (is_string($key) && is_string($relative) && $relative !== '') {
                $map[$key] = $relative;
            }
        }

        return $map;
    }

    /**
     * 블록별 요약(항목 수·출처)을 만듭니다.
     *
     * 항목이 여러 하위 자리에 나뉜 블록(`nesting`, `sampleData`)은 자리마다 한 행을
     * 냅니다. 합산하면 "끌 수 있는 컴포넌트 84 + 담을 수 있는 컨테이너 19 = 103" 처럼
     * 뜻이 없는 수가 되고, 그 수는 표에서 사실처럼 읽힙니다.
     *
     * @param  array<string, mixed>  $spec  합본 spec
     * @param  array<string, string>  $includes  블록 키 → 분할 파일 상대 경로
     * @return array<int, array{key: string, count: int|null, source: string}> 블록 요약
     */
    private function blockSummaries(array $spec, array $includes): array
    {
        $summaries = [];

        foreach (self::ITEM_PATHS as $key => $paths) {
            if (! array_key_exists($key, $spec)) {
                continue;
            }

            $source = $includes[$key] ?? 'editor-spec.json (인라인)';
            $block = $spec[$key];

            if ($paths === []) {
                $summaries[] = [
                    'key' => $key,
                    'count' => $this->countItems($block),
                    'source' => $source,
                ];

                continue;
            }

            // 선언된 하위 자리 중 **실제로 있는 것만** 행으로 낸다. 없는 자리를 0 으로
            // 내보내면 "선언했는데 비었다" 와 "그 형태를 쓰지 않는다" 가 같은 모양이 된다.
            foreach ($paths as $path) {
                if (! is_array($block) || ! array_key_exists($path, $block)) {
                    continue;
                }

                $summaries[] = [
                    'key' => $key.'.'.$path,
                    'count' => $this->countItems($block[$path]),
                    'source' => $source,
                ];
            }
        }

        return $summaries;
    }

    /**
     * 값의 항목 수를 셉니다. 맵이면 메타 키를 뺀 나머지, 리스트면 길이입니다.
     *
     * @param  mixed  $value  블록 또는 하위 자리의 값
     * @return int|null 항목 수 (개수 개념이 없으면 null)
     */
    private function countItems(mixed $value): ?int
    {
        if (! is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            return count($value);
        }

        return count($this->withoutMeta($value));
    }

    /**
     * 맵에서 메타 키를 제거합니다.
     *
     * @param  array<string, mixed>  $map  대상 맵
     * @return array<string, mixed> 메타 키를 뺀 맵
     */
    private function withoutMeta(array $map): array
    {
        return array_filter(
            $map,
            static fn ($k): bool => ! in_array((string) $k, self::META_KEYS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * 팔레트 그룹별 컴포넌트 수를 셉니다.
     *
     * 팔레트는 `{ groups: [{ label, kind, components[] }], entries: { … } }` 형태입니다.
     * `groups` 가 편집기 좌측 목록의 묶음이고, 각 묶음이 담는 컴포넌트 이름이 `components`
     * 입니다.
     *
     * @param  mixed  $palette  componentPalette 블록
     * @return array<int, array{group: string, kind: string, count: int}> 그룹 요약
     */
    private function paletteGroups(mixed $palette): array
    {
        if (! is_array($palette) || ! is_array($palette['groups'] ?? null)) {
            return [];
        }

        $out = [];

        foreach ($palette['groups'] as $group) {
            if (! is_array($group)) {
                continue;
            }

            $out[] = [
                'group' => $this->resolveLabel($this->stringOrNull($group['label'] ?? null) ?? '(이름 없음)'),
                'kind' => $this->stringOrNull($group['kind'] ?? null) ?? '-',
                'count' => is_array($group['components'] ?? null) ? count($group['components']) : 0,
            ];
        }

        return $out;
    }

    /**
     * 블록의 하위 자리에서 항목 ID 목록을 뽑습니다.
     *
     * 맵이면 키가 ID 이고, 리스트면 각 항목의 `id`(없으면 `scope.match`)가 ID 입니다.
     * 페이지 상태는 리스트 + `scope` 형태라 키가 없습니다.
     *
     * @param  mixed  $block  블록 값
     * @param  string  $path  항목이 담긴 하위 키
     * @return array<int, string> ID 목록
     */
    private function idsAt(mixed $block, string $path): array
    {
        if (! is_array($block) || ! is_array($block[$path] ?? null)) {
            return [];
        }

        $items = $block[$path];

        if (! array_is_list($items)) {
            return array_values(array_map(
                static fn ($k): string => (string) $k,
                array_keys($this->withoutMeta($items)),
            ));
        }

        $ids = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $this->stringOrNull($item['id'] ?? null)
                ?? $this->stringOrNull($item['scope']['match'] ?? null)
                ?? $this->stringOrNull($item['label'] ?? null);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * 이 확장 레이아웃의 `data_source` 중 **프리뷰 샘플이 붙지 않는 것**을 찾습니다.
     *
     * 편집기 캔버스는 실제 API 를 부르지 않고 `sampleData` 로 화면을 그립니다. 그래서
     * 레이아웃에 `data_source` 를 추가하고 샘플을 붙이지 않으면 그 영역만 편집기에서
     * 빈 화면이 되는데, 실제 화면은 정상 동작하므로 어긋남이 드러나지 않습니다. 오류도
     * 경고도 남지 않아 문서의 이 목록이 유일한 통로입니다.
     *
     * 커버 판정에는 확장 자신의 스펙뿐 아니라 **번들 템플릿의 스펙**도 넣습니다 —
     * `settings` 처럼 여러 확장이 함께 쓰는 공용 ID 는 템플릿 스펙이 대신 채우도록 설계된
     * 것이라, 그것까지 미커버로 세면 목록이 잡음으로 가득 차 정작 볼 것이 묻힙니다.
     *
     * 번들 템플릿은 출하 기본값입니다. 운영자가 다른 템플릿을 쓰면 커버 집합이 달라질 수
     * 있으므로, 이 목록은 "반드시 빈다" 가 아니라 "기본 구성에서 빈다" 로 읽습니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  array<int, string>  $layoutRelFiles  레이아웃 파일(확장 루트 기준 상대 경로)
     * @param  array<string, mixed>  $spec  이 확장의 합본 spec (없으면 빈 배열)
     * @return array<int, string> 샘플이 없는 data_source ID 목록
     */
    private function uncoveredDataSources(array $record, array $layoutRelFiles, array $spec): array
    {
        if ($layoutRelFiles === []) {
            return [];
        }

        $covered = array_flip(array_merge($this->sampleIdsOf($spec), $this->fallbackSampleIds()));
        $uncovered = [];

        foreach ($layoutRelFiles as $rel) {
            $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $layout = $this->decodeJson($abs);

            if ($layout === null) {
                continue;
            }

            foreach ($this->layoutDataSourceIds($layout) as $id) {
                if (! isset($covered[$id])) {
                    $uncovered[$id] = true;
                }
            }
        }

        $ids = array_keys($uncovered);
        sort($ids);

        return $ids;
    }

    /**
     * spec 의 `sampleData.byDataSourceId` 키 목록을 뽑습니다.
     *
     * @param  array<string, mixed>  $spec  합본 spec
     * @return array<int, string> ID 목록
     */
    private function sampleIdsOf(array $spec): array
    {
        return $this->idsAt($spec['sampleData'] ?? null, 'byDataSourceId');
    }

    /**
     * 번들 템플릿 스펙이 채우는 샘플 ID 집합을 돌려줍니다.
     *
     * 결과를 프로세스 단위로 기억합니다. 확장 20개를 도는 동안 매번 다시 합본하면 템플릿
     * 스펙(팔레트·컨트롤·역량을 담아 수만 줄에 이른다)을 스무 번 메모리에 올리게 되고,
     * 메모리 한도가 낮은 실행 환경(테스트 프로세스)에서는 그대로 OOM 이 됩니다. 남기는
     * 것은 스펙 전체가 아니라 **ID 문자열 목록**이라 유지 비용도 작습니다.
     *
     * @return array<int, string> 번들 템플릿이 커버하는 data_source ID 목록
     */
    private function fallbackSampleIds(): array
    {
        if (self::$fallbackSampleIds !== null) {
            return self::$fallbackSampleIds;
        }

        $glob = glob(base_path('templates'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'editor-spec.json'));
        $ids = [];

        foreach ($glob === false ? [] : $glob as $manifest) {
            $assembled = EditorSpecAssembler::assemble($manifest);

            if (is_array($assembled)) {
                $ids = array_merge($ids, $this->sampleIdsOf($assembled));
            }

            // 합본 결과를 즉시 놓아준다 — 다음 manifest 를 읽기 전에 회수되게 한다.
            unset($assembled);
        }

        return self::$fallbackSampleIds = array_values(array_unique($ids));
    }

    /**
     * 레이아웃 JSON 트리에서 `data_sources[].id` 를 전부 긁습니다.
     *
     * `data_sources` 는 최상위뿐 아니라 컴포넌트 노드에도 붙을 수 있어 트리 전체를 봅니다.
     *
     * @param  array<string, mixed>  $node  레이아웃 노드
     * @return array<int, string> data_source ID 목록
     */
    private function layoutDataSourceIds(array $node): array
    {
        $ids = [];

        if (is_array($node['data_sources'] ?? null)) {
            foreach ($node['data_sources'] as $ds) {
                $id = is_array($ds) ? $this->stringOrNull($ds['id'] ?? null) : null;

                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $ids = array_merge($ids, $this->layoutDataSourceIds($value));
            }
        }

        return $ids;
    }

    /**
     * `$t:` 접두 다국어 키를 코어 한국어 문자열로 풉니다.
     *
     * 편집기 스펙의 라벨은 화면에 그대로 나오지 않고 프론트 i18n 을 거칩니다. 문서에 키
     * 원문(`$t:layout_editor.palette.group.design`)을 그대로 실으면 읽는 쪽이 그 묶음이
     * 무엇인지 알 수 없습니다.
     *
     * Laravel 의 `__()` 로는 풀리지 않습니다 — 이 키들은 PHP lang 파일이 아니라 프론트
     * 다국어 JSON(`lang/ko.json` + `$partial` 분할)에 있고, JSON 번역기는 문자열 전체를
     * 키로 쓰기 때문입니다. 그래서 `$partial` 한 홉을 직접 따라갑니다.
     *
     * 풀리지 않으면 **원문을 그대로 둡니다.** 없는 번역을 지어내는 것보다 키가 드러나는
     * 편이 어디를 고쳐야 하는지 알려 주고, 이 해석이 어긋나도 문서가 틀린 사실을 주장하는
     * 대신 키만 남습니다.
     *
     * @param  string  $label  라벨 원문
     * @return string 해석된 라벨 (실패 시 원문)
     */
    private function resolveLabel(string $label): string
    {
        if (! str_starts_with($label, '$t:')) {
            return $label;
        }

        $root = base_path('lang');
        $node = $this->langRoot($root);

        if ($node === null) {
            return $label;
        }

        foreach (explode('.', substr($label, 3)) as $segment) {
            if (is_array($node) && isset($node['$partial']) && is_string($node['$partial'])) {
                $node = $this->decodeJson($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $node['$partial']));
            }

            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return $label;
            }

            $node = $node[$segment];
        }

        return is_string($node) && $node !== '' ? $node : $label;
    }

    /**
     * 코어 프론트 다국어 루트(`lang/ko.json`)를 읽습니다.
     *
     * @param  string  $root  `lang` 디렉토리 절대 경로
     * @return array<string, mixed>|null 디코드 결과
     */
    private function langRoot(string $root): ?array
    {
        return $this->decodeJson($root.DIRECTORY_SEPARATOR.'ko.json');
    }

    /**
     * JSON 파일을 배열로 읽습니다.
     *
     * @param  string  $path  절대 경로
     * @return array<string, mixed>|null 디코드 결과 (실패 시 null)
     */
    private function decodeJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 블록이 그 하위 자리를 **선언했는지** 봅니다.
     *
     * 선언하고 비운 것(`0`)과 아예 선언하지 않은 것은 다릅니다 — 전자는 채울 자리가
     * 있다는 뜻이고 후자는 그 형태를 쓰지 않는다는 뜻입니다. 뭉뚱그려 `0` 으로 적으면
     * 읽는 쪽이 "채워야 할 자리를 비워 뒀다" 로 오해합니다.
     *
     * @param  mixed  $block  블록 값
     * @param  string  $path  하위 키
     * @return bool 선언 여부
     */
    private function hasPath(mixed $block, string $path): bool
    {
        return is_array($block) && array_key_exists($path, $block);
    }

    /**
     * 비어 있지 않은 문자열만 통과시킵니다.
     *
     * @param  mixed  $value  후보 값
     * @return string|null 문자열 또는 null
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
