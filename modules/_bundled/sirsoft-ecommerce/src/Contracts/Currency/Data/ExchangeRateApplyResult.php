<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Currency\Data;

/**
 * 환율 반영 결과 (공개 계약 DTO)
 *
 * @since 1.4.0
 */
final readonly class ExchangeRateApplyResult
{
    /**
     * ExchangeRateApplyResult 생성자
     *
     * @param  array<int, string>  $applied  환율이 바뀌어 저장한 통화 코드
     * @param  array<int, string>  $unchanged  값이 같아 그대로 둔 통화 코드
     * @param  array<int, string>  $skipped  기준 통화이거나 값이 올바르지 않아 건너뛴 통화 코드
     */
    public function __construct(
        public array $applied,
        public array $unchanged,
        public array $skipped,
    ) {}
}
