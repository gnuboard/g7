<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\DocumentCompiler;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutMarkupDecompiler;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\PartialReferenceNotation;
use Illuminate\Support\Facades\File;

/**
 * 조각 참조 표기의 **경계 정규화 계약**을 잠근다 (S21.2 ⓑ · U-REFEXT 안 B).
 *
 * 계약은 둘이다.
 *
 * 1. **매핑은 정확한 역함수다.** 왕복 게이트는 `JSON → 마크업 → JSON` 이라 매핑이 역함수이기만
 *    하면 항등이다 — 바꿔 말하면 왕복이 잡는 것은 역함수성 하나뿐이므로, 그 성질을 직접 잰다.
 *    표본은 손으로 적지 않고 **코퍼스 산출 JSON 의 `partial` 값 전량**에서 도출하고(열거는 전수를
 *    증명하지 못한다), 코퍼스에 없는 경계 형태만 합성으로 덧댄다.
 *
 * 2. **양쪽 세계가 각자 자기 표기를 지킨다.** 소스는 `.g7.html` 만 받고(아니면 컴파일 에러),
 *    산출 JSON 은 `.json` 을 유지한다. 이 두 방향을 컴파일러·역컴파일러 산출로 직접 확인한다.
 *
 * 표기가 코퍼스 전량에서 지켜지는가와 매핑 산출물이 실재하는가는
 * {@see PartialReferenceNotationCorpusTest} 가 따로 잰다 — 다른 명제이므로 합치지 않는다.
 */
class PartialReferenceNotationTest extends LayoutMarkupTestCase
{
    /**
     * 코퍼스에서 도출하는 `partial` 값의 하한 — 도출이 죽으면 "위반 0" 이 아니라 "검사 0" 이다.
     *
     * 실측 **422**(`LayoutCorpus::scan('.json')` 634파일, 08-02) 이라 마진이 22(5%)뿐이다.
     * 이 하한이 잡는 실패 모드는 스캐너 사망(0 또는 그 근처)이므로 값 자체는 낮아도 되지만,
     * 조각을 통폐합해 유니크 값이 5% 줄면 **위반이 없는데 red** 가 난다. 그때 통과시키려
     * 이 상수를 내리지 말고 먼저 재계량해서 줄어든 것이 도출인지 코퍼스인지 가른다.
     */
    private const CORPUS_PARTIAL_MINIMUM = 400;

    /**
     * 코퍼스에 없는 경계 형태 — 표본을 **덧대는** 용도이지 대체하는 용도가 아니다.
     *
     * @var list<string>
     */
    private const SYNTHETIC_JSON_VALUES = [
        '_x.json',
        'partials/a/_x.json',
        '../common/_x.json',
        // 경로 중간의 확장자는 건드리지 않는다 — 접미사 치환의 범위를 못박는다.
        'a.json/b.json',
        '_x.g7.html.json',
    ];

    /**
     * 매핑이 정확한 역함수다 — 표본은 코퍼스 산출에서 도출한다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects partial_reference_notation_is_an_exact_inverse_on_corpus_values
     */
    public function test_notation_mapping_is_an_exact_inverse(): void
    {
        $corpusValues = $this->corpusPartialValues();

        $this->assertGreaterThanOrEqual(self::CORPUS_PARTIAL_MINIMUM, count($corpusValues),
            '코퍼스 `partial` 값 도출이 '.count($corpusValues).'건이다 — 표본이 무너지면 아래 성질이 공허하게 참이 된다');

        foreach ([...$corpusValues, ...self::SYNTHETIC_JSON_VALUES] as $json) {
            $markup = PartialReferenceNotation::toMarkup($json);

            $this->assertTrue(PartialReferenceNotation::isAuthoringNotation($markup),
                "산출 표기 '{$json}' 를 마크업 표기로 못 바꾼다");

            $this->assertSame($json, PartialReferenceNotation::toJson($markup),
                "왕복이 원본으로 돌아오지 않는다: '{$json}' → '{$markup}' → '"
                .PartialReferenceNotation::toJson($markup)."'");
        }
    }

