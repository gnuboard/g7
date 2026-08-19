<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 답글 달린 글의 삭제 정책
 *
 * 답글(parent_id 자식 글)이 남아 있는 글을 삭제하려 할 때의 동작을 게시판별로 정의합니다.
 * 기본값은 Cascade — 댓글·첨부가 이미 연쇄 소프트 삭제되는 기존 의미론과 일치하며,
 * 복원 시 연쇄분만 선택 복원되어 되돌릴 수 있습니다.
 */
enum ReplyDeletePolicy: string
{
    /**
     * 답글이 살아 있는 동안 원글 삭제 차단
     */
    case Block = 'block';

    /**
     * 원글 삭제 시 답글도 함께 소프트 삭제 (기본값)
     */
    case Cascade = 'cascade';

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
     * 유효한 값인지 확인
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-board::enums.reply_delete_policy.'.$this->value;
    }

    /**
     * 다국어 라벨 반환
     *
     * @return string 번역된 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 모든 값을 배열로 반환 (value, label 포함)
     *
     * @return array<array{value: string, label: string}>
     */
    public static function toArray(): array
    {
        return array_map(
            fn (self $item) => [
                'value' => $item->value,
                'label' => $item->label(),
            ],
            self::cases()
        );
    }
}
