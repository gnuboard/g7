<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 조치 주체 Enum
 *
 * 게시글/댓글 상태 변경의 트리거 주체를 정의합니다.
 */
enum TriggerType: string
{
    /**
     * 신고에 의한 조치
     */
    case Report = 'report';

    /**
     * 관리자 직권 조치
     */
    case Admin = 'admin';

    /**
     * 시스템 자동 조치
     */
    case System = 'system';

    /**
     * 신고 누적에 의한 자동 블라인드
     */
    case AutoHide = 'auto_hide';

    /**
     * 사용자 직접 삭제
     */
    case User = 'user';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
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

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 라벨
     */
    public function label(): string
    {
        return __('sirsoft-board::enums.trigger_type.'.$this->value);
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