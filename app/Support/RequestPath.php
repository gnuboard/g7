<?php

namespace App\Support;

/**
 * 요청 경로 정규화 (공개#156)
 *
 * Symfony `getPathInfo()` 는 끝 슬래시(`/admin/`)와 연속 슬래시(`/board//free`)를 그대로 남긴다.
 * 이 값을 라우트 판정·SEO 캐시 키·방문 통계에 그대로 쓰면 같은 화면이 두 갈래로 나뉜다
 * (nginx 에서 `/admin/` 이 404 가 되던 원인). 요청 경로를 문자열 비교·캐시 키로 쓰는 곳은
 * 모두 이 헬퍼를 거쳐 하나의 정규형을 쓴다. 클라이언트 `Router.normalizePathname()` 과 같은 규칙이다.
 */
final class RequestPath
{
    /**
     * 쿼리 없는 요청 경로를 정규형으로 바꿉니다.
     *
     * 앞 `/` 를 보장하고, 연속 `/` 를 하나로 줄이고, 루트가 아니면 끝 `/` 를 뗀다.
     * 퍼센트 인코딩은 건드리지 않는다. 쿼리스트링이 붙은 값은 받지 않는다(호출부가 분리해서 넘긴다).
     *
     * @param  string  $path  쿼리 없는 요청 경로 (예: `getPathInfo()` 값)
     * @return string 정규형 경로 (빈 값·`/`·`//` 는 `/`)
     */
    public static function normalize(string $path): string
    {
        $path = (string) preg_replace('#/{2,}#', '/', '/'.$path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
