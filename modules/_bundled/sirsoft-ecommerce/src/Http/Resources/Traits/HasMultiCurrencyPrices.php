<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources\Traits;

/**
 * 다중 통화 가격 변환 Trait
 *
 * API 리소스에서 다중 통화 가격 정보를 생성하는 공통 메서드를 제공합니다.
 * ProductListResource, ProductOptionResource 등에서 사용됩니다.
 */
trait HasMultiCurrencyPrices
{
    /**
     * 통화 설정 캐시 (동일 요청 내 중복 조회 방지)
     */
    private static ?array $currencySettingsCache = null;

    /**
     * 다중 통화 가격 정보를 생성합니다.
     *
     * @param  float|int  $basePrice  기본 통화 가격
     * @return array 통화별 가격 정보
     */
    protected function buildMultiCurrencyPrices(float|int $basePrice): array
    {
        $currencies = $this->getCurrencySettings();
        $result = [];

        foreach ($currencies as $currency) {
            $code = $currency['code'];
            $isDefault = $currency['is_default'] ?? false;

            if ($isDefault) {
                // 기본 통화 (is_default: true로 설정된 통화)
                $result[$code] = [
                    'price' => $basePrice,
                    'formatted' => $this->formatCurrencyPrice($basePrice, $code),
                    'is_default' => true,
                    'editable' => true,
                ];
            } else {
                // 외화: 환율 기반 계산
                $exchangeRate = $currency['exchange_rate'] ?? 0;
                $roundingUnit = $currency['rounding_unit'] ?? '0.01';
                $roundingMethod = $currency['rounding_method'] ?? 'round';

                if ($exchangeRate > 0) {
                    // 계산: (기본통화가격 / 1000) * exchange_rate
                    $convertedPrice = ($basePrice / 1000) * $exchangeRate;
                    $convertedPrice = $this->applyRounding($convertedPrice, $roundingUnit, $roundingMethod);

                    // 부동소수점 오차 제거 (4505 * 0.01 = 45.050000000000004 방지)
                    $decimalPlaces = $currency['decimal_places'] ?? 2;
                    $convertedPrice = round($convertedPrice, $decimalPlaces);

                    $result[$code] = [
                        'price' => $convertedPrice,
                        'formatted' => $this->formatCurrencyPrice($convertedPrice, $code),
                        'is_default' => false,
                        'editable' => false,
                        'exchange_rate' => $exchangeRate,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * 통화 설정을 조회합니다 (캐시 적용).
     *
     * @return array 통화 설정 배열
     */
    protected function getCurrencySettings(): array
    {
        if (self::$currencySettingsCache === null) {
            $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency');
            self::$currencySettingsCache = $settings['currencies'] ?? [];
        }

        return self::$currencySettingsCache;
    }

    /**
     * 기본 통화 코드를 반환합니다.
     *
     * @return string 기본 통화 코드 (예: 'KRW')
     */
    protected function getDefaultCurrencyCode(): string
    {
        $currencies = $this->getCurrencySettings();

        foreach ($currencies as $currency) {
            if ($currency['is_default'] ?? false) {
                return $currency['code'];
            }
        }

        // 설정이 없는 경우 모듈 설정에서 직접 조회
        $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency');

        return $settings['default_currency'] ?? 'KRW';
    }

    /**
     * 절사/반올림/올림을 적용합니다.
     *
     * @param  float  $price  가격
     * @param  string  $unit  절사 단위
     * @param  string  $method  방법 (floor, round, ceil)
     * @return float 처리된 가격
     */
    protected function applyRounding(float $price, string $unit, string $method): float
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
    protected function formatCurrencyPrice(float|int $price, string $code): string
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

        $decimalPlaces = $this->getDecimalPlacesForCurrency($code);
        $formattedNumber = number_format($price, $decimalPlaces);

        // prefix나 suffix가 없으면 기본 포맷
        if (empty($prefix) && empty($suffix)) {
            return $formattedNumber.' '.$code;
        }

        return $prefix.$formattedNumber.$suffix;
    }

    /**
     * 통화의 소수 자릿수를 반환합니다.
     *
     * @param  string  $code  통화 코드
     * @return int 소수 자릿수 (기본값: 2)
     */
    protected function getDecimalPlacesForCurrency(string $code): int
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
     * 저장된 다중 통화 금액을 포맷팅합니다.
     *
     * 주문 시점에 저장된 mc_* 필드를 프론트엔드에서 사용할 수 있는 형태로 변환합니다.
     * buildMultiCurrencyPrices()와 달리 환율 재계산 없이 저장된 값을 그대로 포맷합니다.
     *
     * @param array|null $multiCurrencyAmounts 다중 통화 금액 배열 (예: {'KRW': 10000, 'USD': 7.5})
     * @return array 포맷된 배열 (예: {'KRW': {'amount': 10000, 'formatted': '10,000원'}, ...})
     */
    protected function formatStoredMultiCurrency(?array $multiCurrencyAmounts): array
    {
        if (empty($multiCurrencyAmounts)) {
            return [];
        }

        $result = [];

        foreach ($multiCurrencyAmounts as $code => $amount) {
            if (! is_numeric($amount)) {
                continue;
            }

            $result[$code] = [
                'amount' => $amount,
                'formatted' => $this->formatCurrencyPrice($amount, $code),
            ];
        }

        return $result;
    }

    /**
     * 통화 설정 캐시를 초기화합니다.
     *
     * 테스트 또는 설정 변경 시 캐시를 리셋해야 할 때 사용합니다.
     */
    public static function clearCurrencySettingsCache(): void
    {
        self::$currencySettingsCache = null;
    }
}
