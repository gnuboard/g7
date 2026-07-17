<?php

namespace App\Enums;

/**
 * `.g7.html` 저작 포맷 컴파일 실패 사유 코드 Enum.
 *
 * `CompileError` 가 이 코드로 오류를 식별하며, 코드 값이 그대로
 * `lang/{locale}/exceptions.php` 의 `layout_markup.{value}` 메시지 키가 된다.
 *
 * 메시지가 번역되어도 코드는 불변이므로, 테스트·규정 문서·audit 룰은 번역문이 아닌
 * 이 코드로 오류를 지목한다.
 *
 * 아래 코드는 **토크나이저(S1) + 트리·노드 컴파일(S2) 층**의 실패 사유다. 상위 층(액션·문서
 * 구조 해석)이 추가되면 그 층의 사유도 여기에 누적한다.
 */
enum LayoutMarkupErrorCode: string
{
    /**
     * 요소·텍스트·주석이 아닌 마크업 (DOCTYPE·CDATA·처리 명령).
     */
    case UnsupportedMarkup = 'unsupported_markup';

    /**
     * 텍스트에 이스케이프하지 않은 `<` — 태그 시작으로 해석된다.
     */
    case UnescapedLt = 'unescaped_lt';

    /**
     * 태그가 닫히지 않은 채 파일이 끝남.
     */
    case EofInTag = 'eof_in_tag';

    /**
     * 주석이 닫히지 않은 채 파일이 끝남.
     */
    case EofInComment = 'eof_in_comment';

    /**
     * 태그명이 비어 있음 (`</>`).
     */
    case EmptyTagName = 'empty_tag_name';

    /**
     * 태그명에 대문자 사용 (`<Div>`).
     */
    case TagUppercase = 'tag_uppercase';

    /**
     * 소문자·kebab 규칙을 벗어난 태그명 (`<data_grid>`).
     */
    case InvalidTagName = 'invalid_tag_name';

    /**
     * 종료 태그에 자기닫힘 표기 (`</div/>`).
     */
    case EndTagSelfClosing = 'end_tag_self_closing';

    /**
     * 종료 태그에 속성 표기 (`</div class="a">`).
     */
    case EndTagWithAttributes = 'end_tag_with_attributes';

    /**
     * void 요소의 종료 태그 (`</input>`).
     */
    case VoidEndTag = 'void_end_tag';

    /**
     * 허용 문자를 벗어난 속성명.
     */
    case InvalidAttributeName = 'invalid_attribute_name';

    /**
     * 인용되지 않은 속성값 (`class=admin-card`).
     */
    case UnquotedAttributeValue = 'unquoted_attribute_value';

    /**
     * 속성값의 따옴표가 닫히지 않음.
     */
    case UnterminatedAttributeValue = 'unterminated_attribute_value';

    /**
     * 같은 속성이 한 태그에 중복 지정됨.
     */
    case DuplicateAttribute = 'duplicate_attribute';

    /**
     * 속성 사이의 공백 누락 (`class="a"id="b"`).
     */
    case MissingAttributeSeparator = 'missing_attribute_separator';

    /**
     * 참조 모양이지만 알려지지 않은 문자 참조 (`&foo;`).
     */
    case UnknownEntity = 'unknown_entity';

    /**
     * 문서에 실을 수 없는 코드포인트를 가리키는 문자 참조 (`&#xD800;`).
     */
    case InvalidEntity = 'invalid_entity';

    /**
     * 레지스트리(`components.json`) 파일을 읽을 수 없음.
     */
    case ManifestUnreadable = 'manifest_unreadable';

    /**
     * 레지스트리 파일이 올바른 JSON 이 아님.
     */
    case ManifestInvalidJson = 'manifest_invalid_json';

    /**
     * 레지스트리가 매니페스트 스키마를 위반함 (필수 필드 누락·형식 이탈).
     */
    case ManifestInvalid = 'manifest_invalid';

    /**
     * 두 컴포넌트가 같은 저작 태그로 해석됨 (매니페스트 모호 등록).
     */
    case AmbiguousComponentTag = 'ambiguous_component_tag';

    /**
     * 열린 태그가 없는데 종료 태그를 만남 (`</div>` 단독).
     */
    case UnexpectedEndTag = 'unexpected_end_tag';

    /**
     * 종료 태그가 열린 태그와 다름 (`<div></span>`).
     */
    case MismatchedEndTag = 'mismatched_end_tag';

    /**
     * 태그가 닫히지 않은 채 문서가 끝남 (중첩 검출 — 토크나이저의 EofInTag 와 다름).
     */
    case UnclosedTag = 'unclosed_tag';

