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
     * 직렬화가 저작 표기를 그대로 되돌린다 — 이스케이프·불리언 속성·따옴표 선택·void 처리.
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
