<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 결제 통화로 환산한 청구액 (공개 계약 DTO)
 *
 * 결제사에 보내는 금액은 `minorUnitAmount`(최소 화폐 단위 정수)다.
 *
 * @since 1.4.0
 */
final readonly class ChargeAmount
{
    /**
     * ChargeAmount 생성자
     *
     * @param  int  $minorUnitAmount  최소 화폐 단위 정수 금액
     * @param  string  $currency  청구 통화
     * @param  float|int|null  $amount  청구 통화 금액(클레임 결제 건은 계산하지 않아 null)
     * @param  int|null  $decimalPlaces  청구 통화 소수 자릿수
     * @param  float|null  $exchangeRate  적용 환율
     */
    public function __construct(
        public int $minorUnitAmount,
        public string $currency,
        public float|int|null $amount = null,
        public ?int $decimalPlaces = null,
        public ?float $exchangeRate = null,
    ) {}

    /**
     * 통화 환산 결과 배열에서 DTO 를 만듭니다.
     *
     * @internal 이커머스 계약 구현만 쓴다.
     *
     * @param  array<string, mixed>  $charge  환산 결과(minor_unit_amount, currency, amount, decimal_places, exchange_rate)
     * @return self DTO
     */
    public static function fromArray(array $charge): self
    {
        return new self(
            minorUnitAmount: (int) $charge['minor_unit_amount'],
            currency: (string) $charge['currency'],
            amount: $charge['amount'] ?? null,
            decimalPlaces: isset($charge['decimal_places']) ? (int) $charge['decimal_places'] : null,
            exchangeRate: isset($charge['exchange_rate']) ? (float) $charge['exchange_rate'] : null,
        );
    }
}
