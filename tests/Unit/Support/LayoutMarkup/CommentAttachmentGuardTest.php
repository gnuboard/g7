<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutMarkupDecompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use App\Support\LayoutMarkup\MarkupElement;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;

/**
 * 주석 귀속 폐쇄 가드 (S21).
 *
 * 지키는 명제는 하나다 — **주석은 산출 JSON 에 남거나 컴파일 에러로 거부된다. 조용히 사라지지
 * 않는다.**
 *
 * 이 가드가 생긴 경위: S21 착수 실측이 `<on>` 앞 주석이 lint 경고도 컴파일 에러도 없이 사라지는
 * 것을 잡았고, 코퍼스 전 태그 스윕으로 재니 그런 자리가 **65종**이었다. 왕복 게이트는
 * JSON→마크업→JSON 이라 마크업에만 있던 주석의 소실을 구조적으로 볼 수 없다.
 *
 * 판정을 **태그 목록으로 적지 않는다**. 같은 태그라도 자리에 따라 갈리기 때문이다 — Modal 자식
 * `<footer>` 는 `props.footer` 로 접혀 주석 자리가 없고, 같은 이름의 `Footer` 컴포넌트 노드는
 * 있다. 그래서 판정은 언제나 **컴파일 산출과의 대조**이고, 대상 태그 목록은 코퍼스에서 도출한다.
 */
class CommentAttachmentGuardTest extends LayoutMarkupTestCase
{
    private const PROBE = 'G7COMMENTPROBE';

    /** 코퍼스에서 도출되는 시험 대상 태그 수의 하한 (도출이 죽으면 "위반 0" 이 아니라 "판정 불가"다). */
    private const MIN_TAGS = 180;

