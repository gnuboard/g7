/*
 * NHN KCP PC 결제창 프레임 브리지 (`/plugins/sirsoft-pay_nhnkcp/payment/kcp-frame`)
 *
 * 주문 화면이 투명 iframe 으로 이 페이지를 연다. 페이지는 KCP SDK(payplus_web.jsp)를 정적
 * <script src> 로 먼저 싣고(SDK 가 파싱 중 document.write 를 쓰므로 동기 로드여야 한다), 그 다음
 * 이 파일이 order_info 폼을 채워 KCP_Pay_Execute 를 부른다.
 *
 * 종전에는 부모가 빈 iframe 에 document.write 로 SDK 와 인라인 <script> 를 써 넣었다 — 사이트가
 * script-src 'self' 를 걸면 그 인라인 코드가 실행되지 않아 PC 결제가 15초 뒤 시간 초과로 끝났다(#158).
 *
 * 부모와는 같은 출처의 `window.parent.__g7KcpFrame` 객체로만 주고받는다(결과 폼은 DOM 이라
 * postMessage 로 넘길 수 없다). 객체가 없으면(다른 출처·직접 열기) 아무 것도 하지 않는다.
 *   - fields      : order_info 에 넣을 hidden 값 {name: value}
 *   - complete(form, frameWindow) : KCP 가 결과를 돌려줄 때 (m_Completepayment)
 *   - ready()     : 결제창이 열렸을 때
 *   - fail(message) : SDK 가 없거나 실행이 실패했을 때
 *
 * 손작성 ES5, 빌드하지 않는다.
 */
(function () {
    'use strict';

    var bridge = null;

    try {
        if (window.parent && window.parent !== window) {
            bridge = window.parent.__g7KcpFrame || null;
        }
    } catch (e) {
        bridge = null; // 다른 출처 부모 — 접근 불가
    }

    if (!bridge) {
        return;
    }

    // KCP SDK 가 결제 완료·취소 때 전역 이름으로 부른다.
    window.m_Completepayment = function (form) {
        bridge.complete(form, window);
    };

    var form = document.forms.order_info;
    var fields = bridge.fields || {};

    for (var name in fields) {
        if (!Object.prototype.hasOwnProperty.call(fields, name)) {
            continue;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(fields[name]);
        form.appendChild(input);
    }

    try {
        if (typeof window.KCP_Pay_Execute === 'function') {
            window.KCP_Pay_Execute(form);
            bridge.ready();
        } else {
            bridge.fail('KCP_Pay_Execute not defined');
        }
    } catch (error) {
        bridge.fail(error && error.message ? String(error.message) : String(error));
    }
})();