    /**
     * 한 요소에 텍스트와 자식 요소가 공존함 (JSON 노드의 text/children 은 배타적).
     */
    case MixedTextAndChildren = 'mixed_text_and_children';

    /**
     * 요소 밖에 텍스트가 있음.
     */
    case TextOutsideElement = 'text_outside_element';

    /**
     * 한 요소 앞에 주석이 둘 이상 붙음 (노드 `comment` 는 문자열 하나).
     */
    case ConsecutiveComments = 'consecutive_comments';

    /**
     * 주석 뒤에 귀속시킬 요소가 없음.
     */
    case DanglingComment = 'dangling_comment';

    /**
     * 레지스트리에 없는 태그 (가까운 후보를 찾지 못함).
     */
    case UnknownTag = 'unknown_tag';

    /**
     * 레지스트리에 없는 태그 (오타로 보이는 후보 있음).
     */
    case UnknownTagWithSuggestion = 'unknown_tag_with_suggestion';

    /**
     * 문서 루트 전용 태그를 노드 위치에 사용함 (`<partial>`·`<layout>`·`<extension>`).
     */
    case RootTagInNodePosition = 'root_tag_in_node_position';

    /**
     * 다른 프레임워크 관용구를 사용함 (`v-if`·`@click`·`*ngIf`·무접두 `if`).
     */
    case ForeignIdiom = 'foreign_idiom';

    /**
     * `:attr` 타입 리터럴의 값이 JSON 이 아님.
     */
    case InvalidTypeLiteral = 'invalid_type_literal';

    /**
     * 불리언 속성에 `"false"` 를 표기함 (HTML5 는 거짓을 속성 생략으로 표기).
     */
    case BooleanAttributeFalse = 'boolean_attribute_false';

    /**
     * 값이 필요한 지시 속성에 값이 없음 (`<div data-if>`).
     */
    case DirectiveNeedsValue = 'directive_needs_value';

    /**
     * 타입이 고정된 지시 속성에 `:` 타입 리터럴을 사용함.
     */
    case DirectiveRejectsTypeLiteral = 'directive_rejects_type_literal';

    /**
     * 지시 속성 값이 정수가 아님 (`data-slot-order="첫째"`).
     */
    case DirectiveNeedsInteger = 'directive_needs_integer';

    /**
     * `data-each` 표기가 `아이템[, 인덱스] in 소스` 형식이 아님.
     */
    case InvalidEachSyntax = 'invalid_each_syntax';

    /**
     * `data-if` 가 보간 혼합형임 (`{{x}} === 'y'` — 보간 후 항상 참이 되는 함정).
     */
    case MixedIfExpression = 'mixed_if_expression';

    /**
     * 해당 부모 아래에 올 수 없는 구조 태그 (`<variant>` 가 `<class-map>` 밖에 있음).
     */
    case UnexpectedStructuralChild = 'unexpected_structural_child';

    /**
     * 태그에 필수 속성이 없음.
     */
    case MissingRequiredAttribute = 'missing_required_attribute';

    /**
     * 같은 노드 키를 두 번 지정함 (`<class-map>` 중복 등).
     */
    case DuplicateNodeKey = 'duplicate_node_key';

    /**
     * `<prop>`·`<data>` 본문이 올바른 JSON 이 아님.
     */
    case InvalidJsonBody = 'invalid_json_body';

    /**
     * 상태 초기화 `<field>` 에 값과 자식이 함께 있음.
     */
    case FieldValueAndChildren = 'field_value_and_children';

    /**
     * JSON 노드 키 이름을 무접두 속성으로 사용함 (`condition="..."`).
     */
    case NodeKeyAsAttribute = 'node_key_as_attribute';

    /**
     * G7 이 쓰지 않는 표준 속성 (`is` — customized built-in element).
     */
    case AttributeNotSupported = 'attribute_not_supported';

    /**
     * G7 태그이지만 이 컴파일 단계가 아직 다루지 않음.
     */
    case TagNotYetSupported = 'tag_not_yet_supported';

    /**
     * 조건 속성에 리터럴 불리언을 문자열로 표기함 (`data-if="false"`).
     */
    case ConditionBooleanLiteral = 'condition_boolean_literal';

    /**
     * 다국어 오류 본문을 반환합니다.
     *
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return string 오류 본문 (lang/{locale}/exceptions.php 의 layout_markup 키)
     */
    public function message(array $params = []): string
    {
        return __('exceptions.layout_markup.'.$this->value, $params);
    }

    /**
     * 모든 에러 코드 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 에러 코드 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