    private LayoutMarkupCompiler $compiler;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new LayoutMarkupCompiler(
            ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json')),
        );
    }

    /**
     * `<on>` 블록 앞 주석은 자리가 없어 거부된다.
     *
     * @scenario compile_unit=facade,source_health=invalid
     *
     * @effects comment_before_on_block_is_a_compile_error
     *
     * @return void 반환값 없음
     */
    public function test_comment_before_on_block_is_rejected(): void
    {
        try {
            $this->compiler->compile(<<<'HTML'
            <partial description="probe">
              <button data-ref="b1">
                <!-- 저장 흐름 -->
                <on type="click">
                  <toast message="$t:common.saved" type="success"/>
                </on>
              </button>
            </partial>
            HTML);
            $this->fail('CompileError 를 기대했으나 통과했습니다 — <on> 앞 주석이 조용히 사라집니다.');
        } catch (CompileError $error) {
            $this->assertSame(LayoutMarkupErrorCode::CommentNotAttachable, $error->getErrorCode());
            $this->assertStringContainsString('sequence', $error->getMessage());
        }
    }

    /**
     * 자식 액션이 하나뿐인 블록의 주석이 보존된다.
     *
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects comment_on_first_action_lands_in_actions_entry
     *
     * @return void 반환값 없음
     */
    public function test_comment_on_single_child_action_is_preserved(): void
    {
        $result = $this->compiler->compile(<<<'HTML'
        <partial description="probe">
          <button data-ref="b1">
            <on type="click">
              <!-- 저장 흐름 -->
              <toast message="$t:common.saved" type="success"/>
            </on>
          </button>
        </partial>
        HTML);

        $this->assertSame('저장 흐름', $result->json['actions'][0]['comment']);
    }

    /**
     * 명시적 순차 실행 블록의 주석이 보존된다.
     *
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects comment_on_explicit_sequence_lands_in_actions_entry
     *
     * @return void 반환값 없음
     */
    public function test_comment_on_explicit_sequence_is_preserved(): void
    {
        $result = $this->compiler->compile(<<<'HTML'
        <partial description="probe">
          <button data-ref="b1">
            <on type="click">
              <!-- 저장하고 토스트 -->
              <sequence>
                <toast message="$t:common.saved" type="success"/>
                <close-modal target="m1"/>
              </sequence>
            </on>
          </button>
        </partial>
        HTML);

        $this->assertSame('저장하고 토스트', $result->json['actions'][0]['comment']);
        $this->assertSame('sequence', $result->json['actions'][0]['handler']);
    }

    /**
     * 모달 조각 참조 앞 주석이 보존된다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects comment_before_modal_src_lands_in_modals_entry
     *
     * @return void 반환값 없음
     */
    public function test_comment_before_modal_src_is_preserved(): void
    {
        $result = $this->compiler->compile(<<<'HTML'
        <layout name="sample" version="1.0.0">
          <div class="x"/>
          <!-- 삭제 확인 창 -->
          <modal src="partials/_modal_delete.g7.html"/>
        </layout>
        HTML);

        $this->assertSame('삭제 확인 창', $result->json['modals'][0]['comment']);
        $this->assertSame('partials/_modal_delete.json', $result->json['modals'][0]['partial']);
    }

    /**
     * 인라인 모달 감싸개 앞 주석은 노드 주석으로 올라간다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects wrapper_comment_hoists_onto_the_inline_modal_node
     *
     * @return void 반환값 없음
     */
    public function test_comment_before_inline_modal_wrapper_hoists_to_node(): void
    {
        $result = $this->compiler->compile(<<<'HTML'
        <layout name="sample" version="1.0.0">
          <div class="x"/>
          <!-- 재고 조정 창 -->
          <modal>
            <modal data-ref="stock_modal" title="재고 조정"/>
          </modal>
        </layout>
        HTML);

        $this->assertSame('재고 조정 창', $result->json['modals'][0]['comment']);
        $this->assertSame('stock_modal', $result->json['modals'][0]['id']);
    }

    /**
     * 감싸개 주석과 노드 주석이 겹치면 거부된다.
     *
     * @scenario compile_unit=document,source_health=invalid
     *
     * @effects wrapper_and_node_comment_collision_is_a_compile_error
     *
     * @return void 반환값 없음
     */
    public function test_wrapper_and_node_comment_collision_is_rejected(): void
    {
        try {
            $this->compiler->compile(<<<'HTML'
            <layout name="sample" version="1.0.0">
              <div class="x"/>
              <!-- 바깥 설명 -->
              <modal>
                <!-- 안쪽 설명 -->
                <modal data-ref="stock_modal" title="재고 조정"/>
              </modal>
            </layout>
            HTML);
            $this->fail('CompileError 를 기대했으나 통과했습니다 — 두 주석 중 하나가 사라집니다.');
        } catch (CompileError $error) {
            $this->assertSame(LayoutMarkupErrorCode::CommentNotAttachable, $error->getErrorCode());
        }
    }

    /**
     * 모달 조각 참조 주석이 왕복해도 그대로다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects modals_reference_comment_survives_a_full_round_trip
     *
     * @return void 반환값 없음
     */
    public function test_modal_src_comment_round_trips(): void
    {
        $source = <<<'HTML'
        <layout name="sample" version="1.0.0">
          <div class="x"/>
          <!-- 삭제 확인 창 -->
          <modal src="partials/_modal_delete.g7.html"/>
        </layout>
        HTML;

        $registry = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
        $json = $this->compiler->compile($source)->json;

        $markup = (new LayoutMarkupDecompiler($registry))->decompile($json);
        $again = $this->compiler->compile($markup)->json;

        $this->assertStringContainsString('<!-- 삭제 확인 창 -->', $markup);
        $this->assertSame($json, $again);
    }

    /**
     * 코퍼스에 실재하는 모든 태그 자리에서 "보존 아니면 거부" 를 전수 확인한다.
     *
     * 태그 목록은 손으로 적지 않고 소스 코퍼스에서 도출하며, 도출이 죽는 경우를 "위반 0" 과
     * 구분하기 위해 태그 수 하한을 단언한다.
     *
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects no_tag_position_in_the_corpus_drops_a_comment_silently
     *
     * @return void 반환값 없음
     */
    public function test_no_corpus_tag_position_drops_a_comment_silently(): void
    {
        $tokenizer = new MarkupTokenizer;
        $builder = new MarkupTreeBuilder;
        $resolver = new MarkupCompileContextResolver;

        $sites = [];
        $sources = [];

        foreach (LayoutCorpus::scan('.g7.html') as $absolute) {
            $source = (string) file_get_contents($absolute);
            $sources[$absolute] = $source;

            $found = [];
            $this->collectSites($builder->build($tokenizer->tokenize($source, $absolute), $absolute), $found);

            foreach ($found as $site) {
                if (isset($sites[$site['tag']])) {
                    continue;
                }

                $sites[$site['tag']] = $site + ['file' => $absolute];
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_TAGS,
            count($sites),
            '코퍼스에서 도출한 시험 대상 태그가 하한보다 적습니다 — 도출이 죽었을 수 있습니다(위반 0 이 아니라 판정 불가).',
        );

        $compilers = [];
        $silent = [];
        $unreachable = [];
        $wrongCode = [];
        $reached = 0;

        foreach ($sites as $tag => $site) {
            $absolute = $site['file'];
            $directory = dirname($absolute);
            $where = '<'.$tag.'> — '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $absolute).':'.$site['line'];

            if (! array_key_exists($directory, $compilers)) {
                // 검사용 레지스트리는 템플릿 자신의 파일에만 있다 — 없으면 템플릿 없이 컴파일한다(설치와 같은 조건).
                $compilers[$directory] = new LayoutMarkupCompiler($resolver->registryFor($absolute, null));
            }

            // 아래 두 탈출구는 "위반 없음" 이 아니라 "판정 못 함" 이다 — 조용히 넘기면 그 태그는
            // 검사받은 적이 없으면서 초록에 포함된다. 세어서 따로 단언한다.
            if ($compilers[$directory] === null) {
                $unreachable[] = $where.' (레지스트리 해석 실패)';

                continue;
            }

            $offset = $this->offsetOf($sources[$absolute], $site['line'], $site['column']);

            if ($offset === null) {
                $unreachable[] = $where.' (좌표 → 오프셋 역산 실패)';

                continue;
            }

            $patched = substr($sources[$absolute], 0, $offset)
                .'<!-- '.self::PROBE.' -->'
                .substr($sources[$absolute], $offset);

            $reached++;

            try {
                $json = $compilers[$directory]->compile($patched, $absolute)->json;
            } catch (CompileError $e) {
                // 거부는 명제 충족이지만 **그 이유가 주석 때문일 때만** 그렇다. 무관한 컴파일
                // 실패(문법 오류·미등록 태그 …)를 충족으로 세면 "위반 0" 과 "검사 실패" 가 같아진다.
                if ($e->getErrorCode() !== LayoutMarkupErrorCode::CommentNotAttachable) {
                    $wrongCode[] = $where.' — '.$e->getErrorCode()->value;
                }

                continue;
            }

            if (! str_contains(json_encode($json, JSON_UNESCAPED_UNICODE), self::PROBE)) {
                $silent[] = $where;
            }
        }

        $this->assertSame(
            [],
            $unreachable,
            "프로브가 컴파일러에 도달하지 못한 자리 — 검사받지 않은 채 초록에 포함된다:\n"
            .implode("\n", $unreachable),
        );

        $this->assertSame(
            [],
            $wrongCode,
            "주석과 무관한 이유로 거부된 자리 — 이 자리의 명제는 확인되지 않았다:\n".implode("\n", $wrongCode),
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_TAGS,
            $reached,
            '프로브가 실제로 컴파일된 자리가 '.$reached.'건 — 도출은 살아 있어도 도달이 죽으면 '
            .'같은 초록이 나온다(위반 0 이 아니라 판정 불가).',
        );

        $this->assertSame(
            [],
            $silent,
            "주석이 산출에도 없고 거부되지도 않는 자리:\n".implode("\n", $silent),
        );
    }

    /**
     * 주석이 아직 없는 요소 자리를 (태그, 줄, 열) 로 모읍니다.
     *
     * @param  array<int, MarkupElement>  $elements  요소 목록
     * @param  array<int, array{tag: string, line: int, column: int}>  $out  수집 결과 (참조)
     * @return void 반환값 없음
     */
    private function collectSites(array $elements, array &$out): void
    {
        foreach ($elements as $element) {
            if ($element->comment === null) {
                $out[] = ['tag' => $element->name, 'line' => $element->line, 'column' => $element->column];
            }

            $this->collectSites($element->children, $out);
        }
    }

    /**
     * (줄, 열) 좌표를 소스의 바이트 오프셋으로 바꿉니다.
     *
     * @param  string  $source  소스
     * @param  int  $line  줄 (1-base)
     * @param  int  $column  열 (1-base)
     * @return int|null 오프셋, 좌표가 소스 밖이면 null
     */
    private function offsetOf(string $source, int $line, int $column): ?int
    {
        $offset = 0;

        for ($current = 1; $current < $line; $current++) {
            $newline = strpos($source, "\n", $offset);

            if ($newline === false) {
                return null;
            }

            $offset = $newline + 1;
        }

        // 열은 **문자** 단위이고 오프셋은 바이트 단위다. 같은 줄 앞쪽에 한글이 있으면 그 차이만큼
        // 프로브가 태그 안쪽에 박혀, 주석과 무관한 문법 오류로 거부되고 그 자리는 검사받지 못한다
        // (실측 — `_identity_challenge_modal.g7.html:34` 한 자리가 그렇게 조용히 빠져 있었다).
        $lineEnd = strpos($source, "\n", $offset);
        $lineText = $lineEnd === false ? substr($source, $offset) : substr($source, $offset, $lineEnd - $offset);

        if (mb_strlen($lineText) < $column - 1) {
            return null;
        }

        $offset += strlen(mb_substr($lineText, 0, $column - 1));

        return $offset > strlen($source) ? null : $offset;
    }
}
