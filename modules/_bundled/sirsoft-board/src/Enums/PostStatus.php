<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 게시글/댓글 상태 Enum
 *
 * 게시글과 댓글의 상태를 정의합니다.
 */
enum PostStatus: string
{
    /**
     * 게시됨 (정상 게시 상태)
     */
    case Published = 'published';

    /**
     * 블라인드 처리됨 (관리자가 숨김 처리)
     */
    case Blinded = 'blinded';

    /**
     * 삭제됨 (관리자가 삭제 처리)
     */
    case Deleted = 'deleted';

    /**
     * 모든 상태 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 상태 값인지 확인합니다.
     *
     * @param  string  $value  검증할 상태 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 상태의 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-board::enums.post_status.'.$this->value;
    }

    /**
     * 상태의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 상태 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 상태별 CSS 클래스(variant)를 반환합니다.
     *
     * @return string 스타일 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Blinded => 'warning',
            self::Deleted => 'danger',
        };
    }

    /**
     * 모든 상태를 배열로 반환합니다 (value, label 포함).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function toArray(): array
    {
        return array_map(
            fn (self $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            self::cases()
        );
    }
}
