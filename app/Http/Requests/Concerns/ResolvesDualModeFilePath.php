<?php

namespace App\Http\Requests\Concerns;

use App\Support\Routing\DualExtensionRoute;

/**
 * 자산 URL 이중 모드(`.../{path}` 와 `?file=`)의 경로를 **한 규칙으로** 읽습니다.
 *
 * 두 모드는 같은 파일을 두 형태로 여는 장치이지 해석 규칙을 가르는 장치가 아니다.
 * 그런데 프레임워크의 쿼리 접근자(`$request->query()`)는 내부적으로 폼 인코딩
 * (`application/x-www-form-urlencoded`) 규칙을 쓰므로 `+` 를 공백으로 되돌리는 반면,
 * 라우트 세그먼트는 RFC 3986(`rawurldecode`)이다. 그래서 이름에 `+` 가 든 파일이
 * 실재하면 같은 파일이 세그먼트 모드에서는 200, 쿼리 모드에서는 404 였다.
 *
 * 같은 계열의 비대칭이 널바이트·선행 공백에서도 있었다 — 한쪽에서만 잘려 **다른 파일**을
 * 서빙했다(POSIX 에서 공백은 정당한 파일 이름 문자다). 그 판정을 네 통로(공개 스토리지 ·
 * 모듈 · 플러그인 · 템플릿)가 각자 적으면 한 곳만 고쳐도 나머지가 조용히 갈린다.
 *
 * 퍼센트 인코딩(`%2B`·`%26`)의 해석은 종전과 같으므로, 서버·프론트가 실제로 만드는
 * URL(`rawurlencode` 경유)에는 변화가 없다.
 */
trait ResolvesDualModeFilePath
{
    /**
     * 이중 모드 요청의 경로를 돌려줍니다 — 세그먼트가 있으면 그것을, 없으면 쿼리 원문을.
     *
     * @return string|null 경로 (양쪽 다 없으면 null)
     */
    protected function dualModeFilePath(): ?string
    {
        $fromRoute = $this->route('path');

        if (is_string($fromRoute) && $fromRoute !== '') {
            return $fromRoute;
        }

        return $this->fileParamFromRawQuery();
    }

    /**
     * 원문 쿼리스트링에서 `file=` 값을 RFC 3986 규칙으로 읽습니다.
     *
     * 같은 이름이 여러 번 오면 마지막 값을 쓴다 (프레임워크 접근자의 종전 동작 유지).
     *
     * @return string|null `file=` 값 (없으면 null)
     */
    protected function fileParamFromRawQuery(): ?string
    {
        $fromQuery = $this->server('QUERY_STRING');

        if (! is_string($fromQuery) || $fromQuery === '') {
            return null;
        }

        $value = null;

        foreach (explode('&', $fromQuery) as $pair) {
            if ($pair === '') {
                continue;
            }

            $separator = strpos($pair, '=');
            $name = $separator === false ? $pair : substr($pair, 0, $separator);

            if (rawurldecode($name) !== DualExtensionRoute::FILE_QUERY_PARAM) {
                continue;
            }

            $value = $separator === false ? '' : rawurldecode(substr($pair, $separator + 1));
        }

        return $value;
    }
}
