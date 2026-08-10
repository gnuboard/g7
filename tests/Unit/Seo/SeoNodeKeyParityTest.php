<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use Tests\TestCase;

/**
 * SEO(봇) 렌더러가 해석하지 못하는 노드 키가 봇 대상 화면에 유입되는지 감시합니다.
 *
 * 배경 (이슈 #86):
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
                if (! in_array($key, $known, true)) {
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
     * 문서 루트가 곧 노드인 partial 파일도 실제로 스캔됩니다.
     *
     * 수집 진입점이 `components`/`slots` 뿐이면 봇 대상 자산의 대부분(partial)이 통째로
     * 스킵되어 위 분류 단언이 항상 통과한다 — 이슈 #86 이 터진 `_reply_section.json` 이
     * 바로 그 형태이므로, 감시망이 비어 있는 채로 초록이 되는 상황을 여기서 차단한다.
     *
     * @effects node_rooted_partials_are_scanned
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
     * 봇 렌더링 대상이 되는 레이아웃/확장 자산 파일 목록을 반환합니다.
     *
     * 관리자 템플릿과 admin 레이아웃은 인증이 필요해 봇이 접근할 수 없으므로 제외합니다.
     *
     * @return array<int, string> 파일 절대 경로 목록
     */
    private function botFacingLayoutFiles(): array
    {
        $roots = [
            base_path('templates/_bundled/sirsoft-basic/layouts'),
            base_path('templates/_bundled/sirsoft-basic/extensions'),
            base_path('plugins/_bundled/sirsoft-ckeditor5/resources/extensions'),
            base_path('plugins/_bundled/sirsoft-daum_postcode/resources/extensions'),
            base_path('plugins/_bundled/sirsoft-gdpr/resources/extensions'),
        ];

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

                $files[] = $path;
            }
        }

        return $files;
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
     * 3번을 빠뜨리면 봇 대상 자산의 대부분(partial)이 통째로 감시망 밖에 남는다 — 이슈 #86 이
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
