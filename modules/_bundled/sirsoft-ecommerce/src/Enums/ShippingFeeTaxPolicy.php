<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송비 과세 정책 Enum
 *
 * 부가가치세법 제14조는 주된 재화에 부수되는 용역이 주된 재화의 과세/면세를 따른다고 정하지만,
 * 과세·면세 상품이 섞인 장바구니의 배송비 안분에 관한 명문 규정은 존재하지 않는다.
 * (이슈 본문의 "과세금액 50% 이상이면 과세" 규정은 현행 법령·예규에서 확인되지 않았다.)
 * 따라서 상점의 세무 판단에 따라 아래 3가지 중 선택하게 한다.
 */
enum ShippingFeeTaxPolicy: string
{
    /**
     * 안분 — 과세상품금액 비율만큼 배송비를 과세로 분류 (기본값)
     */
    case PROPORTIONAL = 'proportional';

    /**
     * 전액과세 — 배송비 전액을 과세로 분류
     */
    case TAXABLE = 'taxable';

    /**
     * 주된재화 — 상품금액이 큰 쪽(과세 또는 면세)의 성격을 따름
     */
    case FOLLOW_MAIN_ITEM = 'follow_main_item';

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
     * 값을 안전하게 변환합니다. 해석 불가 시 기본값(안분)을 반환합니다.
     *
     * @param  string|null  $value  원본 값
     * @return self 변환된 Enum
     */
    public static function fromValueOrDefault(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::PROPORTIONAL;
        }

        return self::tryFrom($value) ?? self::PROPORTIONAL;
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
        return 'sirsoft-ecommerce::cash_receipt.shipping_fee_tax_policy.'.$this->value;
    }
}
