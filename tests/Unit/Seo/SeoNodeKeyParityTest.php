<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\PipeRegistry;
use App\Thumbnails\ThumbnailPresetRegistry;
use Tests\TestCase;

/**
 * SEO(봇) 렌더러가 해석하지 못하는 노드 키가 봇 대상 화면에 유입되는지 감시합니다.
 *
 * 배경 (이슈 공개 #86):
 * 같은 레이아웃 JSON 을 React 렌더러와 SEO PHP 렌더러가 각각 그린다. React 에만 새 노드
 * 키 처리를 추가하면 봇 화면에서는 그 부분이 조용히 사라지는데, 실제로 확장 주입 props
 * (`extensionPointProps`) 가 그렇게 누락되어 게시글·댓글·페이지·상품 설명 본문이
 * 통째로 빈 요소로 나갔다.
 *
 * 이 테스트는 봇 대상 자산에서 사용되는 노드 최상위 키를 모두 모아, 아래 두 목록의
 * 합집합에 속하는지 확인한다. 새 키가 등장하면 실패하므로 "SEO 렌더러가 이 키를
 * 알아야 하는가" 를 반드시 판단하게 된다.
 *
 * 목록을 갱신할 때는 docs/backend/seo-system.md 의 지원 노드 키 표도 함께 갱신한다.
 *
 * @see ComponentHtmlMapper
 */
class SeoNodeKeyParityTest extends TestCase
{
    /**
     * SEO 렌더러가 해석하는 노드 키.
     */
    private const SUPPORTED_KEYS = [
        'name',
        'type',
        'props',
        'children',
        'text',
        'if',
        'condition',
        'conditions',
        'iteration',
        'classMap',
        'responsive',
        'actions',
        'extensionPointProps',
        // type: "iterator" 형태의 반복 정의
        'data',
        'itemName',
        'indexName',
    ];

    /**
     * SEO 렌더러가 의도적으로 무시하는 노드 키.
     *
     * 인터랙션·편집기·빌드 메타 전용이라 봇 화면 콘텐츠에 영향을 주지 않는다.
     */
    private const INTENTIONALLY_IGNORED_KEYS = [
        'id',
        'key',
        'comment',
        // `_comment` 는 접두사 계열이다 — 저장소 관례상 `_comment_id`·`_comment_hidden` 등
        // 문맥 이름을 붙인 변형이 통용된다(전수 8종). 정확일치로만 등록하면 새 변형마다
        // 이 테스트가 red 가 되므로 isCommentKey() 가 접두사로 판정한다.
        '_comment',
        // 설치 시점에 인라인 확장되므로 SEO 렌더 시에는 남지 않는다
        'partial',
        // 레이아웃 병합 단계에서 처리된다
        'slot',
        'slotOrder',
        // extension_point 호스트의 기본 children — LayoutExtensionService 가 렌더러 진입 전에
        // 주입 컴포넌트로 교체하거나(mode=replace) 그대로 펼쳐 두므로 SEO 렌더러에는 남지 않는다
        'default',
        // 인터랙션 전용
        'lifecycle',
        'onComponentEvent',
        'dataKey',
        'trackChanges',
        'debounce',
        'blur_until_loaded',
        'sortable',
        'itemTemplate',
        'expandChildren',
        'component_layout',
        'isolatedState',
        'isolatedScopeId',
        'extensionPointCallbacks',
        // extension_point 호스트가 선언하는 콜백 원본 — LayoutExtensionService 가 이를 읽어
        // 주입 컴포넌트에 extensionPointCallbacks 로 부착한다. SEO 에는 액션 디스패처가 없다
        'callbacks',
        // 노드 최상위에 잘못 놓인 컴포넌트 prop (props 안에 있어야 정상).
        // React 도 props 객체만 읽으므로(DynamicRenderer.tsx:2235) 양쪽 모두 무시 — 패리티 유지.
        // 렌더링에 관여하지 않는 잔여 키이며, props 로 옮기면 아이콘 크기가 실제로 바뀌므로
        // SEO 패리티와 분리해 판단할 사항이다
        'size',
        // 표현 전용 / 편집기 메타
        'style',
        '__source',
        '_fromBase',
    ];

