<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use InvalidArgumentException;

/**
 * 통화 변환 서비스
 *
 * 기본 통화 금액을 다른 통화로 변환하는 기능을 제공합니다.
 * OrderCalculationService, ProductResource 등에서 공통으로 사용됩니다.
 *
 * 환율 계산식: 변환금액 = (기본통화금액 / 1000) × exchange_rate
 */
class CurrencyConversionService
{
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
     * 단일 금액을 다중 통화로 변환합니다.
     *
     * @param  int  $basePrice  기본 통화 금액
     * @return array<string, array{price: float|int, formatted: string, is_default: bool, exchange_rate?: float}> 통화별 가격 정보
     */
    public function convertToMultiCurrency(int $basePrice): array
    {
        $currencies = $this->getCurrencySettings();
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
                    $convertedPrice = ($basePrice / 1000) * $exchangeRate;
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
                        $convertedPrice = ($baseAmount / 1000) * $exchangeRate;
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

        $convertedPrice = ($basePrice / 1000) * $exchangeRate;
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
                        $convertedPrice = ($baseAmount / 1000) * $snapshotRate;
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
                $convertedPrice = ($basePrice / 1000) * $snapshotRate;
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
