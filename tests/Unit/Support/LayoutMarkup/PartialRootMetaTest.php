<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\DocumentCompiler;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;

/**
 * 조각(`<partial>`) 문서의 meta 자리 단위 테스트.
 *
 * 조각 문서는 루트 노드 자체라 meta 자리가 하나뿐이고 `<partial>` 속성이 그 자리를 차지한다.
 * 루트 노드 안에 `<meta>` 를 적으면 예전에는 오류 없이 덮여 사라져, 모달 조각의 편집기 라벨
 * (`editor_label`)이 굽힌 JSON 에서 빠지고 편집기 트리에 제목 식 원문이 노출됐다.
 */
class PartialRootMetaTest extends LayoutMarkupTestCase
{
    /**
     * 루트 노드 안의 `<meta>` 는 컴파일 오류로 거부됩니다.
     *
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects partial_root_meta_must_live_on_partial_envelope
     *
     * @return void 반환값 없음
     */
    public function test_meta_inside_partial_root_node_is_rejected(): void
    {
        $error = $this->compileError(
            '<partial description="d"><modal data-type="composite" title="{{x}}"><meta editor_label="$t:a.b"/><div/></modal></partial>'
        );

        $this->assertSame(LayoutMarkupErrorCode::PartialRootMetaNotAllowed, $error->getErrorCode());
    }

    /**
     * `<partial>` 속성으로 적은 편집기 라벨은 조각 meta 에 그대로 남습니다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects partial_envelope_attributes_become_meta
     *
     * @return void 반환값 없음
     */
    public function test_editor_label_on_partial_envelope_is_kept_in_meta(): void
    {
        $json = $this->compile(
            '<partial description="d" editor_label="$t:a.b"><modal data-type="composite" title="{{x}}"><div/></modal></partial>'
        );

        $this->assertSame(
            ['is_partial' => true, 'description' => 'd', 'editor_label' => '$t:a.b'],
            $json['meta'],
        );
        $this->assertSame('{{x}}', $json['props']['title']);
    }

    /**
     * 소스를 템플릿 비의존 컴파일러로 컴파일합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return array<string, mixed> 컴파일된 JSON
     */
    private function compile(string $source): array
    {
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($source));

        return (new DocumentCompiler)->compile($roots);
    }

    /**
     * 컴파일이 실패할 것을 전제로 오류를 잡아 반환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @return CompileError 발생한 오류
     */
    private function compileError(string $source): CompileError
    {
        try {
            $this->compile($source);
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail("컴파일 오류를 기대했으나 통과했습니다: {$source}");
    }
}