    /**
     * 접미사가 아닌 자리의 확장자는 건드리지 않는다 (매핑 범위를 좁게 못박는다).
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects partial_reference_notation_only_rewrites_the_trailing_extension
     */
    public function test_notation_rewrites_only_the_trailing_extension(): void
    {
        $this->assertSame('a.json/b.g7.html', PartialReferenceNotation::toMarkup('a.json/b.json'));
        $this->assertSame('a.g7.html/b.json', PartialReferenceNotation::toJson('a.g7.html/b.g7.html'));

        // 확장자가 없거나 다른 값은 원본 그대로 — 여기서 임의로 붙이면 규약 위반이 정상처럼 보인다.
        $this->assertSame('_x', PartialReferenceNotation::toJson('_x'));
        $this->assertSame('_x.txt', PartialReferenceNotation::toMarkup('_x.txt'));
        $this->assertFalse(PartialReferenceNotation::isAuthoringNotation('.g7.html'));
        $this->assertFalse(PartialReferenceNotation::isJsonNotation('.json'));

        // 대소문자가 다른 표기는 인식하지 않는다 — 인식하면 매핑이 역함수가 아니게 되고
        // (`_X.JSON` → `_X.g7.html` → `_X.json`), 왕복 게이트가 잡는 유일한 성질이 깨진다.
        // 그 값은 컴파일러가 표기 오류로 세운다(조용히 뭉개지 않는다).
        $this->assertFalse(PartialReferenceNotation::isJsonNotation('_X.JSON'));
        $this->assertFalse(PartialReferenceNotation::isAuthoringNotation('_X.G7.HTML'));
        $this->assertSame('_X.JSON', PartialReferenceNotation::toMarkup('_X.JSON'));
    }

    /**
     * 소스는 마크업 표기만 받고, 산출 JSON 은 `.json` 을 유지한다 (`<include>`·`<modal>` 양쪽).
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects include_and_modal_src_compile_to_json_notation
     */
    public function test_both_reference_tags_emit_json_notation(): void
    {
        $document = $this->compile(<<<'HTML'
            <layout>
              <modal src="partials/x/_modal_delete.g7.html"/>
              <div><include src="_summary.g7.html"/></div>
            </layout>
            HTML);

        $this->assertSame('partials/x/_modal_delete.json', $document['modals'][0]['partial'],
            '`<modal src>` 산출이 `.json` 표기를 유지하지 않는다');

        $this->assertSame('_summary.json', $document['components'][0]['children'][0]['partial'],
            '`<include src>` 산출이 `.json` 표기를 유지하지 않는다');
    }

    /**
     * 규약 밖 표기는 컴파일 에러다 — 조용히 통과시키면 그 값이 산출물 안에서 정상처럼 보인다.
     *
     * @scenario compile_unit=layout,source_health=invalid
     *
     * @effects non_authoring_partial_src_is_a_compile_error
     */
    public function test_non_authoring_src_is_rejected_on_both_tags(): void
    {
        $cases = [
            '<include src>' => '<layout><div><include src="_summary.json"/></div></layout>',
            '<modal src>' => '<layout><modal src="_modal.json"/><div/></layout>',
            '확장자 없음' => '<layout><div><include src="_summary"/></div></layout>',
        ];

        foreach ($cases as $label => $markup) {
            try {
                $this->compile($markup);
                $this->fail($label.' — 규약 밖 표기가 통과했다');
            } catch (CompileError $e) {
                $this->assertSame(LayoutMarkupErrorCode::PartialSrcNotAuthoring, $e->getErrorCode(),
                    $label.' — 다른 에러 코드로 거부됐다: '.$e->getErrorCode()->value);
            }
        }
    }

