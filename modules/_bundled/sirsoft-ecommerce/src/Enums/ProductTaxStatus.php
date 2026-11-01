<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 상품 과세 상태 Enum
 *
 * 상품 기본값(`products.tax_status`)과 옵션별 지정(`product_options.tax_status`, null = 상품 따름)이
 * 같은 값을 쓴다. 영세(ZERO_RATED)는 과세 대상이되 세율 0% — 과세표준에 들어가고 부가세는 0 이다.
 */
enum ProductTaxStatus: string
{
    case TAXABLE = 'taxable';       // 과세
    case TAX_FREE = 'tax_free';     // 면세
    case ZERO_RATED = 'zero_rated'; // 영세 (과세 대상·세율 0%)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 번역 키를 반환합니다.
     *
     * @return string
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.tax_status.'.$this->value;
    }

    /**
     * 옵션 지정값과 상품 기본값으로 실제 적용할 과세 종류를 정합니다.
     *
     * 옵션에 지정한 값이 있으면 그 값, 없으면(null) 상품 기본값을 따른다.
     *
     * @param  self|null  $option  옵션에 지정한 과세 종류 (null = 상품 따름)
     * @param  self|null  $product  상품 기본 과세 종류 (null 이면 과세로 본다)
     * @return self 실제 적용할 과세 종류
     */
    public static function resolveEffective(?self $option, ?self $product): self
    {
        return $option ?? $product ?? self::TAXABLE;
    }

    /**
     * 과세표준(부가세 신고 대상)에 들어가는 종류인지 반환합니다 — 과세·영세.
     *
     * @return bool 과세표준 포함 여부
     */
    public function isInTaxBase(): bool
    {
        return $this !== self::TAX_FREE;
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
}
