<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\DocumentCompiler;
use App\Support\LayoutMarkup\LayoutLinter;
use App\Support\LayoutMarkup\LayoutMarkupDecompiler;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\LintWarning;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;
use Tests\TestCase;

/**
 * 레이아웃 마크업 `<responsive class-merge>` — 컴파일·역출력·왕복·린트 (7.2.1, engine-v1.98.0)
 *
 * `class-merge` 는 구간 오버라이드의 `classMerge` 키로 컴파일된다(props 가 아니라 형제 키라서 구버전 엔진은
 * 무시하고 DOM 으로 새지 않는다). 역출력은 같은 속성으로 되돌리고, 값에 폭 변형(`md:` 등)이 있으면 린터가 경고한다.
 */
class ResponsiveClassMergeMarkupTest extends TestCase
{
    private ComponentRegistry $registry;

    /**
     * 테스트 준비 — 관리자 템플릿 레지스트리를 읽습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
    }

    /**
     * 소스의 첫 루트 요소를 노드로 컴파일합니다.
     *
     * @param  string  $source  `.g7.html` 조각
     * @return array<string, mixed> 노드
     */
    private function compileNode(string $source): array
    {
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source));

        return (new NodeCompiler)->compile($roots[0]);
    }

    /**
     * `class-merge` 가 구간 `classMerge` 키로 컴파일되고 props 에는 들어가지 않는다.
     *
     * @scenario surface=markup
     *
     * @effects markup_class_merge_compiles_and_round_trips
     *
     * @return void 반환값 없음
     */
    public function test_class_merge_compiles_to_override_key(): void
    {
        $node = $this->compileNode('<div class="flex p-8"><responsive on="portable" class-merge="p-6 gap-2"/></div>');

        $this->assertSame('p-6 gap-2', $node['responsive']['portable']['classMerge']);
        $this->assertArrayNotHasKey('props', $node['responsive']['portable']);
    }

    /**
     * `class` 와 `class-merge` 를 한 구간에 함께 쓸 수 있다(구간 className 위에 덧붙는다).
     *
     * @scenario surface=markup
     *
     * @effects markup_class_merge_compiles_and_round_trips
     *
     * @return void 반환값 없음
     */
    public function test_class_and_class_merge_together(): void
    {
        $node = $this->compileNode('<div class="flex"><responsive on="mobile" class="grid" class-merge="gap-4"/></div>');

        $this->assertSame('grid', $node['responsive']['mobile']['props']['className']);
        $this->assertSame('gap-4', $node['responsive']['mobile']['classMerge']);
    }

    /**
     * 값이 빈 `class-merge` 는 컴파일 오류다.
     *
     * @scenario surface=markup
     *
     * @effects markup_class_merge_compiles_and_round_trips
     *
     * @return void 반환값 없음
     */
    public function test_empty_class_merge_is_compile_error(): void
    {
        $this->expectException(CompileError::class);

        $this->compileNode('<div class="flex"><responsive on="mobile" class-merge=""/></div>');
    }

    /**
     * classMerge 를 가진 문서가 역출력 → 재컴파일 왕복에서 같다.
     *
     * @scenario surface=markup
     *
     * @effects markup_class_merge_compiles_and_round_trips
     *
     * @return void 반환값 없음
     */
    public function test_decompile_round_trips_class_merge(): void
    {
        $json = [
            'version' => '1.0.0',
            'layout_name' => 'class_merge_sample',
            'components' => [[
                'id' => 'card',
                'type' => 'basic',
                'name' => 'Div',
                'props' => ['className' => 'w-full max-w-md p-8 bg-white'],
                'responsive' => [
                    'portable' => ['classMerge' => 'p-6'],
                    'mobile' => ['props' => ['className' => 'w-full'], 'classMerge' => 'p-4'],
                ],
            ]],
        ];

        $markup = (new LayoutMarkupDecompiler($this->registry))->decompile($json);
        $this->assertStringContainsString('class-merge="p-6"', $markup);

        $recompiled = (new DocumentCompiler($this->registry))->compile(
            (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($markup), null)
        );

        $this->assertSame(
            LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($json)))),
            LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($recompiled)))),
            "왕복 불일치:\n{$markup}",
        );
    }

    /**
     * 빈 classMerge 는 역출력하지 않는다 — `class-merge=""` 는 재컴파일이 거부해 HTML 보기 왕복이 막힌다.
     *
     * @scenario surface=markup
     *
     * @effects markup_class_merge_compiles_and_round_trips
     *
     * @return void 반환값 없음
     */
    public function test_empty_class_merge_is_not_decompiled(): void
    {
        $json = [
            'version' => '1.0.0',
            'layout_name' => 'class_merge_empty',
            'components' => [[
                'id' => 'box', 'type' => 'basic', 'name' => 'Div',
                'props' => ['className' => 'p-8'],
                'responsive' => ['mobile' => ['props' => ['className' => 'p-4'], 'classMerge' => '  ']],
            ]],
        ];

        $markup = (new LayoutMarkupDecompiler($this->registry))->decompile($json);

        $this->assertStringNotContainsString('class-merge', $markup);
        (new DocumentCompiler($this->registry))->compile((new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($markup), null));
    }

    /**
     * `class-merge` 에 폭 변형 클래스가 있으면 경고하고, 상태 변형·확장 앞글자만 있으면 경고하지 않는다.
     *
     * @scenario surface=linter
     *
     * @effects linter_warns_breakpoint_variant_in_class_merge
     *
     * @return void 반환값 없음
     */
    public function test_linter_warns_breakpoint_variant_in_class_merge(): void
    {
        $linter = new LayoutLinter($this->registry);
        $warned = ['components' => [[
            'type' => 'basic',
            'name' => 'Div',
            'responsive' => ['portable' => ['classMerge' => 'p-6 md:p-8 ecommerce:dark:lg:flex']],
        ]]];
        $warnings = array_values(array_filter(
            $linter->lint($warned, 'layout', new CompileContext),
            fn (LintWarning $w): bool => $w->getCode() === LayoutMarkupLintCode::ClassMergeBreakpointVariant,
        ));
        $this->assertCount(1, $warnings);

        $clean = ['components' => [[
            'type' => 'basic',
            'name' => 'Div',
            'responsive' => ['portable' => ['classMerge' => 'p-6 dark:bg-gray-800 hover:bg-gray-50 ecommerce:p-4']],
        ]]];
        $this->assertSame([], array_values(array_filter(
            $linter->lint($clean, 'layout', new CompileContext),
            fn (LintWarning $w): bool => $w->getCode() === LayoutMarkupLintCode::ClassMergeBreakpointVariant,
        )));
    }
}
