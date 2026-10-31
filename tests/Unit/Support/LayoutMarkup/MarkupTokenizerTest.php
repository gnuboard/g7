<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Enums\MarkupTokenType;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\MarkupToken;
use App\Support\LayoutMarkup\MarkupTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MarkupTokenizer 단위 테스트 (`.g7.html` 마크업 형식 S1).
 *
 * 검증 축은 셋이다:
 *
 *  1. **정상** — 계획서 예시(본 레이아웃·주입 fragment·partial)가 그대로 토큰화되는가.
 *  2. **직렬화 충실도** — 문자 참조·공백·대괄호 속성명·개행·멀티바이트가 손실 없이
 *     실려 나오는가. 토크나이저가 값을 한 글자라도 바꾸면 상위 층은 그 사실을 알 수
 *     없고, 산출 JSON 이 원본과 달라져 왕복 동등성이 붕괴한다.
 *  3. **엣지·퍼지** — 부분집합을 벗어난 입력이 좌표 붙은 CompileError 로 거부되는가,
 *     그리고 어떤 쓰레기 입력에도 매달리거나 PHP 오류를 내지 않는가.
 */
class MarkupTokenizerTest extends LayoutMarkupTestCase
{
    private MarkupTokenizer $tokenizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenizer = new MarkupTokenizer;
    }

    // ---------------------------------------------------------------------
    // 정상 — 태그·속성
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects start_tag_carries_name_attributes_and_source_position,
     *          end_tag_and_text_and_comment_tokens_are_emitted_in_authoring_order
     */
    public function test_tokenizes_element_with_attributes_children_and_end_tag(): void
    {
        $tokens = $this->tokenizer->tokenize('<div class="admin-card">hello</div>');

        $this->assertCount(3, $tokens);

        $this->assertSame(MarkupTokenType::StartTag, $tokens[0]->type);
        $this->assertSame('div', $tokens[0]->name);
        $this->assertFalse($tokens[0]->selfClosing);
        $this->assertFalse($tokens[0]->void);
        $this->assertSame('class', $tokens[0]->attributes[0]->name);
        $this->assertSame('admin-card', $tokens[0]->attributes[0]->value);
        $this->assertSame('"', $tokens[0]->attributes[0]->quote);

        $this->assertSame(MarkupTokenType::Text, $tokens[1]->type);
        $this->assertSame('hello', $tokens[1]->text);

        $this->assertSame(MarkupTokenType::EndTag, $tokens[2]->type);
        $this->assertSame('div', $tokens[2]->name);
    }

    /**
     * @effects boolean_attribute_value_is_null_and_distinct_from_empty_string
     */
    public function test_boolean_attribute_has_null_value_and_empty_string_value_is_distinct(): void
    {
        $tokens = $this->tokenizer->tokenize('<field required name=""/>');

        $required = $tokens[0]->attribute('required');
        $name = $tokens[0]->attribute('name');

        $this->assertNull($required->value);
        $this->assertTrue($required->isBoolean());
        $this->assertNull($required->quote);

        // 빈 문자열 값은 불리언 부재가 아니다 — 구분이 무너지면 상위 층이 `name=""` 를
        // "속성 없음" 으로 오해한다.
        $this->assertSame('', $name->value);
        $this->assertFalse($name->isBoolean());
    }

    /**
     * @effects self_closing_tag_is_marked_without_end_tag
     */
    public function test_self_closing_tag_is_marked_without_end_tag(): void
    {
        $tokens = $this->tokenizer->tokenize('<data-source id="users"/>');

        $this->assertCount(1, $tokens);
        $this->assertSame('data-source', $tokens[0]->name);
        $this->assertTrue($tokens[0]->selfClosing);
        $this->assertFalse($tokens[0]->void);
    }

    /**
     * @effects void_element_is_auto_closed_without_slash
     */
    public function test_void_element_closes_itself_without_slash(): void
    {
        $tokens = $this->tokenizer->tokenize('<br class="a"><hr><wbr/>');

        $this->assertCount(3, $tokens);

        foreach ($tokens as $token) {
            $this->assertTrue($token->void, "{$token->name} 은 void 여야 한다");
            $this->assertTrue($token->selfClosing, "{$token->name} 은 자동으로 닫혀야 한다");
        }
    }

    /**
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects meta_is_a_container_not_a_void_element
     */
    public function test_meta_is_a_container_not_a_void_element(): void
    {
        // HTML5 표준 void 목록에는 meta 가 있으나, G7 에서 <meta> 는 <seo> 를 품는
        // 문서 메타 섹션이다. void 로 취급하면 <seo> 마크업이 불가능해진다.
        $tokens = $this->tokenizer->tokenize('<meta title="t"><seo :enabled="true"/></meta>');

        $this->assertFalse($tokens[0]->void);
        $this->assertFalse($tokens[0]->selfClosing);
        $this->assertSame('seo', $tokens[1]->name);
        $this->assertSame(MarkupTokenType::EndTag, $tokens[2]->type);
        $this->assertSame('meta', $tokens[2]->name);
    }

    /**
     * @effects script_is_a_normal_element_not_raw_text
     */
    public function test_script_is_a_normal_element_not_raw_text(): void
    {
        // <script> 는 외부 JS 로더 선언이라 본문이 없다 — HTML 의 raw text 규칙을
        // 적용하지 않으므로 안쪽 태그가 텍스트로 뭉개지지 않는다.
        $tokens = $this->tokenizer->tokenize('<script src="//t1.daumcdn.net/x.js" id="daum" :async="true"/>');

        $this->assertCount(1, $tokens);
        $this->assertSame('script', $tokens[0]->name);
        $this->assertSame('//t1.daumcdn.net/x.js', $tokens[0]->attribute('src')->value);
        $this->assertSame('true', $tokens[0]->attribute(':async')->value);
    }

    /**
     * @effects attribute_names_are_preserved_verbatim_including_case_brackets_and_colon
     */
    #[DataProvider('preservedAttributeNameProvider')]
    public function test_attribute_names_are_preserved_verbatim(string $source, string $expected): void
    {
        $tokens = $this->tokenizer->tokenize($source);

        $this->assertSame($expected, $tokens[0]->attributes[0]->name);
    }

    /**
     * 원문 보존이 필요한 속성명 표기.
     *
     * @return array<string, array{0: string, 1: string}> [소스, 기대 속성명]
     */
    public static function preservedAttributeNameProvider(): array
    {
        return [
            'camelCase props' => ['<data-grid :serverSidePagination="true"/>', ':serverSidePagination'],
            'snake_case props' => ['<data-source :auto_fetch="true"/>', ':auto_fetch'],
            'data-* 지시자' => ['<div data-if="{{a}}"/>', 'data-if'],
            '대괄호 리터럴 키' => ['<params filters[0][field]="name"/>', 'filters[0][field]'],
            '점 표기' => ['<api-call body.email="{{x}}"/>', 'body.email'],
            '표준 전역 속성' => ['<div aria-label="x"/>', 'aria-label'],
        ];
    }

    /**
     * @effects tag_names_are_lowercased_while_attribute_case_is_kept
     */
    public function test_tag_names_are_lowercase_while_attribute_case_is_kept(): void
    {
        $tokens = $this->tokenizer->tokenize('<data-grid :serverSidePagination="true"/>');

        $this->assertSame('data-grid', $tokens[0]->name);
        $this->assertSame(':serverSidePagination', $tokens[0]->attributes[0]->name);
    }

    /**
     * @effects attribute_order_is_preserved
     */
    public function test_attribute_order_is_preserved(): void
    {
        $tokens = $this->tokenizer->tokenize('<div id="a" class="b" data-if="{{c}}"/>');

        $this->assertSame(
            ['id', 'class', 'data-if'],
            array_map(static fn ($attribute) => $attribute->name, $tokens[0]->attributes),
        );
    }

    public function test_single_quoted_value_and_spaced_equals_are_accepted(): void
    {
        $tokens = $this->tokenizer->tokenize('<div class = \'a "b" c\'/>');

        $this->assertSame('a "b" c', $tokens[0]->attributes[0]->value);
        $this->assertSame("'", $tokens[0]->attributes[0]->quote);
    }

    /**
     * @effects angle_brackets_inside_quoted_values_pass_through
     */
    public function test_attribute_value_keeps_angle_brackets_and_newlines(): void
    {
        // 인용된 값 안의 `<`·`>` 는 표현식 작성(`{{a < b}}`)에 필수다.
        $tokens = $this->tokenizer->tokenize("<span data-if=\"{{a < b && c > d}}\"\n      class=\"x\"/>");

        $this->assertSame('{{a < b && c > d}}', $tokens[0]->attribute('data-if')->value);
        $this->assertSame('x', $tokens[0]->attribute('class')->value);
    }

    // ---------------------------------------------------------------------
    // 정상 — 텍스트·주석·문자 참조
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects comment_content_is_verbatim_and_not_entity_decoded
     */
    public function test_comment_content_is_captured_verbatim(): void
    {
        $tokens = $this->tokenizer->tokenize('<!-- 사용자 삭제 확인 --><div/>');

        $this->assertSame(MarkupTokenType::Comment, $tokens[0]->type);
        $this->assertSame(' 사용자 삭제 확인 ', $tokens[0]->text);
        $this->assertSame('div', $tokens[1]->name);
    }

    public function test_comment_does_not_decode_character_references(): void
    {
        $tokens = $this->tokenizer->tokenize('<!-- &amp; -->');

        $this->assertSame(' &amp; ', $tokens[0]->text);
    }

    public function test_comment_ends_at_first_terminator(): void
    {
        $tokens = $this->tokenizer->tokenize('<!-- a --> b');

        $this->assertSame(' a ', $tokens[0]->text);
        $this->assertSame(' b', $tokens[1]->text);
    }

    /**
     * @effects text_whitespace_is_preserved_and_flagged_for_upper_layers
     */
    public function test_text_whitespace_is_preserved_and_flagged(): void
    {
        $tokens = $this->tokenizer->tokenize("<div>\n  <span/>\n</div>");

        $this->assertSame("\n  ", $tokens[1]->text);
        $this->assertTrue($tokens[1]->isWhitespaceOnly());
        $this->assertSame("\n", $tokens[3]->text);
        $this->assertTrue($tokens[3]->isWhitespaceOnly());
    }

    /**
     * @effects character_references_are_decoded_in_text_and_attribute_values
     */
    #[DataProvider('characterReferenceProvider')]
    public function test_character_references_are_decoded(string $source, string $expected): void
    {
        $tokens = $this->tokenizer->tokenize($source);

        $this->assertSame($expected, $tokens[0]->text);
    }

    /**
     * 문자 참조 디코드 대상.
     *
     * @return array<string, array{0: string, 1: string}> [소스, 기대 텍스트]
     */
    public static function characterReferenceProvider(): array
    {
        return [
            'named' => ['&amp;&lt;&gt;&quot;', '&<>"'],
            'nbsp' => ['&nbsp;', "\u{00A0}"],
            'numeric decimal' => ['&#65;', 'A'],
            'numeric hex' => ['&#x1F600;', '😀'],
            'uppercase named' => ['&AMP;', '&'],
        ];
    }

    /**
     * @scenario compile_unit=tokenize,source_health=valid
     *
     * @effects bare_ampersand_is_preserved_for_expressions
     */
    public function test_bare_ampersand_is_preserved_for_expressions(): void
    {
        // `{{a && b}}` 를 `&amp;&amp;` 로 쓰게 만들면 마크업 형식의 존재 이유가 사라진다.
        $tokens = $this->tokenizer->tokenize('<span>{{a && b}} R&D 100% &</span>');

        $this->assertSame('{{a && b}} R&D 100% &', $tokens[1]->text);
    }

    public function test_character_references_are_decoded_inside_attribute_values(): void
    {
        $tokens = $this->tokenizer->tokenize('<div title="&quot;A&quot; &amp; B"/>');

        $this->assertSame('"A" & B', $tokens[0]->attributes[0]->value);
    }

    // ---------------------------------------------------------------------
    // 정상 — 전처리·위치
    // ---------------------------------------------------------------------

    /**
     * @effects bom_is_stripped_and_crlf_is_normalized_to_lf
     */
    public function test_bom_and_crlf_are_normalized(): void
    {
        // Windows 소스 파일의 \r 이 텍스트에 남으면 산출 JSON 이 플랫폼마다 달라진다.
        $tokens = $this->tokenizer->tokenize("\u{FEFF}<div>\r\na\r\n</div>");

        $this->assertSame('div', $tokens[0]->name);
        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(1, $tokens[0]->column);
        $this->assertSame("\na\n", $tokens[1]->text);
    }

    /**
     * @effects line_and_column_are_tracked_across_lines
     */
    public function test_line_and_column_are_tracked_across_lines(): void
    {
        $tokens = $this->tokenizer->tokenize("<layout>\n  <div>\n    <span/>\n  </div>\n</layout>");

        $this->assertSame([1, 1], [$tokens[0]->line, $tokens[0]->column]);
        $this->assertSame([2, 3], [$tokens[2]->line, $tokens[2]->column]);
        $this->assertSame([3, 5], [$tokens[4]->line, $tokens[4]->column]);
        $this->assertSame([4, 3], [$tokens[6]->line, $tokens[6]->column]);
    }

    /**
     * @effects column_counts_characters_not_bytes
     */
    public function test_column_counts_characters_not_bytes(): void
    {
        // 한국어 라벨 뒤의 컬럼이 바이트로 세지면 편집기 커서 위치와 어긋나 좌표 구실을
        // 못 한다.
        $tokens = $this->tokenizer->tokenize('<div title="한국어"><span/></div>');

        // `<div title="한국어">` 는 17자 — <span 은 18번째 문자에서 시작한다.
        // 바이트로 셌다면 한글 한 글자당 3바이트라 24 가 나온다.
        $this->assertSame(18, $tokens[1]->column);
    }

    /**
     * @effects attribute_positions_are_recorded
     */
    public function test_attribute_positions_are_recorded(): void
    {
        $tokens = $this->tokenizer->tokenize("<div\n  class=\"a\"\n  data-if=\"{{b}}\"/>");

        $this->assertSame([2, 3], [$tokens[0]->attributes[0]->line, $tokens[0]->attributes[0]->column]);
        $this->assertSame([3, 3], [$tokens[0]->attributes[1]->line, $tokens[0]->attributes[1]->column]);
    }

    // ---------------------------------------------------------------------
    // 정상 — 계획서 대표 예시 (통합)
    // ---------------------------------------------------------------------

    public function test_tokenizes_representative_layout_document(): void
    {
        $source = <<<'HTML'
        <layout name="admin_user_list" version="1.0.0" extends="_admin_base">
          <meta title="$t:admin.users.title"/>
          <data-source id="users" endpoint="/api/admin/users" method="GET"
                       :auto_fetch="true" label_key="admin.users.source"/>
          <init-local><field name="selectedIds_admin_users" :value="[]"/></init-local>

          <slot name="content">
            <!-- 사용자 목록 -->
            <div data-ref="user_list_card" class="admin-card">
              <data-grid data-ref="user_grid" :serverSidePagination="true" data="{{users?.data?.data ?? []}}">
                <column field="email" header="$t:admin.users.col_email" width="180px">
                  <cell><span data-if="!row.email" class="text-muted">-</span></cell>
                </column>
                <on event="onRowAction">
                  <switch value="{{$args[0]}}">
                    <case when="edit"><navigate path="/admin/users/{{$args[1].uuid}}/edit"/></case>
                  </switch>
                </on>
              </data-grid>
            </div>
          </slot>

          <modal src="partials/admin_user_list/_modal_delete.g7.html"/>
        </layout>
        HTML;

        $tokens = $this->tokenizer->tokenize($source, 'admin_user_list.g7.html');

        $names = array_values(array_filter(array_map(
            static fn (MarkupToken $token) => $token->type === MarkupTokenType::StartTag ? $token->name : null,
            $tokens,
        )));

        $this->assertSame([
            'layout', 'meta', 'data-source', 'init-local', 'field', 'slot', 'div',
            'data-grid', 'column', 'cell', 'span', 'on', 'switch', 'case', 'navigate', 'modal',
        ], $names);

        $comments = array_values(array_filter(
            $tokens,
            static fn (MarkupToken $token) => $token->type === MarkupTokenType::Comment,
        ));

        $this->assertCount(1, $comments);
        $this->assertSame(' 사용자 목록 ', $comments[0]->text);

        // 시작·종료 태그 짝은 상위 층 관심사지만, 스트림이 짝을 잃지 않았는지는 여기서 센다.
        $starts = array_filter($tokens, static fn ($t) => $t->type === MarkupTokenType::StartTag && ! $t->selfClosing);
        $ends = array_filter($tokens, static fn ($t) => $t->type === MarkupTokenType::EndTag);
        $this->assertCount(count($starts), $ends);
    }

    public function test_tokenizes_representative_extension_fragment(): void
    {
        $source = <<<'HTML'
        <extension target-layout="admin_ecommerce_order_detail" priority="50">
          <inject target-id="tab_content_area" position="append_child">
            <div data-ref="panel" data-if="{{(order.data?.payments ?? []).length > 0}}" class="admin-card">…</div>
          </inject>
        </extension>
        HTML;

        $tokens = $this->tokenizer->tokenize($source, 'fragment.g7.html');

        $this->assertSame('extension', $tokens[0]->name);
        $this->assertSame('admin_ecommerce_order_detail', $tokens[0]->attribute('target-layout')->value);
        $this->assertSame('50', $tokens[0]->attribute('priority')->value);
        $this->assertSame('inject', $tokens[2]->name);
        $this->assertSame('{{(order.data?.payments ?? []).length > 0}}', $tokens[4]->attribute('data-if')->value);
    }

    // ---------------------------------------------------------------------
    // 엣지 — 부분집합 위반
    // ---------------------------------------------------------------------

    /**
     * @effects doctype_cdata_and_processing_instruction_are_rejected,
     *          eof_inside_tag_or_comment_is_rejected,
     *          invalid_tag_name_is_rejected,
     *          end_tag_with_attributes_or_self_closing_is_rejected,
     *          unquoted_attribute_value_is_rejected,
     *          unterminated_attribute_value_is_rejected,
     *          duplicate_attribute_is_rejected,
     *          missing_attribute_separator_is_rejected,
     *          unknown_or_invalid_character_reference_is_rejected
     */
    #[DataProvider('compileErrorProvider')]
    public function test_rejects_markup_outside_the_supported_subset(string $source, string $expectedCode): void
    {
        $error = $this->captureError($source);

        $this->assertSame($expectedCode, $error->getErrorCode()->value, "소스: {$source}");
    }

    /**
     * 부분집합을 벗어난 표기와 기대 에러 코드.
     *
     * @return array<string, array{0: string, 1: string}> [소스, 에러 코드]
     */
    public static function compileErrorProvider(): array
    {
        return [
            'DOCTYPE' => ['<!DOCTYPE html><div/>', 'unsupported_markup'],
            'CDATA' => ['<![CDATA[x]]>', 'unsupported_markup'],
            '처리 명령' => ['<?php echo 1; ?>', 'unsupported_markup'],
            '텍스트의 <' => ['<span>1 < 2</span>', 'unescaped_lt'],
            '태그 미종료 EOF' => ['<div class="a"', 'eof_in_tag'],
            '태그명 직후 EOF' => ['<div', 'eof_in_tag'],
            '주석 미종료' => ['<!-- a', 'eof_in_comment'],
            '짧은 주석 표기' => ['<!-->', 'eof_in_comment'],
            '빈 종료 태그명' => ['</>', 'empty_tag_name'],
            '대문자 태그' => ['<Div/>', 'tag_uppercase'],
            '대문자 종료 태그' => ['<div></Div>', 'tag_uppercase'],
            '언더스코어 태그' => ['<data_grid/>', 'invalid_tag_name'],
            '숫자 시작 태그' => ['<1div/>', 'unescaped_lt'],
            '자기닫힘 종료 태그' => ['<div></div/>', 'end_tag_self_closing'],
            '속성 붙은 종료 태그' => ['<div></div class="a">', 'end_tag_with_attributes'],
            'void 종료 태그' => ['<hr></hr>', 'void_end_tag'],
            '속성명 없음' => ['<div ="a"/>', 'invalid_attribute_name'],
            '무인용 값' => ['<div class=admin-card/>', 'unquoted_attribute_value'],
            '값 미종료' => ['<div class="a/>', 'unterminated_attribute_value'],
            '중복 속성' => ['<div class="a" class="b"/>', 'duplicate_attribute'],
            '속성 구분 공백 누락' => ['<div class="a"id="b"/>', 'missing_attribute_separator'],
            '알 수 없는 문자 참조' => ['<span>&foo;</span>', 'unknown_entity'],
            '속성값의 알 수 없는 참조' => ['<div title="&foo;"/>', 'unknown_entity'],
            '서로게이트 코드포인트' => ['<span>&#xD800;</span>', 'invalid_entity'],
            '유니코드 상한 초과' => ['<span>&#x110000;</span>', 'invalid_entity'],
        ];
    }

    /**
     * @scenario compile_unit=tokenize,source_health=invalid
     *
     * @effects uppercase_tag_is_rejected_with_lowercase_suggestion
     */
    public function test_uppercase_tag_error_suggests_the_lowercase_form(): void
    {
        $error = $this->captureError('<Div/>');

        $this->assertSame(['found' => 'Div', 'suggestion' => 'div'], $error->getParams());
        $this->assertStringContainsString('<Div>', $error->getMessage());
        $this->assertStringContainsString('<div>', $error->getMessage());
    }

    /**
     * @scenario compile_unit=tokenize,source_health=invalid
     *
     * @effects void_end_tag_is_rejected
     */
    public function test_void_element_end_tag_is_rejected_at_the_tag_position(): void
    {
        $error = $this->captureError("<div>\n  <hr>\n  </hr>\n</div>");

        $this->assertSame(LayoutMarkupErrorCode::VoidEndTag, $error->getErrorCode());
        $this->assertSame(['tag' => 'hr'], $error->getParams());
        $this->assertSame([3, 3], [$error->getSourceLine(), $error->getSourceColumn()]);
    }

    /**
     * @scenario compile_unit=tokenize,source_health=invalid
     *
     * @effects unescaped_lt_in_text_is_rejected_with_entity_suggestion
     */
    public function test_unescaped_lt_in_text_is_rejected_at_its_position(): void
    {
        $error = $this->captureError("<span>\n  {{a}} < {{b}}\n</span>");

        $this->assertSame(LayoutMarkupErrorCode::UnescapedLt, $error->getErrorCode());
        $this->assertSame([2, 9], [$error->getSourceLine(), $error->getSourceColumn()]);
        $this->assertStringContainsString('&lt;', $error->getDetail());
    }

    /**
     * @scenario compile_unit=tokenize,source_health=invalid
     *
     * @effects eof_inside_tag_or_comment_is_rejected
     */
    public function test_unterminated_comment_is_rejected_at_the_comment_start(): void
    {
        $error = $this->captureError("<div>\n  <!-- 닫히지 않은 주석\n</div>");

        $this->assertSame(LayoutMarkupErrorCode::EofInComment, $error->getErrorCode());
        $this->assertSame([2, 3], [$error->getSourceLine(), $error->getSourceColumn()]);
    }

    /**
     * @effects error_carries_code_line_column_and_path
     */
    public function test_error_carries_source_coordinates_and_path(): void
    {
        $error = $this->captureError("<layout>\n  <div>\n    <Span/>\n  </div>\n</layout>", 'admin_user_list.g7.html');

        $this->assertSame(3, $error->getSourceLine());
        $this->assertSame(6, $error->getSourceColumn());
        $this->assertSame('admin_user_list.g7.html', $error->getSourcePath());

        $this->assertSame([
            'code' => 'tag_uppercase',
            'line' => 3,
            'column' => 6,
            'path' => 'admin_user_list.g7.html',
            'detail' => $error->getDetail(),
        ], $error->toArray());
    }

    public function test_error_message_includes_location_and_code(): void
    {
        $error = $this->captureError('<span>1 < 2</span>', 'a.g7.html');

        $this->assertStringContainsString('a.g7.html', $error->getMessage());
        $this->assertStringContainsString('1', $error->getMessage());
        $this->assertStringContainsString('[unescaped_lt]', $error->getMessage());
        $this->assertStringContainsString($error->getDetail(), $error->getMessage());
    }

    /**
     * @effects error_message_is_translated_per_locale
     */
    public function test_error_message_is_translated_per_locale(): void
    {
        $ko = $this->captureError('<Div/>')->getDetail();

        $this->app->setLocale('en');
        $en = $this->captureError('<Div/>')->getDetail();

        $this->assertNotSame($ko, $en);
        $this->assertStringContainsString('lowercase', $en);
    }

    /**
     * @effects error_detail_is_not_mangled_by_envelope_replacement
     */
    public function test_detail_is_not_mangled_by_envelope_replacement(): void
    {
        // 본문에 실린 작성자 원문이 봉투 치환에 휩쓸리면 안 된다 — `:line` 이라는 이름의
        // 속성이 라인 번호로 둔갑하던 함정.
        $error = $this->captureError('<div class="a":line="b"/>');

        $this->assertSame(LayoutMarkupErrorCode::MissingAttributeSeparator, $error->getErrorCode());
        $this->assertStringContainsString(':line', $error->getMessage());
    }

    // ---------------------------------------------------------------------
    // 퍼지
    // ---------------------------------------------------------------------

    /**
     * @effects random_input_terminates_with_tokens_or_compile_error
     */
    public function test_random_input_never_hangs_or_raises_php_errors(): void
    {
        // 결과가 토큰이든 CompileError 든 상관없다. 검증 대상은 "그 둘 중 하나로 반드시
        // 끝난다" 는 것 — 매달림(LogicException)이나 PHP 오류는 실패로 드러난다.
        mt_srand(20260716);

        $alphabet = ['<', '>', '/', '"', "'", '=', '&', ';', '#', '-', '!', '?', ' ', "\n", "\t",
            'a', 'Z', '0', '{', '}', ':', '[', ']', '.', '한', '😀', "\u{FEFF}", "\r"];

        $accepted = 0;
        $rejected = 0;

        for ($case = 0; $case < 2000; $case++) {
            $source = '';
            $length = mt_rand(0, 40);

            for ($i = 0; $i < $length; $i++) {
                $source .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            try {
                $this->tokenizer->tokenize($source);
                $accepted++;
            } catch (CompileError) {
                $rejected++;
            }
        }

        // 31차가 `assertTrue(true)` 를 루프 카운터 단언으로 바꿨는데, 그 카운터는 **리터럴 루프
        // 상한의 자기계수**라 무조건 2000 이었다 — 토크나이저가 무엇을 하든 초록이다(32차 ⚠️).
        // 하한을 토크나이저의 **거동**에 건다: 두 갈래가 모두 관측돼야 퍼징이 살아 있는 것이고,
        // 알파벳이 퇴화하거나 토크나이저가 전부 통과/전부 거부로 바뀌면 한쪽이 0 이 되어 red 다.
        $this->assertGreaterThan(0, $accepted,
            '무작위 표본 2000건 중 토큰화에 성공한 것이 하나도 없습니다 — 토크나이저가 전부 '
            .'거부하거나 표본 생성이 퇴화했다');

        $this->assertGreaterThan(0, $rejected,
            '무작위 표본 2000건 중 거부된 것이 하나도 없습니다 — 잘못된 입력을 걸러내는 경로가 '
            .'죽었거나 표본이 유효 문서만 만들고 있다');
    }

    /**
     * @effects mutated_valid_document_terminates_with_tokens_or_compile_error
     */
    public function test_mutated_valid_document_never_hangs_or_raises_php_errors(): void
    {
        mt_srand(20260717);

        $valid = '<layout name="a"><meta title="t"/><slot name="content">'
            .'<div class="c" data-if="{{x && y}}">&amp; 텍스트<!-- c --><input/></div>'
            .'</slot></layout>';

        $accepted = 0;
        $rejected = 0;
        $distinct = [];

        for ($case = 0; $case < 1000; $case++) {
            $mutated = $valid;
            $mutations = mt_rand(1, 4);

            for ($i = 0; $i < $mutations; $i++) {
                $position = mt_rand(0, max(strlen($mutated) - 1, 0));

                $mutated = mt_rand(0, 1) === 0
                    ? substr($mutated, 0, $position).substr($mutated, $position + 1)
                    : substr($mutated, 0, $position).'<"&/>'[mt_rand(0, 4)].substr($mutated, $position);
            }

            $distinct[$mutated] = true;

            try {
                $this->tokenizer->tokenize($mutated);
                $accepted++;
            } catch (CompileError) {
                $rejected++;
            }
        }

        // 32차 ⚠️ — 종전 하한(`$exercised`)은 리터럴 루프 상한의 자기계수라 토크나이저 거동과
        // 무관하게 늘 참이었다. 변이가 **서로 다른 입력을 만들고** 두 갈래가 모두 관측되는지로
        // 바꾼다(변이기가 죽어 원본만 1000번 넣으면 `$distinct` 가 1 이 되어 red).
        $this->assertGreaterThan(100, count($distinct),
            '변이가 서로 다른 입력을 만들지 못했습니다 — 변이기가 죽으면 같은 문서를 천 번 '
            .'넣는 것과 같다');

        $this->assertGreaterThan(0, $accepted,
            '변이 표본 1000건 중 토큰화에 성공한 것이 하나도 없습니다');

        $this->assertGreaterThan(0, $rejected,
            '변이 표본 1000건 중 거부된 것이 하나도 없습니다 — 변이가 문서를 전혀 깨뜨리지 '
            .'못했거나 거부 경로가 죽었다');
    }

    /**
     * @effects empty_source_yields_no_tokens
     */
    public function test_empty_source_yields_no_tokens(): void
    {
        $this->assertSame([], $this->tokenizer->tokenize(''));
    }

    /**
     * @effects tokenizer_instance_resets_state_between_runs
     */
    public function test_tokenizer_instance_is_reusable(): void
    {
        // 내부 상태(오프셋·라인)를 tokenize 마다 초기화하지 않으면 두 번째 호출의 좌표가
        // 첫 호출 끝에서 이어져 오류 위치가 어긋난다.
        $this->tokenizer->tokenize("<div>\n\n\n</div>");
        $tokens = $this->tokenizer->tokenize('<span/>');

        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(1, $tokens[0]->column);
    }

    /**
     * 소스를 토큰화하고 발생한 CompileError 를 반환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @param  string|null  $path  소스 경로
     * @return CompileError 발생한 컴파일 오류
     */
    private function captureError(string $source, ?string $path = null): CompileError
    {
        try {
            $this->tokenizer->tokenize($source, $path);
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail("CompileError 가 발생하지 않았다: {$source}");
    }
}
