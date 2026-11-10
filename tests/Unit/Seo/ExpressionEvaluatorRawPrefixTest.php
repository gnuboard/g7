<?php

namespace Tests\Unit\Seo;

use App\Seo\ExpressionEvaluator;
use Tests\PureTestCase;

/**
 * SEO(봇) 렌더러의 `{{raw:...}}` 번역 면제 바인딩 패리티 회귀 테스트.
 *
 * 배경: React 쪽 노드 `text` 처리에서 `raw:` 접두사를 벗기지 않아 표현식 평가가
 * 예외로 끊기던 결함(engine-v1.54.4)을 고치면서, 봇 렌더러에는 접두사 처리 자체가
 * 아예 없다는 사실이 드러났다. 같은 표현식이 화면에서는 값이 나오고 봇 화면에서만
 * 사라지는 패리티 붕괴가 잠재해 있었다.
 */
class ExpressionEvaluatorRawPrefixTest extends PureTestCase
{
    /**
     * common.save 번역을 주입한 ExpressionEvaluator 를 준비한다.
     *
     * @return ExpressionEvaluator 번역 사전이 설정된 평가기
     */
    private function evaluator(): ExpressionEvaluator
    {
        $e = new ExpressionEvaluator;
        $e->setTranslations(['common' => ['save' => '저장']]);

        return $e;
    }

    /**
     * {{raw:...}} 바인딩은 접두사를 벗긴 뒤 평가돼 || 폴백 식의 값을 돌려준다.
     *
     * @scenario case=raw_binding
     *
     * @effects raw_prefix_stripped_before_evaluation
     *
     * @return void 반환값 없음
     */
    public function test_raw_prefix_is_stripped_before_evaluation(): void
    {
        $context = ['file' => ['description' => '', 'name' => 'admin_module_list.json']];

        $this->assertSame(
            'admin_module_list.json',
            $this->evaluator()->evaluate('{{raw:file.description || file.name}}', $context),
            'raw: 접두사를 벗기지 않으면 콜론이 식으로 파싱돼 평가가 실패한다'
        );
    }

    /**
     * {{raw:...}} 결과에 들어 있는 $t: 토큰은 번역하지 않고 원문 그대로 남긴다.
     *
     * @scenario case=raw_binding
     *
     * @effects raw_result_translation_exempt
     *
     * @return void 반환값 없음
     */
    public function test_raw_prefix_result_is_translation_exempt(): void
    {
        $context = ['post' => ['title' => '$t:common.save']];

        $this->assertSame(
            '$t:common.save',
            $this->evaluator()->evaluate('{{raw:post.title}}', $context),
            'raw: 결과의 $t: 토큰은 번역하지 않아야 한다 (React rawMarkers 와 동일)'
        );
    }

    /**
     * raw: 접두사가 없는 바인딩은 종전대로 $t: 토큰을 번역 값으로 바꾼다.
     *
     * @scenario case=plain_binding
     *
     * @effects plain_binding_translates_tokens
     *
     * @return void 반환값 없음
     */
    public function test_non_raw_binding_still_resolves_translation_tokens(): void
    {
        $context = ['post' => ['title' => '$t:common.save']];

        $this->assertSame(
            '저장',
            $this->evaluator()->evaluate('{{post.title}}', $context),
            'raw: 가 없으면 종전대로 번역되어야 한다 (비회귀)'
        );
    }

    /**
     * evaluateRaw()도 raw: 접두사를 벗기고 배열 등 원본 타입을 유지해 돌려준다.
     *
     * @scenario case=evaluate_raw
     *
     * @effects evaluate_raw_strips_prefix_keeps_type
     *
     * @return void 반환값 없음
     */
    public function test_evaluate_raw_strips_prefix(): void
    {
        $context = ['items' => [1, 2, 3]];

        $this->assertSame(
            [1, 2, 3],
            $this->evaluator()->evaluateRaw('{{raw:items}}', $context),
            'evaluateRaw 도 접두사를 벗기고 원본 타입을 유지해야 한다'
        );
    }
}
