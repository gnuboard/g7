<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 신고 대상 타입 Enum
 *
 * 신고 가능한 대상의 타입을 정의합니다.
 */
enum ReportType: string
{
    /**
     * 게시글
     */
    case Post = 'post';

    /**
     * 댓글
     */
    case Comment = 'comment';

    /**
     * 모든 타입 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 타입 값인지 확인합니다.
     *
     * @param  string  $value  검증할 타입 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 타입의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 타입 라벨
     */
    public function label(): string
    {
        return __('sirsoft-board::enums.report_type.'.$this->value);
    }

    /**
     * 모든 타입을 배열로 반환합니다 (value, label 포함).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function toArray(): array
    {
        return array_map(
            fn (self $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ],
            self::cases()
        );
    }
}
