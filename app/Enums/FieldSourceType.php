<?php

namespace App\Enums;

/**
 * 항목 정의의 출처.
 *
 * `field_definitions.source_type` 에 저장된다. 선언 재동기화의 stale 정리는 core·module·plugin 행만
 * 대상으로 하며, 운영자가 만든 custom 행은 어떤 동기화에서도 지우지 않는다.
 *
 * @since 7.2.0
 */
enum FieldSourceType: string
{
    /** 코어 선언 */
    case Core = 'core';

    /** 모듈 선언 */
    case Module = 'module';

    /** 플러그인 선언 */
    case Plugin = 'plugin';

    /** 운영자가 만든 항목 */
    case Custom = 'custom';

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
     * @return string 번역된 출처 라벨
     */
    public function label(): string
    {
        return __('fields.source_type.'.$this->value);
    }
}
