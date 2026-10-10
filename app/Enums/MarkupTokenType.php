<?php

namespace App\Enums;

/**
 * `.g7.html` 마크업 형식 토크나이저가 방출하는 토큰의 종류 Enum.
 *
 * `MarkupTokenizer` → `LayoutMarkupCompiler` 사이의 유일한 계약이며, 컴파일러는 이
 * 종류에 따라 트리 구성·노드 해석을 분기한다.
 */
enum MarkupTokenType: string
{
    /**
     * 시작 태그 (`<div class="a">`, `<br/>`).
     */
    case StartTag = 'start_tag';

    /**
     * 종료 태그 (`</div>`).
     */
    case EndTag = 'end_tag';

    /**
     * 텍스트 (문자 참조 디코드 후, 공백 원문 보존).
     */
    case Text = 'text';

    /**
     * 주석 (`<!-- -->` 안쪽 원문).
     */
    case Comment = 'comment';

    /**
     * 모든 토큰 종류 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 토큰 종류 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
