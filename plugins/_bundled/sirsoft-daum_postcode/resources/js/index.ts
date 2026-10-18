/**
 * sirsoft-daum_postcode 플러그인 엔트리포인트
 *
 * 플러그인 활성화 시 자동으로 로드되어 핸들러를 등록합니다.
 */

// 핸들러 맵 임포트
import { handlerMap } from './handlers';

// 플러그인 식별자
const PLUGIN_IDENTIFIER = 'sirsoft-daum_postcode';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.(`Plugin:${PLUGIN_IDENTIFIER}`)) ?? {
    log: (...args: unknown[]) => console.log(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
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
        // 네임스페이스: {plugin-identifier}.{handler-name}
        Object.entries(handlerMap).forEach(([name, handler]) => {
            const fullName = `${PLUGIN_IDENTIFIER}.${name}`;
            actionDispatcher.registerHandler(fullName, handler, {
                category: 'plugin',
                source: PLUGIN_IDENTIFIER,
            });
        });

        logger.log(
            `${Object.keys(handlerMap).length} handler(s) registered:`,
            Object.keys(handlerMap).map(name => `${PLUGIN_IDENTIFIER}.${name}`)
        );
    } else if (retry) {
        // 최초 로드 시에만 재시도 (언어 전환 등 재초기화 시에는 재시도하지 않음)
        let retryCount = 0;
        const maxRetries = 50; // 최대 5초 대기 (50 * 100ms)

        const retryRegister = () => {
            const dispatcher = (window as any).G7Core?.getActionDispatcher?.();

            if (dispatcher) {
                Object.entries(handlerMap).forEach(([name, handler]) => {
                    const fullName = `${PLUGIN_IDENTIFIER}.${name}`;
                    dispatcher.registerHandler(fullName, handler, {
                        category: 'plugin',
                        source: PLUGIN_IDENTIFIER,
                    });
                });

                logger.log(
                    `${Object.keys(handlerMap).length} handler(s) registered:`,
                    Object.keys(handlerMap).map(name => `${PLUGIN_IDENTIFIER}.${name}`)
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
 * 플러그인 초기화 함수
 *
 * ActionDispatcher에 핸들러를 등록합니다.
 * 플러그인 식별자를 네임스페이스로 사용하여 다른 플러그인과 충돌을 방지합니다.
 */
export function initPlugin(): void {
    // DOMContentLoaded 이벤트 사용 (DOM 파싱 완료 후)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => registerHandlers(true));
    } else {
        // DOM이 이미 로드된 경우 즉시 실행
        const hasDispatcher = !!(window as any).G7Core?.getActionDispatcher?.();
        registerHandlers(hasDispatcher ? false : true);
    }
}

// 플러그인 초기화 자동 실행
initPlugin();

// 전역 객체에 플러그인 노출 (디버깅용)
if (typeof window !== 'undefined') {
    (window as any).__SirsoftDaumPostcode = {
        identifier: PLUGIN_IDENTIFIER,
        handlers: Object.keys(handlerMap),
        initPlugin,
    };
}
