/**
 * KG이니시스 결제 플러그인 엔트리
 *
 * ActionDispatcher 에 핸들러를 등록하여 레이아웃에서 "sirsoft-pay_kginicis.requestPayment" 형태로 호출할 수 있게 한다.
 * 화면 덧붙임(주문 상세·주문 완료·관리자 주문 상세의 영수증·입금 안내·간편결제 이름)은 레이아웃 조각
 * (resources/extensions/*.g7.html)이 맡고, 주문서 결제수단 제한은 서버 훅(RestrictPaymentMethodsForCurrencyListener)이
 * 맡는다 — 이 엔트리는 화면을 엿보거나 남의 요청을 가로채지 않는다.
 */
// 설정 화면 안내 글자색(접두 의미 클래스) — 빌드가 dist/css/plugin.css 로 뽑아낸다
import '../css/main.css';
import { handlerMap } from './handlers';
import {
    installPaymentCloseMessageListener,
    reportStandardPaymentFailureOnReturn,
} from './paymentCloseMessageListener';

const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

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

function initPlugin(): void {
    const doInit = () => {
        const count = registerHandlers();

        if (count > 0) {
            logger.info(`${count} handler(s) registered`);
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

installPaymentCloseMessageListener();

// 결제 실패로 돌아온 화면이면 서버에 보고한다. 브라우저 리턴 콜백(PC·모바일·해외결제)은
// PG 서명도 IP 증명도 없어 주문 상태를 바꾸지 않으므로, 소유권을 대조하는 close-report 가
// 정당한 결제 실패를 기록하는 유일한 경로다. 저장해 둔 정보가 없으면 아무 일도 하지 않는다.
void reportStandardPaymentFailureOnReturn();

initPlugin();

(window as Record<string, unknown>).__SirsoftKginicis = {
    identifier: PLUGIN_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    initPlugin,
};
