<?php

namespace App\Http\Responses;

use Illuminate\Http\Response;

/**
 * 304 Not Modified 응답 — 검증자 `Last-Modified` 를 보존한다.
 *
 * Laravel 라우터(`Router::prepareResponse`)는 상태 코드가 304 이면
 * `Symfony\Component\HttpFoundation\Response::setNotModified()` 를 호출한다. 그 메서드는
 * 본문을 비우면서 헤더 일곱 개를 제거하는데 그중에 **`Last-Modified` 가 있다.**
 *
 * 그래서 컨트롤러가 304 에 `Last-Modified` 를 붙여도 응답이 나갈 때는 사라져 있었다 —
 * 붙이는 코드는 있는데 효과가 없는 상태, 즉 「있는 줄 알았던 가드」다. 이 클래스가 없으면
 * 다음 두 가지가 어긋난다:
 *
 *  - **정적 서빙과 비동등.** `public/storage` 심볼릭 링크가 되는 서버에서는 같은 파일을
 *    웹서버가 직접 주고, Apache·nginx 는 304 에도 `Last-Modified` 를 재전송한다. 링크
 *    유무로 응답 형태가 갈리면 안 된다는 것이 이 저장소의 파일 서빙 규율이다.
 *  - **ETag 를 쓰지 않는 중간 프록시.** 그런 프록시는 `If-Modified-Since` 로만 재검증하는데,
 *    304 가 검증자를 갱신해 주지 않으면 저장한 시각이 낡아 간다.
 *
 * 제거 대상 중 되살리는 것은 `Last-Modified` 하나뿐이다. 나머지 여섯
 * (`Allow`·`Content-Encoding`·`Content-Language`·`Content-Length`·`Content-MD5`·
 * `Content-Type`)은 본문이 없는 응답에서 제거되는 것이 옳다.
 */
class NotModifiedResponse extends Response
{
    /**
     * 응답을 304 로 바꾸되 `Last-Modified` 는 유지합니다.
     *
     * @return $this
     */
    public function setNotModified(): static
    {
        $lastModified = $this->headers->get('Last-Modified');

        parent::setNotModified();

        if ($lastModified !== null) {
            $this->headers->set('Last-Modified', $lastModified);
        }

        return $this;
    }
}
