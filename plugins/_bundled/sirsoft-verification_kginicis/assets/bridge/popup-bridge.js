/*
 * KG 이니시스 본인인증 결과 전달 브리지 (이니시스 매뉴얼 STEP4 결과 페이지)
 *
 * - 데스크톱(window.opener 존재): 부모창에 postMessage 후 이 창을 닫는다
 * - 모바일(opener 없음): sessionStorage 의 redirectStash 를 복원해 원 페이지로 이동(verification_token 부착)
 *
 * 서버 값은 직전의 비실행 데이터 블록 `#bridge-data` 에서 읽는다 — {siteOrigin, payload}.
 * 종전에는 페이지 인라인 <script> 였다 — 사이트가 script-src 'self' 를 걸면 실행되지 않아 인증 결과가
 * 전달되지 않고 팝업도 닫히지 않았다(#158). 손작성 ES5, 빌드하지 않는다.
 *
 * sessionStorage 키는 코어 `IDENTITY_REDIRECT_STASH_KEY` 상수와 같아야 launcher 가 stash 한 값을 복원한다.
 */
(function () {
    'use strict';

    var REDIRECT_STASH_KEY = 'g7.identity.redirectStash';
    var data = {};

    try {
        var el = document.getElementById('bridge-data');
        data = JSON.parse((el && el.textContent) || '{}') || {};
    } catch (e) {
        data = {};
    }

    var SITE_ORIGIN = data.siteOrigin || '/';
    var payload = data.payload || {};

    // 복귀 주소는 같은 출처 경로만 — 사이트 origin 절대 주소는 경로로 바꾸고, 그 밖(다른 출처·//·역슬래시·제어 문자)은 버린다.
    function toSafeReturnPath(u) {
        if (typeof u !== 'string' || u === '') { return null; }
        if (u.indexOf(SITE_ORIGIN + '/') === 0) { u = u.slice(SITE_ORIGIN.length); }
        else if (u === SITE_ORIGIN) { u = '/'; }
        if (u.charAt(0) !== '/' || u.charAt(1) === '/' || u.indexOf(String.fromCharCode(92)) >= 0) { return null; }
        for (var i = 0; i < u.length; i++) { var c = u.charCodeAt(i); if (c < 32 || c === 127) { return null; } }
        return u;
    }

    // 데스크톱: 부모창 postMessage + 자기 창 닫기
    if (window.opener && !window.opener.closed) {
        try {
            window.opener.postMessage({
                type: 'identity_result',
                verification_token: payload.verification_token,
                challenge_id: payload.challenge_id,
                identity_error: payload.identity_error
            }, SITE_ORIGIN);
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

    var dest = (stash && toSafeReturnPath(stash.return_url)) || '/';
    var sep = dest.indexOf('?') >= 0 ? '&' : '?';
    if (payload.verification_token) {
        dest += sep + 'verification_token=' + encodeURIComponent(payload.verification_token);
    } else if (payload.identity_error) {
        dest += sep + 'identity_error=' + encodeURIComponent(payload.identity_error);
    }
    window.location.replace(dest);
})();
