/**
 * sirsoft-ecommerce 모듈 엔트리포인트
 *
 * 모듈 활성화 시 자동으로 로드되어 핸들러를 등록합니다.
 */

// CSS 임포트 (Vite 번들링)
import '../css/main.css';

// 핸들러 맵 임포트
import { handlerMap } from './handlers';

// 모듈 식별자 (전체 식별자 사용)
const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.(`Module:${MODULE_IDENTIFIER}`)) ?? {
    log: (...args: unknown[]) => console.log(`[Module:${MODULE_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[Module:${MODULE_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[Module:${MODULE_IDENTIFIER}]`, ...args),
};

/**
 * 핸들러 등록 함수 (내부용)
 *
 * ActionDispatcher에 핸들러를 등록합니다.
 * retry 옵션으로 ActionDispatcher가 없을 때 재시도 여부를 제어합니다.
 *
 * @param retry 재시도 여부 (기본: false)
 */
function registerHandlers(retry: boolean = false): void {
    const actionDispatcher = (window as any).G7Core?.getActionDispatcher?.();

    if (actionDispatcher) {
        // handlerMap의 모든 핸들러를 자동으로 등록
        // 네임스페이스: {module-identifier}.{handler-name}
        Object.entries(handlerMap).forEach(([name, handler]) => {
            const fullName = `${MODULE_IDENTIFIER}.${name}`;
            actionDispatcher.registerHandler(fullName, handler, {
                category: 'module',
                source: MODULE_IDENTIFIER,
            });
        });

        logger.log(
            `${Object.keys(handlerMap).length} handler(s) registered:`,
            Object.keys(handlerMap).map(name => `${MODULE_IDENTIFIER}.${name}`)
        );
    } else if (retry) {
        // 최초 로드 시에만 재시도 (언어 전환 등 재초기화 시에는 재시도하지 않음)
        let retryCount = 0;
        const maxRetries = 50; // 최대 5초 대기 (50 * 100ms)

        const retryRegister = () => {
            const dispatcher = (window as any).G7Core?.getActionDispatcher?.();

            if (dispatcher) {
                Object.entries(handlerMap).forEach(([name, handler]) => {
                    const fullName = `${MODULE_IDENTIFIER}.${name}`;
                    dispatcher.registerHandler(fullName, handler, {
                        category: 'module',
                        source: MODULE_IDENTIFIER,
                    });
                });

                logger.log(
                    `${Object.keys(handlerMap).length} handler(s) registered:`,
                    Object.keys(handlerMap).map(name => `${MODULE_IDENTIFIER}.${name}`)
                );
            } else {
                retryCount++;
                if (retryCount <= maxRetries) {
                    logger.warn(
                        `ActionDispatcher not found, retrying... (${retryCount}/${maxRetries})`
                    );
                    setTimeout(retryRegister, 100);
                } else {
                    logger.error(
                        `Failed to register handlers: ActionDispatcher not available after maximum retries`
                    );
                }
            }
        };

        retryRegister();
    } else {
        logger.warn(
            `ActionDispatcher not found, handlers not registered`
        );
    }
}

/**
 * 모듈 초기화 함수
 *
 * ActionDispatcher에 핸들러를 등록합니다.
 * 모듈 식별자를 네임스페이스로 사용하여 다른 모듈과 충돌을 방지합니다.
 *
 * 이 함수는 다음 상황에서 호출됩니다:
 * 1. 최초 모듈 로드 시 (자동 실행)
 * 2. 언어 전환 시 ActionDispatcher 재생성 후 (TemplateApp.reinitializeModuleHandlers)
 */
export function initModule(): void {
    // DOMContentLoaded 이벤트 사용 (DOM 파싱 완료 후, init_actions보다 먼저 실행되도록)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => registerHandlers(true));
    } else {
        // DOM이 이미 로드된 경우 즉시 실행
        // ActionDispatcher가 이미 있으면 바로 등록, 없으면 재시도
        const hasDispatcher = !!(window as any).G7Core?.getActionDispatcher?.();
        registerHandlers(hasDispatcher ? false : true);
    }
}

// 모듈 초기화 자동 실행
initModule();

// 전역 객체에 모듈 노출 (디버깅용)
if (typeof window !== 'undefined') {
    (window as any).__SirsoftEcommerce = {
        identifier: MODULE_IDENTIFIER,
        handlers: Object.keys(handlerMap),
        initModule,
    };
}
