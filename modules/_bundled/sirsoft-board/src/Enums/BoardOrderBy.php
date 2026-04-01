<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 게시판 정렬 기준
 */
enum BoardOrderBy: string
{
    /**
     * 생성일
     */
    case CreatedAt = 'created_at';

    /**
     * 조회수
     */
    case ViewCount = 'view_count';

    /**
     * 제목
     */
    case Title = 'title';

    /**
     * 작성자
     */
    case Author = 'author';

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
        return 'sirsoft-board::enums.board_order_by.'.$this->value;
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