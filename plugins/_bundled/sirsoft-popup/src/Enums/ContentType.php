<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 팝업 내용 유형 — 이미지형 / 직접 작성형(에디터).
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
}
