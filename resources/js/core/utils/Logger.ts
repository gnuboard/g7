/**
 * Template Engine Logger
 *
 * 싱글톤 패턴을 사용하는 디버깅 및 로깅 유틸리티
 * DevTools 통합: G7Core.devTools.trackLog()를 통해 로그를 DevTools에 기록합니다.
 *
 * 조기 로그 버퍼링:
 * DevTools 초기화 전에 발생한 에러/경고 로그를 버퍼에 저장하고,
 * 초기화 후 flush하여 누락 없이 기록합니다.
 */

import type { G7DevToolsInterface } from '../template-engine/G7CoreGlobals';

// ============================================================================
// 조기 로그 버퍼링 (DevTools 초기화 전 에러/경고 캡처)
// ============================================================================

type LogLevel = 'log' | 'warn' | 'error' | 'debug' | 'info';

interface EarlyLogEntry {
    level: LogLevel;
    prefix: string;
    args: unknown[];
    timestamp: number;
}

/** DevTools 초기화 전 에러/경고를 저장하는 버퍼 */
const EARLY_LOG_BUFFER: EarlyLogEntry[] = [];

/** 버퍼 최대 크기 (메모리 제한) */
const MAX_EARLY_BUFFER = 50;

/** DevTools 초기화 완료 여부 */
let devToolsInitialized = false;

/**
 * G7Core.devTools 인터페이스 가져오기
 */
function getDevTools(): G7DevToolsInterface | undefined {
    try {
        const G7Core = (window as any).G7Core;
        return G7Core?.devTools;
    } catch {
        return undefined;
    }
}

/**
 * 로그를 DevTools에 전달 (버퍼링 지원)
 *
 * DevTools가 초기화되지 않은 상태에서 에러/경고가 발생하면
 * 버퍼에 저장하고, 초기화 후 flush합니다.
 *
 * @param level - 로그 레벨
 * @param prefix - 로그 prefix (모듈명)
 * @param args - 로그 인자들
 */
function trackLogWithBuffer(level: LogLevel, prefix: string, args: unknown[]): void {
    const devTools = getDevTools();

    // DevTools가 이미 초기화되어 활성화된 경우 바로 전달
    if (devToolsInitialized && devTools?.isEnabled?.()) {
        devTools.trackLog?.(level, prefix, args);
        return;
    }

    // DevTools가 초기화되었지만 비활성화된 경우 무시
    if (devToolsInitialized) {
        return;
    }

    // 초기화 전: 에러/경고만 버퍼링 (log는 버퍼링 안 함 - 성능)
    if (level === 'error' || level === 'warn') {
        if (EARLY_LOG_BUFFER.length < MAX_EARLY_BUFFER) {
            EARLY_LOG_BUFFER.push({
                level,
                prefix,
                args,
                timestamp: Date.now(),
            });
        }
    }
}

/**
 * 조기 로그 버퍼를 DevTools로 flush
 *
 * DevTools 초기화 완료 후 호출하여 버퍼에 저장된 에러/경고를 전달합니다.
 * DevTools가 비활성화된 경우 버퍼만 비우고 종료합니다.
 *
 * @example
 * ```typescript
 * // G7CoreGlobals.ts의 initDevToolsAPI()에서 호출
 * import { flushEarlyLogs } from '../utils/Logger';
 * flushEarlyLogs();
 * ```
 */
export function flushEarlyLogs(): void {
    devToolsInitialized = true;

    const devTools = getDevTools();
    if (!devTools?.isEnabled?.()) {
        // DevTools 비활성화: 버퍼 비우고 종료 (보안)
        EARLY_LOG_BUFFER.length = 0;
        return;
    }

    // DevTools 활성화: 버퍼된 로그 flush
    for (const entry of EARLY_LOG_BUFFER) {
        devTools.trackLog?.(entry.level, entry.prefix, entry.args);
    }

    // 버퍼 비우기
    EARLY_LOG_BUFFER.length = 0;
}

export class Logger {
    private static instance: Logger;
    private debug: boolean;
    private readonly prefix = '[TemplateEngine]';

    private constructor() {
        this.debug = false;
    }

    /**
     * 싱글톤 인스턴스 반환
     */
    public static getInstance(): Logger {
        if (!Logger.instance) {
            Logger.instance = new Logger();
        }
        return Logger.instance;
    }

    /**
     * 디버그 모드 설정
     */
    public setDebug(enabled: boolean): void {
        this.debug = enabled;
    }

    /**
     * 디버그 모드 확인
     */
    public isDebugEnabled(): boolean {
        return this.debug;
    }

    /**
     * 일반 로그 출력
     */
    public log(...args: unknown[]): void {
        if (this.debug) {
            console.log(this.prefix, ...args);
        }
        // DevTools 추적 (버퍼링 지원)
        trackLogWithBuffer('log', 'TemplateEngine', args);
    }

    /**
     * 에러 로그 출력
     */
    public error(...args: unknown[]): void {
        if (this.debug) {
            console.error(this.prefix, ...args);
        }
        // DevTools 추적 (버퍼링 지원 - 초기화 전 에러도 캡처)
        trackLogWithBuffer('error', 'TemplateEngine', args);
    }

    /**
     * 경고 로그 출력
     */
    public warn(...args: unknown[]): void {
        if (this.debug) {
            console.warn(this.prefix, ...args);
        }
        // DevTools 추적 (버퍼링 지원 - 초기화 전 경고도 캡처)
        trackLogWithBuffer('warn', 'TemplateEngine', args);
    }
}

/**
 * 파일별 prefix를 지원하는 로거 인터페이스
 */
export interface PrefixedLogger {
    log: (...args: unknown[]) => void;
    warn: (...args: unknown[]) => void;
    error: (...args: unknown[]) => void;
}

/**
 * 파일별 prefix를 지원하는 로거 팩토리 함수
 *
 * @example
 * ```typescript
 * import { createLogger } from './utils/Logger';
 *
 * const logger = createLogger('TemplateApp');
 * logger.log('Initializing...');  // [TemplateApp] Initializing...
 * logger.warn('Warning message'); // [TemplateApp] Warning message
 * logger.error('Error occurred'); // [TemplateApp] Error occurred
 * ```
 *
 * @param prefix - 로그 메시지 앞에 붙을 prefix (예: 'TemplateApp', 'Router')
 * @returns PrefixedLogger 객체
 */
export function createLogger(prefix: string): PrefixedLogger {
    const formattedPrefix = `[${prefix}]`;
    const loggerInstance = Logger.getInstance();

    return {
        log: (...args: unknown[]): void => {
            if (loggerInstance.isDebugEnabled()) {
                console.log(formattedPrefix, ...args);
            }
            // DevTools 추적 (버퍼링 지원)
            trackLogWithBuffer('log', prefix, args);
        },
        warn: (...args: unknown[]): void => {
            if (loggerInstance.isDebugEnabled()) {
                console.warn(formattedPrefix, ...args);
            }
            // DevTools 추적 (버퍼링 지원 - 초기화 전 경고도 캡처)
            trackLogWithBuffer('warn', prefix, args);
        },
        error: (...args: unknown[]): void => {
            if (loggerInstance.isDebugEnabled()) {
                console.error(formattedPrefix, ...args);
            }
            // DevTools 추적 (버퍼링 지원 - 초기화 전 에러도 캡처)
            trackLogWithBuffer('error', prefix, args);
        },
    };
}

export default Logger.getInstance();
