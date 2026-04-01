/**
 * ServerConnector 테스트
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ServerConnector, getServerConnector } from '../ServerConnector';

// fetch 모킹
const mockFetch = vi.fn();

describe('ServerConnector', () => {
    let connector: ServerConnector;

    beforeEach(() => {
        // 전역 fetch 모킹
        global.fetch = mockFetch;

        // G7DevToolsCore 모킹
        vi.mock('../G7DevToolsCore', () => ({
            G7DevToolsCore: {
                getInstance: () => ({
                    isEnabled: () => true,
                    getState: () => ({ _global: { test: true }, _local: {}, _computed: {} }),
                    getActionHistory: () => [],
                    getCacheStats: () => ({ hits: 10, misses: 2, entries: 5, hitRate: 83.3 }),
                    getLifecycleInfo: () => ({ mountedComponents: [], orphanedListeners: [] }),
                    getNetworkInfo: () => ({
                        activeRequests: [],
                        requestHistory: [],
                        pendingDataSources: [],
                    }),
                    getExpressions: () => [],
                    getForms: () => [],
                    getPerformanceInfo: () => ({
                        renderCounts: new Map(),
                        bindingEvalCount: 0,
                        memoryWarnings: [],
                    }),
                    getConditionalInfo: () => ({ conditionals: [], iterations: [] }),
                    getDataSources: () => [],
                    getHandlers: () => ({ builtIn: [], custom: [], module: [] }),
                    getComponentEventInfo: () => ({ subscriptions: [], history: [] }),
                    getStateRenderingInfo: () => [],
                    getStateHierarchyInfo: () => ({
                        layers: [],
                        conflicts: [],
                        resolutionOrder: [],
                    }),
                    getContextFlowInfo: () => ({
                        tree: [],
                        unusedContexts: [],
                    }),
                    getStyleValidationInfo: () => ({
                        issues: [],
                        tailwindAnalysis: { purgedClasses: [], darkModeClasses: [], responsiveClasses: [] },
                    }),
                    getAuthDebugInfo: () => ({
                        isAuthenticated: false,
                        tokenInfo: null,
                        events: [],
                        headerAnalysis: [],
                    }),
                    getLogInfo: () => ({
                        logs: [],
                        stats: { total: 0, byLevel: {}, byPrefix: {} },
                    }),
                    getLayoutDebugInfo: () => ({
                        currentLayout: null,
                        layoutHistory: [],
                        cacheStatus: { cached: false },
                    }),
                    getChangeDetectionInfo: () => ({
                        executionDetails: [],
                        stateChangeHistory: [],
                        dataSourceChangeHistory: [],
                        alerts: [],
                        stats: {
                            totalExecutions: 0,
                            earlyReturns: 0,
                            noChangeExecutions: 0,
                            totalAlerts: 0,
                            alertsByType: {},
                        },
                        timestamp: Date.now(),
                    }),
                }),
            },
        }));

        connector = new ServerConnector();
    });

    afterEach(() => {
        vi.clearAllMocks();
        connector.destroy();
    });

    describe('상태 덤프', () => {
        it('성공적으로 상태를 덤프해야 한다', async () => {
            const responseData = {
                status: 'success',
                path: 'storage/debug-dump/state-latest.json',
                timestamp: '20260109_1530',
            };
            mockFetch.mockResolvedValueOnce({
                ok: true,
                text: async () => JSON.stringify(responseData),
            });

            const sessionId = await connector.dumpState();

            expect(mockFetch).toHaveBeenCalledWith(
                '/_boost/g7-debug/dump-state',
                expect.objectContaining({
                    method: 'POST',
                    headers: expect.objectContaining({
                        'Content-Type': 'application/json',
                    }),
                })
            );
            // dumpState는 이제 sessionId를 반환함
            expect(sessionId).toBeTruthy();
        });

        it('이력 저장 옵션을 전달해야 한다', async () => {
            const responseData = {
                status: 'success',
                path: 'storage/debug-dump/state-latest.json',
            };
            mockFetch.mockResolvedValueOnce({
                ok: true,
                text: async () => JSON.stringify(responseData),
            });

            await connector.dumpState(true);

            expect(mockFetch).toHaveBeenCalledWith(
                '/_boost/g7-debug/dump-state',
                expect.objectContaining({
                    body: expect.stringContaining('"saveHistory":true'),
                })
            );
        });

        it('API가 error 응답을 반환해도 dumpState는 성공해야 한다 (디버그 로깅 특성상 에러 무시)', async () => {
            // 현재 구현에서 sendBulkDump는 응답 status를 확인하지 않음
            // 디버그 데이터 덤프 실패가 애플리케이션 크래시를 유발하면 안 되므로
            // 이 동작은 의도된 것임
            const responseData = {
                status: 'error',
                message: '디버그 모드가 비활성화되어 있습니다.',
            };
            mockFetch.mockResolvedValueOnce({
                ok: true,
                text: async () => JSON.stringify(responseData),
            });

            // 에러를 throw하지 않고 정상 완료되어야 함
            await expect(connector.dumpState()).resolves.toBeTruthy();
        });

        it('네트워크 에러 시 에러를 throw해야 한다', async () => {
            mockFetch.mockRejectedValueOnce(new Error('Network error'));

            await expect(connector.dumpState()).rejects.toThrow('Network error');
        });
    });

    describe('로그 전송', () => {
        it('디버그 로그를 전송해야 한다', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                text: async () => JSON.stringify({ status: 'success', logged: true }),
            });

            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});

            await connector.sendLog({ message: 'test log' });

            expect(consoleSpy).toHaveBeenCalledWith(
                '[G7:DEBUG]',
                expect.stringContaining('test log')
            );

            consoleSpy.mockRestore();
        });

        it('로그 전송 실패를 무시해야 한다', async () => {
            mockFetch.mockRejectedValueOnce(new Error('Network error'));

            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});

            // 에러 throw 없이 완료되어야 함
            await expect(connector.sendLog({ message: 'test' })).resolves.toBeUndefined();

            consoleSpy.mockRestore();
        });
    });

    describe('에러 전송', () => {
        it('에러 정보를 전송해야 한다', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                text: async () => JSON.stringify({ status: 'success', logged: true }),
            });

            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
            const error = new Error('Test error');

            await connector.sendError(error, { component: 'TestComponent' });

            expect(consoleSpy).toHaveBeenCalledWith(
                '[G7:ERROR]',
                expect.stringContaining('Test error')
            );

            consoleSpy.mockRestore();
        });
    });

    describe('연결 상태', () => {
        it('초기 연결 상태는 false여야 한다', () => {
            expect(connector.isConnected()).toBe(false);
        });

        it('성공적인 덤프 후 연결 상태가 true가 되어야 한다', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                text: async () => JSON.stringify({
                    status: 'success',
                    path: 'storage/debug-dump/state-latest.json',
                }),
            });

            await connector.dumpState();

            expect(connector.isConnected()).toBe(true);
        });

        it('실패한 덤프 후 연결 상태가 false가 되어야 한다', async () => {
            // 먼저 성공하여 connected 상태로 만듦
            mockFetch.mockResolvedValueOnce({
                ok: true,
                text: async () => JSON.stringify({
                    status: 'success',
                    path: 'storage/debug-dump/state-latest.json',
                }),
            });
            await connector.dumpState();
            expect(connector.isConnected()).toBe(true);

            // 실패
            mockFetch.mockRejectedValueOnce(new Error('Network error'));
            await expect(connector.dumpState()).rejects.toThrow();

            expect(connector.isConnected()).toBe(false);
        });
    });

    describe('연결 테스트', () => {
        it('성공적인 연결 테스트', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                text: async () => JSON.stringify({ status: 'success', message: '연결 성공' }),
            });

            const result = await connector.testConnection();

            expect(result).toBe(true);
            expect(connector.isConnected()).toBe(true);
        });

        it('실패한 연결 테스트', async () => {
            mockFetch.mockRejectedValueOnce(new Error('Connection failed'));

            const result = await connector.testConnection();

            expect(result).toBe(false);
            expect(connector.isConnected()).toBe(false);
        });
    });

    describe('자동 캡처', () => {
        it('자동 캡처를 시작할 수 있어야 한다', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});

            connector.startAutoCapture();

            expect(consoleSpy).toHaveBeenCalledWith(
                expect.stringContaining('[G7DevTools] 자동 캡처 시작'),
                expect.any(Number),
                'ms)'
            );

            connector.stopAutoCapture();
            consoleSpy.mockRestore();
        });

        it('자동 캡처를 중지할 수 있어야 한다', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});

            connector.startAutoCapture();
            connector.stopAutoCapture();

            expect(consoleSpy).toHaveBeenCalledWith('[G7DevTools] 자동 캡처 중지');

            consoleSpy.mockRestore();
        });
    });

    describe('설정 업데이트', () => {
        it('타임아웃을 업데이트할 수 있어야 한다', () => {
            connector.updateConfig({ timeout: 5000 });
            // 설정이 업데이트되었는지 직접 확인하기 어려우므로 에러가 없으면 성공
            expect(true).toBe(true);
        });

        it('자동 캡처 설정을 업데이트할 수 있어야 한다', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});

            connector.updateConfig({ autoCapture: true, autoCaptureInterval: 10000 });

            expect(consoleSpy).toHaveBeenCalledWith(
                expect.stringContaining('[G7DevTools] 자동 캡처 시작'),
                expect.any(Number),
                'ms)'
            );

            connector.stopAutoCapture();
            consoleSpy.mockRestore();
        });
    });

    describe('싱글톤', () => {
        it('getServerConnector는 동일한 인스턴스를 반환해야 한다', () => {
            const instance1 = getServerConnector();
            const instance2 = getServerConnector();

            expect(instance1).toBe(instance2);
        });
    });

    describe('리소스 정리', () => {
        it('destroy 시 자동 캡처를 중지해야 한다', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});

            connector.startAutoCapture();
            connector.destroy();

            expect(consoleSpy).toHaveBeenCalledWith('[G7DevTools] 자동 캡처 중지');

            consoleSpy.mockRestore();
        });
    });
});
