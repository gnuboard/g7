<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 마일리지 거래 유형 Enum
 */
enum MileageTransactionTypeEnum: string
{
    case PURCHASE_EARN = 'purchase_earn';               // 구매 적립
    case ADMIN_EARN = 'admin_earn';                     // 관리자 수동 적립
    case ORDER_USE = 'order_use';                       // 주문 시 사용
    case ADMIN_DEDUCT = 'admin_deduct';                 // 관리자 수동 차감
    case EXPIRED = 'expired';                           // 소멸(유효기간 만료·회원 탈퇴)
    case REFUND_RESTORE = 'refund_restore';             // 환불 시 복원
    case ORDER_CANCEL_RESTORE = 'order_cancel_restore'; // 주문 취소 시 복원
    case EARN_CANCEL = 'earn_cancel';                   // 기적립 회수
    case REFUND_CREDIT = 'refund_credit';               // 환불 현금분 마일리지 지급 (무기한)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.mileage_transaction_type.'.$this->value);
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<int, string> 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<int, array{value: string, label: string}> 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }

    /**
     * 적립(잔액 증가) 유형인지 확인합니다.
     *
     * @return bool 적립 유형 여부
     */
    public function isEarning(): bool
    {
        return in_array($this, [self::PURCHASE_EARN, self::ADMIN_EARN, self::REFUND_RESTORE, self::ORDER_CANCEL_RESTORE, self::REFUND_CREDIT], true);
    }

    /**
     * 차감(잔액 감소) 유형인지 확인합니다.
     *
     * @return bool 차감 유형 여부
     */
    public function isDeducting(): bool
    {
        return in_array($this, [self::ORDER_USE, self::ADMIN_DEDUCT, self::EXPIRED, self::EARN_CANCEL], true);
    }

    /**
     * 관리자 내역 화면용 배지 색상 그룹을 반환합니다.
     *
     * 운영자가 거래 성격을 즉시 구별할 수 있도록 9종을 5색 그룹으로 세분화합니다.
     * 적립계=green, 사용계=blue, 소멸=gray, 복원계=teal(환불 지급 포함 — 돌려받은 돈), 수동/회수계=amber.
     *
     * @return string 배지 색상 그룹 (green|blue|gray|teal|amber)
     */
    public function adminBadgeGroup(): string
    {
        return match ($this) {
            self::PURCHASE_EARN => 'green',
            self::ORDER_USE => 'blue',
            self::EXPIRED => 'gray',
            self::REFUND_RESTORE, self::ORDER_CANCEL_RESTORE, self::REFUND_CREDIT => 'teal',
            self::ADMIN_EARN, self::ADMIN_DEDUCT, self::EARN_CANCEL => 'amber',
        };
    }

    /**
     * 사용자 마이페이지 표시용 4분류를 반환합니다.
     *
     * 잔액이 늘어난 거래는 전부 '적립' 에 선다(isEarning() 과 같은 기준) — 요약 카드의
     * '누적 적립' 이 그 기준으로 세기 때문에, 목록 필터가 관리자 지급·환불 복원·취소 복원을
     * '조정' 으로 접으면 한 화면이 같은 거래를 두 갈래로 분류하게 된다. '조정' 에는 사용도
     * 소멸도 아닌 차감(관리자 차감·기적립 회수)만 남는다. 세부 사유는 description 이 말한다.
     *
     * @return string 표시 분류 (earn|use|expire|adjust)
     */
    public function userDisplayCategory(): string
    {
        return match ($this) {
            self::PURCHASE_EARN, self::ADMIN_EARN, self::REFUND_RESTORE, self::ORDER_CANCEL_RESTORE, self::REFUND_CREDIT => 'earn',
            self::ORDER_USE => 'use',
            self::EXPIRED => 'expire',
            self::ADMIN_DEDUCT, self::EARN_CANCEL => 'adjust',
        };
    }
}
