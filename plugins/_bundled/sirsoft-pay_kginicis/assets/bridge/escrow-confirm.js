/*
 * KG 이니시스 에스크로 구매결정 브리지 — 확인창·결과·닫기 페이지 공용
 *
 * 자기 <script> 태그의 data-mode 로 동작을 고른다.
 *   - pc-open      : 확인창. 이니시스 INIStdPay_escrow_conf.js 를 먼저 싣고, 문서가 다 뜨면
 *                    INIStdPay.pay(data-form) 로 구매결정 창을 띄운다
 *   - pc-return    : PC 결과 수신 뒤. 주문 화면(opener)을 새로고침하고 이 창을 닫는다
 *   - close        : 이니시스 closeUrl. 이 창을 닫는다
 *   - mobile-submit: 모바일. data-form 폼을 이니시스 모바일 결제 주소로 자동 제출한다
 *
 * 종전에는 각 페이지의 인라인 <script>(window.onload·자동 submit·window.close)였다 — 사이트가
 * script-src 'self' 를 걸면 실행되지 않아 구매결정을 할 수 없었다(#158). 손작성 ES5, 빌드하지 않는다.
 */
(function () {
    'use strict';

    var self = document.currentScript;
    var mode = self ? self.getAttribute('data-mode') : '';
    var formId = self ? self.getAttribute('data-form') : '';

    function closeWindow() {
        window.close();
    }

    if (mode === 'pc-return') {
        try {
            if (window.opener) {
                window.opener.location.reload();
            }
        } catch (e) {
            // 다른 출처로 바뀐 opener 는 새로고침할 수 없다 — 창 닫기는 계속한다
        }
        closeWindow();
        return;
    }

    if (mode === 'close') {
        closeWindow();
        return;
    }

    if (mode === 'pc-open') {
        window.addEventListener('load', function () {
            if (window.INIStdPay && typeof window.INIStdPay.pay === 'function') {
                window.INIStdPay.pay(formId);
            }
        });
        return;
    }

    if (mode === 'mobile-submit') {
        var submit = function () {
            var form = document.getElementById(formId);
            if (form) {
                form.submit();
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', submit);
        } else {
            submit();
        }
    }
})();
