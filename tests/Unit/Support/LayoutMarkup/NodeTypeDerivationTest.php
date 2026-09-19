<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupDecompiler;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 노드 `type` 은 레지스트리에서 파생한다 — 소스 JSON 의 `type` 은 정본이 아니다 (D47).
 *
 * `type`(basic/composite/layout)은 정규화 인벤토리에 "레지스트리 파생" 으로 등재돼 있었지만
 * "무해" 라는 서술은 틀렸다. [DynamicRenderer](resources/js/core/template-engine/DynamicRenderer.tsx)
 * 는 이 값을 **레지스트리가 아니라 레이아웃 노드에서** 읽어 세 갈래를 가른다 —
 * ① `dynamicState`(loadingActions·apiError) props 주입(composite 만) ② `__componentContext` 자동 전달
 * ③ `editorAttrs`·`__` 접두 키의 DOM 차단(basic·layout 만).
 *
 * 출하 JSON 실측 결과 **25,551 노드 중 345개**가 레지스트리와 어긋나 있었고, 어긋난 쪽이 결함이다:
 *
 *  - `Toggle`(19)·`Badge`(1)이 `basic` 으로 적혀 있었다. 두 컴포넌트는 `editorAttrs` 를 명시
 *    수신하고 `{...props}` 를 펼치지 않으므로, basic 분기에서 `editorAttrs` 가 제거되면 편집 표식이
 *    **DOM 에 아예 도달하지 못한다** — 지금 레이아웃 편집기에서 누락된 상태다.
 *  - 반대로 `Input`(9) 등이 `composite` 로 적혀 있었다. 그 결과 `dynamicState` 가 props 로 밀려들어
 *    DOM 으로 샜고, [Input.tsx](templates/_bundled/sirsoft-admin_basic/src/components/basic/Input.tsx)
 *    가 `const { loadingactions, formdata, ...validProps }` 로 **손수 걷어내는 방어코드**를 달고 있다.
 *
 * 그래서 이관은 `type` 을 레지스트리 값으로 **정정**한다(무해한 수렴이 아니라 결함 수정).
 * 이 테스트는 그 파생이 양방향 모두 성립함을 못박는다 — 컴파일·역변환 어느 쪽도 소스 `type` 을
 * 신뢰하지 않아야 한다.
 */
class NodeTypeDerivationTest extends LayoutMarkupTestCase
{
    private NodeCompiler $compiler;

    private ComponentRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = ComponentRegistry::fromFile(
            base_path('templates/_bundled/sirsoft-admin_basic/components.json'),
        );
        $this->compiler = new NodeCompiler($this->registry);
    }

    /**
     * 레지스트리 분류가 컴포넌트의 물리 위치와 일치한다 (파생의 전제).
     *
     * 이 전제가 깨지면 "레지스트리가 정본" 이라는 판정 자체가 무너지므로 함께 잠근다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects registry_type_matches_component_directory
     */
    #[DataProvider('driftingComponents')]
    public function test_registry_type_matches_component_file_location(string $name, string $expected): void
    {
        $definition = $this->registry->findByName($name);

        $this->assertNotNull($definition, "레지스트리에 {$name} 이 없습니다.");
        $this->assertSame($expected, $definition->type, "{$name} 의 레지스트리 type 이 기대와 다릅니다.");

        // `ComponentDefinition` 은 매니페스트의 `path` 를 싣지 않으므로(컴파일에 불필요) 원문에서 읽는다.
        $manifest = json_decode(
            file_get_contents(base_path('templates/_bundled/sirsoft-admin_basic/components.json')),
            true,
        );
        $path = null;

        foreach ($manifest['components'] as $components) {
            foreach ($components as $component) {
                if (($component['name'] ?? null) === $name) {
                    $path = $component['path'] ?? null;
                }
            }
        }

        $this->assertSame(
            "src/components/{$expected}/{$name}.tsx",
            $path,
            "{$name} 의 레지스트리 type 이 물리 디렉토리와 어긋납니다 — 파생의 전제가 깨졌습니다.",
        );
        $this->assertFileExists(base_path("templates/_bundled/sirsoft-admin_basic/{$path}"));
    }

    /**
     * 출하 JSON 에서 실제로 어긋나 있던 컴포넌트와 레지스트리 정본.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function driftingComponents(): array
    {
        return [
            // 출하 JSON 이 basic 으로 적어 편집 표식이 소실되던 것들
            'Toggle' => ['Toggle', 'composite'],
            'Badge' => ['Badge', 'composite'],
            // 출하 JSON 이 composite 로 적어 dynamicState 가 DOM 으로 새던 것들
            'Select' => ['Select', 'basic'],
            'Icon' => ['Icon', 'basic'],
            'Checkbox' => ['Checkbox', 'basic'],
            'Input' => ['Input', 'basic'],
            'Textarea' => ['Textarea', 'basic'],
            'Table' => ['Table', 'basic'],
        ];
    }

    /**
     * 컴파일러는 태그 → 레지스트리로만 `type` 을 정한다 (소스에 type 표기가 없다).
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects node_type_is_derived_from_registry_not_source
     */
    public function test_compiler_derives_type_from_registry(): void
    {
        $this->assertSame('composite', $this->compile('<toggle data-ref="x"/>')['type']);
        $this->assertSame('composite', $this->compile('<badge>on</badge>')['type']);
        $this->assertSame('basic', $this->compile('<select/>')['type']);
        $this->assertSame('basic', $this->compile('<input type="text"/>')['type']);
    }

    /**
     * 역변환기는 소스 JSON 의 `type` 을 태그 선택에 쓰지 않는다 — 어긋난 값이 실려도 태그는 name 기준.
     *
     * 이관 대상 JSON 이 `Toggle` 을 `basic` 으로 적고 있어도 `<toggle>` 로 찍혀야 하고, 되컴파일하면
     * 레지스트리 정본(`composite`)이 나와야 한다. 이것이 "이관이 type 을 정정한다" 의 실체다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_ignores_stale_source_type
     */
    public function test_decompiler_ignores_stale_source_type(): void
    {
        $layout = [
            'layout_name' => 'stale_type',
            'id' => 'root',
            'type' => 'basic',
            'name' => 'Div',
            'children' => [
                // 출하 JSON 이 실제로 담고 있던 어긋난 값
                ['type' => 'basic', 'name' => 'Toggle', 'props' => ['checked' => true]],
                ['type' => 'composite', 'name' => 'Select', 'props' => ['options' => [['value' => 'a', 'label' => 'A']]]],
            ],
        ];

        $markup = (new LayoutMarkupDecompiler($this->registry))->decompile($layout);

        $this->assertStringContainsString('<toggle', $markup);
        $this->assertStringContainsString('<select', $markup);
        // 어긋난 type 이 `<json>` 탈출구로 새지 않아야 한다 (정정이지 보존이 아니다).
        $this->assertStringNotContainsString('<json', $markup);
    }

    /**
     * 노드 하나를 컴파일합니다.
     *
     * @param  string  $source  `.g7.html` 조각
     * @return array<string, mixed> 컴파일된 노드
     */
    private function compile(string $source): array
    {
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source, null), null);

        return $this->compiler->compile($roots[0], null);
    }
}
