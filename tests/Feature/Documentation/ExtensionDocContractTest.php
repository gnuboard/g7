<?php

namespace Tests\Feature\Documentation;

use App\Support\ExtensionDoc\DataModelCollector;
use App\Support\ExtensionDoc\DeclarativeSurfaceCollector;
use App\Support\ExtensionDoc\DependencyGraphCollector;
use App\Support\ExtensionDoc\ExtensionDocContext;
use App\Support\ExtensionDoc\ExtensionDocScaffolder;
use App\Support\ExtensionDoc\ExtensionInventory;
use App\Support\ExtensionDoc\FrontendInventory;
use App\Support\ExtensionDoc\HookInventory;
use App\Support\ExtensionDoc\TestPathCollector;
use Tests\TestCase;

/**
 * 확장 개발자 문서 계약 (#601)
 *
 * 세션 훅은 CI 에서 돌지 않는다. 문서 체계를 실제로 잠그는 것은 이 테스트다.
 *
 * 확장 목록을 **열거하지 않는다** — `_bundled` 를 스캔하므로 새 확장이 추가되면 자동으로
 * 검사 대상에 편입된다. 그래서 21번째 확장이 문서 없이 태어나면 이 테스트가 먼저 붉어진다.
 *
 * 문서가 아직 없는 확장은 집필 백로그(`DOC_BACKLOG`)로 선언한다. 백로그는 **줄어들기만
 * 해야 한다** — 목록에 없는 확장이 문서를 잃거나 새 확장이 문서 없이 들어오면 실패한다.
 * 백로그가 비면 그 순간부터 전수 강제로 바뀌며, 별도 코드 변경이 필요 없다.
 */
class ExtensionDocContractTest extends TestCase
{
    /**
     * 개발자 문서 집필 대기 중인 확장 (`{type}/{id}`).
     *
     * S1 은 인프라만 구축했고 집필은 S2(파일럿 3 + 동형군 6)·S3(잔여 11)에서 수행한다.
     * 확장 하나를 집필할 때마다 이 목록에서 그 줄을 지운다. 목록에 항목을 **추가하는 것은
     * 금지**다 — 추가는 곧 문서 없는 확장을 새로 들이는 것이고, 그러면 이 계약이 무의미해진다.
     *
     * @var array<int, string>
     */
    private const DOC_BACKLOG = [
    ];

    /**
     * 집필 백로그의 상한 (S1 종료 시점의 번들 확장 수).
     *
     * "추가 금지" 를 주석으로만 적으면 강제되지 않습니다 — 줄 하나를 보태면 문서 없는 확장이
     * 조용히 통과하고 아무 테스트도 red 가 되지 않습니다. 상한을 상수로 고정해, 백로그를
     * 늘리려면 이 숫자를 올리는 **눈에 보이는 행위**를 거치게 합니다. 집필이 진행되면 이
     * 숫자도 함께 내려갑니다 (백로그는 줄어들기만 하므로 상한도 단조 감소한다).
     */
    private const DOC_BACKLOG_CEILING = 0;

    /**
     * `_bundled` 스캔이 번들 확장 전수를 발견하는지 확인합니다.
     */
    public function test_inventory_discovers_every_bundled_extension(): void
    {
        $records = (new ExtensionInventory)->collect('all');

        $this->assertNotEmpty($records, '번들 확장을 하나도 발견하지 못했습니다.');

        foreach ($records as $record) {
            $this->assertDirectoryExists($record['path']);
            $this->assertFileExists($record['manifestPath']);
            $this->assertNotSame('', $record['id']);
            $this->assertContains($record['type'], ExtensionInventory::types());
        }

        // 디스크의 manifest 개수와 스캔 결과가 일치해야 한다 (조용한 누락 금지).
        $onDisk = 0;
        foreach ([['modules', 'module.json'], ['plugins', 'plugin.json'], ['templates', 'template.json']] as [$dir, $manifest]) {
            $root = base_path($dir.'/_bundled');
            if (! is_dir($root)) {
                continue;
            }
            $onDisk += count(glob($root.'/*/'.$manifest) ?: []);
        }

        $this->assertSame($onDisk, count($records), '스캔 결과가 디스크의 manifest 수와 다릅니다.');
    }

    /**
     * 백로그에 없는 확장은 필수 문서를 갖추고 있어야 합니다.
     *
     * 백로그 자신도 검사 대상입니다 — 실재하지 않는 확장이 남아 있으면 목록이 낡은 것이고,
     * 문서를 갖춘 확장이 남아 있으면 지워야 할 줄을 지우지 않은 것입니다.
     */
    public function test_documented_extensions_have_every_required_document(): void
    {
        $records = (new ExtensionInventory)->collect('all');
        $backlog = array_flip(self::DOC_BACKLOG);
        $ids = [];
        $missing = [];
        $staleBacklog = [];

        foreach ($records as $record) {
            $key = $record['type'].'/'.$record['id'];
            $ids[$key] = true;

            $present = [];
            $absent = [];

            foreach (ExtensionDocScaffolder::documentsForType($record['type']) as $doc) {
                $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $doc);
                if (is_file($abs)) {
                    $present[] = $doc;
                } else {
                    $absent[] = $doc;
                }
            }

            if (isset($backlog[$key])) {
                if ($absent === []) {
                    $staleBacklog[] = $key.' — 문서가 완비되었으니 DOC_BACKLOG 에서 지우세요';
                }

                continue;
            }

            foreach ($absent as $doc) {
                $missing[] = $key.' → '.$doc;
            }
        }

        foreach (array_keys($backlog) as $key) {
            if (! isset($ids[$key])) {
                $staleBacklog[] = $key.' — 존재하지 않는 확장입니다 (DOC_BACKLOG 에서 지우세요)';
            }
        }

        $this->assertSame([], $missing, "필수 문서 누락:\n".implode("\n", $missing));
        $this->assertSame([], $staleBacklog, "DOC_BACKLOG 가 낡았습니다:\n".implode("\n", $staleBacklog));

        // 백로그는 줄어들기만 한다 — 상한을 **정확히** 일치시켜 래칫으로 만든다.
        //
        // `<=` 로 두면 항목 하나를 집필해 지우는 순간(20 → 19) 상한 20 아래에 빈자리가
        // 하나 영구히 열린다. 그 다음부터는 문서 없는 확장을 백로그에 얹어도 red 가 되지
        // 않는다 — 막으려던 것이 첫 집필과 동시에 되살아난다. `===` 는 백로그에서 한 줄을
        // 지울 때 상한도 함께 내리게 강제하고, 늘리려면 그 숫자를 올리는 행위가 diff 에 남는다.
        $this->assertSame(
            self::DOC_BACKLOG_CEILING,
            count(self::DOC_BACKLOG),
            'DOC_BACKLOG 와 DOC_BACKLOG_CEILING 이 어긋났습니다. 확장을 집필해 백로그에서 '
            .'지웠다면 상한도 같은 수만큼 내리세요. 늘려야 할 근거가 정말 있다면 상한을 '
            .'올리고 그 사유를 남기세요 — 그것이 "문서 없는 확장을 새로 들인다" 는 선언입니다.',
        );

