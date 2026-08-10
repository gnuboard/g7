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
     * @return string 결제수단 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.payment_method.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string 결제수단 라벨
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<int, string> 결제수단 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<int, array{value: string, label: string}> Select 옵션 배열
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
     * @return bool PG 결제 여부
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
     * @return bool PG사 선택 필요 여부
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

    /**
     * 환불 계좌를 받아야 하는 결제수단인지 확인합니다.
     *
     * 무통장입금은 관리자가 수동으로 이체할 대상 계좌가 필요하고, 가상계좌는 PG 환불 API 의
     * refundReceiveAccount 로 계좌가 필요하다. 카드·계좌이체·휴대폰은 원거래 취소로 환불되고
     * 마일리지·예치금·무료는 내부 처리이므로 계좌가 필요 없다.
     *
     * 체크아웃 화면은 결제수단을 바꿔도 입력값을 비우지 않으므로, 계좌가 필요 없는 주문에
     * 계좌정보가 저장되지 않도록 서버가 이 축으로 게이팅한다.
     *
     * @return bool 환불 계좌 필요 여부
     */
    public function needsRefundBankAccount(): bool
    {
        return in_array($this, [self::DBANK, self::VBANK], true);
    }

    /**
     * 결제수단의 현금성(현금영수증 발급 대상) 금액을 산정합니다.
     *
     * 무통장입금(dbank)만 구매자가 직접 현금을 입금하므로 실결제액 전액이 현금성이다.
     * 가상계좌(vbank)는 PG 가 현금영수증을 자동 발급하므로 0, 카드/계좌이체/휴대폰도 0,
     * 마일리지·예치금·무료는 애초에 현금 입금이 없으므로 0이다.
     *
     * 주문 생성 시점(OrderProcessingService)과 취소 재계산 시점(OrderAdjustmentService)이
     * 같은 규칙을 써야 부분환불 후 현금영수증 재발급액이 잔여 실결제액과 일치한다.
     * 이 메서드가 그 규칙의 SSoT 다.
     *
     * @param  int  $finalAmount  마일리지·예치금 차감 후 실결제액
     * @return int 현금성 금액 (음수 방지)
     */
    public function resolveCashEquivalentAmount(int $finalAmount): int
    {
        if ($this !== self::DBANK) {
            return 0;
        }

        return max(0, $finalAmount);
    }
}
