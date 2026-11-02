<?php

namespace App\Support;

/**
 * UTF-8 문자열 정화 (JSON 직렬화 경계 공용).
 *
 * 깨진 멀티바이트가 섞인 문자열을 `JsonResponse` 가 그대로 받으면 "Malformed UTF-8" 로 500 이 난다.
 * 깨진 바이트만 떼고 나머지 내용은 그대로 둔다. iconv 는 코어 기준 「선택」 확장이라 없는 서버가 있다 —
 * `@iconv()` 의 `@` 는 미정의 함수 Error 를 막지 못해 폴백까지 닿지 않고 500 이 났다(#151). 그래서 iconv 가
 * 있을 때만 쓰고, 없거나 실패하면 필수 확장(mbstring)으로 같은 결과를 낸다.
 */
final class Utf8
{
    /**
     * 유효하지 않은 UTF-8 바이트를 제거합니다.
     *
     * @param  string  $value  정화할 문자열
     * @param  bool|null  $iconvAvailable  iconv 사용 가능 여부 (기본: function_exists — 테스트는 부재를 주입)
     * @return string 유효한 UTF-8 문자열
     */
    public static function scrub(string $value, ?bool $iconvAvailable = null): string
    {
        $iconvAvailable ??= function_exists('iconv');

        if ($iconvAvailable) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($clean)) {
                return $clean;
            }
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
