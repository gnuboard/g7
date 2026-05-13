<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 결제 수단 Enum
 */
enum PaymentMethodEnum: string
{
    case CARD = 'card';                             // 신용카드
    case VBANK = 'vbank';                           // 가상계좌
    case DBANK = 'dbank';                           // 무통장입금 (수동 입금확인)
    case BANK = 'bank';                             // 계좌이체
    case PHONE = 'phone';                           // 휴대폰결제
    case POINT = 'point';                           // 포인트결제
    case DEPOSIT = 'deposit';                       // 예치금결제
    case FREE = 'free';                             // 무료 (전액 할인)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.payment_method.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }

    /**
     * PG 결제 여부를 확인합니다.
     *
     * 실제 금전 결제인지 판별합니다. DBANK(무통장입금)도 실제 돈이 오가므로 true입니다.
     * POINT, DEPOSIT, FREE는 내부 처리이므로 false입니다.
     *
     * @return bool
     */
    public function isPgPayment(): bool
    {
        return ! in_array($this, [self::POINT, self::DEPOSIT, self::FREE]);
    }

    /**
     * PG사 선택이 필요한 결제수단인지 확인합니다.
     *
     * DBANK(무통장입금)는 수동 입금확인이므로 PG 불필요.
     * POINT, DEPOSIT, FREE는 내부 처리이므로 PG 불필요.
     *
     * @return bool
     */
    public function needsPgProvider(): bool
    {
        return ! in_array($this, [
            self::DBANK,
            self::POINT,
            self::DEPOSIT,
            self::FREE,
        ]);
    }
}
