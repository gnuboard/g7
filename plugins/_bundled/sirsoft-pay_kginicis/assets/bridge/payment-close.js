/*
 * KG 이니시스 PC 표준결제창 닫기 브리지 (closeUrl 페이지)
 *
 * 결제창을 닫으면 이니시스가 이 페이지를 연다. 주문 화면(부모·opener)에 "결제창이 닫혔다" 를 알려
 * '결제 중' 상태를 풀게 하고, 뒤이어 이니시스 INIStdPay_close.js 가 창을 닫는다.
 *
 * 종전에는 페이지 인라인 <script> 였다 — 사이트가 script-src 'self' 를 걸면 실행되지 않아 결제창을 닫아도
 * 주문 화면이 '결제 중' 에 머물렀다(#158). 손작성 ES5, 빌드하지 않는다.
 */
(function () {
    'use strict';

    var payload = {
        source: 'sirsoft-pay_kginicis',
        type: 'payment-window-closed',
        reason: 'inicis-close-url'
    };
    var targetOrigin = window.location.origin;

    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(payload, targetOrigin);
        }

        if (window.opener && !window.opener.closed) {
            window.opener.postMessage(payload, targetOrigin);
        }
    } catch (error) {
        // 결제창 닫기 페이지는 상태 알림 실패와 무관하게 KG close script 를 실행한다.
    }
})();
