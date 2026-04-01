<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 다중 통화 금액 컨테이너 DTO
 *
 * 여러 금액 필드의 다통화 변환 결과를 통화 코드를 키로 그룹화합니다.
 * ItemCalculation, Summary 등에서 다통화 정보를 담는 데 사용됩니다.
 */
class MultiCurrencyPrices
{
    /**
     * @param  array<string, array<string, float|int|array>>  $currencies  통화별 금액 필드들
     *     예: [
     *         'USD' => ['subtotal' => 85.0, 'final_amount' => 80.0, '_meta' => ['is_default' => false, 'exchange_rate' => 0.85]],
     *         'KRW' => ['subtotal' => 100000, 'final_amount' => 94000, '_meta' => ['is_default' => true, 'exchange_rate' => null]],
     *     ]
     */
    public function __construct(
        public array $currencies = [],
    ) {}

    /**
     * 특정 통화의 모든 금액을 반환합니다.
     *
     * @param  string  $code  통화 코드
     * @return array<string, float|int|array> 해당 통화의 금액 필드들
     */
    public function getCurrency(string $code): array
    {
        return $this->currencies[$code] ?? [];
    }

    /**
     * 특정 통화의 특정 필드 값을 반환합니다.
     *
     * @param  string  $code  통화 코드
     * @param  string  $field  필드명
     * @return float|int|null 금액 또는 null
     */
    public function getAmount(string $code, string $field): float|int|null
    {
        return $this->currencies[$code][$field] ?? null;
    }

    /**
     * 통화가 기본통화인지 확인합니다.
     *
     * @param  string  $code  통화 코드
     * @return bool 기본통화 여부
     */
    public function isDefaultCurrency(string $code): bool
    {
        return $this->currencies[$code]['_meta']['is_default'] ?? false;
    }

    /**
     * 특정 통화의 환율을 반환합니다.
     *
     * @param  string  $code  통화 코드
     * @return float|null 환율 (기본통화는 null)
     */
    public function getExchangeRate(string $code): ?float
    {
        return $this->currencies[$code]['_meta']['exchange_rate'] ?? null;
    }

    /**
     * 지원하는 통화 코드 목록을 반환합니다.
     *
     * @return string[] 통화 코드 배열
     */
    public function getCurrencyCodes(): array
    {
        return array_keys($this->currencies);
    }

    /**
     * 배열로 변환합니다.
     *
     * @return array<string, array<string, float|int|array>>
     */
    public function toArray(): array
    {
        return $this->currencies;
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(currencies: $data);
    }
}
