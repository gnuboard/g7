<?php

namespace App\Enums;

/**
 * 항목의 열람·수정 범위.
 *
 * `field_definitions.edit_scope` 에 저장된다.
 *
 * @since 7.2.0
 */
enum FieldEditScope: string
{
    /** 본인 수정 */
    case Self_ = 'self';

    /** 관리자만 수정(본인은 열람) */
    case AdminEdit = 'admin_edit';

    /** 관리자만 열람 */
    case AdminOnly = 'admin_only';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 범위 라벨
     */
    public function label(): string
    {
        return __('fields.edit_scope.'.$this->value);
    }
}