    /**
     * `<modal src>` 는 **문서의 modals 선언 자리에서만** 성립한다 — 노드 자리에서는 거부한다.
     *
     * 노드 자리(`<partial>` 본문·컴포넌트 자식)의 `<modal>` 은 Modal **컴포넌트**로 해석되므로,
     * 거기 붙은 `src` 는 조각 참조가 아니라 **아무도 읽지 않는 prop** 이 된다. 표기 검사
     * ({@see DocumentCompiler}) 는 선언 자리에만 걸려 있어 이 자리를 보지 못했고, 그래서 규약 밖
     * 표기가 노드 자리에 있으면 컴파일이 **통과**했다. 산출물에는 `"props":{"src":"..."}` 가 남고
     * 화면에는 아무 창도 뜨지 않는다 — 조용한 소실이라 여기서 세운다.
     *
     * 실물 표면: 문서 예시 게이트가 봉투를 차례로 씌워 하나라도 통과하면 통과로 치는데,
     * `<modal>` 로 시작하는 펜스는 `<partial>` 봉투가 먼저 통과시켜 표기 검사를 건너뛰었다.
     *
     * @scenario compile_unit=node,source_health=invalid
     *
     * @effects modal_src_in_node_position_is_a_compile_error
     */
    public function test_modal_src_is_rejected_in_node_position(): void
    {
        // 표기가 규약에 맞아도 자리가 틀렸으면 거부한다 — 결함은 표기가 아니라 위치다.
        $cases = [
            '<partial> 본문' => '<partial><modal src="_modal_delete.g7.html"/></partial>',
            '컴포넌트 자식' => '<layout><div><modal src="_modal_delete.g7.html"/></div></layout>',
            '규약 밖 표기까지 겹친 자리' => '<partial><modal src="_modal_delete.json"/></partial>',
        ];

        foreach ($cases as $label => $markup) {
            try {
                $this->compile($markup);
                $this->fail($label.' — 노드 자리의 `<modal src>` 가 통과했다: src 가 죽은 prop 이 된다');
            } catch (CompileError $e) {
                $this->assertSame(LayoutMarkupErrorCode::ModalSrcInNodePosition, $e->getErrorCode(),
                    $label.' — 다른 에러 코드로 거부됐다: '.$e->getErrorCode()->value);
            }
        }

        // 반대쪽으로 죽지 않았음을 같은 자리에서 증명한다 — 노드 자리의 `<modal>` 은 정상 마크업이다
        // (코퍼스 908건). src 만 거부하는 것이지 태그를 거부하는 것이 아니다.
        $document = $this->compile('<partial><modal data-ref="m" title="t"><div/></modal></partial>');

        $this->assertSame('Modal', $document['name'],
            '노드 자리의 `<modal>` 이 컴포넌트로 해석되지 않는다 — 규약 밖으로 넓게 잡았다');
    }

    /**
     * 역컴파일이 두 자리 모두 마크업 표기로 되돌린다 (재생성이 규약을 되돌리지 않는다).
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects decompiled_markup_restores_authoring_notation_on_both_tags
     */
    public function test_decompiler_restores_authoring_notation(): void
    {
        $markup = (new LayoutMarkupDecompiler($this->registry()))->decompile([
            'modals' => [['partial' => 'partials/x/_modal_delete.json']],
            'components' => [['type' => 'basic', 'name' => 'Div', 'children' => [['partial' => '_summary.json']]]],
        ]);

        $this->assertStringContainsString('src="partials/x/_modal_delete.g7.html"', $markup,
            '`<modal>` 역컴파일이 산출 표기를 그대로 옮긴다 — 재생성이 규약을 조용히 되돌린다');

        $this->assertStringContainsString('src="_summary.g7.html"', $markup,
            '`<include>` 역컴파일이 산출 표기를 그대로 옮긴다');

        $this->assertStringNotContainsString('.json"', $markup,
            '역컴파일 산출에 `.json` 표기가 남았다');
    }

    /**
     * 컴포넌트 레지스트리 — 다른 컴파일러 테스트와 같은 판본을 쓴다.
     *
     * @return ComponentRegistry 레지스트리
     */
    private function registry(): ComponentRegistry
    {
        return ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
    }

    /**
     * 마크업을 문서 JSON 으로 컴파일합니다.
     *
     * @param  string  $markup  마크업
     * @return array<string, mixed> 문서 JSON
     */
    private function compile(string $markup): array
    {
        $roots = (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($markup, 'probe.g7.html'), 'probe.g7.html');

        return (new DocumentCompiler($this->registry()))->compile($roots, 'probe.g7.html');
    }

    /**
     * 코퍼스 산출 JSON 에서 `partial` 값을 전수 도출합니다 (중복 제거).
     *
     * @return list<string> `.json` 표기 값 목록
     */
    private function corpusPartialValues(): array
    {
        $values = [];

        foreach (LayoutCorpus::scan('.json') as $file) {
            preg_match_all('/"partial"\s*:\s*"([^"]+)"/', File::get($file), $matches);

            foreach ($matches[1] as $value) {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }
}
