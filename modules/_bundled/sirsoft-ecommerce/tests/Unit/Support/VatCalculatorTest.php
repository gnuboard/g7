<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Support\VatCalculator;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 부가세 계산 헬퍼 테스트 (E5)
 *
 * 기존 코드에 `× 10/110` 과 `/ 11` 두 표기가 섞여 있었습니다. 수학적으로 같은 식이므로
 * 헬퍼로 단일화했고, 세율이 섞인 주문에서는 옵션별 합산만이 정확합니다.
 */
class VatCalculatorTest extends ModuleTestCase
{
    /**
     * 10% 세율에서 과세표준 / 11 과 동일한 값을 산출하는지 테스트합니다.
     */
    public function test_default_rate_matches_divide_by_eleven(): void
    {
        foreach ([11000, 33000, 99999, 1, 7] as $taxable) {
            $this->assertSame(
                (int) round($taxable / 11),
                VatCalculator::fromTaxableAmount($taxable),
                "과세표준 {$taxable}"
            );
        }
    }

    /**
     * 세율 0(영세율) 과 면세는 부가세 0 인지 테스트합니다.
     */
    public function test_zero_rate_yields_zero_vat(): void
    {
        $this->assertSame(0, VatCalculator::fromTaxableAmount(50000, 0.0));
        $this->assertSame(0, VatCalculator::fromTaxableAmount(0, 10.0));
        $this->assertSame(0, VatCalculator::fromTaxableAmount(-100, 10.0));
    }

    /**
     * 상품별 세율이 반영되는지 테스트합니다.
     */
    public function test_custom_rate_is_applied(): void
    {
        // 세율 5% → 10500 / 21 = 500
        $this->assertSame(500, VatCalculator::fromTaxableAmount(10500, 5.0));
    }

    /**
     * 세율이 섞인 분류에서 옵션별 합산이 이뤄지는지 테스트합니다.
     */
    public function test_sum_from_classification_handles_mixed_rates(): void
    {
        $classification = [
            1 => ['taxable_amount' => 11000, 'tax_rate' => 10.0],
            2 => ['taxable_amount' => 10500, 'tax_rate' => 5.0],
            3 => ['taxable_amount' => 0, 'tax_rate' => null],  // 면세
        ];

        // 1000 + 500 + 0
        $this->assertSame(1500, VatCalculator::sumFromClassification($classification));
    }

    /**
     * 이미 계산된 vat_amount 가 있으면 그대로 합산하는지 테스트합니다.
     */
    public function test_sum_uses_precomputed_vat_amount(): void
    {
        $classification = [
            1 => ['taxable_amount' => 11000, 'tax_rate' => 10.0, 'vat_amount' => 1000],
            2 => ['taxable_amount' => 22000, 'tax_rate' => 10.0, 'vat_amount' => 2000],
        ];

        $this->assertSame(3000, VatCalculator::sumFromClassification($classification));
    }

    /**
     * 전체 과세표준에 단일 세율을 적용하는 방식과 결과가 다름을 고정합니다.
     *
     * 세율이 섞인 주문에서 단일 세율 계산은 틀린 값을 낸다 — 이것이 옵션별 합산이 필요한 이유.
     */
    public function test_mixed_rate_differs_from_single_rate_calculation(): void
    {
        $classification = [
            1 => ['taxable_amount' => 11000, 'tax_rate' => 10.0],
            2 => ['taxable_amount' => 10500, 'tax_rate' => 5.0],
        ];

        $perOption = VatCalculator::sumFromClassification($classification);
        $singleRate = VatCalculator::fromTaxableAmount(21500, 10.0);

        $this->assertSame(1500, $perOption);
        $this->assertNotSame($perOption, $singleRate);
    }
}
