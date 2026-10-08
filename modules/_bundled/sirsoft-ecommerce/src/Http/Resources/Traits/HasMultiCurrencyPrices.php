<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources\Traits;

use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;
use Modules\Sirsoft\Ecommerce\Support\EarnedPointsFormatter;

// audit:allow api-doc-coverage 절사 결과의 되곱하기 잔차만 제거한다 — 응답 필드·타입·구조가
// 그대로라 갱신할 API 레퍼런스 서술이 없다 (금액 값의 표현만 설정 단위에 맞게 정규화).

/**
 * 다중 통화 가격 변환 Trait
 *
 * API 리소스에서 다중 통화 가격 정보를 생성하는 공통 메서드를 제공합니다.
 * ProductListResource, ProductOptionResource 등에서 사용됩니다.
 */
trait HasMultiCurrencyPrices
{
    /**
     * 부모 주문 리소스가 주입한 주문 시점 기준 통화 코드 (자식 리소스용).
     *
     * OrderResource 등 부모가 `currency_snapshot.base_currency` 를 추출해 자식
     * (OrderOption/OrderPayment/OrderShipping) 에 전파한다. null 이면 현재 기본 통화로 폴백.
     */
    protected ?string $orderCurrencyCode = null;

    /**
     * 부모 주문 리소스가 주입한 주문 시점 통화 스냅샷 (공개 #91 후속).
     *
     * 소수 자릿수를 현재 설정이 아니라 주문 시점 값으로 고정하기 위해 전파한다.
     * null 이면 현재 설정으로 폴백 — 상품 등 카탈로그 경로는 그것이 정답이다.
     */
    protected ?array $currencySnapshot = null;

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
     * 주문 시점 통화 스냅샷을 주입합니다 (부모 → 자식 리소스 전파).
     *
     * 관리자가 통화를 삭제하면 현재 설정에서 사라져 자릿수가 폴백 2자리로 바뀐다.
     * 그러면 0자리 통화로 결제된 과거 주문의 표기가 `¥14,835` → `¥14,835.00` 이 되고,
     * 3자리 이상 통화는 표시 금액이 절사된다. 스냅샷이 있으면 그것을 SSoT 로 삼는다.
     *
     * @param  array|null  $snapshot  주문의 `currency_snapshot`
     * @return $this 메서드 체이닝을 위한 자기 자신
     */
    public function withCurrencySnapshot(?array $snapshot): static
    {
        $this->currencySnapshot = $snapshot;

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
     * 가감액(추가금)의 다중 통화 정보를 생성합니다.
     *
     * 가격과 달리 추가금은 부호를 가지며 화면에 `+3,000원` / `-2,000원` 형태로 표시된다.
     * 환산은 절대값으로 수행한 뒤 부호를 되돌린다 — 통화별 반올림 규칙이 음수에서
     * 방향이 뒤집히는 것(예: floor 가 -2,850 을 -2,851 로)을 막기 위함이다.
     *
     * 추가옵션 추가금은 기본 통화 기준으로 저장되므로, 기본 통화가 아닌 통화로 보는
     * 구매자에게는 이 맵이 없으면 환산할 근거가 없다. 상품가·옵션가와 같은 형태로
     * 내보내 화면이 동일한 방식(`[표시통화].formatted`)으로 읽게 한다.
     *
     * @param  float|int  $baseAdjustment  기본 통화 기준 가감액 (음수 가능)
     * @return array 통화별 가감액 정보 (price 는 부호 있는 값, formatted 는 부호 접두)
     */
    protected function buildMultiCurrencyPriceAdjustments(float|int $baseAdjustment): array
    {
        $isNegative = $baseAdjustment < 0;
        $converted = $this->buildMultiCurrencyPrices(abs($baseAdjustment));

        $result = [];
        foreach ($converted as $code => $entry) {
            $price = $entry['price'] ?? 0;

            $result[$code] = [
                ...$entry,
                'price' => $isNegative ? -$price : $price,
                'formatted' => ($isNegative ? '-' : '+').$this->formatCurrencyPrice($price, $code),
            ];
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
        return CurrencySettingsCache::currencies();
    }

    /**
     * 기본 통화 코드를 반환합니다.
     *
     * 기본 통화의 SSoT 는 설정의 `default_currency` 값이고 통화 항목의 `is_default` 는 그 파생입니다.
     * 표시 계층이 `is_default` 를 먼저 훑으면, 저장본에 없어 defaults 에서 보충된 통화가 옛 플래그를
     * 달고 들어왔을 때 환산 계층(CurrencyConversionService::getDefaultCurrency)과 서로 다른 통화를
     * 기본으로 잡습니다. 같은 화면의 금액이 통화별로 어긋나므로 `default_currency` 를 먼저 해석합니다.
     *
     * @return string 기본 통화 코드 (예: 'KRW')
     */
    protected function getDefaultCurrencyCode(): string
    {
        $currencies = $this->getCurrencySettings();
        $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency');
        $declared = $settings['default_currency'] ?? null;

        // 선언된 기본 통화가 통화 목록에 실재할 때만 채택 (삭제된 통화를 가리키는 설정 방어)
        if ($declared !== null) {
            foreach ($currencies as $currency) {
                if (($currency['code'] ?? null) === $declared) {
                    return $declared;
                }
            }
        }

        foreach ($currencies as $currency) {
            if ($currency['is_default'] ?? false) {
                return $currency['code'];
            }
        }

        return $declared ?? 'KRW';
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

        // 음수 금액(차액·차감)은 절댓값에 같은 규칙을 적용한 뒤 부호를 되돌린다. floor(-342.3) = -343 처럼
        // 0 에서 멀어지면 같은 금액이 양수 ¥342 · 음수 ¥-343 으로 한 단위 어긋난다.
        $divided = abs($price) / $unitValue;

        $rounded = match ($method) {
            'ceil' => ceil($divided),
            'floor' => floor($divided),
            default => round($divided),
        };

        // 단위를 도로 곱하는 마지막 단계에서 이진 부동소수 표현이 한 칸 어긋난다
        // (4505 × 0.01 = 45.050000000000004). 절사 설정은 표시뿐 아니라 **저장되는 값**까지
        // 지배하므로, 단위 자신이 가진 자릿수로 다듬어 결과가 실제로 그 단위의 배수가 되게 한다.
        // 정수 단위(1·10·100)는 자릿수 0 이라 값이 그대로 통과한다.
        $result = round($rounded * $unitValue, $this->roundingUnitScale($unitValue));

        // 0.0 - 0.0 = +0.0 — 0 으로 깎인 음수가 응답에 「-0」 으로 실리지 않게 뺄셈으로 부호를 되돌린다.
        return $price < 0 ? 0.0 - $result : $result;
    }

    /**
     * 절사 단위가 가진 소수 자릿수를 구합니다.
     *
     * 단위 문자열의 형태(`0.01` · `1e-2`)에 기대지 않고 값에서 직접 센다.
     *
     * @param  float  $unit  절사 단위 (양수)
     * @return int 소수 자릿수 (정수 단위면 0)
     */
    protected function roundingUnitScale(float $unit): int
    {
        $scale = 0;

        while ($scale < 10 && abs($unit - round($unit)) > 1e-9) {
            $unit *= 10;
            $scale++;
        }

        return $scale;
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
     * 적립 마일리지를 포인트 단위로 포맷팅합니다.
     *
     * 마일리지는 화폐가 아니라 포인트이므로 통화 기호를 붙이지 않습니다. 통화로 포맷하면
     * 같은 값이 화면마다 갈립니다 — 장바구니·주문서는 `Summary::mileage_formatted` 가
     * `710P` 로 내는데 주문완료·비회원 주문상세·관리자 주문목록은 `710원` 으로 나와,
     * 구매자가 한 클릭 사이에 단위가 바뀌는 것을 봅니다(#599 세션A 실측 2026-09-21 ·
     * 주문 20260921-1536333520 · 운영 결정 2026-09-22 「P 로 통일」).
     *
     * 표기 관례(천 단위 구분)는 `Summary` 와 같은 식을 쓰므로 두 경로가 같은 문자열을 냅니다.
     * 단위 글자는 다국어 키(`messages.mileage.points_format`)가 정하고, 식은 서비스 층과 같은
     * `EarnedPointsFormatter` 한 곳이다 — 신청 미리보기 스냅샷과 주문 응답이 같은 문자열을 낸다.
     *
     * @param  float|int|null  $points  적립 포인트 (null 은 0 으로 처리)
     * @return string `710P` 형태의 포인트 표기
     */
    protected function formatEarnedPoints(float|int|null $points): string
    {
        return EarnedPointsFormatter::format($points);
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
     * 주문 시점 스냅샷이 주입돼 있고 그 통화의 자릿수를 박제해 두었으면 그것이 SSoT 다
     * (공개 #91 후속 — 삭제된 통화가 현재 설정에 없어 폴백 2자리로 바뀌는 것을 막는다).
     * 스냅샷이 없으면(상품 등 카탈로그 경로) 현재 설정을 그대로 따른다.
     *
     * @param  string  $code  통화 코드
     * @return int 소수 자릿수 (기본값: 2)
     */
    protected function getDecimalPlacesForCurrency(string $code): int
    {
        $snapshotPlaces = $this->currencySnapshot['exchange_rates'][$code]['decimal_places'] ?? null;
        if ($snapshotPlaces !== null) {
            return (int) $snapshotPlaces;
        }

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
     * 저장된 다중 통화 금액들을 통화별로 더합니다.
     *
     * 화면의 보조 통화 줄은 바로 위 기준 통화 줄과 **같은 값의 다른 표기**여야 한다. 기준 줄이
     * 두 금액의 합(예: 환불 총액 = 현금 + 마일리지)인데 보조 통화가 한쪽만 읽으면 두 줄이 서로
     * 다른 금액이 되는데, 둘 다 정상 서식이라 어긋난 흔적이 응답에도 화면에도 남지 않는다.
     * 그래서 합계는 화면이 아니라 여기서 만든다 — 화면이 두 맵을 더하면 그 규칙이 화면마다 갈린다.
     *
     * 환율을 다시 보지 않는다. 통화별로 더한 뒤 그 통화의 자릿수로 반올림한다.
     *
     * @param  array|null  ...$multiCurrencyAmounts  통화별 금액 맵들 (예: {'KRW': 10000, 'USD': 7.5})
     * @return array<string, float|int>|null 통화별 합계 (모두 비면 null)
     */
    protected function sumMultiCurrency(?array ...$multiCurrencyAmounts): ?array
    {
        $sums = [];

        foreach ($multiCurrencyAmounts as $map) {
            if (empty($map)) {
                continue;
            }

            foreach ($map as $code => $amount) {
                if (! is_numeric($amount)) {
                    continue;
                }

                $sums[$code] = ($sums[$code] ?? 0) + $amount;
            }
        }

        if ($sums === []) {
            return null;
        }

        $result = [];

        foreach ($sums as $code => $amount) {
            $result[$code] = $this->roundToCurrency($amount, (string) $code);
        }

        return $result;
    }

    /**
     * 저장된 다중 통화 단가에 수량을 곱해 소계 배열을 만듭니다.
     *
     * 환율을 다시 보지 않는다 — 거래 시점에 동결된 통화별 단가를 그대로 곱하므로 기준 통화
     * 소계와 같은 근거 위에 선다. 통화별로 따로 곱한 뒤 그 통화의 자릿수로 반올림하는 것은
     * 기준 통화 소계를 환산하는 방식과 다르다: 후자는 환산 오차가 수량만큼 증폭된다.
     *
     * @param  array|null  $multiCurrencyUnitPrices  통화별 단가 (예: {'KRW': 28000, 'USD': 23.8})
     * @param  int  $quantity  수량
     * @return array<string, float|int>|null 통화별 소계 (입력이 비면 null)
     */
    protected function multiplyMultiCurrency(?array $multiCurrencyUnitPrices, int $quantity): ?array
    {
        if (empty($multiCurrencyUnitPrices)) {
            return null;
        }

        $result = [];

        foreach ($multiCurrencyUnitPrices as $code => $amount) {
            if (! is_numeric($amount)) {
                continue;
            }

            $result[$code] = $this->roundToCurrency($amount * $quantity, (string) $code);
        }

        return $result === [] ? null : $result;
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
     * 실제 보관소는 CurrencySettingsCache 단일 클래스이므로, 어느 사용 클래스에서
     * 호출하든 전체가 비워집니다.
     *
     * @return void 반환값 없음
     */
    public static function clearCurrencySettingsCache(): void
    {
        CurrencySettingsCache::clear();
    }
}
