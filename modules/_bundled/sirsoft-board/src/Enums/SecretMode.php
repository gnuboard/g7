<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 비밀글 설정 모드
 */
enum SecretMode: string
{
    /**
     * 비밀글 사용 안함
     */
    case Disabled = 'disabled';

    /**
     * 비밀글 사용 (선택)
     */
    case Enabled = 'enabled';

    /**
     * 비밀글 고정 (필수)
     */
    case Always = 'always';

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
        return 'sirsoft-board::enums.secret_mode.'.$this->value;
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
