/**
 * NHN KCP 플러그인 엔트리
 *
 * ActionDispatcher 에 핸들러를 등록하여 레이아웃에서 "sirsoft-pay_nhnkcp.requestPayment" 형태로 부를 수 있게 한다.
 * 주문 상세·주문 완료·관리자 화면에 붙는 표시는 모두 레이아웃 조각(`resources/extensions`)이 맡는다 — 이 엔트리는
 * 화면 DOM 을 찾거나 고치지 않고, 코어 함수(fetch·history)를 덮어쓰지 않는다.
 */
// 설정 화면 안내 글자색(접두 의미 클래스) — 빌드가 dist/css/plugin.css 로 뽑아낸다
import '../css/main.css';
import { handlerMap } from './handlers';
import { reportPaymentFailureOnReturn } from './paymentCloseReport';

/**
 * 외부(PG) 영수증 주소를 팝업으로 엽니다.
 *
 * 보안: https 주소만 열고, 새 창이 이 창(window.opener)을 조작하지 못하게 opener 를 끊은 뒤 주소를 싣는다
 * (역방향 탭내빙 차단). 빈 창을 먼저 여는 이유 — `noopener` 를 주면 팝업 크기 지정이 무시되는 브라우저가 있다.
 *
 * @param url 영수증 주소
 * @param name 창 이름
 * @param features 창 속성
 * @returns 열었으면 true
 */
export function openExternalReceiptWindow(url: unknown, name: string, features: string): boolean {
    if (typeof url !== 'string' || !/^https:\/\//i.test(url)) {
        return false;
    }
    const popup = window.open('', name, features);
    if (popup) {
        popup.opener = null;
        popup.location.href = url;
    }

    return true;
}

class KcpReceiptPopup {
    constructor(params: { url?: string; cash_url?: string }) {
        const features = 'width=800,height=600,scrollbars=yes,resizable=yes,toolbar=no,menubar=no';
        if (params.url) {
            openExternalReceiptWindow(params.url, 'kcp_receipt', features);
        }
        if (params.cash_url) {
            openExternalReceiptWindow(params.cash_url, 'kcp_cash_receipt', features);
        }
    }
}
(window as Record<string, unknown>).KcpReceiptPopup = KcpReceiptPopup;

const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

function registerHandlers(): number {
    const g7Core = (window as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;

    if (!g7Core) {
        return 0;
    }

    const getDispatcher = g7Core.getActionDispatcher as (() => Record<string, unknown>) | undefined;

    if (typeof getDispatcher !== 'function') {
        return 0;
    }

    const dispatcher = getDispatcher() as Record<string, unknown> | undefined;

    if (!dispatcher || typeof dispatcher.registerHandler !== 'function') {
        return 0;
    }

    let count = 0;
    for (const [name, handler] of Object.entries(handlerMap)) {
        const fullName = `${PLUGIN_IDENTIFIER}.${name}`;
        dispatcher.registerHandler(fullName, handler, {
            category: 'plugin',
            source: PLUGIN_IDENTIFIER,
        });
        count++;
    }

    return count;
}

/**
 * 핸들러를 등록합니다. 로케일 전환 시 코어가 다시 부르는 재등록 진입점이다.
 *
 * ActionDispatcher 가 아직 준비되지 않았으면 100ms 간격으로 최대 5초 재시도한다.
 *
 * @param onRegistered 등록에 성공했을 때 한 번 부를 콜백 (부팅 때만 넘긴다)
 */
function initPlugin(onRegistered?: () => void): void {
    const doInit = () => {
        const count = registerHandlers();

        if (count > 0) {
            logger.info(`${count} handler(s) registered`);
            onRegistered?.();
            return;
        }

        let retries = 0;
        const maxRetries = 50;
        const interval = setInterval(() => {
            retries++;
            const result = registerHandlers();

            if (result > 0) {
                clearInterval(interval);
                logger.info(`${result} handler(s) registered (after ${retries} retries)`);
                onRegistered?.();
                return;
            }

            if (retries >= maxRetries) {
                clearInterval(interval);
                logger.warn('ActionDispatcher not available after timeout');
            }
        }, 100);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', doInit);
    } else {
        doInit();
    }
}

// 부팅 — 핸들러를 등록하고, 코어가 준비되면 결제 실패로 돌아온 화면인지 한 번 확인해 서버에 보고한다.
// 브라우저 리턴 콜백은 PG 서명도 IP 증명도 없어 주문 상태를 바꾸지 않으므로, 소유권을 대조하는
// close-report 가 정당한 결제 실패를 기록하는 유일한 경로다. 저장해 둔 정보가 없으면 아무 일도 하지 않는다.
// 보고는 G7Core.api 로만 보내므로 코어가 준비된 뒤(핸들러 등록 성공)에 한다.
initPlugin(() => {
    void reportPaymentFailureOnReturn();
});

(window as Record<string, unknown>).__SirsoftNhnkcp = {
    identifier: PLUGIN_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    // 재등록 진입점은 핸들러 등록만 한다(인자 없이 불린다) — 부팅 1회 작업을 다시 돌리지 않는다.
    initPlugin: () => initPlugin(),
};
