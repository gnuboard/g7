<?php

namespace Tests\Unit\Seo;

use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

/**
 * 봇(SEO) 화면 평가기의 `$t:키|이름={{값}}` 인자 분리 회귀 테스트 (7.1.1 출시 전 실측)
 *
 * 브라우저 렌더러는 `$t:` 인자 자리의 바인딩 값을 보호해 값의 `&`·`|`·`=` 를 구분자로 읽지 않는다(engine-v1.80.1).
 * 봇 화면 평가기는 인자를 나눈 뒤 바인딩을 풀어 값 글자는 지켰지만, 인자를 `|` 로 나눌 때 `{{…}}` 식 안의
 * 파이프(`{{v | date}}`)·`||` 까지 잘라 값이 사라지거나 식 조각이 문장에 섞였다. 인자를 나눌 때 식 구간을 보호한다.
 */
class ExpressionEvaluatorTranslationParamTest extends TestCase
{
    private ExpressionEvaluator $evaluator;

    /**
     * 테스트 초기화 — 번역 사전을 넣은 평가기를 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new ExpressionEvaluator;
        $this->evaluator->setTranslations([
            'common' => [
                'select_item' => '{{name}} 선택',
                'row_cell' => '{{row}} {{column}}',
            ],
            'mypage' => ['updated_at' => '{{value}} 수정'],
        ]);
    }

    /**
     * 바인딩 값에 `&`·`|`·`=`·끝 ` /` 가 들어도 값이 잘리지 않습니다 (브라우저 렌더러와 같은 결과).
     *
     * @scenario entry_point=evaluate, expression_form=translation_key
     *
     * @effects seo_translation_param_value_kept_verbatim
     *
     * @return void 없음
     */
    public function test_bound_value_with_separators_is_kept(): void
    {
        $value = 'Tom & Jerry | a=b 머그컵 /';

        $result = $this->evaluator->evaluate('$t:common.select_item|name={{item.name}}', ['item' => ['name' => $value]]);

        $this->assertSame($value.' 선택', $result);
    }

    /**
     * 인자 식 안의 `||`·파이프는 인자 구분자가 아닙니다 — 식 전체를 값으로 풉니다.
     *
     * @scenario entry_point=evaluate, expression_form=translation_key
     *
     * @effects seo_translation_param_expression_not_split
     *
     * @return void 없음
     */
    public function test_pipe_and_logical_or_inside_binding_are_not_separators(): void
    {
        $this->assertSame(
            '대체 이름 선택',
            $this->evaluator->evaluate("\$t:common.select_item|name={{item.name || '대체 이름'}}", ['item' => ['name' => '']]),
        );

        $this->assertSame(
            '2026-10-17 수정',
            $this->evaluator->evaluate('$t:mypage.updated_at|value={{item.updated_at | date}}', ['item' => ['updated_at' => '2026-10-17 12:34:56']]),
        );
    }

    /**
     * 인자가 여럿이고 그중 일부가 글자 그대로여도 종전과 같습니다.
     *
     * @scenario entry_point=evaluate, expression_form=translation_key
     *
     * @effects seo_translation_param_value_kept_verbatim
     *
     * @return void 없음
     */
    public function test_multiple_params_unchanged(): void
    {
        $this->assertSame(
            '3번째 행 판매가',
            $this->evaluator->evaluate('$t:common.row_cell|row={{row}}|column=판매가', ['row' => '3번째 행']),
        );
    }

    /**
     * 리터럴만 있는 인자 식(`{{1}}`·`{{'abc'}}`·`{{true}}`)은 값으로 풉니다 — 브라우저 렌더러(engine-v1.80.1)와 같은 결과.
     *
     * @scenario entry_point=evaluate, expression_form=translation_key
     *
     * @effects seo_translation_param_value_kept_verbatim
     *
     * @return void 없음
     */
    public function test_literal_only_param_expressions_resolve_to_values(): void
    {
        $this->evaluator->setTranslations(['count' => ['of' => '{{count}}/{{max}}개'], 'common' => ['select_item' => '{{name}} 선택']]);

        $this->assertSame('1/3개', $this->evaluator->evaluate('$t:count.of|count={{1}}|max={{3}}', ['route' => []]));
        $this->assertSame('abc 선택', $this->evaluator->evaluate("\$t:common.select_item|name={{'abc'}}", ['route' => []]));
        $this->assertSame('true 선택', $this->evaluator->evaluate('$t:common.select_item|name={{true}}', ['route' => []]));
    }

    /**
     * 빈 인자 값은 빈 글자로 치환합니다 — 봇 화면은 자리표시자(`{{name}}`)를 색인 HTML 에 싣지 않습니다.
     *
     * 브라우저 렌더러는 `$t:키|이름=` 의 값이 비면 그 인자를 빼서 자리표시자가 남는다(빈 인자 진단 단서 —
     * TranslationEngine 「빈 파라미터 값 처리」 시험). 봇 화면은 없는 번역 키를 빈 글자로 내는 것과 같은 이유로
     * 자리표시자를 내지 않는다 — 검색 결과 요약에 `{{name}}` 이 실리면 안 된다. 의도된 차이다(seo-system.md 노드 키 표).
     *
     * @scenario entry_point=evaluate, expression_form=translation_key
     *
     * @effects seo_translation_empty_param_renders_empty
     *
     * @return void 없음
     */
    public function test_empty_param_value_renders_empty_text(): void
    {
        $this->evaluator->setTranslations(['count' => ['of' => '{{count}}/{{max}}개'], 'common' => ['select_item' => '{{name}} 선택']]);

        $this->assertSame(' 선택', $this->evaluator->evaluate('$t:common.select_item|name={{item.nope}}', ['item' => []]));
        $this->assertSame(' 선택', $this->evaluator->evaluate("\$t:common.select_item|name={{''}}", ['route' => []]));
        $this->assertSame(' 선택', $this->evaluator->evaluate('$t:common.select_item|name=', ['route' => []]));
        $this->assertSame('1/개', $this->evaluator->evaluate('$t:count.of|count={{1}}|max={{item.nope}}', ['item' => []]));

        foreach (['$t:common.select_item|name={{item.nope}}', '$t:count.of|count={{1}}|max={{item.nope}}'] as $expression) {
            $this->assertStringNotContainsString('{{', $this->evaluator->evaluate($expression, ['item' => []]), $expression);
        }
    }
}
