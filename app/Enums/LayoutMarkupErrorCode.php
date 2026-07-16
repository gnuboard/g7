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
 * 아래 코드는 **토크나이저(S1) 층**의 실패 사유다. 상위 층(노드·액션·문서 구조 해석)이
 * 추가되면 그 층의 사유도 여기에 누적한다.
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
