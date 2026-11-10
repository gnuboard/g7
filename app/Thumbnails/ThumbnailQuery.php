<?php

namespace App\Thumbnails;

use Closure;
use Illuminate\Http\Request;

/**
 * 썸네일 요청 파라미터(`size`·`w`) 규약
 *
 * 서명 URL(비밀글 첨부·리뷰·클레임)은 서버가 발급한 쿼리 전체에 서명한다. 화면이 그 주소 뒤에 `size` 를
 * 덧붙이면 서명이 깨지므로, 서명 검증은 이 두 파라미터를 빼고 한다 — 썸네일은 원본과 같은 게이트를 지난 뒤
 * 크기만 바꾸므로 서명 범위를 줄여도 접근 범위는 넓어지지 않는다.
 *
 * 서명 검증은 `$request->hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure())` 로 한다.
 * (`hasValidSignature` 매크로는 인자가 `absolute` 하나뿐이라 무시 목록을 넘길 수 없다.)
 */
final class ThumbnailQuery
{
    /** @var list<string> 서명 검증에서 빼는 파라미터 */
    public const SIGNATURE_IGNORED = ['size', 'w'];

    /**
     * 서명 검증에서 썸네일 파라미터를 빼는 판정 Closure.
     *
     * Laravel 은 원문 쿼리를 `&` 로 쪼갠 키(인코딩 그대로)를 넘긴다 — `size%5B%5D` 같은 인코딩 배열 키도
     * 디코딩해 같은 규칙으로 본다.
     *
     * @return Closure(string): bool 무시할 키면 true
     */
    public static function ignoreClosure(): Closure
    {
        return static function (string $parameter): bool {
            $key = rawurldecode(str_replace('+', ' ', $parameter));

            foreach (self::SIGNATURE_IGNORED as $ignored) {
                if ($key === $ignored || str_starts_with($key, $ignored.'[')) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * 요청이 썸네일을 원하는지 (`size`·`w` 중 하나라도 실렸는지)
     *
     * @param  Request  $request  요청
     * @return bool 실렸으면 true
     */
    public static function requested(Request $request): bool
    {
        return self::occurrences($request) !== [];
    }

    /**
     * 원문 쿼리에서 썸네일 키가 몇 번 나왔는지 셉니다.
     *
     * PHP 는 같은 키가 두 번 오면 마지막 값만 남겨(`size=sm&size=md` → md) 어느 쪽을 원했는지 알 수 없다.
     * 그런 요청은 원본으로 돌려보내기 위해 원문을 직접 센다.
     *
     * @param  Request  $request  요청
     * @return array<string, int> 키(size·w) → 등장 횟수 (배열 키 `size[]` 도 size 로 센다)
     */
    public static function occurrences(Request $request): array
    {
        $counts = [];
        $closure = self::ignoreClosure();

        foreach (explode('&', (string) $request->server->get('QUERY_STRING')) as $pair) {
            if ($pair === '') {
                continue;
            }

            $rawKey = explode('=', $pair, 2)[0];

            if (! $closure($rawKey)) {
                continue;
            }

            $key = rawurldecode($rawKey);
            $base = str_starts_with($key, 'w') ? 'w' : 'size';
            $counts[$base] = ($counts[$base] ?? 0) + 1;
        }

        return $counts;
    }
}
