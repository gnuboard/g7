/**
 * sirsoft-message_bizppurio 플러그인 엔트리포인트.
 *
 * 플러그인 활성화 시 자동 로드되어 handlerMap 의 커스텀 핸들러를 ActionDispatcher 에
 * 등록한다. 핸들러명은 `sirsoft-message_bizppurio.{name}` 네임스페이스를 갖는다.
 * (현재 등록 핸들러: insertVariable, uploadTemplateImage — handlers/index.ts 참조.)
 */

import { handlerMap } from './handlers';

const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

const logger = ((window as any).G7Core?.createLogger?.(`Plugin:${PLUGIN_IDENTIFIER}`)) ?? {
    log: (...args: unknown[]) => console.log(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
};

/**
 * handlerMap 의 모든 핸들러를 ActionDispatcher 에 등록합니다.
 *
 * @param dispatcher  코어 ActionDispatcher 인스턴스
 */
function register(dispatcher: any): void {
    Object.entries(handlerMap).forEach(([name, handler]) => {
        dispatcher.registerHandler(`${PLUGIN_IDENTIFIER}.${name}`, handler, {
            category: 'plugin',
            source: PLUGIN_IDENTIFIER,
        });
    });

    logger.log(
        `${Object.keys(handlerMap).length} handler(s) registered:`,
        Object.keys(handlerMap).map(name => `${PLUGIN_IDENTIFIER}.${name}`),
    );
}

/**
 * ActionDispatcher 준비 후 핸들러를 등록합니다.
 *
 * 최초 로드 시 ActionDispatcher 가 아직 없을 수 있으므로 짧게 재시도한다.
 *
 * @param retry  ActionDispatcher 부재 시 재시도 여부
 */
function registerHandlers(retry: boolean): void {
    const dispatcher = (window as any).G7Core?.getActionDispatcher?.();

    if (dispatcher) {
        register(dispatcher);
        return;
    }

    if (!retry) {
        logger.warn('ActionDispatcher 를 찾지 못해 핸들러를 등록하지 못했습니다.');
        return;
    }

    let count = 0;
    const max = 50; // 최대 5초 (50 * 100ms)
    const tick = () => {
        const found = (window as any).G7Core?.getActionDispatcher?.();
        if (found) {
            register(found);
            return;
        }
        if (++count <= max) {
            setTimeout(tick, 100);
        } else {
            logger.error('ActionDispatcher 를 찾지 못해 핸들러 등록에 실패했습니다.');
        }
    };
    tick();
}

/**
 * 플러그인 초기화 — DOM 준비 후 핸들러 등록.
 */
export function initPlugin(): void {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => registerHandlers(true));
    } else {
        const hasDispatcher = !!(window as any).G7Core?.getActionDispatcher?.();
        registerHandlers(!hasDispatcher);
    }
}

initPlugin();

if (typeof window !== 'undefined') {
    (window as any).__SirsoftMessageBizppurio = {
        identifier: PLUGIN_IDENTIFIER,
        handlers: Object.keys(handlerMap),
        initPlugin,
    };
}