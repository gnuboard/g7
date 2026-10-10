<?php

namespace App\Extension\Layout;

use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\LayoutPartialOrigin;

/**
 * 레이아웃 본문 두 판이 "같은 화면" 인지 판정하는 정규화 비교기 (순수 함수, 상태 없음).
 *
 * 편집기 저장은 본문을 마크업으로 풀었다가 다시 컴파일하므로, 운영자가 손대지 않은 부분도 키 순서·빈
 * map(`{}`/`[]`)·노드 `type`·`meta.is_partial` 같은 표기만 달라질 수 있다. 그 차이를 "운영자가 고쳤다"
 * 로 세면 손 안 댄 조각까지 전부 수정본이 되어 병합이 무의미해진다. 그래서 편집기 왕복을 판정하는 게이트
 * (`layout:check-installed`)와 **같은 정규화**를 쓴다 — {@see LayoutMarkupRoundTrip}.
 *
 * 문서 루트 비교와 노드·조각 비교를 가른다. 문서 루트 정규화(`normalizeDocument`)는 최상위 별칭(`state`
 * → `initLocal`)·사어 키를 접는데, 노드 층에 적용하면 노드 키 `state` 같은 남남을 잘못 접는다.
 *
 * 해시(`original_content_hash`)는 이 비교기로 바꾸지 않는다 — 해시는 원본 본문이 없는 옛 행의 1차 판정에만
 * 쓰고, 원본 본문이 있는 행은 이 비교기로 판정한다.
 */
final class LayoutContentComparator
{
    /**
     * 비교에서 지우는 키 — 조각 출처 표식·편집기 부가 정보.
     *
     * 병합 자리표시(`__g7_unit`)는 지우지 않는다 — 어느 조각이 어느 자리에 있는지가 몸통의 일부라서,
     * 지우면 운영자가 조각 둘의 자리를 맞바꾼 것을 "안 고침" 으로 잘못 본다.
     */
    private const IGNORED_KEYS = [LayoutPartialOrigin::KEY, '__editor', 'lock_version'];

    /**
     * 문서 루트(화면 전체)를 비교용 정본 문자열로 만듭니다.
     *
     * @param  mixed  $value  문서 루트
     * @return string 정본 JSON 문자열
     */
    public static function canonicalDocument(mixed $value): string
    {
        return self::encode(LayoutMarkupRoundTrip::normalizeDocument(self::prepare($value)));
    }

    /**
     * 노드·조각 몸통·최상위 키 값을 비교용 정본 문자열로 만듭니다.
     *
     * @param  mixed  $value  값
     * @return string 정본 JSON 문자열
     */
    public static function canonicalFragment(mixed $value): string
    {
        return self::encode(self::prepare($value));
    }

    /**
     * 두 문서 루트가 같은 화면인지 판정합니다 (null 은 null 끼리만 같다).
     *
     * @param  mixed  $a  한쪽 문서
     * @param  mixed  $b  다른 쪽 문서
     * @return bool 같으면 true
     */
    public static function sameDocument(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        return self::canonicalDocument($a) === self::canonicalDocument($b);
    }

    /**
     * 두 값(노드·조각 몸통·키 값)이 같은지 판정합니다 (null 은 null 끼리만 같다).
     *
     * @param  mixed  $a  한쪽 값
     * @param  mixed  $b  다른 쪽 값
     * @return bool 같으면 true
     */
    public static function sameFragment(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        return self::canonicalFragment($a) === self::canonicalFragment($b);
    }

    /**
     * 비교 전에 값을 맞춥니다 — JSON 왕복(빈 map 표식·객체를 배열로)과 비교 제외 키 제거.
     *
     * @param  mixed  $value  값
     * @return mixed 맞춘 값
     */
    private static function prepare(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        } elseif (is_array($value) || is_object($value)) {
            $value = json_decode((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        }

        return self::dropIgnored($value);
    }

    /**
     * 비교 제외 키를 재귀로 지웁니다.
     *
     * @param  mixed  $value  값
     * @return mixed 지운 값
     */
    private static function dropIgnored(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach (self::IGNORED_KEYS as $key) {
            unset($value[$key]);
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::dropIgnored($child);
            }
        }

        return $value;
    }

    /**
     * 정규화·정렬 후 결정적 JSON 으로 인코드합니다.
     *
     * @param  mixed  $value  값
     * @return string JSON 문자열
     */
    private static function encode(mixed $value): string
    {
        return LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize($value)));
    }
}
