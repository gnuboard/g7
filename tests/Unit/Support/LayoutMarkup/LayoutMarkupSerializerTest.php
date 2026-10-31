<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutMarkupSerializer;
use App\Support\LayoutMarkup\MarkupAttribute;
use App\Support\LayoutMarkup\MarkupElement;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 직렬화기(S9) 단위 테스트 — 요소 트리를 토크나이저가 똑같이 되읽는 문자열로 찍는다.
 *
 * 직렬화기의 유일한 계약은 토크나이저(S1) 어휘 규칙 준수다: 이스케이프·인용·void·self-closing·주석.
 * "찍은 것을 다시 토큰화·트리화하면 원래 요소 그래프가 나온다" 를 왕복으로 확인한다.
 */
class LayoutMarkupSerializerTest extends TestCase
{
    private LayoutMarkupSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serializer = new LayoutMarkupSerializer;
    }

    /**
     * 직렬화가 마크업 표기를 그대로 되돌린다 — 이스케이프·불리언 속성·따옴표 선택·void 처리.
     *
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects serializer_emits_authoring_notation_verbatim
     */
    public function test_escapes_ampersand_and_lt_in_text(): void
    {
        $element = $this->el('span', [], [], '{{a && b < c}}');
        $markup = $this->serializer->serialize($element);

        $this->assertStringContainsString('{{a &amp;&amp; b &lt; c}}', $markup);
        $this->assertSame('{{a && b < c}}', $this->reparse($markup)->text);
    }

    public function test_boolean_attribute_emits_name_only(): void
    {
        $markup = $this->serializer->serialize($this->el('field', [$this->attr('required', null)]));

        $this->assertStringContainsString('<field required/>', $markup);
        $this->assertTrue($this->reparse($markup)->attribute('required')->isBoolean());
    }

    public function test_empty_string_value_is_distinct_from_boolean(): void
    {
        $markup = $this->serializer->serialize($this->el('field', [$this->attr('value', '')]));

        $this->assertStringContainsString('value=""', $markup);
        $this->assertSame('', $this->reparse($markup)->attribute('value')->value);
    }

    public function test_single_quote_chosen_when_value_contains_double_quote(): void
    {
        $markup = $this->serializer->serialize($this->el('div', [$this->attr(':sources', '["a","b"]')]));

        $this->assertStringContainsString(":sources='[\"a\",\"b\"]'", $markup);
        $this->assertSame('["a","b"]', $this->reparse($markup)->attribute(':sources')->value);
    }

    public function test_self_closing_versus_empty_element(): void
    {
        $this->assertStringContainsString('<div/>', $this->serializer->serialize($this->el('div')));
        $this->assertStringContainsString('<span></span>', $this->serializer->serialize($this->el('span', [], [], '')));
    }

    public function test_void_element_never_gets_end_tag(): void
    {
        $markup = $this->serializer->serialize($this->el('img', [$this->attr('src', '/a.png')]));

        $this->assertStringContainsString('<img src="/a.png"/>', $markup);
        $this->assertStringNotContainsString('</img>', $markup);
    }

    public function test_comment_attaches_to_following_element(): void
    {
        $element = $this->el('div', [], [$this->el('span', [], [], 'x', '주석')]);
        $markup = $this->serializer->serialize($element);

        $this->assertStringContainsString('<!-- 주석 -->', $markup);
        $this->assertSame('주석', $this->reparse($markup)->children[0]->comment);
    }

    public function test_text_node_preserves_leading_space(): void
    {
        $markup = $this->serializer->serialize($this->el('span', [], [], ' 라벨'));

        $this->assertSame(' 라벨', $this->reparse($markup)->text);
    }

    /**
     * 글자와 자식을 함께 가진 요소도 자식은 들여쓰기로 전개한다.
     *
     * 종전에는 서브트리 전체를 한 줄로 찍었다 — `<button>` 하나가 1,800자를 넘어 소스가 읽히지
     * 않는 원인이었다. 트리 빌더의 `normalizeText` 가 텍스트 **앞뒤**의 개행+공백만 걷어내므로,
     * 텍스트 런이 하나이고 그 앞뒤 공백이 인덴트뿐이면 줄을 나눠도 굽기 결과가 같다.
     *
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects text_bearing_element_indents_children_when_whitespace_is_not_meaningful
     */
    public function test_text_bearing_element_with_children_is_broken_across_lines(): void
    {
        $element = $this->el('button', [$this->attr('type', 'button')], [
            $this->el('on', [$this->attr('type', 'click')], [
                $this->el('set-state', [$this->attr('target', 'local')]),
            ]),
        ], '라벨');

        $markup = $this->serializer->serialize($element);

        // 단언은 **자식이 자기 줄에 인덴트와 함께 있는가** 로 잡는다. `">\n"` 같은 느슨한 형태는
        // 끝태그 뒤 개행에도 매칭돼 종전 동작에서도 통과한다(첫 판본이 실제로 그렇게 초록이었다).
        $this->assertStringContainsString("<button type=\"button\">\n  <on type=\"click\">\n    <set-state", $markup,
            '글자를 가진 요소의 자식이 한 줄에 눌려 있다 — 소스가 읽히지 않는다');

        $reparsed = $this->reparse($markup);

        $this->assertSame('라벨', $reparsed->text, '줄바꿈이 텍스트를 바꿨다');
        $this->assertSame('on', $reparsed->children[0]->name);
        $this->assertSame('set-state', $reparsed->children[0]->children[0]->name);
    }

    /**
     * 텍스트 앞뒤 공백이 의미를 가지면 한 줄을 유지한다 — 줄바꿈이 그 공백을 먹기 때문이다.
     *
     * `normalizeText` 는 개행이 섞인 앞뒤 공백을 인덴트로 보고 걷어낸다. 들여쓰기로 펼치면
     * `" — {{v}}"` 의 선행 공백이 그 인덴트에 흡수돼 값이 조용히 달라진다.
     *
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects meaningful_edge_whitespace_keeps_the_element_inline
     */
    public function test_element_with_meaningful_edge_whitespace_stays_inline(): void
    {
        $element = $this->el('span', [], [
            $this->el('on', [$this->attr('type', 'click')]),
        ], ' 라벨 ');

        $markup = $this->serializer->serialize($element);

        $this->assertStringNotContainsString("\n", trim($markup, "\n"),
            '앞뒤 공백이 의미를 갖는 텍스트인데 줄을 나눴다 — 그 공백이 인덴트로 흡수된다');
        $this->assertSame(' 라벨 ', $this->reparse($markup)->text);
    }

    /**
     * 자식 없는 글자 요소는 그대로 한 줄이다 (줄을 나눌 이유가 없다).
     *
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects text_only_element_stays_on_a_single_line
     */
    public function test_text_only_element_stays_on_a_single_line(): void
    {
        $markup = $this->serializer->serialize($this->el('span', [], [], '라벨'));

        $this->assertSame('<span>라벨</span>', trim($markup, "\n"));
    }

    /**
     * 주석은 요소 종류와 무관하게 자기 줄에 선다.
     *
     * 종전에는 텍스트 없는 요소만 주석을 윗줄에 두고, 텍스트를 가진 요소는 `<!-- … --><span …>`
     * 처럼 한 줄에 붙였다 — 같은 문법이 자리에 따라 다르게 찍히는 불일치이고, 긴 주석이 붙으면
     * 그 줄이 통째로 읽히지 않는다. 주석 위치는 텍스트 공백에 닿지 않으므로 분리해도 무손실이다.
     *
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects comment_takes_its_own_line_regardless_of_element_shape
     */
    public function test_comment_takes_its_own_line_even_on_a_text_bearing_element(): void
    {
        $element = $this->el('div', [], [$this->el('span', [], [], '라벨', '설명 주석')]);

        $markup = $this->serializer->serialize($element);

        $this->assertStringContainsString("  <!-- 설명 주석 -->\n  <span>라벨</span>", $markup,
            '텍스트를 가진 요소의 주석이 같은 줄에 붙어 있다 — 주석이 길면 그 줄을 읽을 수 없다');
        $this->assertSame('설명 주석', $this->reparse($markup)->children[0]->comment);
        $this->assertSame('라벨', $this->reparse($markup)->children[0]->text);
    }

    /**
     * @param  array<int, MarkupAttribute>  $attributes
     * @param  array<int, MarkupElement>  $children
     */
    private function el(string $name, array $attributes = [], array $children = [], ?string $text = null, ?string $comment = null): MarkupElement
    {
        return new MarkupElement($name, $attributes, $children, $text, $comment, $text === null && $children === [], 0, 0);
    }

    private function attr(string $name, ?string $value): MarkupAttribute
    {
        return new MarkupAttribute($name, $value, 0, 0, null);
    }

    private function reparse(string $markup): MarkupElement
    {
        return (new MarkupTreeBuilder)->build((new MarkupTokenizer)->tokenize($markup), null)[0];
    }
}
