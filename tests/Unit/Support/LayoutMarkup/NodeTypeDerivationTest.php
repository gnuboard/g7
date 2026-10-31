<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutMarkupDecompiler;
use App\Support\LayoutMarkup\MarkupElement;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 노드 `type` 은 작성자가 `data-type` 으로 정한다 — 템플릿과 무관하다 (D47 → 2026-09-28 결정으로 개정).
 *
 * **개정 경위**: 종전(D47)에는 컴파일러가 대상 템플릿 레지스트리에서 type 을 파생했다. 그 결과 확장 레이아웃의
 * 설치·업데이트·조각 주입이 번들 기본 템플릿 두 개에 고정됐다(합의 없는 중대 설계 결함). 결정으로 HTML
 * 컴파일은 JSON 과 같이 템플릿 없이 동작한다 — 이름은 표기 규칙, 종류는 `data-type`(생략 시 basic). 번들 전량은
 * 레지스트리 파생값을 `data-type` 으로 기계 이관해 산출 JSON 이 한 글자도 바뀌지 않았다.
 *
 * 아래 D47 서술은 **왜 종류가 중요한가**의 근거로 남긴다 — 종류를 잘못 적으면 렌더러가 다른 갈래를 탄다.
 * 그래서 대상 템플릿이 분명한 자리(편집기·템플릿 자신의 레이아웃)에서는 선언과 다르면 경고한다
 * (`LayoutMarkupLintCode::ComponentTypeMismatch`).
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

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @param  string  $name  이름
     * @param  string  $expected  기대값
     * @return void 반환값 없음
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
     * @return array<string, 반환값
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
     * 컴파일러는 `data-type` 으로 종류를 정한다 — 레지스트리가 있어도 산출은 바뀌지 않는다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects node_type_comes_from_data_type_not_template
     *
     * @return void 반환값 없음
     */
    public function test_compiler_takes_type_from_data_type_not_registry(): void
    {
        $this->assertSame('composite', $this->compile('<toggle data-type="composite" data-ref="x"/>')['type']);
        $this->assertSame('composite', $this->compile('<badge data-type="composite">on</badge>')['type']);
        $this->assertSame('basic', $this->compile('<select/>')['type']);
        $this->assertSame('basic', $this->compile('<input type="text"/>')['type']);

        // 레지스트리(Toggle=composite)가 주어져도 적지 않았으면 basic 이다 — 산출은 템플릿과 무관하다.
        $this->assertSame('basic', $this->compile('<toggle data-ref="x"/>')['type']);
        $this->assertSame('basic', (new NodeCompiler)->compile($this->root('<toggle data-ref="x"/>'), null)['type']);
    }

    /**
     * 대상 템플릿이 분명한 자리에서는 적은 종류가 템플릿 선언과 다르면 경고한다.
     *
     * @scenario compile_unit=node,source_health=invalid
     *
     * @effects written_type_mismatch_is_linted_against_the_target_template
     *
     * @return void 반환값 없음
     */
    public function test_type_mismatch_against_target_template_is_linted(): void
    {
        $source = '<layout name="t" version="1.0.0"><div data-ref="root"><toggle data-ref="x"/></div></layout>';

        $warned = (new LayoutMarkupCompiler($this->registry))->compile($source, null, new CompileContext);
        $codes = array_map(static fn ($warning) => $warning->getCode(), $warned->warnings);
        $this->assertContains(LayoutMarkupLintCode::ComponentTypeMismatch, $codes);

        // 검사용 레지스트리가 없으면(설치 경로) 경고도 없다 — 산출은 같다.
        $plain = (new LayoutMarkupCompiler)->compile($source, null, new CompileContext);
        $this->assertNotContains(LayoutMarkupLintCode::ComponentTypeMismatch, array_map(static fn ($warning) => $warning->getCode(), $plain->warnings));
        $this->assertSame($warned->json, $plain->json);
    }

    /**
     * 역변환기는 JSON 의 `type` 을 그대로 `data-type` 으로 옮긴다 — 태그는 name 의 표기 규칙.
     *
     * JSON 과 동등해야 하므로 적힌 종류를 보존한다(템플릿으로 "정정" 하지 않는다). 어긋난 종류는 대상
     * 템플릿이 분명한 자리의 lint 가 알린다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_preserves_source_type_as_data_type
     *
     * @return void 반환값 없음
     */
    public function test_decompiler_preserves_source_type_as_data_type(): void
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

        $markup = (new LayoutMarkupDecompiler)->decompile($layout);

        $this->assertStringContainsString('<toggle', $markup);
        $this->assertStringContainsString('<select data-type="composite"', $markup);
        $this->assertStringNotContainsString('<toggle data-type', $markup);
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
        return $this->compiler->compile($this->root($source), null);
    }

    /**
     * 조각의 첫 요소를 만듭니다.
     *
     * @param  string  $source  `.g7.html` 조각
     * @return MarkupElement 첫 요소
     */
    private function root(string $source): MarkupElement
    {
        return (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source, null), null)[0];
    }
}
