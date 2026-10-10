<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 교환 진행 방식 Enum
 *
 * 일반 교환은 헌 물건을 받은 뒤 새 물건을 보낸다(회수 3단계 → 발송 3단계). 선교환은 새 물건부터
 * 보내고 헌 물건은 기한 안에 받는다 — 상태 줄은 발송 단계만 쓰고, 회수 진행은 상품 행의 회수
 * 송장·도착 시각이 따로 표현한다(`ClaimItemCollectionState`). 관리자가 신청서마다 켠다.
 */
enum ExchangeModeEnum: string
{
    case STANDARD = 'standard';   // 일반 교환
    case ADVANCE = 'advance';     // 선교환

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.exchange_mode.'.$this->value);
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.exchange_mode.'.$this->value;
    }

    /**
     * 뱃지 variant 를 반환합니다.
     *
     * @return string 뱃지 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::STANDARD => 'secondary',
            self::ADVANCE => 'warning',
        };
    }

    /**
     * 선교환인지 반환합니다.
     *
     * @return bool 선교환이면 true
     */
    public function isAdvance(): bool
    {
        return $this === self::ADVANCE;
    }

    /**
     * 저장값을 Enum 으로 해석합니다. (알 수 없거나 비어 있으면 일반 교환)
     *
     * @param  mixed  $value  저장값 또는 Enum
     * @return self 진행 방식
     */
    public static function resolve(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom((string) $value) ?? self::STANDARD;
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<string> 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
