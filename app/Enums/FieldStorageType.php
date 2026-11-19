<?php

namespace App\Enums;

/**
 * 항목 값의 저장 방식.
 *
 * `field_definitions.storage` 에 저장된다.
 *
 * @since 7.2.0
 */
enum FieldStorageType: string
{
    /** 소유 테이블의 컬럼 */
    case Column = 'column';

    /** 확장 핸들러(FieldValueHandlerInterface) */
    case Handler = 'handler';

    /** 공용 값 테이블(field_values) */
    case Value = 'value';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
