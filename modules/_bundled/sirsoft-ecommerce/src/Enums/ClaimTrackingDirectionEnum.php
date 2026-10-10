<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클레임 송장 방향 Enum
 *
 * 송장은 교환·반품 신청 상품 행에 방향별로 붙는다 — 회수(고객 → 판매자, 헌 물건)와
 * 재발송(판매자 → 고객, 교환품). 반품은 회수만 있다. 요청·리소스·훅이 방향을 말할 때 이 값을 쓴다.
 */
enum ClaimTrackingDirectionEnum: string
{
    case COLLECTION = 'collection';   // 회수
    case REDELIVERY = 'redelivery';   // 재발송

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_tracking_direction.'.$this->value);
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.claim_tracking_direction.'.$this->value;
    }

    /**
     * 뱃지 variant 를 반환합니다.
     *
     * @return string 뱃지 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::COLLECTION => 'info',
            self::REDELIVERY => 'primary',
        };
    }

    /**
     * 이 방향 송장 컬럼 접두를 반환합니다. (`collection_*` · `redelivery_*`)
     *
     * @return string 컬럼 접두
     */
    public function columnPrefix(): string
    {
        return $this->value;
    }

    /**
     * 이 방향의 행 도착 시각 컬럼명을 반환합니다.
     *
     * @return string 도착 시각 컬럼명
     */
    public function arrivedAtColumn(): string
    {
        return match ($this) {
            self::COLLECTION => 'collected_at',
            self::REDELIVERY => 'redelivered_at',
        };
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
