<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 통화 변환 서비스
 *
 * 기본 통화 금액을 다른 통화로 변환하는 기능을 제공합니다.
 * OrderCalculationService, ProductResource 등에서 공통으로 사용됩니다.
 *
 * 환율 계산식: 변환금액 = (기본통화금액 / 기본통화.base_unit) × exchange_rate
 *
 * base_unit 은 "그 통화가 기본 통화일 때 환율 입력의 분모가 되는 1단위 금액"이다.
 * 소액 통화만 묶음 단위를 쓴다: KRW=1000, JPY=100, 그 외(USD/CNY/EUR 등)=1.
 * 환율(exchange_rate)은 "1 base_unit 당 N 해당통화"로 입력한다.
 *   예) 기본=USD(base_unit 1): USD→JPY=157 (1달러=157엔)
 *       기본=KRW(base_unit 1000): KRW→USD=0.71 (1000원=0.71달러)
 */
class CurrencyConversionService
{
    /**
     * base_unit 미설정 통화의 폴백 기본값 (소액 통화만 묶음 단위).
     */
    private const BASE_UNIT_FALLBACK = [
        'KRW' => 1000,
        'JPY' => 100,
    ];

    /**
     * 통화 설정 캐시 (동일 요청 내 중복 조회 방지)
     */
    private ?array $currencySettings = null;

    /**
     * 기본 통화 코드 캐시
     */
    private ?string $defaultCurrency = null;

    /**
     * 통화 설정을 조회합니다 (캐시 적용).
     *
     * @return array 통화 설정 배열
     */
    public function getCurrencySettings(): array
    {
        if ($this->currencySettings === null) {
            $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency');
            $this->currencySettings = $settings['currencies'] ?? [];
        }

        return $this->currencySettings;
    }

    /**
     * 기본 통화 코드를 반환합니다.
     *
     * @return string 기본 통화 코드 (예: 'KRW')
     */
    public function getDefaultCurrency(): string
    {
        if ($this->defaultCurrency === null) {
            $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency');
            $this->defaultCurrency = $settings['default_currency'] ?? 'KRW';
        }

        return $this->defaultCurrency;
    }

    /**
     * 지원 통화 코드 목록을 반환합니다.
     *
     * @return string[] 지원 통화 코드 배열
     */
    public function getSupportedCurrencies(): array
    {
        return array_column($this->getCurrencySettings(), 'code');
    }

    /**
     * 통화의 소수 자릿수를 반환합니다.
     *
     * @param  string  $code  통화 코드
     * @return int 소수 자릿수 (기본값: 2)
     */
    public function getDecimalPlaces(string $code): int
    {
        $currencies = $this->getCurrencySettings();

        foreach ($currencies as $currency) {
            if ($currency['code'] === $code) {
                return $currency['decimal_places'] ?? 2;
            }
        }

        // 설정에 없는 통화는 기본값 2 반환
        return 2;
    }

    /**
     * 통화의 base_unit(기본 통화일 때 환율 분모가 되는 1단위 금액)을 반환합니다.
     *
     * 설정에 base_unit 이 있으면 그 값을, 없으면 폴백(KRW=1000, JPY=100, 그 외=1)을 사용합니다.
     *
     * @param  string  $code  통화 코드
     * @return int base_unit (최소 1)
     */
    public function getBaseUnit(string $code): int
    {
        foreach ($this->getCurrencySettings() as $currency) {
            if (($currency['code'] ?? null) === $code) {
                $unit = (int) ($currency['base_unit'] ?? self::BASE_UNIT_FALLBACK[$code] ?? 1);

                return max(1, $unit);
            }
        }

        return self::BASE_UNIT_FALLBACK[$code] ?? 1;
    }

    /**
     * 현재 설정의 기본 통화 base_unit(실시간 변환의 분모)을 반환합니다.
     *
     * @return int 기본 통화 base_unit (최소 1)
     */
    public function getDefaultBaseUnit(): int
    {
        return $this->getBaseUnit($this->getDefaultCurrency());
    }

    /**
     * 스냅샷에 박제된 base_unit(스냅샷 변환의 분모)을 반환합니다.
     *
     * 스냅샷 최상위 base_unit → exchange_rates[base_currency].base_unit 순으로 찾고,
     * 둘 다 없으면 1000 폴백(옛 KRW-base ÷1000 공식 주문 호환 — 환차손 0 유지).
     *
     * @param  array  $currencySnapshot  주문 시점 통화 스냅샷
     * @return int base_unit (최소 1)
     */
    public function getSnapshotBaseUnit(array $currencySnapshot): int
    {
        $baseCurrency = $currencySnapshot['base_currency'] ?? $this->getDefaultCurrency();

        $unit = $currencySnapshot['base_unit']
            ?? ($currencySnapshot['exchange_rates'][$baseCurrency]['base_unit'] ?? null);

        if ($unit === null) {
            // 옛 스냅샷(base_unit 미박제): ÷1000 공식과 짝 → 1000 폴백
            return 1000;
        }

        return max(1, (int) $unit);
    }

