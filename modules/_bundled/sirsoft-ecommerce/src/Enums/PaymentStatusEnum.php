<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 결제 상태 Enum
 */
enum PaymentStatusEnum: string
{
    case READY = 'ready';                           // 결제대기
    case IN_PROGRESS = 'in_progress';               // 결제진행중
    case WAITING_DEPOSIT = 'waiting_deposit';       // 입금대기 (가상계좌)
    case PAID = 'paid';                             // 결제완료
    case PARTIAL_CANCELLED = 'partial_cancelled';   // 부분취소
    case CANCELLED = 'cancelled';                   // 전체취소
    case FAILED = 'failed';                         // 결제실패
    case EXPIRED = 'expired';                       // 기한만료 (가상계좌)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 결제 상태의 현지화된 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.payment_status.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string 결제 상태의 현지화된 라벨
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string 뱃지 색상 variant 키
     */
    public function variant(): string
    {
        return match ($this) {
            self::READY => 'secondary',
            self::IN_PROGRESS => 'info',
            self::WAITING_DEPOSIT => 'warning',
            self::PAID => 'success',
            self::PARTIAL_CANCELLED => 'warning',
            self::CANCELLED => 'danger',
            self::FAILED => 'danger',
            self::EXPIRED => 'secondary',
        };
    }

    /**
     * 입금(결제)이 아직 완료되지 않아 입금확인 대상이 되는 상태인지 반환합니다.
     *
     * 무통장 입금확인 버튼 노출·처리 판정의 SSoT. 주문 상태(order_status)가 아닌
     * 결제 레코드(payment) 자체의 상태로 판정해, order_status 가 다른 경로로 먼저
     * 전이돼도 실제 미입금 결제는 입금확인할 수 있도록 한다.
     *
     * @return bool 결제대기(ready)·입금대기(waiting_deposit)면 true
     */
    public function isAwaitingDeposit(): bool
    {
        return in_array($this, [self::READY, self::WAITING_DEPOSIT], true);
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array 결제 상태 문자열 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array value/label 쌍의 셀렉트 옵션 목록
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
