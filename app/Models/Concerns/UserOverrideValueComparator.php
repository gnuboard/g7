<?php

namespace App\Models\Concerns;

/**
 * 「운영자 수정(user_overrides)」 기록 판정용 값 비교기 (#727 §5.11).
 *
 * 관리 화면 폼은 값을 바꾸지 않아도 표기만 다르게 보내는 일이 잦다 — 문자열 앞뒤 공백(TrimStrings), 빈 문자열과
 * null(ConvertEmptyStringsToNull), 배열 `null`/`[]`/`{signup_stage: null}`, 숫자 문자열과 숫자, enum 객체와 값.
 * 그 차이를 "운영자가 고쳤다" 로 기록하면 저장만 해도 수정됨이 되어 새 버전 교정이 영영 막힌다. 그래서 비교 전에
 * 같은 뜻의 표기를 한 모양으로 맞춘다.
 */
final class UserOverrideValueComparator
{
    /**
     * 두 값이 같은 뜻인지 판정합니다.
     *
     * @param  mixed  $a  한쪽 값
     * @param  mixed  $b  다른 쪽 값
     * @return bool 같은 뜻이면 true
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return json_encode(self::normalize($a)) === json_encode(self::normalize($b));
    }

    /**
     * 값을 비교용 정규형으로 맞춥니다.
     *
     * - 문자열: 앞뒤 공백 제거, 빈 문자열은 null, 숫자 문자열은 숫자
     * - 배열: 원소를 재귀로 맞춘 뒤 비었거나 원소가 전부 null 이면 null, 연관 배열은 키 정렬
     * - BackedEnum: `value`, bool: 그대로(숫자 0/1 과는 구분하지 않도록 정수로)
     *
     * @param  mixed  $value  값
     * @return mixed 정규형
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (is_object($value)) {
            $value = json_decode((string) json_encode($value), true);
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }

            return is_numeric($value) ? $value + 0 : $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value + 0;
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize($item);
        }

        if (array_filter($normalized, fn ($item) => $item !== null) === []) {
            return null;
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
