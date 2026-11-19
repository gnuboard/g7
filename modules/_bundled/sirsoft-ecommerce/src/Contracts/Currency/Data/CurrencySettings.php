<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Currency\Data;

/**
 * 다른 확장에 건네는 통화 설정 (공개 계약 DTO)
 *
 * 환율 제공 확장이 기준 통화와 관리 통화 목록을 읽을 때 쓴다. 통화 항목은 설정 저장 모양(배열) 그대로 싣는다 —
 * 환율 계산기가 코드·기준 단위·소수 자릿수 등을 키로 읽는다.
 *
 * @since 1.4.0
 */
final readonly class CurrencySettings
{
    /**
     * CurrencySettings 생성자
     *
     * @param  string  $defaultCurrency  기준 통화 코드(예: KRW)
     * @param  string|null  $rateProvider  환율 자동 갱신을 맡은 제공자 id(선택 안 했으면 null)
     * @param  array<int, array<string, mixed>>  $currencies  관리 통화 목록 — 항목 키: code, exchange_rate, base_unit, name, symbol, rounding_unit, rounding_method, decimal_places, is_default
     */
    public function __construct(
        public string $defaultCurrency,
        public ?string $rateProvider,
        public array $currencies,
    ) {}
}
