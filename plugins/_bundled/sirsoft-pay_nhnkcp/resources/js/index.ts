import { handlerMap } from './handlers';
import { installOrderResponseInterceptor } from './orderResponseInterceptor';
import { installOrderCompleteReceiptInjector } from './orderCompleteReceiptInjector';
import { installMypageOrderShowInjector } from './mypageOrderShowInjector';
import { installAdminApplePayNoticeInjector } from './adminApplePayNoticeInjector';
import { installAdminPaymentMethodBrandInjector } from './adminPaymentMethodBrandInjector';
import { installAdminOrderPaymentDisplayInjector } from './adminOrderPaymentDisplayInjector';
import { reportPaymentFailureOnReturn } from './paymentCloseReport';

class KcpReceiptPopup {
    constructor(params: { url?: string; cash_url?: string }) {
        const features = 'width=800,height=600,scrollbars=yes,resizable=yes,toolbar=no,menubar=no';
        if (params.url) {
            window.open(params.url, 'kcp_receipt', features);
        }
        if (params.cash_url) {
            window.open(params.cash_url, 'kcp_cash_receipt', features);
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

function initPlugin(): void {
    // 결제 실패로 돌아온 화면이면 서버에 보고한다. 브라우저 리턴 콜백은 PG 서명도 IP 증명도
    // 없어 주문 상태를 바꾸지 않으므로, 소유권을 대조하는 close-report 가 정당한 결제 실패를
    // 기록하는 유일한 경로다. 저장해 둔 정보가 없으면 아무 일도 하지 않는다.
    void reportPaymentFailureOnReturn();

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

installOrderResponseInterceptor();
installOrderCompleteReceiptInjector();
installMypageOrderShowInjector();
installAdminApplePayNoticeInjector();
installAdminPaymentMethodBrandInjector();
installAdminOrderPaymentDisplayInjector();
initPlugin();

(window as Record<string, unknown>).__SirsoftNhnkcp = {
    identifier: PLUGIN_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    initPlugin,
};
