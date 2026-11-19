<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 이미지 위 글 상자의 글 정렬.
 *
 * `label()` 은 두지 않는다 — 편집기가 값으로 프런트 다국어 키(`sirsoft-popup.admin.form.overlay.align.*`)를 고른다.
 */
enum OverlayAlign: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

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
