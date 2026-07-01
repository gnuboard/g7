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
     * 부모 주문 리소스가 주입한 주문 시점 기준 통화 코드 (자식 리소스용).
     *
     * OrderResource 등 부모가 `currency_snapshot.base_currency` 를 추출해 자식
     * (OrderOption/OrderPayment/OrderShipping) 에 전파한다. null 이면 현재 기본 통화로 폴백.
     */
    protected ?string $orderCurrencyCode = null;

    /**
     * 주문 시점 기준 통화 코드를 주입합니다 (부모 → 자식 리소스 전파).
     *
     * @param  string|null  $currencyCode  주문 시점 기준 통화 코드
     * @return $this 메서드 체이닝을 위한 자기 자신
     */
    public function withOrderCurrency(?string $currencyCode): static
    {
        $this->orderCurrencyCode = $currencyCode;

        return $this;
    }

    /**
     * 다중 통화 가격 정보를 생성합니다.
     *
     * @param  float|int  $basePrice  기본 통화 가격
     * @return array 통화별 가격 정보
     */
    protected function buildMultiCurrencyPrices(float|int $basePrice): array
    {
        $currencies = $this->getCurrencySettings();
        $baseUnit = $this->getDefaultBaseUnit();
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
                    // 계산: (기본통화가격 / 기본통화.base_unit) * exchange_rate
                    $convertedPrice = ($basePrice / $baseUnit) * $exchangeRate;
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
     * 현재 설정의 기본 통화 base_unit(환율 분모)을 반환합니다.
     *
     * 설정에 base_unit 이 있으면 그 값을, 없으면 폴백(KRW=1000, JPY=100, 그 외=1)을 사용합니다.
     * CurrencyConversionService::getDefaultBaseUnit 과 동일한 규칙.
     *
     * @return int 기본 통화 base_unit (최소 1)
     */
    protected function getDefaultBaseUnit(): int
    {
        $defaultCode = $this->getDefaultCurrencyCode();
        $fallback = ['KRW' => 1000, 'JPY' => 100];

        foreach ($this->getCurrencySettings() as $currency) {
            if (($currency['code'] ?? null) === $defaultCode) {
                $unit = (int) ($currency['base_unit'] ?? $fallback[$defaultCode] ?? 1);

                return max(1, $unit);
            }
        }

        return $fallback[$defaultCode] ?? 1;
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
     * 기본 통화 기준 금액을 포맷팅합니다.
     *
     * `*_formatted` 같은 단일 통화 표시 필드는 기본 통화(설정의 is_default 통화) 기준 금액이므로,
     * 통화 기호를 원화로 하드코딩하지 않고 설정된 기본 통화의 기호·소수 자릿수로 포맷합니다.
     * 기본 통화가 원(KRW)이면 기존과 동일하게 "3,000원"으로, 달러면 "$3,000.00"으로 표시됩니다.
     *
     * @param  float|int|null  $price  기본 통화 기준 금액 (null 은 0 으로 처리)
     * @return string 기본 통화 기호로 포맷팅된 금액
     */
    protected function formatBaseCurrency(float|int|null $price): string
    {
        return $this->formatCurrencyPrice($price ?? 0, $this->getDefaultCurrencyCode());
    }

    /**
     * 주문 시점 기준 통화로 금액을 포맷팅합니다.
     *
     * 주문/결제/취소·환불 금액의 `*_formatted` 는 거래가 기록된 시점의 기준 통화로 고정 표기해야 합니다.
     * 운영자가 이후 기본 통화를 바꿔도 과거 주문의 표기 통화는 불변이어야 하므로, 주문 스냅샷에 저장된
     * 기준 통화(`currency_snapshot.base_currency`)를 명시적으로 받아 포맷합니다. 코드가 없으면(레거시
     * 주문 등) 현재 기본 통화로 폴백합니다.
     *
     * @param  float|int  $price  주문 기준 통화 금액
     * @param  string|null  $currencyCode  주문 시점 기준 통화 코드 (null 이면 현재 기본 통화)
     * @return string 해당 통화 기호로 포맷팅된 금액
     */
    protected function formatOrderCurrency(float|int|null $price, ?string $currencyCode = null): string
    {
        $code = $currencyCode
            ?: ($this->orderCurrencyCode ?: $this->getDefaultCurrencyCode());

        return $this->formatCurrencyPrice($price ?? 0, $code);
    }

    /**
     * 주문 모델에서 스냅샷 기준 통화 코드를 추출합니다.
     *
     * `currency_snapshot.base_currency` (주문 시점 시스템 기본 통화)를 우선 사용하고,
     * 없으면 현재 기본 통화로 폴백합니다.
     *
     * @param  mixed  $order  주문 모델(또는 currency_snapshot 속성을 가진 객체)
     * @return string 주문 시점 기준 통화 코드
     */
    protected function resolveOrderBaseCurrencyCode(mixed $order): string
    {
        $snapshot = $order->currency_snapshot ?? null;

        if (is_array($snapshot) && ! empty($snapshot['base_currency'])) {
            return $snapshot['base_currency'];
        }

        return $this->getDefaultCurrencyCode();
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
     * @param  array|null  $multiCurrencyAmounts  다중 통화 금액 배열 (예: {'KRW': 10000, 'USD': 7.5})
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
     * 금액을 지정 통화의 소수 자릿수로 라운딩한 raw 숫자로 반환합니다.
     *
     * DB 가격 컬럼은 통화와 무관하게 `decimal:2` 로 cast 되어, JPY/KRW(소수 0자리)
     * 통화에서도 `200.00` 처럼 소수가 붙어 응답됩니다. 이 메서드는 통화 설정의
     * `decimal_places` 를 적용해, 0자리 통화는 정수(`int`)로, 그 외는 해당 자릿수로
     * 라운딩한 `float` 로 반환합니다. JSON 직렬화 시 0자리 통화는 `200`, 2자리 통화는
     * `1.7` 처럼 표기됩니다. (표시용 `*_formatted` 와 달리 숫자 타입을 유지합니다.)
     *
     * @param  float|int|null  $price  기본 통화 기준 금액 (null 은 0 으로 처리)
     * @param  string  $code  통화 코드
     * @return float|int 통화 자릿수로 라운딩된 금액 (0자리면 int, 그 외 float)
     */
    protected function roundToCurrency(float|int|null $price, string $code): float|int
    {
        $decimalPlaces = $this->getDecimalPlacesForCurrency($code);
        $rounded = round((float) ($price ?? 0), $decimalPlaces);

        return $decimalPlaces === 0 ? (int) $rounded : $rounded;
    }

    /**
     * 금액을 현재 기본 통화의 소수 자릿수로 라운딩한 raw 숫자로 반환합니다.
     *
     * 상품·쿠폰·배송비 등 기본 통화 기준 raw 가격 필드에 사용합니다.
     *
     * @param  float|int|null  $price  기본 통화 기준 금액
     * @return float|int 기본 통화 자릿수로 라운딩된 금액
     */
    protected function roundToBaseCurrency(float|int|null $price): float|int
    {
        return $this->roundToCurrency($price, $this->getDefaultCurrencyCode());
    }

    /**
     * 금액을 주문 시점 기준 통화의 소수 자릿수로 라운딩한 raw 숫자로 반환합니다.
     *
     * 주문/결제/취소·환불의 raw 금액 필드에 사용합니다. 거래가 기록된 시점의 기준 통화
     * (`currency_snapshot.base_currency`)로 자릿수를 고정하며, 코드가 없으면 현재 기본
     * 통화로 폴백합니다. `formatOrderCurrency()` 와 동일한 통화 해석 규칙을 따릅니다.
     *
     * @param  float|int|null  $price  주문 기준 통화 금액
     * @param  string|null  $currencyCode  주문 시점 기준 통화 코드 (null 이면 주입값/현재 기본 통화)
     * @return float|int 주문 통화 자릿수로 라운딩된 금액
     */
    protected function roundToOrderCurrency(float|int|null $price, ?string $currencyCode = null): float|int
    {
        $code = $currencyCode
            ?: ($this->orderCurrencyCode ?: $this->getDefaultCurrencyCode());

        return $this->roundToCurrency($price, $code);
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
