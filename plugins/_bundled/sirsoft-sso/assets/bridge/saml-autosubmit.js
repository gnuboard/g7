/**
 * SAML POST 바인딩 자동 제출 (strict CSP — 인라인 onload 를 쓰지 않는다)
 *
 * SamlDriver::autoSubmitHtml() 이 그린 페이지의 첫 폼을 문서가 준비되는 대로 제출한다.
 * 스크립트가 막힌 브라우저는 <noscript> 의 [계속] 단추로 직접 제출한다.
 */
(function () {
    'use strict';

    function submit() {
        var form = document.forms[0];
        if (form) {
            form.submit();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', submit);
    } else {
        submit();
    }
})();
