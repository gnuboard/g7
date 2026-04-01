<?php

namespace App\Enums;

/**
 * 첨부파일 소스 타입 Enum
 *
 * 첨부파일이 어디서 생성되었는지 구분합니다.
 */
enum AttachmentSourceType: string
{
    /** 코어 시스템에서 생성된 첨부파일 */
    case Core = 'core';

    /** 모듈에서 생성된 첨부파일 */
    case Module = 'module';

    /** 플러그인에서 생성된 첨부파일 */
    case Plugin = 'plugin';

    /**
     * 모든 값 배열 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 값이 유효한지 확인
     *
     * @param string $value 확인할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 라벨 반환
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return match ($this) {
            self::Core => __('attachment.source_type.core'),
            self::Module => __('attachment.source_type.module'),
            self::Plugin => __('attachment.source_type.plugin'),
        };
    }
}
