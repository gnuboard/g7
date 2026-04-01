<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 정렬 방향
 */
enum OrderDirection: string
{
    /**
     * 오름차순
     */
    case Asc = 'ASC';

    /**
     * 내림차순
     */
    case Desc = 'DESC';

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
        return 'sirsoft-board::enums.order_direction.'.strtolower($this->value);
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