    /**
     * 단일 금액을 다중 통화로 변환합니다.
     *
     * @param  int  $basePrice  기본 통화 금액
     * @return array<string, array{price: float|int, formatted: string, is_default: bool, exchange_rate?: float}> 통화별 가격 정보
     */
    public function convertToMultiCurrency(int $basePrice): array
    {
        $currencies = $this->getCurrencySettings();
        $baseUnit = $this->getDefaultBaseUnit();
        $result = [];

        foreach ($currencies as $currency) {
            $code = $currency['code'];
            $isDefault = $currency['is_default'] ?? false;

            if ($isDefault) {
                // 기본 통화: 변환 없이 원본 반환
                $result[$code] = [
                    'price' => $basePrice,
                    'formatted' => $this->formatPrice($basePrice, $code),
                    'is_default' => true,
                ];
            } else {
                // 외화: 환율 기반 계산
                $exchangeRate = $currency['exchange_rate'] ?? 0;

                if ($exchangeRate > 0) {
                    $convertedPrice = ($basePrice / $baseUnit) * $exchangeRate;
                    $convertedPrice = $this->applyRounding(
                        $convertedPrice,
                        $currency['rounding_unit'] ?? '0.01',
                        $currency['rounding_method'] ?? 'round'
                    );

                    $result[$code] = [
                        'price' => $convertedPrice,
                        'formatted' => $this->formatPrice($convertedPrice, $code),
                        'is_default' => false,
                        'exchange_rate' => $exchangeRate,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * 여러 금액 필드를 통화별로 그룹화하여 변환합니다.
     *
     * @param  array<string, int>  $amounts  필드명 => 기본통화 금액
     * @return array<string, array<string, float|int|array>> 통화코드 => [필드명 => 변환금액, '_meta' => [...]]
     */
    public function convertMultipleAmounts(array $amounts): array
    {
        $currencies = $this->getCurrencySettings();
        $baseUnit = $this->getDefaultBaseUnit();
        $result = [];

        foreach ($currencies as $currency) {
            $code = $currency['code'];
            $isDefault = $currency['is_default'] ?? false;
            $exchangeRate = $currency['exchange_rate'] ?? null;
            $roundingUnit = $currency['rounding_unit'] ?? '0.01';
            $roundingMethod = $currency['rounding_method'] ?? 'round';

            $currencyAmounts = [];

            foreach ($amounts as $field => $baseAmount) {
                if ($isDefault) {
                    // 기본 통화: 변환 없이 원본
                    $currencyAmounts[$field] = $baseAmount;
                    $currencyAmounts[$field.'_formatted'] = $this->formatPrice($baseAmount, $code);
                } else {
                    // 외화: 환율 적용
                    if ($exchangeRate > 0) {
                        $convertedPrice = ($baseAmount / $baseUnit) * $exchangeRate;
                        $convertedAmount = $this->applyRounding(
                            $convertedPrice,
                            $roundingUnit,
                            $roundingMethod
                        );
                        $currencyAmounts[$field] = $convertedAmount;
                        $currencyAmounts[$field.'_formatted'] = $this->formatPrice($convertedAmount, $code);
                    }
                }
            }

            // 외화인데 exchange_rate가 없으면 결과에 포함하지 않음
            if (! $isDefault && empty($currencyAmounts)) {
                continue;
            }

            $result[$code] = $currencyAmounts;
            $result[$code]['_meta'] = [
                'is_default' => $isDefault,
                'exchange_rate' => $exchangeRate,
            ];
        }

        return $result;
    }

    /**
     * 특정 통화로 단일 변환합니다 (결제용).
     *
     * @param  int  $basePrice  기본 통화 금액
     * @param  string  $targetCurrency  변환할 통화 코드
     * @return array{price: float|int, formatted: string, currency: string, exchange_rate?: float}
     *
     * @throws InvalidArgumentException 미지원 통화인 경우
     */
    public function convertToCurrency(int $basePrice, string $targetCurrency): array
    {
        $currencies = $this->getCurrencySettings();
        $currencyConfig = null;

        foreach ($currencies as $currency) {
            if ($currency['code'] === $targetCurrency) {
                $currencyConfig = $currency;
                break;
            }
        }

        if (! $currencyConfig) {
            throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.unknown_currency', ['currency' => $targetCurrency]));
        }

        if ($currencyConfig['is_default'] ?? false) {
            return [
                'price' => $basePrice,
                'formatted' => $this->formatPrice($basePrice, $targetCurrency),
                'currency' => $targetCurrency,
            ];
        }

        $exchangeRate = $currencyConfig['exchange_rate'] ?? 0;

        if ($exchangeRate <= 0) {
            throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.invalid_exchange_rate', ['currency' => $targetCurrency]));
        }

        $convertedPrice = ($basePrice / $this->getDefaultBaseUnit()) * $exchangeRate;
        $convertedPrice = $this->applyRounding(
            $convertedPrice,
            $currencyConfig['rounding_unit'] ?? '0.01',
            $currencyConfig['rounding_method'] ?? 'round'
        );

        return [
            'price' => $convertedPrice,
            'formatted' => $this->formatPrice($convertedPrice, $targetCurrency),
            'currency' => $targetCurrency,
            'exchange_rate' => $exchangeRate,
        ];
    }

    /**
     * 주문 스냅샷 기준으로 PG 청구 금액을 산정합니다 (결제 청구 SSoT).
     *
     * 주문 시점에 동결된 currency_snapshot 의 환율로 base 금액을 결제 통화(order_currency)
     * 금액으로 환산하고, PG 가 요구하는 최소 화폐단위 정수(minor unit)까지 산출합니다.
     * 현재 환율을 재조회하지 않으므로 환차손이 0 입니다(D-BASE-3).
     *
     * - base 통화와 결제 통화가 같으면 환산 없이 base 금액을 그대로 사용합니다.
     * - 비-base 결제 통화는 (base / 스냅샷 base_unit) × 스냅샷 환율 + 스냅샷 절사규칙으로 환산합니다.
     * - minor_unit_amount = 환산금액 × 10^decimal_places (KRW 0자리→×1, USD 2자리→×100).
     *
     * @param  float|int  $baseAmount  결제예정 base 통화 금액(total_due_amount)
     * @param  array  $currencySnapshot  주문 시점 통화 스냅샷(buildCurrencySnapshot 형식)
     * @return array{currency: string, amount: float|int, minor_unit_amount: int, decimal_places: int, exchange_rate: float} 청구 통화/금액
     *
     * @throws InvalidArgumentException 결제 통화의 환율이 없거나 0 이하인 경우(미지원 통화)
     */
    public function resolveSnapshotPaymentCharge(float|int $baseAmount, array $currencySnapshot): array
    {
        $baseCurrency = $currencySnapshot['base_currency'] ?? $this->getDefaultCurrency();
        $orderCurrency = $currencySnapshot['order_currency'] ?? $baseCurrency;
        $exchangeRates = $currencySnapshot['exchange_rates'] ?? [];

        $rateData = $exchangeRates[$orderCurrency] ?? null;

        // 스냅샷 환율/절사규칙 추출 (하위 호환: 단순 float 형태 허용)
        if (is_numeric($rateData)) {
            $snapshotRate = (float) $rateData;
            $roundingUnit = '0.01';
            $roundingMethod = 'round';
            $decimalPlaces = $this->getDecimalPlaces($orderCurrency);
        } else {
            $snapshotRate = (float) ($rateData['rate'] ?? 0);
            $roundingUnit = $rateData['rounding_unit'] ?? '0.01';
            $roundingMethod = $rateData['rounding_method'] ?? 'round';
            $decimalPlaces = (int) ($rateData['decimal_places'] ?? $this->getDecimalPlaces($orderCurrency));
        }

        $isBase = ($orderCurrency === $baseCurrency);

        if ($isBase) {
            // base 통화 결제: 환산 없이 그대로. base 의 decimal_places 로 정수화.
            $convertedAmount = (float) $baseAmount;
            $decimalPlaces = (int) ($rateData['decimal_places'] ?? $this->getDecimalPlaces($orderCurrency));
            $snapshotRate = 1.0;
        } else {
            if ($snapshotRate <= 0) {
                throw new InvalidArgumentException(
                    __('sirsoft-ecommerce::exceptions.invalid_exchange_rate', ['currency' => $orderCurrency])
                );
            }

            $convertedPrice = ((float) $baseAmount / $this->getSnapshotBaseUnit($currencySnapshot)) * $snapshotRate;
            $convertedAmount = $this->applyRounding($convertedPrice, $roundingUnit, $roundingMethod);
        }

        // PG 최소 화폐단위 정수 (KRW: ×1, USD/소수통화: ×10^2). 부동소수 오차 방어로 round.
        $minorUnitAmount = (int) round($convertedAmount * (10 ** $decimalPlaces));

        return [
            'currency' => $orderCurrency,
            'amount' => $convertedAmount,
            'minor_unit_amount' => $minorUnitAmount,
            'decimal_places' => $decimalPlaces,
            'exchange_rate' => $snapshotRate,
        ];
    }

    /**
     * 주문의 PG 청구/승인 검증 기준 금액(결제 통화 minor unit 정수)을 산정합니다.
     *
     * PG 결제창에 청구하는 금액(buildPgPaymentData → resolveSnapshotPaymentCharge['minor_unit_amount'])과
     * 동일 SSoT 로, 결제 통화(order_currency)가 base 통화와 다를 때도 정합한 비교 기준을 제공합니다.
     * 코어 최종 승인 검증(validatePaymentAmount 2단계)과 각 PG 플러그인 콜백/사전가드가 이 메서드를
     * 공유해, base≠결제 통화 조합에서 "결제금액 불일치" 회귀를 차단합니다.
     *
     * total_due_amount(base) 를 직접 정수화해 비교하면 base≠order_currency 일 때(예: base JPY,
     * 결제 KRW) PG 청구액(환산 KRW)과 단위가 어긋난다. 반드시 본 메서드를 거쳐야 한다.
     *
     * @param  Order  $order  주문(total_due_amount + currency_snapshot 보유)
     * @return int 결제 통화 기준 청구 금액(최소 화폐단위 정수)
     *
     * @throws InvalidArgumentException 결제 통화의 환율이 없거나 0 이하인 경우(미지원 통화)
     */
    public function resolveOrderPaymentChargeAmount(Order $order): int
    {
        return $this->resolveSnapshotPaymentCharge(
            (float) $order->total_due_amount,
            $order->currency_snapshot ?? []
        )['minor_unit_amount'];
    }

    /**
     * 반올림/절사/올림을 적용합니다.
     *
     * @param  float  $price  가격
     * @param  string  $unit  절사 단위 (예: '1', '0.01')
     * @param  string  $method  방법 (floor, round, ceil)
     * @return float 처리된 가격
     */
    public function applyRounding(float $price, string $unit, string $method): float
    {
        $unitValue = (float) $unit;
        if ($unitValue <= 0) {
            $unitValue = 1;
        }

        $divided = $price / $unitValue;

        $rounded = match ($method) {
            'ceil' => ceil($divided),
            'floor' => floor($divided),
            default => round($divided),
        };

        return $rounded * $unitValue;
    }

    /**
     * 통화별 가격을 포맷팅합니다.
     *
     * @param  float|int  $price  가격
     * @param  string  $code  통화 코드
     * @return string 포맷팅된 가격
     */
    public function formatPrice(float|int $price, string $code): string
    {
        $prefix = __('sirsoft-ecommerce::messages.currency.prefix.'.$code, [], app()->getLocale());
        $suffix = __('sirsoft-ecommerce::messages.currency.suffix.'.$code, [], app()->getLocale());

        // 번역 키가 없으면 기본값 사용
        if ($prefix === 'sirsoft-ecommerce::messages.currency.prefix.'.$code) {
            $prefix = '';
        }
        if ($suffix === 'sirsoft-ecommerce::messages.currency.suffix.'.$code) {
            $suffix = '';
        }

        $decimalPlaces = $this->getDecimalPlaces($code);
        $formattedNumber = number_format($price, $decimalPlaces);

        // prefix나 suffix가 없으면 기본 포맷
        if (empty($prefix) && empty($suffix)) {
            return $formattedNumber.' '.$code;
        }

        return $prefix.$formattedNumber.$suffix;
    }

    /**
     * 통화가 지원되는지 확인합니다.
     *
     * @param  string  $currencyCode  통화 코드
     * @return bool 지원 여부
     */
    public function isSupportedCurrency(string $currencyCode): bool
    {
        return in_array($currencyCode, $this->getSupportedCurrencies(), true);
    }

    /**
     * 스냅샷 환율 기반으로 다통화 변환을 수행합니다.
     *
     * 환불 재계산 등에서 주문 시점의 환율을 사용하여
     * 현재 환율이 아닌 스냅샷 환율로 금액을 변환합니다.
     *
     * @param  array<string, int|float>  $amounts  필드명 → 기본통화 금액
     * @param  array  $currencySnapshot  주문 시점의 통화 스냅샷 (buildCurrencySnapshot 형식)
     * @return array<string, array> 통화코드 → [필드명 → 변환금액, 필드명_formatted → 포맷]
     */
    public function convertMultipleAmountsWithSnapshot(array $amounts, array $currencySnapshot): array
    {
        $exchangeRates = $currencySnapshot['exchange_rates'] ?? [];
        $baseCurrency = $currencySnapshot['base_currency'] ?? $this->getDefaultCurrency();
        $baseUnit = $this->getSnapshotBaseUnit($currencySnapshot);
        $result = [];

        foreach ($exchangeRates as $code => $rateData) {
            // 하위 호환: 기존 스냅샷이 단순 float 형태인 경우
            if (is_numeric($rateData)) {
                $snapshotRate = (float) $rateData;
                $roundingUnit = '0.01';
                $roundingMethod = 'round';
            } else {
                $snapshotRate = (float) ($rateData['rate'] ?? 1.0);
                $roundingUnit = $rateData['rounding_unit'] ?? '0.01';
                $roundingMethod = $rateData['rounding_method'] ?? 'round';
            }

            $isDefault = ($code === $baseCurrency);
            $currencyAmounts = [];

            foreach ($amounts as $field => $baseAmount) {
                if ($isDefault) {
                    $currencyAmounts[$field] = $baseAmount;
                    $currencyAmounts[$field.'_formatted'] = $this->formatPrice($baseAmount, $code);
                } else {
                    if ($snapshotRate > 0) {
                        $convertedPrice = ($baseAmount / $baseUnit) * $snapshotRate;
                        $convertedAmount = $this->applyRounding(
                            $convertedPrice,
                            $roundingUnit,
                            $roundingMethod
                        );
                        $currencyAmounts[$field] = $convertedAmount;
                        $currencyAmounts[$field.'_formatted'] = $this->formatPrice($convertedAmount, $code);
                    }
                }
            }

            if (! $isDefault && empty($currencyAmounts)) {
                continue;
            }

            $result[$code] = $currencyAmounts;
            $result[$code]['_meta'] = [
                'is_default' => $isDefault,
                'exchange_rate' => $snapshotRate,
                'snapshot_based' => true,
            ];
        }

        return $result;
    }

    /**
     * 스냅샷 환율 기반으로 단일 금액을 다중 통화로 변환합니다.
     *
     * buildAllCurrencyConverter에서 사용하며,
     * 주문 시점의 환율/절사규칙으로 금액을 변환합니다.
     *
     * @param  int|float  $basePrice  기본 통화 금액
     * @param  array  $currencySnapshot  주문 시점의 통화 스냅샷
     * @return array<string, float|int> 통화코드 → 변환금액
     */
    public function convertToMultiCurrencyWithSnapshot(int|float $basePrice, array $currencySnapshot): array
    {
        $exchangeRates = $currencySnapshot['exchange_rates'] ?? [];
        $baseCurrency = $currencySnapshot['base_currency'] ?? $this->getDefaultCurrency();
        $baseUnit = $this->getSnapshotBaseUnit($currencySnapshot);
        $result = [];

        foreach ($exchangeRates as $code => $rateData) {
            $isDefault = ($code === $baseCurrency);

            if ($isDefault) {
                $result[$code] = $basePrice;

                continue;
            }

            // 하위 호환: 기존 스냅샷이 단순 float 형태인 경우
            if (is_numeric($rateData)) {
                $snapshotRate = (float) $rateData;
                $roundingUnit = '0.01';
                $roundingMethod = 'round';
            } else {
                $snapshotRate = (float) ($rateData['rate'] ?? 0);
                $roundingUnit = $rateData['rounding_unit'] ?? '0.01';
                $roundingMethod = $rateData['rounding_method'] ?? 'round';
            }

            if ($snapshotRate > 0) {
                $convertedPrice = ($basePrice / $baseUnit) * $snapshotRate;
                $result[$code] = $this->applyRounding($convertedPrice, $roundingUnit, $roundingMethod);
            }
        }

        // 변환 결과가 없으면 기본 통화라도 포함
        if (empty($result)) {
            $result[$baseCurrency] = $basePrice;
        }

        return $result;
    }

    /**
     * 캐시를 초기화합니다.
     *
     * 테스트 또는 설정 변경 시 캐시를 리셋해야 할 때 사용합니다.
     */
    public function clearCache(): void
    {
        $this->currencySettings = null;
        $this->defaultCurrency = null;
    }
}
