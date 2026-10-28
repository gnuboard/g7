<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 팝업 내용 유형 — 이미지형 / 직접 작성형(에디터).
 *
 * `label()` 은 두지 않는다 — API 는 값만 싣고 화면이 값으로 프런트 다국어 키(`sirsoft-popup.…`)를 고른다.
 */
enum ContentType: string
{
    case Image = 'image';
    case Html = 'html';

    /**
     * 값 목록을 반환합니다.
     *
     * @return array<int, string> 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