        $this->assertSame(
            array_values(array_unique(self::DOC_BACKLOG)),
            array_values(self::DOC_BACKLOG),
            'DOC_BACKLOG 에 중복 항목이 있습니다 (상한 판정이 왜곡됩니다).',
        );
    }

    /**
     * 작성된 문서는 필수 섹션과 자동 생성 블록을 갖추고 있어야 합니다.
     *
     * 문서를 만들다 만 상태(헤딩만 있고 블록이 없거나 그 반대)를 잡습니다.
     *
     * 백로그 확장은 제외합니다 — 표준 골격 이전에 손으로 쓰인 README 5개가 그 상태이며,
     * 재정비는 집필 단계의 작업입니다. 백로그에서 빠지는 순간 이 검사가 전면 적용됩니다.
     */
    public function test_existing_documents_have_required_sections_and_blocks(): void
    {
        $records = (new ExtensionInventory)->collect('all');
        $backlog = array_flip(self::DOC_BACKLOG);
        $problems = [];

        foreach ($records as $record) {
            if (isset($backlog[$record['type'].'/'.$record['id']])) {
                continue;
            }

            foreach (ExtensionDocScaffolder::documentsForType($record['type']) as $doc) {
                $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $doc);
                if (! is_file($abs)) {
                    continue;
                }

                $content = (string) file_get_contents($abs);
                $meta = ExtensionDocScaffolder::DOCUMENTS[$doc];
                $label = $record['type'].'/'.$record['id'].' → '.$doc;

                foreach (ExtensionDocScaffolder::sectionsFor($doc, $record['type']) as $section) {
                    if (! ExtensionDocScaffolder::hasSection($content, $section)) {
                        $problems[] = $label.' : 필수 섹션 없음 — '.$section;
                    }
                }

                $present = ExtensionDocScaffolder::presentBlockKeys($content);
                foreach (ExtensionDocScaffolder::blocksFor($doc) as $block) {
                    if (! in_array($block, $present, true)) {
                        $problems[] = $label.' : 자동 생성 블록 없음 — '.$block;
                    }
                }
            }
        }

        $this->assertSame([], $problems, "문서 골격 위반:\n".implode("\n", $problems));
    }

    /**
     * 자동 생성 블록의 훅 목록이 소스 실측과 일치해야 합니다 (문서 부패 검출).
     *
     * 훅은 다른 확장이 잡는 계약이므로, 문서와 코드가 어긋나면 그 확장이 잡을 수 없는
     * 훅 이름을 문서가 광고하게 됩니다.
     */
    public function test_generated_blocks_match_measured_source(): void
    {
        $inventory = new ExtensionInventory;
        $scaffolder = new ExtensionDocScaffolder;
        $drifted = [];

        foreach ($inventory->collect('all') as $record) {
            $documents = array_filter(
                ExtensionDocScaffolder::documentsForType($record['type']),
                fn (string $doc): bool => is_file($record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $doc)),
            );

            if ($documents === []) {
                continue;
            }

            $bodies = $scaffolder->renderBlocks($this->contextFor($record, $inventory));

            foreach ($documents as $doc) {
                $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $doc);
                $content = (string) file_get_contents($abs);

                $subject = [];
                foreach (ExtensionDocScaffolder::blocksFor($doc) as $block) {
                    if (isset($bodies[$block])) {
                        $subject[$block] = $bodies[$block];
                    }
                }

                $merged = ExtensionDocScaffolder::replaceBlocks($content, $subject);

                if (! $merged['unchanged']) {
                    $drifted[] = $record['type'].'/'.$record['id'].' → '.$doc;
                }
            }
        }

        $this->assertSame(
            [],
            $drifted,
            "자동 생성 블록이 코드 실측과 어긋납니다 (`php artisan ext:docgen` 재실행 필요):\n".implode("\n", $drifted),
        );
    }

    /**
     * 드리프트 판정식 자체가 살아 있어야 합니다 (공허 통과 방지).
     *
     * 위 검사는 **문서를 가진 확장**만 대상으로 하는데, 표준 골격으로 전환된 확장이 아직
     * 없어 실질 대상이 0건입니다. 그래서 판정식이 깨져도 계속 초록입니다 — "드리프트 없음"
     * 과 "드리프트를 보지 않음" 이 구분되지 않습니다. 낡은 훅 표를 심은 합성 문서로 판정식이
     * 실제로 드리프트를 잡는지 고정합니다.
     */
    public function test_drift_detection_actually_detects_drift(): void
    {
        $stale = "# 확장점\n\n"
            .ExtensionDocScaffolder::wrap('hooks-published', '| 훅 이름 | 유형 |\n|---|---|\n| `옛.훅.이름` | action |')
            ."\n";

        $fresh = '| 훅 이름 | 유형 |'."\n".'|---|---|'."\n".'| `새.훅.이름` | action |';

        $drifted = ExtensionDocScaffolder::replaceBlocks($stale, ['hooks-published' => $fresh]);

        $this->assertFalse(
            $drifted['unchanged'],
            '블록 본문이 실측과 다른데 드리프트로 판정되지 않았습니다 — 판정식이 죽어 있습니다.',
        );
        $this->assertStringContainsString('새.훅.이름', $drifted['content']);
        $this->assertStringNotContainsString('옛.훅.이름', $drifted['content']);

        // 같은 본문이면 드리프트가 아니어야 한다 (거짓 양성 금지).
        $same = ExtensionDocScaffolder::replaceBlocks($drifted['content'], ['hooks-published' => $fresh]);
        $this->assertTrue($same['unchanged'], '동일 본문인데 드리프트로 판정했습니다.');
    }

    /**
     * 훅 이름을 조립해 발행하는 확장도 발행 훅 표에 실려야 합니다.
     *
     * 리터럴 스캔만으로는 `self::PLUGIN_ID.'.consent.granted'` 형태를 읽지 못해, 훅을 12곳에서
     * 발행하는 확장이 "훅을 발행하지 않습니다" 로 문서화됩니다. 그래서 확장이 `getHooks()` 로
     * 선언한 목록이 1차 출처입니다 — 이 계약이 깨지면 그 확장의 확장점이 통째로 비공개가 되고,
     * 문서는 사실이 아닌 문장을 광고합니다.
     */
    public function test_declared_hooks_reach_the_published_table(): void
    {
        $inventory = new ExtensionInventory;
        $scaffolder = new ExtensionDocScaffolder;

        $records = array_values(array_filter(
            $inventory->collect('plugin:sirsoft-gdpr'),
            fn (array $r): bool => $r['id'] === 'sirsoft-gdpr',
        ));

        $this->assertNotEmpty($records, 'sirsoft-gdpr 를 찾지 못했습니다.');

        $ctx = $this->contextFor($records[0], $inventory);

        $this->assertGreaterThan(
            0,
            $ctx['hooks']['publishedSites'],
            '이 확장은 훅 발행 호출을 갖고 있어야 합니다 (전제 확인).',
        );
        $this->assertNotSame(
            [],
            $ctx['hooks']['published'],
            '발행 호출이 있는데 발행 훅이 0종입니다 — 선언이 반영되지 않았습니다.',
        );

        $block = $scaffolder->renderBlocks($ctx)['hooks-published'];

        $this->assertStringNotContainsString(
            '훅을 발행하지 않습니다',
            $block,
            '훅을 발행하는 확장에 "발행하지 않습니다" 가 렌더되었습니다.',
        );

        foreach ($ctx['hooks']['published'] as $hook) {
            $this->assertStringContainsString(
                $hook['name'],
                $block,
                "선언된 훅 '{$hook['name']}' 이 발행 훅 표에 없습니다.",
            );
        }
    }

    /**
     * 자동 생성 블록 교체가 블록 밖 텍스트를 손상하지 않아야 합니다.
     *
     * 이 계약이 깨지면 사람이 쓴 서술이 재생성 때마다 소실됩니다 — `api:docgen` 이 과거
     * 34,000줄을 날린 사고가 그 형태였고, 그래서 이 생성기에는 파괴적 재생성 플래그가 없습니다.
     */
    public function test_block_replacement_preserves_human_text(): void
    {
        $human = [
            'intro' => '사람이 쓴 서론 — 생성기가 건드리면 안 된다.',
            'intent' => '사람이 쓴 의도 서술 — 블록 사이에 있어도 보존되어야 한다.',
            'outro' => '사람이 쓴 마지막 문단.',
        ];

        $document = "# 제목\n\n{$human['intro']}\n\n"
            .ExtensionDocScaffolder::wrap('models', "| 옛 |\n|---|\n| 표 |')")
            ."\n\n<!-- @intent START -->\n{$human['intent']}\n<!-- @intent END -->\n\n"
            .ExtensionDocScaffolder::wrap('tables', '옛 테이블 표')
            ."\n\n## 마무리\n\n{$human['outro']}\n";

        $bodies = [
            'models' => "| 새 |\n|---|\n| 표 |",
            'tables' => '새 테이블 표',
            'enums' => '문서에 마커가 없는 블록 — 주입되면 안 된다',
        ];

        $result = ExtensionDocScaffolder::replaceBlocks($document, $bodies);

        foreach ($human as $key => $text) {
            $this->assertStringContainsString($text, $result['content'], "사람 서술 손실: {$key}");
        }

        $this->assertStringContainsString('| 새 |', $result['content']);
        $this->assertStringNotContainsString('| 옛 |', $result['content']);
        $this->assertStringContainsString('새 테이블 표', $result['content']);
        $this->assertStringNotContainsString('옛 테이블 표', $result['content']);

        $this->assertStringNotContainsString(
            '주입되면 안 된다',
            $result['content'],
            '문서에 마커가 없는 블록은 임의 위치에 주입하지 않는다 (누락으로 보고).',
        );
        $this->assertSame(['enums'], $result['missing']);
        $this->assertSame(['models', 'tables'], $result['replaced']);

        // 멱등: 같은 본문으로 다시 돌리면 한 글자도 바뀌지 않아야 한다.
        $again = ExtensionDocScaffolder::replaceBlocks($result['content'], [
            'models' => $bodies['models'],
            'tables' => $bodies['tables'],
        ]);
        $this->assertTrue($again['unchanged'], '재실행이 멱등이 아닙니다.');
    }

    /**
     * 미채움 마커 목록이 PHP · 검사 스크립트 · audit 룰 세 곳에서 일치해야 합니다.
     *
     * 세 곳이 갈라지면 한쪽이 세지 않는 마커가 생기고, 그 자리는 영영 집계되지 않습니다.
     */
    public function test_todo_marker_list_is_consistent_across_tooling(): void
    {
        $markers = ExtensionDocScaffolder::todoMarkers();

        $this->assertCount(5, $markers, '미채움 마커는 5종으로 고정입니다.');

        $script = (string) file_get_contents(base_path('.claude/scripts/check-extension-docs.cjs'));
        $rule = (string) file_get_contents(base_path('.claude/scripts/audit/rules/extension-doc-unfilled-markers.cjs'));

        foreach ($markers as $marker) {
            $this->assertStringContainsString(
                "'{$marker}'",
                $script,
                "check-extension-docs.cjs 에 마커 '{$marker}' 가 없습니다.",
            );
            $this->assertStringContainsString(
                "'{$marker}'",
                $rule,
                "extension-doc-unfilled-markers.cjs 에 마커 '{$marker}' 가 없습니다.",
            );
        }

        // 포함만 단언하면 방향이 하나뿐이라 JS 쪽 **초과** 항목(6번째 마커·오탈자 잔여)이
        // 그대로 살아 있다. 두 도구가 세는 모집단이 갈라지면 잔량 집계가 서로 다른 답을
        // 내는데, 그 차이는 어느 쪽 출력에도 드러나지 않는다.
        foreach ([$script, $rule] as $i => $source) {
            preg_match_all("/'(TODO: [^']+)'/u", $source, $m);
            $found = array_values(array_unique($m[1]));
            sort($found);
            $expected = $markers;
            sort($expected);

            $this->assertSame(
                $expected,
                $found,
                ($i === 0 ? 'check-extension-docs.cjs' : 'extension-doc-unfilled-markers.cjs')
                .' 의 마커 목록이 PHP SSoT 와 다릅니다 (초과 또는 누락).',
            );
        }
    }

    /**
     * 빈 서술 축이 PHP 와 검사 스크립트 양쪽에 존재하고 같은 판정을 내려야 합니다.
     *
     * 미채움은 두 축입니다 — `TODO:` 마커 잔량과, 마커를 지우고 서술을 쓰지 않은 빈
     * `@intent` 블록. 후자가 한쪽에만 있으면 그 도구만 통과시키는데, 결과가 "다 채웠다"
     * 와 구분되지 않아 비어 있는 문서가 완비로 집계됩니다.
     */
    public function test_empty_intent_axis_agrees_across_tooling(): void
    {
        $filled = "<!-- @intent START -->
서술이 있다.
<!-- @intent END -->";
        $empty = "<!-- @intent START -->

<!-- @intent END -->";

        $this->assertSame(0, ExtensionDocScaffolder::emptyIntentBlocks($filled));
        $this->assertSame(1, ExtensionDocScaffolder::emptyIntentBlocks($empty));
        $this->assertSame(2, ExtensionDocScaffolder::emptyIntentBlocks($empty."
".$empty));
        $this->assertSame(1, ExtensionDocScaffolder::emptyIntentBlocks($filled."
".$empty));

        $script = (string) file_get_contents(base_path('.claude/scripts/check-extension-docs.cjs'));

        $this->assertStringContainsString(
            'countEmptyIntentBlocks',
            $script,
            'check-extension-docs.cjs 에 빈 서술 축이 없습니다 — PHP 만 세면 하네스가 통과시킵니다.',
        );
        $this->assertStringContainsString(
            "id: 'emptyIntent'",
            $script,
            'check-extension-docs.cjs 의 빈 서술 축 식별자가 없습니다.',
        );
    }

    /**
     * 모든 번들 확장에서 수집기와 렌더러가 예외 없이 동작해야 합니다.
     *
     * 확장 하나의 특이 구조(다국어 배열 라벨 등)가 생성 전체를 중단시키는 것을 막습니다.
     */
    public function test_every_extension_renders_without_error(): void
    {
        $inventory = new ExtensionInventory;
        $scaffolder = new ExtensionDocScaffolder;

        foreach ($inventory->collect('all') as $record) {
            $label = $record['type'].'/'.$record['id'];
            $ctx = $this->contextFor($record, $inventory);

            $this->assertSame(
                [],
                $ctx['surface']['errors'],
                "{$label}: 선언형 표면 수집 중 실패한 getter 가 있습니다.",
            );

            // 수집 **실패** 는 errors 에 남지 않는다 — 진입 클래스 로드·인스턴스화 실패는
            // 조기 반환이라 errors 가 빈 배열인 채 available=false 가 된다. errors 만
            // 단언하면 모듈 20개의 표면이 전부 죽어도 이 테스트는 초록이고, 그 사이 문서에는
            // "확인하지 못했습니다" 가 대량으로 기록된다. 템플릿은 선언형 표면을 갖지 않는
            // 것이 정상이므로 대상에서 뺀다.
            if ($record['type'] !== ExtensionInventory::TYPE_TEMPLATE) {
                $this->assertTrue(
                    $ctx['surface']['available'],
                    "{$label}: 선언형 표면을 읽지 못했습니다 — ".($ctx['surface']['reason'] ?? '사유 미기록'),
                );
            }

            $blocks = $scaffolder->renderBlocks($ctx);
            $this->assertNotEmpty($blocks, "{$label}: 렌더된 블록이 없습니다.");

            foreach ($blocks as $key => $body) {
                $this->assertIsString($body, "{$label}: 블록 '{$key}' 본문이 문자열이 아닙니다.");
                $this->assertNotSame('', trim($body), "{$label}: 블록 '{$key}' 본문이 비었습니다.");
            }

            foreach (ExtensionDocScaffolder::documentsForType($record['type']) as $doc) {
                $skeleton = $scaffolder->skeleton($doc, $ctx);
                $this->assertNotSame('', trim($skeleton), "{$label}: {$doc} 골격이 비었습니다.");

                foreach (ExtensionDocScaffolder::sectionsFor($doc, $record['type']) as $section) {
                    $this->assertTrue(
                        ExtensionDocScaffolder::hasSection($skeleton, $section),
                        "{$label}: {$doc} 골격에 섹션 '{$section}' **헤딩**이 없습니다.",
                    );
                }

                foreach (ExtensionDocScaffolder::blocksFor($doc) as $block) {
                    $this->assertContains(
                        $block,
                        ExtensionDocScaffolder::presentBlockKeys($skeleton),
                        "{$label}: {$doc} 골격에 블록 '{$block}' 마커가 없습니다.",
                    );
                }
            }
        }
    }

    /**
     * 섹션 판정이 낱말 등장이 아니라 헤딩을 봅니다.
     *
     * 절 이름을 부분문자열로 찾으면 이 축은 실패할 수 없습니다. README 는 자동 생성
     * 인라인 목차가 12개 절 이름을 전부 담고, `docs/data-model.md` 의 모델 표는 헤더에
     * `| 모델 | 테이블 |` 을 싣습니다 — 헤딩을 통째로 지워도 통과합니다. 실측 선례:
     * `sirsoft-gdpr/README.md` 는 `## 변경 이력` 헤딩이 없는데 본문에 그 낱말이 3회
     * 등장한다는 이유로 "섹션 있음" 으로 판정됐습니다.
     */
    public function test_section_detection_requires_a_heading_not_a_word(): void
    {
        $section = '변경 이력';

        // 헤딩 없이 낱말만 있는 형태 — 인라인 목차 · 표 헤더 · 산문
        $decoys = [
            "[소개](#소개) · [{$section}](#변경-이력) · [라이선스](#라이선스)\n",
            "| 항목 | {$section} |\n|---|---|\n| a | b |\n",
            "회원/게스트의 모든 동의 {$section}을 조회할 수 있습니다.\n",
            "`## {$section}` 처럼 적으면 됩니다.\n",
        ];

        foreach ($decoys as $i => $decoy) {
            $this->assertFalse(
                ExtensionDocScaffolder::hasSection($decoy, $section),
                "낱말 등장(#{$i})이 섹션 존재로 판정됐습니다 — 헤딩을 지워도 통과하게 됩니다.",
            );
        }

        foreach (["## {$section}\n", "### {$section}\n", "본문\n\n##  {$section}  \n\n다음"] as $i => $real) {
            $this->assertTrue(
                ExtensionDocScaffolder::hasSection($real, $section),
                "실제 헤딩(#{$i})을 섹션 없음으로 판정했습니다.",
            );
        }

        // 접두 일치로 다른 절을 인정하지 않는다 (`문서` 가 `문서 목차` 를 먹으면 안 된다).
        $this->assertFalse(ExtensionDocScaffolder::hasSection("## 문서 목차\n", '문서'));
        $this->assertTrue(ExtensionDocScaffolder::hasSection("## 문서 목차\n", '문서 목차'));
    }

    /**
     * 골격의 절 아래에 **그 절의 블록**이 놓이는지 단언합니다.
     *
     * 헤딩 존재와 블록 마커 존재를 각각만 보면 짝이 어긋나도 전부 초록입니다 — 절을
     * 하나 끼우는 순간 그 뒤 블록이 통째로 밀려 엉뚱한 헤딩 밑에 박히는데, 그 상태가
     * 어떤 게이트에도 걸리지 않습니다.
     */
    public function test_skeleton_places_each_block_under_its_own_section(): void
    {
        $inventory = new ExtensionInventory;
        $scaffolder = new ExtensionDocScaffolder;

        foreach ($inventory->collect('all') as $record) {
            $ctx = $this->contextFor($record, $inventory);

            foreach (ExtensionDocScaffolder::documentsForType($record['type']) as $doc) {
                if (! ExtensionDocScaffolder::pairsSectionsWithBlocks($doc)) {
                    continue;
                }

                $skeleton = $scaffolder->skeleton($doc, $ctx);
                $overrides = ExtensionDocScaffolder::DOCUMENTS[$doc]['sectionOverrides'][$record['type']] ?? [];

                foreach (ExtensionDocScaffolder::DOCUMENTS[$doc]['blocks'] as $section => $blockKey) {
                    $heading = '## '.($overrides[$section] ?? $section);
                    $headingPos = mb_strpos($skeleton, $heading);
                    $blockPos = mb_strpos($skeleton, ExtensionDocScaffolder::GEN_PREFIX.$blockKey.' START');

                    $this->assertNotFalse($headingPos, "{$record['id']}: {$doc} 에 '{$heading}' 헤딩이 없습니다.");
                    $this->assertNotFalse($blockPos, "{$record['id']}: {$doc} 에 '{$blockKey}' 블록이 없습니다.");
                    $this->assertGreaterThan(
                        $headingPos,
                        $blockPos,
                        "{$record['id']}: {$doc} 의 '{$blockKey}' 블록이 '{$heading}' 절 아래에 있지 않습니다.",
                    );

                    // 다음 절이 시작되기 전에 그 블록이 나와야 한다 (다른 절로 밀리지 않음).
                    $nextHeading = mb_strpos($skeleton, '
## ', $headingPos + mb_strlen($heading));
                    if ($nextHeading !== false) {
                        $this->assertLessThan(
                            $nextHeading,
                            $blockPos,
                            "{$record['id']}: {$doc} 의 '{$blockKey}' 블록이 다음 절로 밀려 있습니다.",
                        );
                    }
                }
            }
        }
    }

    /**
     * 프로세스 경계를 넘는 리터럴 2종이 PHP·JS 양쪽에서 일치하는지 단언합니다.
     *
     * 코어 인덱스 스캐너는 `@generated:stats` 마커와 "확인하지 못했습니다" 문장을 **문자열로**
     * 다시 적어 두고 파싱합니다. 생성기 쪽 문구가 바뀌면 스캐너가 조용히 실패해 인덱스가
     * 다시 `훅 0 · 라우트 0` 을 사실로 싣습니다 — 미채움 마커 5종에는 이미 양방향 집합
     * 단언이 있는데 같은 성질의 이 두 리터럴만 그 취급을 받지 못했습니다.
     */
    public function test_cross_process_literals_match_the_index_scanner(): void
    {
        $scanner = base_path('.claude/scripts/generate-docs-index.cjs');

        if (! is_file($scanner)) {
            $this->markTestSkipped('내부 스캐너가 없는 배포본입니다.');
        }

        $js = (string) file_get_contents($scanner);

        // 생성기가 실제로 방출하는 마커에서 키 부분을 뽑아 스캐너 소스와 대조한다.
        // (스캐너는 정규식으로 공백을 흡수하므로 마커 전문이 리터럴로 있지는 않다.)
        $wrapped = ExtensionDocScaffolder::wrap('stats', '**훅 수**: 1');

        $this->assertStringContainsString('@generated:stats START', $wrapped);
        $this->assertStringContainsString('@generated:stats END', $wrapped);

        $this->assertStringContainsString(
            '@generated:stats',
            $js,
            '인덱스 스캐너가 집계 블록 마커를 그대로 알고 있어야 합니다.',
        );

        // 수집 실패 문구 — 통지는 둘(표면 전체 실패 · 개별 getter 실패)이고 스캐너는 그
        // 둘이 공유하는 어구를 찾는다. 소스에 리터럴이 있는지만 보면 "어딘가에 그 문자열이
        // 있다" 만 확인할 뿐, **실제로 방출되는 문장**이 그것을 담는지는 보지 않는다 —
        // 개별 getter 실패 통지가 다른 어구로 시작해 스캐너에 통째로 새던 것이 그 사각이다.
        $marker = ExtensionDocScaffolder::SURFACE_NOTICE_MARKER;

        $this->assertStringContainsString(
            // 스캐너 정규식은 `**` 를 이스케이프하므로 그 형태로 대조한다.
            str_replace('**', '\*\*', $marker),
            $js,
            '인덱스 스캐너가 표면 실패 판정 어구를 읽지 못하면 점검 불가가 0 으로 굳습니다.',
        );

        // 두 통지가 실제로 그 어구를 담고 방출되는지 렌더 결과로 단언한다.
        $inventory = new ExtensionInventory;
        $record = $inventory->find('module', 'sirsoft-board');

        $this->assertNotNull($record, '대조 기준 확장(sirsoft-board)이 없습니다.');

        $ctx = $this->contextFor($record, $inventory);
        $scaffolder = new ExtensionDocScaffolder;

        // (1) 개별 getter 실패 — 본문은 살리고 사유를 덧붙이는 통지
        $partial = $ctx;
        $partial['surface']['errors'] = ['getRoutes' => '테스트 주입'];

        foreach (['stats', 'hooks-published', 'hooks-subscribed', 'permissions'] as $key) {
            $this->assertStringContainsString(
                $marker,
                $scaffolder->renderBlock($key, $partial),
                "개별 getter 실패 시 `{$key}` 블록에 판정 어구가 실려야 인덱스가 그 수치를 점검 불가로 가릅니다.",
            );
        }

        // (2) 표면 전체 실패 — 본문을 대체하는 통지
        $whole = $ctx;
        $whole['surface']['available'] = false;
        $whole['surface']['reason'] = '테스트 주입';
        $whole['surface']['errors'] = [];

        // 본문을 대체하는 블록(permissions 등)뿐 아니라, 읽어낸 사실을 함께 싣는 블록
        // (수치·훅 표)에도 붙어야 한다. 그 셋만 통지 대상에서 빠져 있으면 진입 클래스를
        // 통째로 못 읽은 확장의 문서가 "발행 훅 N종 … 이 중 N종은 선언에 없어 자동 감지"
        // 라는 거짓 문장을 경고 없이 싣고, 인덱스 스캐너는 그 수치를 실측으로 옮긴다.
        foreach (['permissions', 'stats', 'hooks-published', 'hooks-subscribed'] as $key) {
            $this->assertStringContainsString(
                $marker,
                $scaffolder->renderBlock($key, $whole),
                "표면 전체 실패 시 `{$key}` 블록에도 같은 판정 어구가 실려야 합니다.",
            );
        }
    }

    /**
     * 세지 못한 지표가 0 으로 굳지 않는지 단언합니다.
     *
     * 수집기는 셀 수 없는 지표를 `null` 로 올립니다("0 을 돌려주면 주소가 없다는 사실
     * 주장이 된다"). 그 계약은 소비처가 지키지 않으면 그 자리에서 끝납니다 — 배지가
     * `?? 0` 으로 받으면 수집기가 구분해 올린 값이 다시 0 이 되고, 템플릿은 표면 실패
     * 통지 대상이 아니라 단서도 남지 않아 인덱스가 그 0 을 실측으로 옮깁니다.
     */
    public function test_unmeasured_stats_do_not_collapse_to_zero(): void
    {
        $inventory = new ExtensionInventory;
        $record = $inventory->find('template', 'sirsoft-basic');

        $this->assertNotNull($record, '대조 기준 템플릿(sirsoft-basic)이 없습니다.');

        $ctx = $this->contextFor($record, $inventory);

        // 정상 경로 — 실측이 그대로 실린다.
        $this->assertIsInt(
            ExtensionDocScaffolder::statsOf($ctx)['라우트 수'],
            '`routes.json` 을 읽은 템플릿은 주소 수가 정수여야 합니다.',
        );

        // 세지 못한 경로 — null 이 0 으로 바뀌지 않아야 한다.
        $unmeasured = $ctx;
        $unmeasured['frontend']['routeCount'] = null;

        $this->assertNull(
            ExtensionDocScaffolder::statsOf($unmeasured)['라우트 수'],
            '세지 못한 지표를 0 으로 받으면 "주소가 없다" 는 사실 주장이 됩니다.',
        );

        $block = (new ExtensionDocScaffolder)->renderBlock('stats', $unmeasured);

        $this->assertStringContainsString(
            ExtensionDocScaffolder::STAT_UNMEASURED,
            $block,
            '세지 못한 지표는 수치 자리에 그 사실이 드러나야 합니다.',
        );
        $this->assertStringNotContainsString(
            '**라우트 수**: 0',
            $block,
            '세지 못한 주소 수가 0 으로 실리면 안 됩니다.',
        );
        $this->assertStringContainsString(
            ExtensionDocScaffolder::SURFACE_NOTICE_MARKER,
            $block,
            '단서가 없으면 인덱스 스캐너가 이 블록을 실측으로 읽습니다.',
        );
    }

    /**
     * 상세 문서로 거는 앵커가 그 문서의 실제 절 이름인지 단언합니다.
     *
     * 요약 표는 절 이름을 문자열로 다시 적어 앵커를 만듭니다. 절 이름을 바꾸면 헤딩
     * 검사는 통과하는데 요약의 링크만 조용히 끊깁니다 — 앵커 유효성을 보는 장치가
     * 없었습니다.
     */
    public function test_summary_anchors_point_at_real_sections(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(ExtensionDocScaffolder::class))->getFileName()
        );

        preg_match_all(
            '/docLink\(\$ctx,\s*\'([^\']+)\',\s*\'([^\']+)\'\)/u',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($matches, 'docLink 호출을 하나도 찾지 못했습니다 — 판정식이 낡았습니다.');

        foreach ($matches as [, $doc, $anchor]) {
            $known = [];
            foreach (['module', 'plugin', 'template'] as $type) {
                $known = array_merge($known, ExtensionDocScaffolder::sectionsFor($doc, $type));
            }

            $this->assertContains(
                $anchor,
                $known,
                "docLink 가 '{$doc}' 의 '{$anchor}' 절을 가리키는데 그런 절이 없습니다 — 링크가 끊깁니다.",
            );
        }
    }

    /**
     * 템플릿 유형은 API·모델·훅 문서를 요구하지 않아야 합니다.
     *
     * 유형별 골격 분기가 사라지면 템플릿에 채울 수 없는 문서가 요구됩니다.
     */
    public function test_document_set_differs_by_extension_type(): void
    {
        $module = ExtensionDocScaffolder::documentsForType(ExtensionInventory::TYPE_MODULE);
        $template = ExtensionDocScaffolder::documentsForType(ExtensionInventory::TYPE_TEMPLATE);

        // `docs/editor-spec.md` 는 세 유형 공통이다. 편집기 스펙을 두지 않는 확장에도 문서를
        // 두는 것은 "왜 없어도 되는가 / 언제 필요해지는가" 를 적을 자리가 필요하기 때문이며,
        // 그 자리가 사라지면 미보유가 누락으로 오해되거나 필요한 시점을 놓친다.
        foreach (['AGENTS.md', 'README.md', 'docs/README.md', 'docs/architecture.md', 'docs/editor-spec.md'] as $shared) {
            $this->assertContains($shared, $module);
            $this->assertContains($shared, $template);
        }

        $this->assertContains('docs/data-model.md', $module);
        $this->assertContains('docs/extension-points.md', $module);
        $this->assertNotContains('docs/data-model.md', $template);
        $this->assertNotContains('docs/extension-points.md', $template);

        $this->assertContains('docs/components.md', $template);
        $this->assertContains('docs/layouts.md', $template);
        $this->assertNotContains('docs/components.md', $module);
    }

    /**
     * 고아 블록(문서에 실재하지만 필수 목록 밖) 검출이 살아 있는지 단언합니다.
     *
     * 이 축은 저장소에 문서가 0세트인 동안 실측이 항상 0건이라, 검출부가 깨져도
     * `--check` 이슈 수만 줄고 아무 게이트도 붉어지지 않습니다. 검출식을 직접 겨눕니다.
     */
    public function test_orphan_block_detection_is_alive(): void
    {
        $doc = 'AGENTS.md';
        $known = ExtensionDocScaffolder::blocksFor($doc);

        $this->assertNotEmpty($known, "{$doc} 의 필수 블록 목록이 비었습니다 — 모집단이 없습니다.");

        $legit = $known[0];
        $content = "# 제목\n\n"
            .ExtensionDocScaffolder::wrap($legit, '본문')."\n\n"
            .ExtensionDocScaffolder::wrap('legacy-orphan', '낡은 실측')."\n";

        $present = ExtensionDocScaffolder::presentBlockKeys($content);

        $this->assertContains($legit, $present, '필수 블록을 찾지 못했습니다.');
        $this->assertContains(
            'legacy-orphan',
            $present,
            '목록 밖 블록을 찾지 못하면 그 블록은 영영 갱신되지 않고 누락으로도 보고되지 않습니다.',
        );

        $orphans = array_values(array_filter(
            $present,
            static fn (string $key): bool => ! in_array($key, $known, true),
        ));

        $this->assertSame(['legacy-orphan'], $orphans, '고아 판정이 필수 블록까지 잡거나 놓치고 있습니다.');
    }

    /**
     * 유형별로 다른 표면을 생성기가 실제 파일 배치대로 서술하는지 단언합니다.
     *
     * 이 축의 결함은 예외도 경고도 남기지 않습니다 — 없는 파일을 요구하거나, 있는
     * 디렉토리를 통째로 빠뜨린 문서가 조용히 커밋됩니다. 그 문서는 골격에 한 번만
     * 쓰이고 자동 생성 블록이 아니라 재생성으로 고쳐지지도 않으므로, 틀린 채로 굳습니다.
     */
    public function test_skeleton_matches_actual_extension_layout(): void
    {
        $inventory = new ExtensionInventory;
        $scaffolder = new ExtensionDocScaffolder;
        $records = $inventory->collect();

        $this->assertNotEmpty($records, '확장을 하나도 찾지 못했습니다 — 모집단이 비었습니다.');

        $checkedTemplate = 0;
        $checkedControllers = 0;

        foreach ($records as $record) {
            $ctx = $this->contextFor($record, $inventory);
            $agents = $scaffolder->skeleton('AGENTS.md', $ctx);

            if ($record['type'] === ExtensionInventory::TYPE_TEMPLATE) {
                $checkedTemplate++;

                // 번들 템플릿은 PHP 패키지가 아니라 composer.json 을 갖지 않는다.
                $this->assertFileDoesNotExist(
                    $record['path'].DIRECTORY_SEPARATOR.'composer.json',
                    "전제가 깨졌습니다: {$record['id']} 에 composer.json 이 생겼습니다.",
                );

                $this->assertStringNotContainsString(
                    '`composer.json` 동기화',
                    $agents,
                    "{$record['id']}: 템플릿에 없는 composer.json 동기화를 동반 의무로 요구하고 있습니다.",
                );

                // 주소는 routes.json 에 있다 — 선언형 표면만 보면 구조적으로 항상 0 이다.
                $declared = $ctx['frontend']['routeCount'] ?? null;

                if (is_file($record['path'].DIRECTORY_SEPARATOR.'routes.json')) {
                    $this->assertIsInt(
                        $declared,
                        "{$record['id']}: routes.json 이 있는데 주소 수를 세지 못했습니다.",
                    );
                    $this->assertSame(
                        $declared,
                        ExtensionDocScaffolder::statsOf($ctx)['라우트 수'],
                        "{$record['id']}: 집계 배지의 라우트 수가 routes.json 실측과 다릅니다.",
                    );
                }
            }

            // 컨트롤러 자리는 두 갈래(`src/Http/Controllers/` · `src/Controllers/`)다.
            // 실재하는 갈래가 디렉토리 지도에 나타나지 않으면 그 확장 문서에서
            // "API 표면 변경 시 api:docgen 재실행" 절차가 통째로 사라진다.
            foreach (['src/Http/Controllers', 'src/Controllers'] as $candidate) {
                if (! is_dir($record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $candidate))) {
                    continue;
                }

                $checkedControllers++;

                $this->assertStringContainsString(
                    $candidate.'/',
                    $agents,
                    "{$record['id']}: {$candidate}/ 가 실재하는데 디렉토리 지도에 없습니다.",
                );
            }

            // 다른 확장 화면에 주입하는 레이아웃 조각도 유형마다 자리가 다르다.
            $extRoot = $record['type'] === ExtensionInventory::TYPE_TEMPLATE
                ? 'extensions'
                : 'resources/extensions';

            if (is_dir($record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $extRoot))) {
                $this->assertNotEmpty(
                    $ctx['frontend']['layoutExtensions'],
                    "{$record['id']}: {$extRoot}/ 가 실재하는데 레이아웃 확장 조각이 수집되지 않았습니다.",
                );

                // getLayoutExtensions() 기본 구현은 위와 같은 디렉토리를 glob() 한 절대경로라
                // 파일 목록과 100% 중복이다. 정규화 없이 실으면 같은 파일이 두 번(상대·절대) 나오고
                // 로컬 머신 절대경로가 커밋되는 확장 저장소에 그대로 남는다.
                // 템플릿은 'layout-extensions' 가 아니라 'template-overrides' 블록(docs/layouts.md)이다
                // — 그 개념(오버라이드)이 모듈/플러그인의 발행과 반대 방향이라 문서 자리가 다르다.
                $blockKey = $record['type'] === ExtensionInventory::TYPE_TEMPLATE ? 'template-overrides' : 'layout-extensions';
                $block = $scaffolder->renderBlocks($ctx)[$blockKey] ?? '';
                $this->assertNotSame('', $block, "{$record['id']}: {$blockKey} 블록이 렌더되지 않았습니다.");
                $this->assertStringNotContainsString(
                    str_replace('\\', '/', $record['path']),
                    str_replace('\\', '/', $block),
                    "{$record['id']}: 레이아웃 확장 표에 로컬 절대경로가 남아 있습니다.",
                );
                $rowCount = substr_count($block, "\n| `");
                $this->assertSame(
                    count($ctx['frontend']['layoutExtensions']),
                    $rowCount,
                    "{$record['id']}: 레이아웃 확장 표 행 수가 실제 파일 수와 다릅니다 (중복 행 의심).",
                );
            }

            // getNotificationDefinitions() 의 표준 계약은 리스트 배열 + 원소별 'type' 키다
            // (NotificationSyncHelper::sync() 소비 형태). 'key'/'event' 로 읽으면 정수 인덱스
            // 배열에서 이름을 못 찾아 모든 행이 '-' 로 찍힌다 — 표가 있으나 마나 해진다.
            $declaredNotifications = $ctx['surface']['values']['getNotificationDefinitions'] ?? [];
            if (is_array($declaredNotifications) && $declaredNotifications !== []) {
                $notificationsBlock = $scaffolder->renderBlocks($ctx)['notifications'] ?? '';
                $this->assertStringNotContainsString(
                    "\n| `-` |",
                    $notificationsBlock,
                    "{$record['id']}: 알림 정의 표의 알림 키가 비어 있습니다 ('type' 필드 매핑 확인).",
                );
            }

            // getSettingsLayout() 은 getModulePath()/getPluginPath() 기준 절대경로를 돌려준다
            // (§레이아웃 확장과 같은 결함군) — relativeToExtension() 을 거치지 않으면 로컬 머신
            // 절대경로가 그대로 커밋 문서(docs/settings.md · README.md)에 실린다.
            $settingsLayout = $ctx['surface']['values']['getSettingsLayout'] ?? null;
            if (is_string($settingsLayout) && $settingsLayout !== '') {
                $rendered = $scaffolder->renderBlocks($ctx);
                // record['path'] 는 Symfony Finder 산출물(슬래시)과 코드 조립 문자열(백슬래시)이
                // 섞인 혼합 구분자다 — 양쪽을 슬래시로 정규화하지 않으면 정규화된 needle 이
                // 정규화되지 않은 haystack 안의 진짜 누출을 놓친다.
                $needle = str_replace('\\', '/', $record['path']);
                foreach (['settings-schema', 'settings-summary'] as $settingsBlockKey) {
                    $this->assertStringNotContainsString(
                        $needle,
                        str_replace('\\', '/', $rendered[$settingsBlockKey] ?? ''),
                        "{$record['id']}: {$settingsBlockKey} 블록에 로컬 절대경로가 남아 있습니다.",
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $checkedTemplate, '템플릿을 하나도 검사하지 않았습니다.');
        $this->assertGreaterThan(0, $checkedControllers, '컨트롤러 디렉토리를 하나도 검사하지 않았습니다.');
    }

    /**
     * 스케줄 주기가 계약 키(`schedule`)에서 읽히는지 단언합니다.
     *
     * `getSchedules()` 의 표준 계약 키는 `schedule` 이다 — `AbstractModule`/`AbstractPlugin`
     * 의 주석과 `routes/console.php` 의 실제 소비부가 그 SSoT 다. 렌더러가 다른 키
     * (`expression`/`cron`/`frequency`)만 보면 주기 열이 **모든 확장에서 영구히 `-`** 가
     * 되는데, 그 모양은 "주기를 선언하지 않았다" 와 구분되지 않아 아무도 이상을 알아채지
     * 못한다. 실제로 그 상태로 board·ecommerce 두 확장이 문서화되었다.
     *
     * 확장 문서를 대조하지 않고 합성 입력으로 판정하는 이유는, 스케줄을 선언한 확장이
     * 저장소에서 사라지면 실측 대조가 **공허 통과**하기 때문이다.
     */
    public function test_schedule_period_reads_the_contract_key(): void
    {
        $ctx = [
            'record' => ['type' => 'module', 'id' => 'vendor-ext'],
            'surface' => [
                'available' => true,
                'values' => [
                    'getSchedules' => [
                        [
                            'command' => 'vendor-ext:do-something',
                            'schedule' => 'hourly',
                            'description' => '계약 키만 선언한 스케줄',
                        ],
                    ],
                ],
            ],
        ];

        $block = (new ExtensionDocScaffolder)->renderBlock('schedules', $ctx);

        $this->assertStringContainsString(
            'hourly',
            $block,
            '계약 키 `schedule` 로 선언한 주기가 표에 실리지 않습니다 — 주기 열이 모든 확장에서 `-` 가 됩니다.',
        );
        $this->assertStringNotContainsString(
            '| `-` |',
            $block,
            '주기를 선언한 스케줄이 미선언으로 렌더되었습니다.',
        );
    }

    /**
     * 네임스페이스를 붙인 핸들러 등록 키가 수집·렌더 양쪽에서 살아남는지 단언합니다.
     *
     * `'vendor-ext.doThing': handler` 처럼 네임스페이스를 붙인 키는 `.`·`-` 때문에 반드시
     * 따옴표로 감싸인다. 수집기가 식별자 키만 보면 그 항목이 통째로 빠지는데, 결과가
     * "그만큼만 등록했다" 와 같은 모양이라 누락이 드러나지 않는다 — 실제로 sirsoft-basic 이
     * 32개 중 10개를 그렇게 잃고 있었다.
     *
     * 렌더 축도 함께 잠근다. 템플릿은 namespace 가 null 이지만 네임스페이스를 붙여 등록하는
     * 핸들러를 함께 가질 수 있어, 그 경우 "네임스페이스 없음" 으로 적으면 사실과 반대가 된다.
     */
    public function test_namespaced_handler_keys_survive_collection_and_render(): void
    {
        $source = <<<'TS'
        export const handlerMap = {
            plainOne: plainOneHandler,
            'vendor-ext.doThing': doThingHandler,
            "vendor-ext.doOther": doOtherHandler,
        };
        TS;

        $inventory = new FrontendInventory;
        $method = (new \ReflectionClass(FrontendInventory::class))->getMethod('objectKeys');
        $method->setAccessible(true);

        /** @var array<int, string> $keys */
        $keys = $method->invoke($inventory, $source, 'handlerMap');

        $this->assertSame(
            ['plainOne', 'vendor-ext.doThing', 'vendor-ext.doOther'],
            $keys,
            '따옴표로 감싼 네임스페이스 등록 키가 수집에서 빠졌습니다 — 누락이 "그만큼만 등록했다" 로 보입니다.',
        );

        $block = (new ExtensionDocScaffolder)->renderBlock('handlers', [
            'record' => ['type' => 'template', 'id' => 'vendor-ext'],
            'surface' => ['available' => true, 'values' => []],
            'frontend' => [
                'handlers' => [
                    'namespace' => null,
                    'names' => $keys,
                    'source' => 'src/handlers/index.ts',
                ],
            ],
        ]);

        $this->assertStringContainsString(
            '핸들러 3개',
            $block,
            '수집한 핸들러 수가 표에 그대로 실려야 합니다.',
        );
        $this->assertStringContainsString(
            '`vendor-ext.doThing`',
            $block,
            '네임스페이스를 붙여 등록한 핸들러는 그 전체 이름이 호출 이름입니다.',
        );
        $this->assertStringContainsString(
            '`plainOne` | (템플릿 전용',
            $block,
            '네임스페이스 없이 등록한 핸들러는 기존 표기를 유지해야 합니다.',
        );
    }

    /**
     * 확장 README 의 첫 화면이 이미지 배지가 아니라 H1 제목인지 단언합니다.
     *
     * PO 결정(2026-08-31): 확장 README 상단의 확장명은 shields.io 이미지 배지가 아니라 평범한
     * H1 마크다운 제목이다. 확장은 20개가 병렬로 존재하는 대등한 구성요소이고, 각자가 코어와
     * 같은 히어로 브랜딩을 받으면 "이 확장이 곧 독립 프로젝트" 라는 착시를 준다.
     *
     * 모집단은 손으로 적지 않고 `_bundled` 스캔에서 파생한다. 실제로 S2 가 이 축을
     * `height="120"` 잔존 0 으로 닫았는데, S3 이 `height="60"` 으로 쓴 11개가 그 모집단 밖이라
     * 게이트가 초록인 채 결정이 절반만 적용된 상태로 남았다. 판정은 높이가 아니라 **형태**
     * (`style=for-the-badge` 이미지가 상단에 있는가)로 한다.
     *
     * `@generated:badges` 블록 안의 version/type/G7/license 배지는 대상이 아니다 — 그것은
     * manifest 에서 오는 flat-square 정보 배지이고 계획서 §1.3 이 유지하기로 한 것이다.
     */
    public function test_extension_readme_leads_with_a_heading_not_a_hero_badge(): void
    {
        $records = (new ExtensionInventory)->collect('all');
        $this->assertNotEmpty($records, '번들 확장을 하나도 발견하지 못했습니다.');

        $checked = 0;
        $heroes = [];
        $missingHeading = [];

        foreach ($records as $record) {
            $readme = $record['path'].'/README.md';
            if (! is_file($readme)) {
                continue;
            }

            $checked++;
            $body = (string) file_get_contents($readme);

            // 자동 생성 배지 블록 앞부분(사람이 쓰는 히어로 영역)만 본다.
            $head = $body;
            $blockAt = strpos($body, '<!-- @generated:badges START');
            if ($blockAt !== false) {
                $head = substr($body, 0, $blockAt);
            }

            if (str_contains($head, 'style=for-the-badge')) {
                $heroes[] = $record['type'].'/'.$record['id'];
            }

            $firstLine = '';
            foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
                if (trim($line) !== '') {
                    $firstLine = trim($line);
                    break;
                }
            }

            if (! str_starts_with($firstLine, '# ')) {
                $missingHeading[] = $record['type'].'/'.$record['id'].' — 첫 줄이 "'.$firstLine.'"';
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'README 를 가진 번들 확장이 하나도 없습니다 — 모집단이 비면 이 단언은 공허 통과합니다.',
        );

        $this->assertSame(
            [],
            $heroes,
            "확장 README 상단에 히어로 이미지 배지가 남아 있습니다 (H1 제목으로 바꾸세요):\n".implode("\n", $heroes),
        );

        $this->assertSame(
            [],
            $missingHeading,
            "확장 README 의 첫 줄이 H1 제목이 아닙니다:\n".implode("\n", $missingHeading),
        );

        // 이미 놓인 파일만 보면 **다음 확장**이 사각이다 — 골격 생성기가 히어로를 계속
        // 찍어내면 21번째 확장은 태어나는 순간 이 단언에 걸리는 README 를 갖는다. 결정이
        // 파일에만 적용되고 그 파일을 만드는 자리에는 적용되지 않은 상태가 남지 않도록
        // 생성기 출력도 같은 판정을 통과시킨다.
        $inventory = new ExtensionInventory;
        $scaffolder = new ExtensionDocScaffolder;

        foreach ($records as $record) {
            $skeleton = $scaffolder->skeleton('README.md', $this->contextFor($record, $inventory));
            $head = $skeleton;
            $blockAt = strpos($skeleton, '<!-- @generated:badges START');
            if ($blockAt !== false) {
                $head = substr($skeleton, 0, $blockAt);
            }

            $this->assertStringNotContainsString(
                'style=for-the-badge',
                $head,
                "README 골격 생성기가 히어로 배지를 찍어냅니다 ({$record['id']} 기준).",
            );
            $this->assertStringStartsWith(
                '# ',
                $skeleton,
                "README 골격 생성기의 첫 줄이 H1 제목이 아닙니다 ({$record['id']} 기준).",
            );
        }
    }

    /**
     * 확장 문서의 「활동 로그 훅」 표가 리스너의 실제 구독과 일치하는지 단언합니다.
     *
     * 이 목록은 코어 `docs/backend/activity-log-hooks.md` 에서 확장 소유로 옮겨온 것이고(#601),
     * 옮겨올 당시 코어 문서가 이미 낡아 있었다. 리스너가 `before_*` 훅 구독을 걷어내고
     * **Service 가 스냅샷을 `after_*` 인자로 넘기는** 구조로 바뀌었는데 표는 그대로였다 —
     * 없는 구독 31행이 실려 있었고 실제 구독 13건이 빠져 있었으며, 소계·총계·메서드 이름까지
     * 어긋나 있었다. 문서가 확장점의 SSoT 이므로 이 어긋남은 그 확장을 잡으려는 쪽이
     * **잡히지 않는 훅을 구독**하게 만든다 (예외도 경고도 없이 리스너가 안 불릴 뿐이다).
     *
     * 모집단은 손으로 적지 않고 **코드**에서 파생한다 — `logActivity` 를 호출하면서 훅을
     * 구독하는 리스너를 가진 확장 전부가 대상이다. 문서의 「활동 로그 훅」 절 존재 여부로
     * 모집단을 정하면 그 절을 통째로 빠뜨린 확장이 검사에서 조용히 빠지는 순환이 된다
     * (결제 3종이 실제로 그 사각에 있었다 — 리스너는 있는데 절이 없어 검사 밖이었다).
     *
     * 대상 판정을 파일명 관례(`*ActivityLog*`)가 아니라 실제 `logActivity` 호출로 하는 이유도
     * 같다. 관례를 벗어난 이름도 잡히고, 설명 변수 해석기(`ActivityLogDescriptionResolver` —
     * 기록하지 않고 해석만 한다)는 클래스명을 손으로 열거하지 않아도 빠진다.
     */
    public function test_activity_log_hook_tables_match_listener_subscriptions(): void
    {
        $records = (new ExtensionInventory)->collect('all');
        $this->assertNotEmpty($records, '번들 확장을 하나도 발견하지 못했습니다.');

        $checked = 0;
        $problems = [];

        foreach ($records as $record) {
            // 모집단은 **코드**에서 파생한다. 「활동 로그 훅」 절을 가진 확장만 보면 절을
            // 통째로 빠뜨린 확장이 검사에서 조용히 빠진다 — 검사 대상 문서의 존재 여부로
            // 모집단을 정하는 순환이다(실제로 결제 3종이 그 사각에 있었다).
            $listeners = glob($record['path'].'/src/Listeners/*.php') ?: [];
            $subscriptions = [];

            foreach ($listeners as $file) {
                // 활동 로그를 **기록하는** 리스너만 대상이다. 파일명 관례(`*ActivityLog*`)가
                // 아니라 실제 `logActivity` 호출로 가른다 — 관례를 벗어난 이름도 잡히고,
                // 설명 해석기(기록하지 않고 해석만 한다)는 클래스명을 열거하지 않아도 빠진다.
                if (! str_contains((string) file_get_contents($file), 'logActivity')) {
                    continue;
                }

                $fqcn = $this->listenerFqcn($record, basename($file, '.php'));
                if ($fqcn === null || ! method_exists($fqcn, 'getSubscribedHooks')) {
                    continue;
                }

                foreach (array_keys($fqcn::getSubscribedHooks()) as $hook) {
                    $subscriptions[] = $hook;
                }
            }

            if ($subscriptions === []) {
                continue;
            }

            $checked++;

            $key = $record['type'].'/'.$record['id'];
            $doc = $record['path'].'/docs/extension-points.md';
            $body = is_file($doc) ? (string) file_get_contents($doc) : '';
            $at = strpos($body, '## 활동 로그 훅');

            if ($at === false) {
                $problems[] = $key.' — 활동 로그를 기록하는 리스너가 있으나 docs/extension-points.md 에 「활동 로그 훅」 절이 없습니다 (구독 '.count($subscriptions).'건).';

                continue;
            }

            // 절은 **다음 `## ` 헤딩에서 끊는다.** 파일 끝까지 열어 두면 뒤따르는 절
            // (「훅 리스너」·「미들웨어」 등)의 표까지 삼켜 그 행이 전부 유령으로 보고된다.
            $section = substr($body, $at);
            $nextHeading = preg_match('/^## /m', substr($section, 3), $m, PREG_OFFSET_CAPTURE) === 1
                ? 3 + $m[0][1]
                : strlen($section);
            $section = substr($section, 0, $nextHeading);

            preg_match_all('/^\|\s*`([^`]+)`/m', $section, $matches);
            $documented = $matches[1];

            $phantom = array_values(array_unique(array_diff($documented, $subscriptions)));
            $missing = array_values(array_unique(array_diff($subscriptions, $documented)));

            foreach ($phantom as $hook) {
                $problems[] = $key.' — 표에 있으나 구독하지 않습니다: '.$hook;
            }
            foreach ($missing as $hook) {
                $problems[] = $key.' — 구독하지만 표에 없습니다: '.$hook;
            }
            if (count($documented) !== count($subscriptions)) {
                $problems[] = $key.' — 표의 행 수('.count($documented).')와 구독 등록 수('.count($subscriptions).')가 다릅니다.';
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            '활동 로그를 기록하는 리스너를 가진 확장이 하나도 없습니다 — 모집단이 비면 이 단언은 공허 통과합니다.',
        );

        $this->assertSame(
            [],
            $problems,
            "활동 로그 훅 표가 리스너의 실제 구독과 어긋납니다:\n".implode("\n", $problems),
        );
    }

    /**
     * 확장 리스너 클래스의 FQCN 을 조립합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  string  $class  리스너 클래스명
     * @return string|null 존재하는 FQCN, 없으면 null
     */
    private function listenerFqcn(array $record, string $class): ?string
    {
        $root = $record['type'] === 'plugin' ? 'Plugins' : 'Modules';

        [$vendor, $name] = array_pad(explode('-', (string) $record['id'], 2), 2, '');
        $studly = static fn (string $v): string => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $v)));

        $sep = chr(92);
        $fqcn = $root.$sep.$studly($vendor).$sep.$studly($name).$sep.'Listeners'.$sep.$class;

        return class_exists($fqcn) ? $fqcn : null;
    }

    /**
     * 수집 컨텍스트를 만듭니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  ExtensionInventory  $inventory  인벤토리 (역방향 의존 스캔에 사용)
     * @return array<string, mixed> 수집 컨텍스트
     */
    private function contextFor(array $record, ExtensionInventory $inventory): array
    {
        // 커맨드와 **같은 조립기**를 쓴다. 여기서 배열을 손으로 다시 엮으면 수집 축이 하나
        // 늘 때 한쪽만 갱신되어, 테스트가 만든 블록에 그 축이 빠진 채 파일과 달라진다 —
        // 결과는 "생성기를 다시 돌려라" 인데 아무리 돌려도 사라지지 않는 드리프트다.
        return app(ExtensionDocContext::class)->build($record);
    }
}
