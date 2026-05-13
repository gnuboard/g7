/**
 * 토스페이먼츠 플러그인 엔트리
 *
 * ActionDispatcher에 핸들러를 등록하여
 * 레이아웃 JSON에서 "sirsoft-tosspayments.requestPayment" 형태로 호출할 수 있게 합니다.
 */

import { handlerMap } from './handlers';

const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

/**
 * 로거 (G7Core 초기화 전에도 동작)
 */
const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

/**
 * ActionDispatcher에 핸들러를 등록합니다.
 *
 * @returns 등록된 핸들러 수
 */
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
 * 플러그인 초기화
 *
 * DOMContentLoaded 후 ActionDispatcher가 준비되면 핸들러를 등록합니다.
 * ActionDispatcher가 아직 준비되지 않았으면 최대 5초간 재시도합니다.
 */
function initPlugin(): void {
    const doInit = () => {
        const count = registerHandlers();

        if (count > 0) {
            logger.info(`${count} handler(s) registered`);
            return;
        }

        // ActionDispatcher 미준비 시 재시도 (100ms 간격, 최대 50회 = 5초)
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

// 자동 초기화
initPlugin();

// 디버그용 글로벌 노출
(window as Record<string, unknown>).__SirsoftTosspayments = {
    identifier: PLUGIN_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    initPlugin,
};
