<?php

namespace App\Notifications;

use App\Support\LocalizedValue;

/**
 * 알림 변수 값을 받는 사람 언어로 해석하는 단일 기준.
 *
 * 변수 값은 문자열·숫자 외에 언어별 값(`['ko' => '모의 회사 계정', 'en' => 'Mock company account']`)을 줄 수 있다.
 * 언어별 값은 받는 사람 언어 → 사이트 기본 언어 → 영어 → 첫 유효값 순(`LocalizedValue::pick`)으로 고른다.
 * 메일·사이트 알림 렌더(`NotificationContentBehavior`)와 확장 채널로 넘기는 data(`GenericNotification`)가
 * 모두 여기서 해석해, 같은 알림의 채널마다 고른 값이 갈리지 않게 한다.
 */
final class NotificationVariables
{
    /** 언어별 값의 키로 인정하는 로케일 모양(ko, en, ja, zh-CN, pt_BR …) */
    private const LOCALE_KEY_PATTERN = '/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/';

    /**
     * data 의 언어별 값을 주어진 언어의 문자열로 바꿉니다.
     *
     * 언어별 값이 아닌 값(문자열·숫자·null·구조 배열)은 그대로 둔다.
     *
     * @param  array<string, mixed>  $data  알림 변수 data
     * @param  string|null  $locale  받는 사람 언어 (null 이면 현재 요청 언어)
     * @return array<string, mixed> 언어별 값을 문자열로 해석한 data
     */
    public static function resolve(array $data, ?string $locale = null): array
    {
        foreach ($data as $key => $value) {
            if (self::isLocalized($value)) {
                $data[$key] = LocalizedValue::pick($value, $locale) ?? '';
            }
        }

        return $data;
    }

    /**
     * 템플릿 `{key}` 치환표를 만듭니다.
     *
     * 언어별 값은 해석하고, 문자열로 바꿀 수 없는 값(구조 배열·객체)은 치환하지 않는다.
     *
     * @param  array<string, mixed>  $data  알림 변수 data
     * @param  string|null  $locale  받는 사람 언어 (null 이면 현재 요청 언어)
     * @param  bool  $includeNull  null 값을 빈 문자열로 치환할지 (false 면 자리표시자를 그대로 둔다)
     * @return array<string, string> `{key}` => 값
     */
    public static function replacements(array $data, ?string $locale = null, bool $includeNull = true): array
    {
        $replacements = [];
        foreach (self::resolve($data, $locale) as $key => $value) {
            if ($value === null && ! $includeNull) {
                continue;
            }

            if (is_scalar($value) || $value === null || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return $replacements;
    }

    /**
     * 언어별 값(`{로케일: 문자열}`)인지 판정합니다.
     *
     * @param  mixed  $value  변수 값
     * @return bool 비어 있지 않은 배열이고 모든 키가 로케일 모양, 모든 값이 문자열·숫자·null 이면 true
     */
    public static function isLocalized(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $locale => $text) {
            if (! is_string($locale) || ! preg_match(self::LOCALE_KEY_PATTERN, $locale)) {
                return false;
            }

            if (! (is_string($text) || is_int($text) || is_float($text) || $text === null)) {
                return false;
            }
        }

        return true;
    }
}
