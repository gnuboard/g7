<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 결제사에 넘기는 과세 구분 금액 (공개 계약 DTO, 기준 통화)
 *
 * @since 1.4.0
 */
final readonly class TaxSplit
{
    /**
     * TaxSplit 생성자
     *
     * @param  float  $taxFree  면세 금액(면세 + 영세율)
     * @param  float  $taxable  과세 금액(과세 − 영세율, 0 미만은 0)
     * @param  float  $vat  부가세 합계
     */
    public function __construct(
        public float $taxFree,
        public float $taxable,
        public float $vat,
    ) {}
}
