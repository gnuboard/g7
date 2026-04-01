import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Logger, createLogger, flushEarlyLogs } from '../Logger';

describe('Logger', () => {
    let logger: Logger;

    beforeEach(() => {
        logger = Logger.getInstance();
        logger.setDebug(false);
        vi.clearAllMocks();
    });

    describe('싱글톤 패턴', () => {
        it('getInstance()를 여러 번 호출해도 동일한 인스턴스를 반환해야 함', () => {
            const instance1 = Logger.getInstance();
            const instance2 = Logger.getInstance();

            expect(instance1).toBe(instance2);
        });
    });

    describe('디버그 모드', () => {
        it('디버그 모드를 설정할 수 있어야 함', () => {
            logger.setDebug(true);
            expect(logger.isDebugEnabled()).toBe(true);

            logger.setDebug(false);
            expect(logger.isDebugEnabled()).toBe(false);
        });

        it('초기 상태에서는 디버그 모드가 비활성화되어 있어야 함', () => {
            const newLogger = Logger.getInstance();
            expect(newLogger.isDebugEnabled()).toBe(false);
        });
    });

    describe('로깅 메서드', () => {
        it('debug 모드가 활성화되면 log()가 콘솔에 출력해야 함', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
            logger.setDebug(true);

            logger.log('test message', 123);

            expect(consoleSpy).toHaveBeenCalledWith('[TemplateEngine]', 'test message', 123);
            consoleSpy.mockRestore();
        });

        it('debug 모드가 비활성화되면 log()가 출력하지 않아야 함', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
            logger.setDebug(false);

            logger.log('test message');

            expect(consoleSpy).not.toHaveBeenCalled();
            consoleSpy.mockRestore();
        });

        it('debug 모드가 활성화되면 error()가 콘솔에 출력해야 함', () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            logger.setDebug(true);

            logger.error('error message', new Error('test'));

            expect(consoleSpy).toHaveBeenCalledWith(
                '[TemplateEngine]',
                'error message',
                expect.any(Error)
            );
            consoleSpy.mockRestore();
        });

        it('debug 모드가 비활성화되면 error()가 출력하지 않아야 함', () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            logger.setDebug(false);

            logger.error('error message');

            expect(consoleSpy).not.toHaveBeenCalled();
            consoleSpy.mockRestore();
        });

        it('debug 모드가 활성화되면 warn()이 콘솔에 출력해야 함', () => {
            const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
            logger.setDebug(true);

            logger.warn('warning message', { key: 'value' });

            expect(consoleSpy).toHaveBeenCalledWith(
                '[TemplateEngine]',
                'warning message',
                { key: 'value' }
            );
            consoleSpy.mockRestore();
        });

        it('debug 모드가 비활성화되면 warn()이 출력하지 않아야 함', () => {
            const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
            logger.setDebug(false);

            logger.warn('warning message');

            expect(consoleSpy).not.toHaveBeenCalled();
            consoleSpy.mockRestore();
        });

        it('모든 로그 메서드가 [TemplateEngine] 접두사를 포함해야 함', () => {
            const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
            const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

            logger.setDebug(true);

            logger.log('message');
            logger.error('message');
            logger.warn('message');

            expect(logSpy).toHaveBeenCalledWith('[TemplateEngine]', 'message');
            expect(errorSpy).toHaveBeenCalledWith('[TemplateEngine]', 'message');
            expect(warnSpy).toHaveBeenCalledWith('[TemplateEngine]', 'message');

            logSpy.mockRestore();
            errorSpy.mockRestore();
            warnSpy.mockRestore();
        });
    });
});

describe('createLogger', () => {
    it('커스텀 prefix로 로거를 생성해야 함', () => {
        const customLogger = createLogger('TestModule');
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        // debug 모드 활성화
        Logger.getInstance().setDebug(true);

        customLogger.error('test error');

        expect(consoleSpy).toHaveBeenCalledWith('[TestModule]', 'test error');
        consoleSpy.mockRestore();
        Logger.getInstance().setDebug(false);
    });
});

describe('조기 로그 버퍼링', () => {
    let mockDevTools: {
        isEnabled: ReturnType<typeof vi.fn>;
        trackLog: ReturnType<typeof vi.fn>;
    };

    beforeEach(() => {
        // G7Core.devTools 모킹
        mockDevTools = {
            isEnabled: vi.fn().mockReturnValue(true),
            trackLog: vi.fn(),
        };
        (global as any).window = {
            G7Core: {
                devTools: mockDevTools,
            },
        };
    });

    afterEach(() => {
        delete (global as any).window;
    });

    it('DevTools 활성화 시 flushEarlyLogs가 버퍼된 로그를 전달해야 함', () => {
        // DevTools가 아직 비활성화 상태로 설정
        mockDevTools.isEnabled.mockReturnValue(false);

        // 에러/경고 로그 발생 (버퍼링됨)
        const testLogger = createLogger('TestModule');
        testLogger.error('buffered error');
        testLogger.warn('buffered warning');

        // trackLog가 호출되지 않아야 함 (비활성화 상태)
        expect(mockDevTools.trackLog).not.toHaveBeenCalled();

        // DevTools 활성화
        mockDevTools.isEnabled.mockReturnValue(true);

        // flushEarlyLogs 호출
        flushEarlyLogs();

        // 버퍼된 에러/경고가 전달되어야 함
        expect(mockDevTools.trackLog).toHaveBeenCalledWith('error', 'TestModule', ['buffered error']);
        expect(mockDevTools.trackLog).toHaveBeenCalledWith('warn', 'TestModule', ['buffered warning']);
    });

    it('DevTools 비활성화 시 flushEarlyLogs가 버퍼만 비워야 함', () => {
        // DevTools 비활성화 상태 유지
        mockDevTools.isEnabled.mockReturnValue(false);

        // 에러 로그 발생
        const testLogger = createLogger('TestModule');
        testLogger.error('should be cleared');

        // flushEarlyLogs 호출 (비활성화 상태)
        flushEarlyLogs();

        // trackLog가 호출되지 않아야 함 (보안 - 버퍼만 비움)
        expect(mockDevTools.trackLog).not.toHaveBeenCalled();
    });

    it('log 레벨은 버퍼링하지 않아야 함 (성능)', () => {
        // DevTools 비활성화 상태
        mockDevTools.isEnabled.mockReturnValue(false);

        // log 레벨 로그 발생
        const testLogger = createLogger('TestModule');
        testLogger.log('should not be buffered');

        // DevTools 활성화
        mockDevTools.isEnabled.mockReturnValue(true);

        // flushEarlyLogs 호출
        flushEarlyLogs();

        // log 레벨은 버퍼링되지 않으므로 전달되지 않음
        expect(mockDevTools.trackLog).not.toHaveBeenCalledWith(
            'log',
            'TestModule',
            expect.anything()
        );
    });
});
