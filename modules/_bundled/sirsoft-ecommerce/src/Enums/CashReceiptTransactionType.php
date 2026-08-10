<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 현금영수증 이력 거래 유형 Enum
 *
 * 이력 테이블(ecommerce_order_cash_receipts)의 원장 성격을 규정한다.
 * 부분취소는 사용하지 않으므로(항상 전체취소 → 전액 재발급) 유형은 2종으로 충분하다.
 */
enum CashReceiptTransactionType: string
{
    /**
     * 발급
     */
    case ISSUE = 'issue';

    /**
     * 취소 (전액취소)
     */
    case CANCEL = 'cancel';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::cash_receipt.transaction_type.'.$this->value;
    }
}
