<?php

namespace App\Support;

/**
 * 필수 의존 선언 (`dependencies`) 정규화
 *
 * manifest 의 `dependencies.{modules|plugins|templates}` 는 「식별자 => 버전 제약」 객체형이 표준이지만 목록형
 * (`["sirsoft-board"]`)도 받습니다. 목록형을 키로 순회하면 식별자 자리에 `0`·`1` 이 들어가므로, 의존을 읽는 곳은
 * 모두 이 정규화기를 거칩니다. 목록형·빈 값은 `'*'`(버전 무관)이고, 객체형의 제약 문자열은 그대로 둡니다
 * (형식이 틀린 제약은 기존과 같이 버전 비교에서 「미충족」이 됩니다).
 *
 * @since 7.1.4
 */
class ExtensionDependencies
{
    /** 의존 대상 유형 */
    public const TYPES = ['modules', 'plugins', 'templates'];

    /** 버전 제약이 없을 때(목록형)의 제약 문자열 */
    public const ANY_VERSION = '*';

    /**
     * manifest 의 `dependencies` 선언을 유형별 「식별자 => 버전 제약」 맵으로 정규화합니다.
     *
     * @param  mixed  $raw  manifest 의 dependencies 값
     * @return array{modules: array<string, string>, plugins: array<string, string>, templates: array<string, string>} 정규화한 제약 맵
     */
    public static function constraints(mixed $raw): array
    {
        $constraints = array_fill_keys(self::TYPES, []);

        if (! is_array($raw)) {
            return $constraints;
        }

        foreach (self::TYPES as $type) {
            $constraints[$type] = self::constraintsOf($raw[$type] ?? []);
        }

        return $constraints;
    }

    /**
     * 유형 하나의 선언 목록을 「식별자 => 버전 제약」 맵으로 정규화합니다.
     *
     * @param  mixed  $list  `dependencies.{type}` 값 (목록형 또는 객체형)
     * @return array<string, string> 식별자 => 버전 제약
     */
    public static function constraintsOf(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $result = [];

        if (array_is_list($list)) {
            foreach ($list as $identifier) {
                if (is_string($identifier) && trim($identifier) !== '') {
                    $result[trim($identifier)] = self::ANY_VERSION;
                }
            }

            return $result;
        }

        foreach ($list as $identifier => $constraint) {
            $identifier = trim((string) $identifier);
            if ($identifier === '') {
                continue;
            }

            $result[$identifier] = self::normalizeConstraint($constraint);
        }

        return $result;
    }

    /**
     * 버전 제약 값 하나를 정규화합니다 — `null`·빈 문자열·공백·문자열이 아닌 값은 `'*'`(버전 무관), 나머지는 앞뒤 공백 제거.
     *
     * @param  mixed  $constraint  manifest 의 버전 제약 값
     * @return string 정규화한 제약
     */
    public static function normalizeConstraint(mixed $constraint): string
    {
        return is_string($constraint) && trim($constraint) !== '' ? trim($constraint) : self::ANY_VERSION;
    }

    /**
     * 유형 하나의 의존 식별자 목록을 반환합니다.
     *
     * @param  mixed  $raw  manifest 의 dependencies 값
     * @param  string  $type  modules|plugins|templates
     * @return array<int, string> 의존 식별자 목록
     */
    public static function identifiers(mixed $raw, string $type): array
    {
        return array_map('strval', array_keys(self::constraints($raw)[$type] ?? []));
    }
}
