/**
 * sirsoft-page 모듈 프론트엔드 엔트리
 *
 * 모듈이 활성화되면 코어가 이 번들을 페이지에 실어 즉시 실행하고, 여기서 handlerMap 의 핸들러를
 * `sirsoft-page.{이름}` 으로 ActionDispatcher 에 등록한다.
 *
 * 로케일 전환처럼 코어가 다시 초기화되면 ActionDispatcher 가 새 인스턴스로 바뀌어 등록이 사라진다.
 * 코어는 그때 `window.__SirsoftPage.initModule()` 을 불러 재등록을 요청하므로, 이 진입점은
 * 핸들러 재등록만 한다.
 */

import { handlerMap } from './handlers';

// 관리자 페이지 화면에만 있는 호버·다크 짝(접두 클래스) — 빌드가 dist/css/module.css 로 뽑아낸다
import '../css/main.css';

const MODULE_IDENTIFIER = 'sirsoft-page';

// Logger (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = (window as any).G7Core?.createLogger?.(`Module:${MODULE_IDENTIFIER}`) ?? {
    log: (...args: unknown[]) => console.log(`[Module:${MODULE_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[Module:${MODULE_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[Module:${MODULE_IDENTIFIER}]`, ...args),
};

/**
 * handlerMap 의 핸들러를 ActionDispatcher 에 등록한다.
 *
 * @returns 등록했으면 true, ActionDispatcher 가 아직 없으면 false
 */
function registerHandlers(): boolean {
    const dispatcher = (window as any).G7Core?.getActionDispatcher?.();

    if (!dispatcher || typeof dispatcher.registerHandler !== 'function') {
        return false;
    }

    Object.entries(handlerMap).forEach(([name, handler]) => {
        dispatcher.registerHandler(`${MODULE_IDENTIFIER}.${name}`, handler, {
            category: 'module',
            source: MODULE_IDENTIFIER,
        });
    });

    logger.log(
        `${Object.keys(handlerMap).length} handler(s) registered:`,
        Object.keys(handlerMap).map((name) => `${MODULE_IDENTIFIER}.${name}`)
    );

    return true;
}

/**
 * 최초 로드 때는 ActionDispatcher 가 준비될 때까지 잠시 재시도한다(최대 5초).
 */
function registerHandlersWithRetry(): void {
    if (registerHandlers()) {
        return;
    }

    let retries = 0;
    const maxRetries = 50;
    const timer = setInterval(() => {
        retries++;

        if (registerHandlers()) {
            clearInterval(timer);
            return;
        }

        if (retries >= maxRetries) {
            clearInterval(timer);
            logger.error('ActionDispatcher not available after maximum retries — handlers not registered');
        }
    }, 100);
}

/**
 * 모듈 초기화 — 핸들러 (재)등록만 수행한다.
 *
 * 1. 최초 번들 로드 시 자동 실행
 * 2. 로케일 전환 등으로 ActionDispatcher 가 교체된 뒤 코어가 재호출
 */
export function initModule(): void {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => registerHandlersWithRetry());
        return;
    }

    registerHandlersWithRetry();
}

initModule();

// 코어 재초기화 시 재등록 진입점 (이름 고정: window.__SirsoftPage.initModule)
(window as any).__SirsoftPage = {
    identifier: MODULE_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    initModule,
};
