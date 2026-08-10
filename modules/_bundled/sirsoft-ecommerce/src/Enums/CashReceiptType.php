<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 현금영수증 발급 용도 Enum
 *
 * 기존 ecommerce_order_payments.cash_receipt_type 컬럼의 comment 는 income/expense 인 반면
 * KG이니시스 플러그인은 income_deduction/expenditure_proof 를 기록해 왔다. 본 Enum 이 SSoT 이며
 * 레거시 값은 Upgrade_1_1_0 업그레이드 스텝에서 정규화된다.
 */
enum CashReceiptType: string
{
    /**
     * 소득공제용 (개인)
     */
    case INCOME = 'income';

    /**
     * 지출증빙용 (사업자)
     */
    case EXPENSE = 'expense';

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
     * 레거시 값(income_deduction/expenditure_proof)을 포함해 안전하게 변환합니다.
     *
     * @param  string|null  $value  원본 값
     * @return self|null 변환된 Enum (해석 불가 시 null)
     */
    public static function fromLegacy(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            'income', 'income_deduction' => self::INCOME,
            'expense', 'expenditure_proof' => self::EXPENSE,
            default => self::tryFrom($value),
        };
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
        return 'sirsoft-ecommerce::cash_receipt.type.'.$this->value;
    }

    /**
     * 해당 용도로 사용 가능한 식별번호 종류를 반환합니다.
     *
     * 휴대폰번호는 소득공제/지출증빙 양쪽에 사용 가능하다.
     * (근거: 팝빌 발행유형 가이드 — 두 용도 모두에 휴대폰번호를 명시)
     *
     * @return array<int, CashReceiptIdentifierType> 허용되는 식별번호 종류
     */
    public function allowedIdentifierTypes(): array
    {
        return match ($this) {
            self::INCOME => [
                CashReceiptIdentifierType::PHONE,
                CashReceiptIdentifierType::CARD,
            ],
            self::EXPENSE => [
                CashReceiptIdentifierType::BUSINESS,
                CashReceiptIdentifierType::PHONE,
                CashReceiptIdentifierType::CARD,
            ],
        };
    }
}
