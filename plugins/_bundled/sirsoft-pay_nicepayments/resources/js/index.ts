/**
 * 나이스페이먼츠 플러그인 엔트리
 *
 * ActionDispatcher 에 결제 핸들러를 등록한다. 화면에 덧붙이는 표시(주문 완료·마이페이지 영수증, 관리자 주문 상세
 * 간편결제 이름)는 모두 레이아웃 조각(resources/extensions)이 그린다 — 종전의 화면 주입 스크립트(주소 정규식 +
 * history.pushState 덮어쓰기 + DOM 패치)는 #639 에서 걷어냈다.
 */
// 설정 화면 안내 글자색(접두 의미 클래스) — 빌드가 dist/css/plugin.css 로 뽑아낸다
import '../css/main.css';
import { handlerMap } from './handlers';

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

/**
 * 플러그인 초기화 — 코어가 재초기화(언어 전환 등) 때 window.__SirsoftNicepayments.initPlugin 으로 다시 부른다.
 */
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

initPlugin();

(window as Record<string, unknown>).__SirsoftNicepayments = {
    identifier: PLUGIN_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    initPlugin,
};
