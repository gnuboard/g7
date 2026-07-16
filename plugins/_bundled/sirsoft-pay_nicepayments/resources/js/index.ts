import { handlerMap } from './handlers';
import { initEasyPayWatcher } from './handlers/setPaymentMethod';
import { installOrderResponseInterceptor } from './orderResponseInterceptor';
import { installOrderCompleteReceiptInjector } from './orderCompleteReceiptInjector';
import { installMypageOrderShowInjector } from './mypageOrderShowInjector';
import { installAdminPaymentMethodBrandInjector } from './adminPaymentMethodBrandInjector';
import { installAdminOrderPaymentDisplayInjector } from './adminOrderPaymentDisplayInjector';

const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';

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
    // fetch 인터셉터는 G7Core 초기화와 무관하게 즉시 설치
    installOrderResponseInterceptor();
    installOrderCompleteReceiptInjector();
    installMypageOrderShowInjector();
    installAdminPaymentMethodBrandInjector();
    installAdminOrderPaymentDisplayInjector();

    const doInit = () => {
        const count = registerHandlers();

        if (count > 0) {
            logger.info(`${count} handler(s) registered`);
            initEasyPayWatcher();
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
                initEasyPayWatcher();
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

initPlugin();

(window as Record<string, unknown>).__SirsoftNicepayments = {
    identifier: PLUGIN_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    initPlugin,
};
