<?php

namespace Plugins\Sirsoft\VerificationKginicis\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 이니시스 매뉴얼 STEP4 결과 전달 페이지 — 데스크톱 팝업 / 모바일 redirect 분기.
 *
 * Callback 컨트롤러가 302 redirect 한 query (verification_token / challenge_id / identity_error)
 * 를 받아서, 사용자 브라우저 환경에 따라 두 가지 분기를 수행한다:
 *
 * - 데스크톱 (window.opener 존재): 부모창에 postMessage + window.close
 * - 모바일 (window.opener 부재): sessionStorage 의 redirectStash 복원 후 원 페이지로 redirect
 *   (verification_token query 부착 — formStash 복원은 launcher 책임)
 *
 * G7 컨벤션 (sirsoft-pay_kginicis UserEscrowConfirmController 참고): 외부 PG 콜백 후 사용자
 * 브라우저에 노출되는 결과 페이지는 컨트롤러 인라인 HTML 응답으로 처리한다 (Blade 미사용).
 *
 * @since 1.0.0-beta.1
 */
class InicisPopupBridgeController extends PublicBaseController
{
    /**
     * Bridge 페이지를 렌더링한다.
     *
     * @param  Request  $request  callback 컨트롤러가 전달한 query (verification_token / challenge_id / identity_error)
     * @return Response
     */
    public function show(Request $request): Response
    {
        $payloadJson = json_encode([
            'verification_token' => (string) $request->query('verification_token', ''),
            'challenge_id' => (string) $request->query('challenge_id', ''),
            'identity_error' => (string) $request->query('identity_error', ''),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        return $this->htmlResponse($this->renderBridgeHtml($payloadJson));
    }

    /**
     * UTF-8 HTML 응답 헬퍼.
     *
     * @param  string  $html
     * @return Response
     */
    protected function htmlResponse(string $html): Response
    {
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Bridge 페이지 HTML 을 생성한다.
     *
     * sessionStorage 키는 코어 `IDENTITY_REDIRECT_STASH_KEY` 상수와 동일해야 launcher 가
     * stash 한 redirectStash 를 정상적으로 복원할 수 있다.
     *
     * @param  string  $payloadJson  json_encode 결과 (script 컨텍스트 안전 — JSON_HEX_* 적용됨)
     * @return string
     */
    protected function renderBridgeHtml(string $payloadJson): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>본인인증 결과</title>
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
                identity_error: payload.identity_error
            }, window.location.origin);
        } catch (e) { /* opener cross-origin — 무시 */ }
        window.close();
        return;
    }

    // 모바일: sessionStorage redirectStash 복원 후 원 페이지로 이동
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
    }
    window.location.replace(dest);
})();
</script>
</body>
</html>
HTML;
    }
}