    /**
     * 봇 대상 자산에서 사용되는 노드 키가 모두 판정된 목록 안에 있습니다.
     *
     * @effects unclassified_node_key_is_detected
     *
     * @return void 반환값 없음
     */
    public function test_all_node_keys_in_bot_facing_assets_are_classified(): void
    {
        $known = array_merge(self::SUPPORTED_KEYS, self::INTENTIONALLY_IGNORED_KEYS);

        $unknown = [];
        foreach ($this->botFacingLayoutFiles() as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->collectNodeKeys($decoded) as $key) {
                // `_comment` 로 시작하는 키는 전부 저자 주석이다 — 접두사 판정은 isCommentKey() 가 한다.
                if (! in_array($key, $known, true) && ! $this->isCommentKey($key)) {
                    $unknown[$key][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertSame([], $unknown, sprintf(
            "SEO 렌더러 판정 목록에 없는 노드 키가 봇 대상 화면에 있습니다: %s\n".
            '→ SEO 렌더러가 이 키를 해석해야 하는지 판단한 뒤, SeoNodeKeyParityTest 의 목록과 '.
            'docs/backend/seo-system.md 의 지원 노드 키 표를 함께 갱신하세요.',
            json_encode(array_map(fn ($files) => array_slice(array_unique($files), 0, 3), $unknown), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
    }

    /**
     * `_comment` 접두사 주석 키인지 판정합니다.
     *
     * 저장소 관례상 주석 키는 `_comment` 단독 외에 `_comment_id` 처럼 설명 대상을
     * 접미사로 붙인 변형이 통용된다. React(DynamicRenderer)와 SEO(ComponentHtmlMapper)
     * 모두 알 수 없는 최상위 키를 무시하므로 렌더 패리티에 영향이 없다.
     *
     * @param  string  $key  노드 최상위 키
     * @return bool 주석 키 여부
     */
    private function isCommentKey(string $key): bool
    {
        return str_starts_with($key, '_comment');
    }

    /**
     * 문서 루트가 곧 노드인 partial 파일도 실제로 스캔됩니다.
     *
     * 수집 진입점이 `components`/`slots` 뿐이면 봇 대상 자산의 대부분(partial)이 통째로
     * 스킵되어 위 분류 단언이 항상 통과한다 — 이슈 공개 #86 이 터진 `_reply_section.json` 이
     * 바로 그 형태이므로, 감시망이 비어 있는 채로 초록이 되는 상황을 여기서 차단한다.
     *
     * @effects node_rooted_partials_are_scanned
     *
     * @return void 반환값 없음
     */
    public function test_scan_covers_node_rooted_partial_documents(): void
    {
        $file = base_path('templates/_bundled/sirsoft-basic/layouts/partials/board/show/_reply_section.json');
        $this->assertFileExists($file);

        $document = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($document);
        $this->assertArrayNotHasKey('components', $document, '전제 변경: 이 파일이 더 이상 노드 루트 형태가 아닙니다.');

        $keys = $this->collectNodeKeys($document);

        $this->assertNotEmpty($keys, '노드 루트 partial 에서 키가 수집되지 않았습니다 — 수집 진입점 누락.');
        $this->assertContains('children', $keys);
        $this->assertNotContains('meta', $keys, '문서 메타 키가 노드 키로 잘못 수집되었습니다.');

        // 자손까지 내려갔는지 (루트 키만 모으고 끝나면 안 된다)
        $this->assertContains('iteration', $keys, '자손 노드까지 재귀하지 않았습니다.');
    }

    /**
     * 스캔 대상 파일 중 노드 루트 partial 이 실제로 다수를 차지하며 모두 수집됩니다.
     *
     * @effects node_rooted_partials_are_scanned
     *
     * @return void 반환값 없음
     */
    public function test_node_rooted_partials_are_not_silently_skipped(): void
    {
        $nodeRooted = 0;
        $collected = 0;

        foreach ($this->botFacingLayoutFiles() as $file) {
            $document = json_decode((string) file_get_contents($file), true);
            if (! is_array($document) || isset($document['components']) || isset($document['slots'])) {
                continue;
            }
            if (! isset($document['type']) && ! isset($document['name'])) {
                continue;
            }

            $nodeRooted++;
            if ($this->collectNodeKeys($document) !== []) {
                $collected++;
            }
        }

        $this->assertGreaterThan(0, $nodeRooted, '노드 루트 partial 탐색 자체가 실패했습니다.');
        $this->assertSame($nodeRooted, $collected, sprintf(
            '노드 루트 partial %d 개 중 %d 개에서만 키가 수집되었습니다.',
            $nodeRooted,
            $collected
        ));
    }

    /**
     * 지원/무시 목록이 서로 겹치지 않습니다 (분류 모호성 차단).
     *
     * @effects key_classification_is_unambiguous
     *
     * @return void 반환값 없음
     */
    public function test_supported_and_ignored_key_lists_are_disjoint(): void
    {
        $this->assertSame(
            [],
            array_values(array_intersect(self::SUPPORTED_KEYS, self::INTENTIONALLY_IGNORED_KEYS)),
            '같은 키가 지원 목록과 무시 목록에 동시에 있습니다.'
        );
    }

    /**
     * 봇 대상 자산의 이미지 썸네일 표기를 SEO 렌더러가 모두 해석합니다.
     *
     * 썸네일은 노드 키가 아니라 Img 의 `thumbPreset` 속성과 표현식 파이프(`| thumb('md')`, `| thumbSrcset(...)`)로 적는다.
     * 위 키 분류 검사는 이 둘을 보지 않으므로, 사람 화면(React)만 썸네일 주소를 쓰고 봇 화면이 조용히 원본 주소를 내는
     * 갈림이 생겨도 잡히지 않는다. 그래서 여기서 따로 잰다.
     * - `thumbPreset` 을 단 컴포넌트는 그 자산을 그리는 템플릿 `seo-config.json` 의 `component_map` 에서 `img` 태그다
     *   (SEO 렌더러는 `img` 태그에서만 `thumbPreset` 을 src·srcset 으로 바꾼다 — ComponentHtmlMapper::buildAttributes).
     * - 쓰인 썸네일 파이프는 SEO 파이프 표(PipeRegistry)에 있다.
     * - 글자 그대로 적은 규격 이름은 어느 사이트에나 있는 코어 규격(xs~xl·og)이거나 확장 규격 이름 형식이다.
     * 모집단이 비면 이 검사는 아무것도 재지 않으므로 두 표기 모두 하나 이상 있어야 한다.
     *
     * @effects thumbnail_notations_resolved_by_seo_renderer
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_notations_in_bot_facing_assets_are_resolved_by_seo_renderer(): void
    {
        $presetNodes = [];
        $pipes = [];

        foreach ($this->botFacingLayoutFiles() as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $this->collectThumbnailNotations($decoded, $file, $presetNodes, $pipes);
            }
        }

        $this->assertNotEmpty($presetNodes, '봇 대상 자산에서 thumbPreset 을 하나도 찾지 못했다 — 수집이 실물과 어긋났다');
        $this->assertNotEmpty($pipes, '봇 대상 자산에서 썸네일 파이프를 하나도 찾지 못했다 — 수집이 실물과 어긋났다');

        $problems = [];
        $componentMaps = $this->userTemplateComponentMaps();
        $this->assertNotEmpty($componentMaps, 'seo-config.json 을 가진 사용자 템플릿이 없다');

        foreach ($presetNodes as [$file, $component, $preset]) {
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file));
            $templateId = preg_match('#^templates/_bundled/([^/]+)/#', $relative, $m) === 1 ? $m[1] : null;
            $maps = $templateId !== null ? array_intersect_key($componentMaps, [$templateId => true]) : $componentMaps;

            foreach ($maps as $id => $map) {
                if (($map[$component]['tag'] ?? null) !== 'img') {
                    $problems[] = "{$relative}: {$component} 의 thumbPreset — {$id} seo-config 에서 img 태그가 아니라 봇 화면이 원본 주소를 낸다";
                }
            }

            if ($preset !== null && ! $this->isPortablePresetName($preset)) {
                $problems[] = "{$relative}: thumbPreset '{$preset}' 은 코어 규격도 확장 규격 이름도 아니다";
            }
        }

        $registry = new PipeRegistry;
        foreach ($pipes as [$file, $pipe, $preset]) {
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file));

            if (! $registry->has($pipe)) {
                $problems[] = "{$relative}: 파이프 {$pipe} 가 SEO 파이프 표에 없다";
            }

            if ($preset !== null && ! $this->isPortablePresetName($preset)) {
                $problems[] = "{$relative}: {$pipe}('{$preset}') 은 코어 규격도 확장 규격 이름도 아니다";
            }
        }

        $this->assertSame([], array_values(array_unique($problems)));
    }

    /**
     * 노드 트리를 훑어 썸네일 표기를 모읍니다.
     *
     * @param  array<mixed>  $node  문서 또는 노드
     * @param  string  $file  파일 경로
     * @param  list<array{0:string,1:string,2:string|null}>  $presetNodes  [파일, 컴포넌트 이름, 글자 그대로 적은 규격(표현식이면 null)] (참조)
     * @param  list<array{0:string,1:string,2:string|null}>  $pipes  [파일, 파이프 이름, 글자 그대로 적은 규격 인자(없으면 null)] (참조)
     * @return void 반환값 없음
     */
    private function collectThumbnailNotations(array $node, string $file, array &$presetNodes, array &$pipes): void
    {
        $preset = $node['props']['thumbPreset'] ?? null;
        if (is_string($preset) && is_string($node['name'] ?? null)) {
            $presetNodes[] = [$file, $node['name'], str_contains($preset, '{{') ? null : $preset];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectThumbnailNotations($value, $file, $presetNodes, $pipes);
            } elseif (is_string($value) && str_contains($value, 'thumb')) {
                // `||`(논리합)는 파이프가 아니다
                preg_match_all('/(?<!\|)\|(?!\|)\s*(thumb[A-Za-z]*)\s*(?:\(\s*[\'"]([^\'"]*)[\'"])?/', $value, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $pipes[] = [$file, $match[1], ($match[2] ?? '') !== '' ? $match[2] : null];
                }
            }
        }
    }

    /**
     * 사용자(비관리자) 템플릿의 SEO 컴포넌트 매핑 (`seo-config.json` 의 `component_map`)
     *
     * @return array<string, array<string, mixed>> 템플릿 id → component_map
     */
    private function userTemplateComponentMaps(): array
    {
        $maps = [];
        $templates = base_path('templates/_bundled');

        foreach (scandir($templates) ?: [] as $id) {
            $config = $templates.'/'.$id.'/seo-config.json';
            $manifest = $templates.'/'.$id.'/template.json';
            if ($id === '.' || $id === '..' || ! is_file($config)) {
                continue;
            }
            $meta = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
            if (is_array($meta) && ($meta['type'] ?? null) === 'admin') {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($config), true);
            $maps[$id] = is_array($decoded['component_map'] ?? null) ? $decoded['component_map'] : [];
        }

        return $maps;
    }

    /**
     * 레이아웃이 글자 그대로 적어도 되는 규격 이름인지 — 코어 규격(어느 사이트에나 있다)이거나 확장 규격 이름 형식.
     *
     * 운영자 규격 이름은 사이트마다 달라 번들 레이아웃이 기대면 다른 사이트에서 썸네일 없이 원본이 나간다.
     *
     * @param  string  $preset  규격 이름
     * @return bool 써도 되면 true
     */
    private function isPortablePresetName(string $preset): bool
    {
        return in_array($preset, ThumbnailPresetRegistry::RESERVED, true)
            || preg_match(ThumbnailPresetRegistry::EXTENSION_NAME_PATTERN, $preset) === 1;
    }

    /**
     * 봇 렌더링 대상이 되는 레이아웃/확장 자산 뿌리를 **저장소에서 도출**합니다.
     *
     * 종전에는 다섯 경로를 손으로 적었다(사용자 템플릿 하나 + 플러그인 셋). 그 목록은 확장이
     * 늘어도 함께 늘지 않아, 새 번들 확장의 자산은 이 패리티 검사 밖에 남았다 — 실측 17 뿌리 중
     * 5 만 보고 있었다. `scandir` 로 도출한다 (`glob` 은 형제 룰이 이 트리에서
     * 차단 가능 함수로 다루는 이름이다).
     *
     * 관리자 템플릿(`template.json` 의 `type: admin`)과 `admin/` 레이아웃은 인증이 필요해 봇이
     * 접근할 수 없으므로 제외합니다. 모듈·플러그인의 `resources/layouts` 는 어느 템플릿에 등록되는지
     * 폴더(`admin/`·`user/`)가 정하므로 뿌리로 넣고 경로 필터가 `admin/` 을 뺀다.
     *
     * @return array<int, string> 뿌리 절대 경로 목록 (정렬)
     */
    private function botFacingAssetRoots(): array
    {
        $roots = [];

        $listDirs = static function (string $dir): array {
            if (! is_dir($dir)) {
                return [];
            }
            $entries = array_filter(scandir($dir) ?: [], static fn (string $e): bool => $e !== '.' && $e !== '..' && is_dir($dir.'/'.$e));
            sort($entries);

            return array_values($entries);
        };

        foreach (['modules', 'plugins'] as $kind) {
            $container = base_path($kind.'/_bundled');
            foreach ($listDirs($container) as $id) {
                foreach (['layouts', 'extensions'] as $leaf) {
                    $candidate = $container.'/'.$id.'/resources/'.$leaf;
                    if (is_dir($candidate)) {
                        $roots[] = $candidate;
                    }
                }
            }
        }

        $templates = base_path('templates/_bundled');
        foreach ($listDirs($templates) as $id) {
            $manifest = $templates.'/'.$id.'/template.json';
            $meta = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
            if (is_array($meta) && ($meta['type'] ?? null) === 'admin') {
                continue;
            }
            foreach (['layouts', 'extensions'] as $leaf) {
                $candidate = $templates.'/'.$id.'/'.$leaf;
                if (is_dir($candidate)) {
                    $roots[] = $candidate;
                }
            }
        }

        sort($roots);

        return $roots;
    }

    /**
     * 봇 렌더링 대상이 되는 레이아웃/확장 자산 파일 목록을 반환합니다.
     *
     * 모집단이 비면 이 검사는 아무것도 재지 않는다 — 그 상태를 통과로 두지 않는다.
     *
     * @return array<int, string> 파일 절대 경로 목록
     */
    private function botFacingLayoutFiles(): array
    {
        $roots = $this->botFacingAssetRoots();
        $this->assertGreaterThanOrEqual(10, count($roots), '봇 대상 자산 뿌리 도출이 실물과 어긋났다 — 모집단이 비면 패리티 검사가 무의미하다');

        $files = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $entry) {
                if (! $entry->isFile() || $entry->getExtension() !== 'json') {
                    continue;
                }

                $path = $entry->getPathname();
                if (str_contains(str_replace('\\', '/', $path), '/admin/')) {
                    continue;
                }

                // 확장 자산이 **관리자 레이아웃**을 겨냥하면(`target_layout: admin_*` · `_admin_base`)
                // 그 화면도 인증 뒤라 봇 대상이 아니다 — 뿌리를 도출로 넓히며 들어온 축 (F-49).
                $document = json_decode((string) file_get_contents($path), true);
                $target = is_array($document) ? ($document['target_layout'] ?? null) : null;
                if (is_string($target) && preg_match('/^_?admin(?:_|\/|$)/', $target) === 1) {
                    continue;
                }

                // 확장점(`extension_point`)에 꽂히는 자산은 `target_layout` 이 없다 — 그 확장점을
                // 여는 화면이 **관리자 레이아웃뿐**이면 같은 이유로 봇 대상이 아니다. 판정은 확장점을
                // 여는 쪽을 저장소에서 도출해 한다 — 어디서도 찾지 못한 확장점은 봇 대상으로 남긴다.
                $point = is_array($document) ? ($document['extension_point'] ?? null) : null;
                if (is_string($point) && in_array($point, $this->adminOnlyExtensionPoints(), true)) {
                    continue;
                }

                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * 관리자 레이아웃에서만 열리는 확장점 이름 (도출 결과 memo).
     *
     * @var list<string>|null
     */
    private ?array $adminOnlyExtensionPoints = null;

    /**
     * 관리자 레이아웃에서만 열리고 봇 대상 레이아웃에서는 열리지 않는 확장점 이름.
     *
     * 관리자 쪽은 `type: admin` 템플릿의 레이아웃과 모듈·플러그인 `resources/layouts/admin/` 이고,
     * 그 밖의 레이아웃·확장 자산(확장점 안에 확장점을 여는 조각 포함)은 봇 쪽이다. 한쪽에서라도
     * 봇 쪽에서 열리면 관리자 전용이 아니다.
     *
     * @return list<string> 확장점 이름 목록
     */
    private function adminOnlyExtensionPoints(): array
    {
        if ($this->adminOnlyExtensionPoints !== null) {
            return $this->adminOnlyExtensionPoints;
        }

        $admin = [];
        $public = [];

        $roots = [];
        foreach (['modules', 'plugins'] as $kind) {
            foreach (scandir(base_path($kind.'/_bundled')) ?: [] as $id) {
                if ($id === '.' || $id === '..') {
                    continue;
                }
                foreach (['layouts', 'extensions'] as $leaf) {
                    $roots[] = [base_path($kind.'/_bundled/'.$id.'/resources/'.$leaf), false];
                }
            }
        }
        foreach (scandir(base_path('templates/_bundled')) ?: [] as $id) {
            if ($id === '.' || $id === '..') {
                continue;
            }
            $manifest = base_path('templates/_bundled/'.$id.'/template.json');
            $meta = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
            $isAdminTemplate = is_array($meta) && ($meta['type'] ?? null) === 'admin';
            foreach (['layouts', 'extensions'] as $leaf) {
                $roots[] = [base_path('templates/_bundled/'.$id.'/'.$leaf), $isAdminTemplate];
            }
        }

        foreach ($roots as [$root, $isAdminRoot]) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (! $entry->isFile() || $entry->getExtension() !== 'json') {
                    continue;
                }
                $path = str_replace('\\', '/', $entry->getPathname());
                $document = json_decode((string) file_get_contents($path), true);
                if (! is_array($document)) {
                    continue;
                }
                $target = $document['target_layout'] ?? null;
                $isAdmin = $isAdminRoot
                    || str_contains($path, '/admin/')
                    || (is_string($target) && preg_match('/^_?admin(?:_|\/|$)/', $target) === 1);

                if ($isAdmin) {
                    $this->collectExtensionPointNames($document, $admin);
                } else {
                    $this->collectExtensionPointNames($document, $public);
                }
            }
        }

        return $this->adminOnlyExtensionPoints = array_values(array_diff(array_unique($admin), array_unique($public)));
    }

    /**
     * 문서 안의 `type: extension_point` 노드 이름을 모읍니다.
     *
     * @param  array<mixed>  $node  문서 또는 노드
     * @param  list<string>  $names  모을 목록 (참조)
     * @return void 반환값 없음
     */
    private function collectExtensionPointNames(array $node, array &$names): void
    {
        if (($node['type'] ?? null) === 'extension_point' && is_string($node['name'] ?? null)) {
            $names[] = $node['name'];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectExtensionPointNames($value, $names);
            }
        }
    }

    /**
     * 문서(레이아웃/확장 자산) 최상위에만 나타나는 키.
     *
     * partial 파일은 문서 루트 자체가 컴포넌트 노드라(§collectNodeKeys) 루트 키를 노드 키로
     * 수집하는데, 그중 이 목록의 키는 노드가 아니라 문서 메타이므로 제외한다.
     */
    private const DOCUMENT_ONLY_KEYS = [
        'meta',
        'version',
        'extends',
        'layout_name',
        'data_sources',
        'computed',
        'permissions',
        'init_actions',
        'modals',
        'states',
        'defines',
        'error_handling',
        'globalHeaders',
        'scripts',
        'priority',
        'extension_point',
        'target_layout',
        'target_id',
        'position',
        'mode',
        'injections',
    ];

    /**
     * 레이아웃/확장 자산에서 컴포넌트 노드의 최상위 키를 수집합니다.
     *
     * 진입점은 셋이다:
     * 1. `components` / `slots.*` — 일반 레이아웃 문서, 확장 자산(`extension_point` 형)
     * 2. `injections[].components` — 주입 목록 형 확장 자산
     * 3. 위 어느 것도 없고 `type`/`name` 이 있으면 **문서 루트 자체가 컴포넌트 노드** (partial)
     *
     * 3번을 빠뜨리면 봇 대상 자산의 대부분(partial)이 통째로 감시망 밖에 남는다 — 이슈 공개 #86 이
     * 실제로 터진 `partials/board/show/_reply_section.json` 이 정확히 이 형태다.
     *
     * 각 노드에서 `children` / `default` / `responsive.*.children` / `itemTemplate` 로 재귀한다.
     *
     * @param  array  $document  디코드된 레이아웃/확장 자산
     * @return array<int, string> 사용된 노드 키 목록 (중복 제거)
     */
    private function collectNodeKeys(array $document): array
    {
        $keys = [];
        $hasComponentEntryPoint = false;

        foreach ($document['components'] ?? [] as $node) {
            $hasComponentEntryPoint = true;
            $this->collectFromNode($node, $keys);
        }

        $slots = $document['slots'] ?? [];
        if (is_array($slots)) {
            foreach ($slots as $slotNodes) {
                if (! is_array($slotNodes)) {
                    continue;
                }
                $hasComponentEntryPoint = true;
                foreach ($slotNodes as $node) {
                    $this->collectFromNode($node, $keys);
                }
            }
        }

        $injections = $document['injections'] ?? [];
        if (is_array($injections)) {
            foreach ($injections as $injection) {
                foreach ($injection['components'] ?? [] as $node) {
                    $hasComponentEntryPoint = true;
                    $this->collectFromNode($node, $keys);
                }
            }
        }

        // partial: 문서 루트가 곧 컴포넌트 노드
        if (! $hasComponentEntryPoint && (isset($document['type']) || isset($document['name']))) {
            // 루트에서만 문서 메타 키를 제외하고, 자손은 일반 노드와 동일하게 수집한다
            foreach (array_keys($document) as $key) {
                if (! in_array((string) $key, self::DOCUMENT_ONLY_KEYS, true)) {
                    $keys[] = (string) $key;
                }
            }

            $this->collectDescendantKeys($document, $keys);
        }

        return array_values(array_unique($keys));
    }

    /**
     * 단일 노드와 그 자손의 최상위 키를 수집합니다.
     *
     * @param  mixed  $node  노드
     * @param  array  $keys  수집 결과 (참조)
     * @return void 반환값 없음
     */
    private function collectFromNode(mixed $node, array &$keys): void
    {
        if (! is_array($node) || array_is_list($node)) {
            return;
        }

        foreach (array_keys($node) as $key) {
            $keys[] = (string) $key;
        }

        $this->collectDescendantKeys($node, $keys);
    }

    /**
     * 노드의 자손(children / default / responsive.*.children / itemTemplate) 키를 수집합니다.
     *
     * @param  array  $node  노드
     * @param  array  $keys  수집 결과 (참조)
     * @return void 반환값 없음
     */
    private function collectDescendantKeys(array $node, array &$keys): void
    {
        foreach (['children', 'default'] as $arrayKey) {
            foreach ($node[$arrayKey] ?? [] as $child) {
                $this->collectFromNode($child, $keys);
            }
        }

        if (isset($node['responsive']) && is_array($node['responsive'])) {
            foreach ($node['responsive'] as $override) {
                foreach ($override['children'] ?? [] as $child) {
                    $this->collectFromNode($child, $keys);
                }
            }
        }

        if (isset($node['itemTemplate'])) {
            $this->collectFromNode($node['itemTemplate'], $keys);
        }
    }
}
