<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\Response;
use Plugins\Sirsoft\VerificationNhnkcp\Http\Requests\KcpBridgeRequest;

/**
 * 인증 결과 전달 브리지 — 데스크톱 팝업 / 모바일 페이지 전환 분기.
 *
 * 콜백 컨트롤러가 302 redirect 한 query (verification_token / challenge_id / identity_error)
 * 를 받아 사용자 브라우저 환경에 따라 두 갈래로 처리한다:
 *
 * - 데스크톱 (window.opener 존재): 부모창에 postMessage + window.close
 * - 모바일 (window.opener 부재): sessionStorage 의 redirectStash 를 복원해 원래 페이지로 이동
 *   (verification_token / identity_error query 부착 — 폼 입력 복원은 플러그인 스크립트 책임)
 *
 * G7 컨벤션: 외부 인증기관 콜백 후 사용자 브라우저에 노출되는 결과 페이지는 컨트롤러 인라인
 * HTML 응답으로 처리한다 (Blade 미사용).
 *
 * @since 1.0.0
 */
class KcpBridgeController extends PublicBaseController
{
    /**
     * 브리지 페이지를 렌더링한다.
     *
     * 배열 주입(`?verification_token[]=x`)은 KcpBridgeRequest 의 string 규칙이 422 로
     * 차단한다 — 종전 `(string)` 캐스팅은 배열을 "Array" 문자열로 바꿔 payload 에 유입시켰다.
     *
     * @param  KcpBridgeRequest  $request  콜백 컨트롤러가 전달한 query
     * @return Response 부모창으로 결과를 전달하는 브리지 HTML
     */
    public function show(KcpBridgeRequest $request): Response
    {
        $payloadJson = json_encode(
            $request->bridgePayload(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return $this->htmlResponse($this->renderBridgeHtml((string) $payloadJson));
    }

    /**
     * UTF-8 HTML 응답 헬퍼.
     *
     * @param  string  $html  본문 HTML
     * @return Response UTF-8 로 인코딩된 HTML 응답
     */
    protected function htmlResponse(string $html): Response
    {
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * 브리지 페이지 HTML 을 생성한다.
     *
     * sessionStorage 키는 코어 `IDENTITY_REDIRECT_STASH_KEY` 상수와 동일해야 플러그인이 저장한
     * 복귀 정보를 정상적으로 읽을 수 있다.
     *
     * @param  string  $payloadJson  json_encode 결과 (script 컨텍스트 안전 — JSON_HEX_* 적용됨)
     * @return string 인증 결과를 부모 창/복귀 주소로 전달하는 브리지 페이지 HTML
     */
    protected function renderBridgeHtml(string $payloadJson): string
    {
        // 즉시 닫히는 중계 페이지지만, 로딩이 지연되면 브라우저 탭 제목으로 노출된다.
        // 문서 언어와 제목을 현재 로케일에 맞춘다 (스크린리더 발음 규칙도 lang 속성을 따른다).
        $locale = e(app()->getLocale());
        $title = e(__('sirsoft-verification_nhnkcp::messages.bridge_page_title'));

        return <<<HTML
<!DOCTYPE html>
<html lang="{$locale}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>{$title}</title>
</head>
<body>
<script>
(function () {
    var REDIRECT_STASH_KEY = 'g7.identity.redirectStash';
    var payload = {$payloadJson};

    // 데스크톱: 부모창 postMessage + 자기 창 닫기
    if (window.opener && !window.opener.closed) {
        try {
            window.opener.postMessage({
                type: 'identity_result',
                verification_token: payload.verification_token,
                challenge_id: payload.challenge_id,
                identity_error: payload.identity_error,
                identity_error_message: payload.identity_error_message
            }, window.location.origin);
        } catch (e) { /* opener cross-origin — 무시 */ }
        window.close();
        return;
    }

    // 모바일: sessionStorage 복귀 정보를 읽어 원래 페이지로 이동
    var stashRaw = null;
    try { stashRaw = window.sessionStorage.getItem(REDIRECT_STASH_KEY); } catch (e) {}
    var stash = null;
    if (stashRaw) {
        try { stash = JSON.parse(stashRaw); } catch (e) {}
    }
    try { window.sessionStorage.removeItem(REDIRECT_STASH_KEY); } catch (e) {}

    var dest = (stash && stash.return_url) ? stash.return_url : '/';
    var sep = dest.indexOf('?') >= 0 ? '&' : '?';
    if (payload.verification_token) {
        dest += sep + 'verification_token=' + encodeURIComponent(payload.verification_token);
    } else if (payload.identity_error) {
        dest += sep + 'identity_error=' + encodeURIComponent(payload.identity_error);
        if (payload.identity_error_message) {
            dest += '&identity_error_message=' + encodeURIComponent(payload.identity_error_message);
        }
    }
    window.location.replace(dest);
})();
</script>
</body>
</html>
HTML;
    }
}
