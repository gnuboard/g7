<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 부가세(VAT) 계산 헬퍼
 *
 * G7 의 상품 가격은 **부가세 포함가**다. 따라서 과세표준에 내재된 부가세는
 * `과세표준 × 세율 / (100 + 세율)` 로 산출한다 (세율 10% 기준 `과세표준 / 11` 과 동일).
 *
 * 기존 코드에 `× 10/110` 과 `/ 11` 두 표기가 섞여 있었는데 수학적으로 같은 식이므로
 * 이 헬퍼로 단일화한다.
 */
class VatCalculator
{
    /**
     * 기본 부가세율 (%) — 상품에 세율이 지정되지 않은 경우의 폴백.
     */
    public const DEFAULT_RATE = 10.0;

    /**
     * 과세표준에 내재된 부가세를 산출합니다.
     *
     * @param  int  $taxableAmount  과세표준 (부가세 포함 금액)
     * @param  float|null  $rate  부가세율 (%). null 이면 기본 세율
     * @return int 부가세 (원 단위 반올림)
     */
    public static function fromTaxableAmount(int $taxableAmount, ?float $rate = null): int
    {
        $rate = $rate ?? self::DEFAULT_RATE;

        if ($taxableAmount <= 0 || $rate <= 0) {
            return 0;
        }

        return (int) round($taxableAmount * $rate / (100 + $rate));
    }

    /**
     * 옵션별 과세 분류 결과에서 부가세 합계를 산출합니다.
     *
     * 세율이 섞인 주문에서는 **옵션별 부가세의 합**만이 정확합니다. 전체 과세표준에
     * 단일 세율을 적용하면 세율이 다른 상품이 섞였을 때 값이 어긋납니다.
     *
     * @param  array<int|string, array{taxable_amount?: int, tax_rate?: float|null, vat_amount?: int}>  $taxClassification  옵션별 과세 분류
     * @return int 부가세 합계
     */
    public static function sumFromClassification(array $taxClassification): int
    {
        $total = 0;

        foreach ($taxClassification as $tax) {
            if (isset($tax['vat_amount'])) {
                $total += (int) $tax['vat_amount'];

                continue;
            }

            $total += self::fromTaxableAmount(
                (int) ($tax['taxable_amount'] ?? 0),
                $tax['tax_rate'] ?? null
            );
        }

        return $total;
    }
}
