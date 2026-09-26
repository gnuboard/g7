<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 신청 상품 행의 회수 진행 Enum (파생값 — 저장하지 않는다)
 *
 * 선교환에서는 상태 줄이 발송 단계만 쓰므로, 헌 물건이 어디쯤 왔는지는 행의 회수 송장·도착 시각·
 * 미회수 표시로만 알 수 있다. 일반 교환·반품도 같은 값으로 화면 표기를 통일한다
 * (`ClaimItemCollectionState::for()` 가 판정한다).
 */
enum ClaimItemCollectionStateEnum: string
{
    case PENDING = 'pending';           // 회수 대기 (송장 없음)
    case IN_TRANSIT = 'in_transit';     // 회수중 (송장 있음, 도착 전)
    case COLLECTED = 'collected';       // 회수 도착
    case UNRETURNED = 'unreturned';     // 미회수 완료

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_item_collection_state.'.$this->value);
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.claim_item_collection_state.'.$this->value;
    }

    /**
     * 뱃지 variant 를 반환합니다.
     *
     * @return string 뱃지 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::PENDING => 'secondary',
            self::IN_TRANSIT => 'info',
            self::COLLECTED => 'success',
            self::UNRETURNED => 'danger',
        };
    }

    /**
     * 헌 물건이 오는 중이거나 이미 도착했는지 반환합니다. (선교환 완료 조건의 회수 축)
     *
     * @return bool 회수중·회수 도착이면 true
     */
    public function isOnTheWayOrArrived(): bool
    {
        return in_array($this, [self::IN_TRANSIT, self::COLLECTED], true);
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
