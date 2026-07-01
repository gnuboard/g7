import { handlerMap } from './handlers';
import { installOrderResponseInterceptor } from './orderResponseInterceptor';
import { installMypageOrderShowInjector } from './mypageOrderShowInjector';
import { installAdminOrderPaymentDisplayInjector } from './adminOrderPaymentDisplayInjector';
import { installOrderCompleteReceiptInjector } from './orderCompleteReceiptInjector';
import { installVbankInfoInjector } from './vbankInfoInjector';
import { installPaymentCloseMessageListener } from './paymentCloseMessageListener';
import { installCheckoutJpyPaymentMethodRestrictor } from './checkoutJpyPaymentMethodRestrictor';
import { installCheckoutNaverpayBrandButton } from './checkoutNaverpayBrandButton';
import { installAdminPaymentMethodBrandInjector } from './adminPaymentMethodBrandInjector';

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

// fetch 인터셉터: 체크아웃 페이지에서 kginicis 주문 응답을 가로채 결제창 호출
// (체크아웃 템플릿이 코어 영역이라 수정 불가하므로 클라이언트 사이드 우회)
installOrderResponseInterceptor();
installCheckoutJpyPaymentMethodRestrictor();
installCheckoutNaverpayBrandButton();

installMypageOrderShowInjector();
installAdminOrderPaymentDisplayInjector();
installAdminPaymentMethodBrandInjector();
installOrderCompleteReceiptInjector();
installVbankInfoInjector();
installPaymentCloseMessageListener();

initPlugin();

(window as Record<string, unknown>).__SirsoftKginicis = {
    identifier: PLUGIN_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    initPlugin,
};
