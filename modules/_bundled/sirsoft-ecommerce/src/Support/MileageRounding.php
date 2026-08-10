<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 적립 마일리지 절사 규칙의 SSoT.
 *
 * 적립 포인트 산출은 종전에 `(int) floor(...)` 로 세 갈래(옵션 정액 · 옵션 정률 · 기본
 * 적립률)에 하드코딩되어 있었고, 부분취소 분할 안분은 `round(..., 2)` 로 소수점 포인트를
 * 만들었다. 두 지점의 규칙이 서로 다르고 운영자가 어느 쪽도 조정할 수 없었다.
 *
 * 이 클래스는 통화별 마일리지 규칙(`mileage.currency_rules.*`)의 절사 단위·방식을
 * 해석해 한 곳에서 적용한다. 통화 설정(`language_currency.currencies.*.rounding_*`)과는
 * 별개다 — 그쪽은 **외화 환산 표시** 전용이라 기본 통화에는 적용조차 되지 않는다.
 * 적립은 기본 통화 원장에 확정 기록되는 값이므로 자기 규칙을 갖는다.
 *
 * 기본값(`1` / `floor`)은 종전 하드코딩 동작과 정확히 같다. 규칙이 없는 기존 설치본과
 * 이 기능 이전에 생성된 주문은 폴백만으로 과거와 동일한 금액을 산출한다.
 *
 * @since 1.0.5
 */
class MileageRounding
{
    /** 절사 단위 설정 키 (통화별 마일리지 규칙 내) */
    public const UNIT_KEY = 'earn_rounding_unit';

    /** 절사 방식 설정 키 (통화별 마일리지 규칙 내) */
    public const METHOD_KEY = 'earn_rounding_method';

    /** 기본 절사 단위 — 1점 (종전 하드코딩과 동일) */
    public const DEFAULT_UNIT = '1';

    /** 기본 절사 방식 — 버림 (종전 하드코딩 floor 와 동일) */
    public const DEFAULT_METHOD = 'floor';

    /**
     * 선택 가능한 절사 단위.
     *
     * 마일리지는 원장에 정수 포인트로 확정되므로 소수 단위는 두지 않는다.
     * 통화 환산 절사(0.01/0.1/1)와 후보가 다른 이유다.
     *
     * @var array<int, string>
     */
    public const UNITS = ['1', '10', '100'];

    /**
     * 선택 가능한 절사 방식 (통화 환산 절사와 동일 어휘).
     *
     * @var array<int, string>
     */
    public const METHODS = ['floor', 'round', 'ceil'];

    /**
     * 통화 규칙에서 절사 기준을 해석합니다.
     *
     * 미설정·오염값은 기본값으로 접는다 — 절사 기준이 해석되지 않는다고 적립을 멈출 수는
     * 없고, 알 수 없는 값에서 임의 방식을 고르면 지급액이 조용히 달라지기 때문이다.
     *
     * @param  array|null  $rule  통화별 마일리지 규칙 (mileage.currency_rules 의 한 행)
     * @return array{unit: string, method: string} 정규화된 절사 기준
     */
    public static function normalize(?array $rule): array
    {
        $unit = (string) ($rule[self::UNIT_KEY] ?? self::DEFAULT_UNIT);
        $method = (string) ($rule[self::METHOD_KEY] ?? self::DEFAULT_METHOD);

        return [
            'unit' => in_array($unit, self::UNITS, true) ? $unit : self::DEFAULT_UNIT,
            'method' => in_array($method, self::METHODS, true) ? $method : self::DEFAULT_METHOD,
        ];
    }

    /**
     * 주문 스냅샷에서 절사 기준을 해석합니다.
     *
     * 재계산(부분취소 · 추가결제)은 주문 시점 기준을 그대로 써야 한다. 설정을 다시 읽으면
     * 운영자가 이후에 바꾼 절사 기준이 과거 주문에 소급 적용돼, 취소하지 않은 잔여분의
     * 적립액이 취소 처리만으로 달라진다.
     *
     * 이 기능 이전 주문의 스냅샷에는 키가 없다 — 그때의 동작이 곧 기본값(`1`/`floor`)이므로
     * 폴백이 당시 산출과 일치한다.
     *
     * @param  array|null  $snapshot  주문의 mileage_policy_snapshot
     * @return array{unit: string, method: string} 정규화된 절사 기준
     */
    public static function fromOrderSnapshot(?array $snapshot): array
    {
        return self::normalize(is_array($snapshot['rule'] ?? null) ? $snapshot['rule'] : null);
    }

    /**
     * 절사 기준을 적용해 정수 포인트를 산출합니다.
     *
     * @param  float|int  $points  절사 전 포인트 (계산 결과 실수)
     * @param  array{unit: string, method: string}|null  $rounding  정규화된 절사 기준 (null 이면 기본값)
     * @return int 절사된 정수 포인트 (음수 방지 없음 — 호출자가 부호 의미를 정한다)
     */
    public static function apply(float|int $points, ?array $rounding = null): int
    {
        $unit = (float) ($rounding['unit'] ?? self::DEFAULT_UNIT);
        $method = (string) ($rounding['method'] ?? self::DEFAULT_METHOD);

        if ($unit <= 0) {
            $unit = 1.0;
        }

        $divided = ((float) $points) / $unit;

        // 부동소수 오차 보정 — 7809.999999999999 가 floor 에서 7809 로 떨어지는 것을 막는다.
        // 마일리지 단위(1/10/100)에서 9자리 이하 오차는 계산 잔여물일 뿐 의미 있는 차이가 아니다.
        $divided = round($divided, 9);

        $quantized = match ($method) {
            'ceil' => ceil($divided),
            'round' => round($divided),
            default => floor($divided),
        };

        return (int) ($quantized * $unit);
    }

    /**
     * 통화 규칙에서 곧바로 절사를 적용합니다 (normalize + apply 축약).
     *
     * @param  float|int  $points  절사 전 포인트
     * @param  array|null  $rule  통화별 마일리지 규칙
     * @return int 절사된 정수 포인트
     */
    public static function applyRule(float|int $points, ?array $rule): int
    {
        return self::apply($points, self::normalize($rule));
    }
}
