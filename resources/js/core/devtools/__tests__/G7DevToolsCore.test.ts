/**
 * G7DevToolsCore 테스트
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { G7DevToolsCore } from '../G7DevToolsCore';

describe('G7DevToolsCore', () => {
    let devTools: G7DevToolsCore;
    let originalWindow: any;

    beforeEach(() => {
        // 싱글톤 인스턴스 초기화를 위해 private 프로퍼티에 접근
        (G7DevToolsCore as any).instance = undefined;

        // 기존 window 저장
        originalWindow = global.window;

        // window.G7Core 모킹 (addEventListener 포함)
        (global as any).window = {
            G7Core: {
                state: {
                    get: () => ({
                        settings: {
                            advanced: {
                                debug_mode: true,
                            },
                        },
                    }),
                },
            },
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        };

        devTools = G7DevToolsCore.getInstance();
        devTools.initialize();
    });

    afterEach(() => {
        vi.clearAllMocks();
        (G7DevToolsCore as any).instance = undefined;
        global.window = originalWindow;
    });

    describe('싱글톤 패턴', () => {
        it('동일한 인스턴스를 반환해야 한다', () => {
            const instance1 = G7DevToolsCore.getInstance();
            const instance2 = G7DevToolsCore.getInstance();
            expect(instance1).toBe(instance2);
        });
    });

    describe('활성화 상태', () => {
        it('디버그 모드가 활성화되면 true를 반환해야 한다', () => {
            expect(devTools.isEnabled()).toBe(true);
        });

        it('디버그 모드가 비활성화되면 false를 반환해야 한다', () => {
            (global as any).window.G7Core.state.get = () => ({
                settings: { advanced: { debug_mode: false } },
            });
            (G7DevToolsCore as any).instance = undefined;
            const newDevTools = G7DevToolsCore.getInstance();
            newDevTools.initialize();
            expect(newDevTools.isEnabled()).toBe(false);
        });
    });

    describe('상태 관리', () => {
        it('초기 상태는 비어 있어야 한다', () => {
            const state = devTools.getState();
            expect(state).toHaveProperty('_global');
            expect(state).toHaveProperty('_local');
            expect(state).toHaveProperty('_computed');
        });

        it('상태 스냅샷을 캡처해야 한다', () => {
            devTools.captureStateSnapshot({
                source: 'test',
                prev: { test: 1 },
                next: { test: 2 },
            });

            const history = devTools.getStateHistory();
            expect(history.length).toBe(1);
            expect(history[0].source).toBe('test');
        });

        it('최대 이력 크기를 준수해야 한다', () => {
            // setMaxHistory는 최솟값 10을 보장함
            devTools.setMaxHistory(15);

            for (let i = 0; i < 20; i++) {
                devTools.captureStateSnapshot({
                    source: `test${i}`,
                    prev: {},
                    next: {},
                });
            }

            const history = devTools.getStateHistory();
            expect(history.length).toBe(15);
        });

        it('상태 변경을 구독할 수 있어야 한다', () => {
            const callback = vi.fn();
            const unsubscribe = devTools.watchState('*', callback);

            devTools.captureStateSnapshot({
                source: 'test',
                prev: {},
                next: { test: true },
            });

            expect(callback).toHaveBeenCalled();

            unsubscribe();
            callback.mockClear();

            devTools.captureStateSnapshot({
                source: 'test2',
                prev: {},
                next: {},
            });

            expect(callback).not.toHaveBeenCalled();
        });
    });

    describe('액션 로깅', () => {
        it('액션을 로깅해야 한다', () => {
            devTools.logAction({
                id: 'test-action-1',
                type: 'setState',
                params: { key: 'value' },
                startTime: Date.now(),
                status: 'success',
            });

            const history = devTools.getActionHistory();
            expect(history.length).toBe(1);
            expect(history[0].type).toBe('setState');
        });

        it('액션 실행을 구독할 수 있어야 한다', () => {
            const callback = vi.fn();
            const unsubscribe = devTools.watchActions(callback);

            devTools.logAction({
                id: 'test-action-2',
                type: 'apiCall',
                params: {},
                startTime: Date.now(),
                status: 'started',
            });

            expect(callback).toHaveBeenCalled();

            unsubscribe();
        });

        it('액션 메트릭을 계산해야 한다', () => {
            devTools.logAction({
                id: 'action-1',
                type: 'setState',
                params: {},
                startTime: Date.now(),
                status: 'success',
                duration: 10,
            });

            devTools.logAction({
                id: 'action-2',
                type: 'apiCall',
                params: {},
                startTime: Date.now(),
                status: 'error',
                duration: 50,
            });

            const metrics = devTools.getActionMetrics();
            expect(metrics.totalActions).toBe(2);
            expect(metrics.successCount).toBe(1);
            expect(metrics.errorCount).toBe(1);
        });
    });

    describe('캐시 통계', () => {
        it('캐시 히트를 기록해야 한다', () => {
            devTools.recordCacheHit();
            devTools.recordCacheHit();

            const stats = devTools.getCacheStats();
            expect(stats.hits).toBe(2);
        });

        it('캐시 미스를 기록해야 한다', () => {
            devTools.recordCacheMiss();

            const stats = devTools.getCacheStats();
            expect(stats.misses).toBe(1);
        });

        it('히트율을 계산해야 한다', () => {
            devTools.recordCacheHit();
            devTools.recordCacheHit();
            devTools.recordCacheHit();
            devTools.recordCacheMiss();

            const stats = devTools.getCacheStats();
            // hitRate는 소수점으로 반환 (0.75 = 75%)
            expect(stats.hitRate).toBe(0.75);
        });

        it('캐시 통계를 초기화할 수 있어야 한다', () => {
            devTools.recordCacheHit();
            devTools.recordCacheMiss();
            devTools.resetCacheStats();

            const stats = devTools.getCacheStats();
            expect(stats.hits).toBe(0);
            expect(stats.misses).toBe(0);
        });
    });

    describe('라이프사이클 추적', () => {
        it('컴포넌트 마운트를 추적해야 한다', () => {
            devTools.trackMount('comp-1', { name: 'TestComponent' });

            const info = devTools.getLifecycleInfo();
            expect(info.mountedComponents.length).toBe(1);
            expect(info.mountedComponents[0].name).toBe('TestComponent');
        });

        it('컴포넌트 언마운트를 추적해야 한다', () => {
            devTools.trackMount('comp-1', { name: 'TestComponent' });
            devTools.trackUnmount('comp-1');

            const info = devTools.getLifecycleInfo();
            expect(info.mountedComponents.length).toBe(0);
        });

        it('이벤트 리스너를 추적해야 한다', () => {
            devTools.trackMount('comp-1', { name: 'TestComponent' });
            devTools.trackListener('comp-1', 'click', 'button');

            devTools.trackUnmount('comp-1');

            const info = devTools.getLifecycleInfo();
            expect(info.orphanedListeners.length).toBe(1);
        });
    });

    describe('성능 추적', () => {
        it('렌더링 횟수를 추적해야 한다', () => {
            devTools.trackRender('TestComponent');
            devTools.trackRender('TestComponent');
            devTools.trackRender('OtherComponent');

            const info = devTools.getPerformanceInfo();
            expect(info.renderCounts.get('TestComponent')).toBe(2);
            expect(info.renderCounts.get('OtherComponent')).toBe(1);
        });

        it('바인딩 평가 횟수를 추적해야 한다', () => {
            devTools.trackBindingEval();
            devTools.trackBindingEval();
            devTools.trackBindingEval();

            const info = devTools.getPerformanceInfo();
            expect(info.bindingEvalCount).toBe(3);
        });

        it('프로파일링을 시작하고 중지할 수 있어야 한다', () => {
            devTools.startProfiling();
            devTools.trackRender('TestComponent');
            const report = devTools.stopProfiling();

            expect(report).toBeDefined();
        });
    });

    describe('네트워크 추적', () => {
        it('요청을 추적해야 한다', () => {
            // trackRequest(url, method) - requestId를 반환
            const requestId = devTools.trackRequest('/api/test', 'GET');

            const info = devTools.getNetworkInfo();
            expect(info.activeRequests.length).toBe(1);
            expect(requestId).toBeTruthy();
        });

        it('요청 완료를 기록해야 한다', () => {
            // trackRequest가 requestId를 반환
            const requestId = devTools.trackRequest('/api/test', 'GET');
            // completeRequest(requestId, statusCode, response?)
            devTools.completeRequest(requestId, 200, { data: 'test' });

            const info = devTools.getNetworkInfo();
            expect(info.activeRequests.length).toBe(0);
            expect(info.requestHistory.length).toBe(1);
        });

        it('데이터소스를 추적해야 한다', () => {
            devTools.trackDataSource('users');

            const info = devTools.getNetworkInfo();
            expect(info.pendingDataSources.includes('users')).toBe(true);
        });
    });

    describe('설정', () => {
        it('로그 레벨을 설정할 수 있어야 한다', () => {
            expect(() => devTools.setLogLevel('warn')).not.toThrow();
        });

        it('최대 이력 크기를 설정할 수 있어야 한다', () => {
            devTools.setMaxHistory(50);
            // 내부 값 변경 확인을 위해 많은 스냅샷 추가
            for (let i = 0; i < 100; i++) {
                devTools.captureStateSnapshot({
                    source: `test${i}`,
                    prev: {},
                    next: {},
                });
            }
            expect(devTools.getStateHistory().length).toBe(50);
        });
    });

    describe('로그 추적', () => {
        it('로그를 추적해야 한다', () => {
            devTools.trackLog('info', 'TestPrefix', ['테스트 메시지', { data: 123 }]);

            const logs = devTools.getLogs();
            expect(logs.length).toBe(1);
            expect(logs[0].level).toBe('info');
            expect(logs[0].prefix).toBe('TestPrefix');
            // message는 모든 args를 join한 결과
            expect(logs[0].message).toBe('테스트 메시지 {"data":123}');
            // args는 원본 args (첫 번째 문자열 제외한 나머지)
            expect(logs[0].args).toEqual(['테스트 메시지', { data: 123 }]);
        });

        it('여러 레벨의 로그를 추적해야 한다', () => {
            devTools.trackLog('log', 'App', ['일반 로그']);
            devTools.trackLog('warn', 'App', ['경고 메시지']);
            devTools.trackLog('error', 'App', ['에러 메시지']);
            devTools.trackLog('debug', 'App', ['디버그 메시지']);

            const logs = devTools.getLogs();
            expect(logs.length).toBe(4);

            const levels = logs.map(l => l.level);
            expect(levels).toContain('log');
            expect(levels).toContain('warn');
            expect(levels).toContain('error');
            expect(levels).toContain('debug');
        });

        it('로그 필터링이 작동해야 한다 - 레벨', () => {
            devTools.trackLog('info', 'App', ['정보']);
            devTools.trackLog('error', 'App', ['에러']);
            devTools.trackLog('warn', 'App', ['경고']);

            const errorLogs = devTools.getLogs({ level: 'error' });
            expect(errorLogs.length).toBe(1);
            expect(errorLogs[0].level).toBe('error');
        });

        it('로그 필터링이 작동해야 한다 - prefix', () => {
            devTools.trackLog('info', 'DataBindingEngine', ['바인딩 처리']);
            devTools.trackLog('info', 'ActionDispatcher', ['액션 실행']);
            devTools.trackLog('warn', 'DataBindingEngine', ['바인딩 경고']);

            const bindingLogs = devTools.getLogs({ prefix: 'DataBindingEngine' });
            expect(bindingLogs.length).toBe(2);
            bindingLogs.forEach(log => {
                expect(log.prefix).toBe('DataBindingEngine');
            });
        });

        it('로그 필터링이 작동해야 한다 - 검색어', () => {
            devTools.trackLog('info', 'App', ['사용자 로그인 성공']);
            devTools.trackLog('info', 'App', ['상품 목록 조회']);
            devTools.trackLog('error', 'App', ['사용자 인증 실패']);

            const userLogs = devTools.getLogs({ search: '사용자' });
            expect(userLogs.length).toBe(2);
        });

        it('로그 통계를 계산해야 한다', () => {
            devTools.trackLog('info', 'App', ['정보1']);
            devTools.trackLog('info', 'Service', ['정보2']);
            devTools.trackLog('error', 'App', ['에러1']);
            devTools.trackLog('error', 'App', ['에러2']);
            devTools.trackLog('warn', 'Service', ['경고1']);

            const stats = devTools.getLogStats();
            expect(stats.totalLogs).toBe(5);
            expect(stats.byLevel.info).toBe(2);
            expect(stats.byLevel.error).toBe(2);
            expect(stats.byLevel.warn).toBe(1);
            expect(stats.byPrefix['App']).toBe(3);
            expect(stats.byPrefix['Service']).toBe(2);
        });

        it('로그를 초기화할 수 있어야 한다', () => {
            devTools.trackLog('info', 'App', ['테스트']);
            devTools.trackLog('error', 'App', ['에러']);

            expect(devTools.getLogs().length).toBe(2);

            devTools.clearLogs();

            expect(devTools.getLogs().length).toBe(0);
        });

        it('최대 로그 이력 크기를 준수해야 한다', () => {
            // 내부적으로 maxLogHistory는 500이지만, 테스트를 위해 많은 로그 추가
            for (let i = 0; i < 600; i++) {
                devTools.trackLog('info', 'App', [`메시지 ${i}`]);
            }

            const logs = devTools.getLogs();
            // 최대 500개 유지
            expect(logs.length).toBeLessThanOrEqual(500);
        });

        it('getLogInfo가 로그와 통계를 함께 반환해야 한다', () => {
            devTools.trackLog('info', 'App', ['정보']);
            devTools.trackLog('error', 'Service', ['에러']);

            const logInfo = devTools.getLogInfo();
            expect(logInfo).toHaveProperty('entries');
            expect(logInfo).toHaveProperty('stats');
            expect(logInfo.entries.length).toBe(2);
            expect(logInfo.stats.totalLogs).toBe(2);
        });

        it('error 로그에 스택 트레이스가 포함되어야 한다', () => {
            devTools.trackLog('error', 'App', ['에러 발생']);

            const logs = devTools.getLogs();
            expect(logs[0].stack).toBeDefined();
            expect(typeof logs[0].stack).toBe('string');
        });

        it('limit 필터가 작동해야 한다', () => {
            for (let i = 0; i < 10; i++) {
                devTools.trackLog('info', 'App', [`메시지 ${i}`]);
            }

            const limitedLogs = devTools.getLogs({ limit: 5 });
            expect(limitedLogs.length).toBe(5);
        });
    });

    describe('Named Actions 추적', () => {
        it('named_actions 정의를 등록해야 한다', () => {
            devTools.setNamedActionDefinitions({
                searchProducts: { handler: 'navigate', params: { path: '/products' } },
                resetFilters: { handler: 'setState', params: { target: 'local', key: 'filters', value: {} } },
            });

            const info = devTools.getNamedActionTrackingInfo();
            expect(info.stats.totalDefinitions).toBe(2);
            expect(info.definitions['searchProducts']).toBeDefined();
            expect(info.definitions['resetFilters']).toBeDefined();
        });

        it('actionRef 해석 이력을 기록해야 한다', () => {
            devTools.setNamedActionDefinitions({
                searchProducts: { handler: 'navigate', params: { path: '/products' } },
            });

            devTools.trackNamedActionRef({
                actionRefName: 'searchProducts',
                resolvedHandler: 'navigate',
                timestamp: Date.now(),
            });

            const info = devTools.getNamedActionTrackingInfo();
            expect(info.refLogs.length).toBe(1);
            expect(info.refLogs[0].actionRefName).toBe('searchProducts');
            expect(info.refLogs[0].resolvedHandler).toBe('navigate');
            expect(info.refLogs[0].id).toBeDefined();
        });

        it('참조 횟수 통계를 계산해야 한다', () => {
            devTools.setNamedActionDefinitions({
                searchProducts: { handler: 'navigate', params: {} },
                resetFilters: { handler: 'setState', params: {} },
            });

            devTools.trackNamedActionRef({
                actionRefName: 'searchProducts',
                resolvedHandler: 'navigate',
                timestamp: Date.now(),
            });
            devTools.trackNamedActionRef({
                actionRefName: 'searchProducts',
                resolvedHandler: 'navigate',
                timestamp: Date.now(),
            });
            devTools.trackNamedActionRef({
                actionRefName: 'resetFilters',
                resolvedHandler: 'setState',
                timestamp: Date.now(),
            });

            const info = devTools.getNamedActionTrackingInfo();
            expect(info.stats.totalRefs).toBe(3);
            expect(info.stats.refCountByName['searchProducts']).toBe(2);
            expect(info.stats.refCountByName['resetFilters']).toBe(1);
        });

        it('미사용 정의를 감지해야 한다', () => {
            devTools.setNamedActionDefinitions({
                searchProducts: { handler: 'navigate', params: {} },
                unusedAction: { handler: 'setState', params: {} },
            });

            devTools.trackNamedActionRef({
                actionRefName: 'searchProducts',
                resolvedHandler: 'navigate',
                timestamp: Date.now(),
            });

            const info = devTools.getNamedActionTrackingInfo();
            expect(info.stats.unusedDefinitions).toContain('unusedAction');
            expect(info.stats.unusedDefinitions).not.toContain('searchProducts');
        });

        it('최대 이력 크기를 준수해야 한다', () => {
            devTools.setNamedActionDefinitions({
                testAction: { handler: 'navigate', params: {} },
            });

            // 최대 200개 제한 테스트
            for (let i = 0; i < 250; i++) {
                devTools.trackNamedActionRef({
                    actionRefName: 'testAction',
                    resolvedHandler: 'navigate',
                    timestamp: Date.now(),
                });
            }

            const info = devTools.getNamedActionTrackingInfo();
            expect(info.refLogs.length).toBeLessThanOrEqual(200);
        });

        it('추적 상태를 초기화할 수 있어야 한다', () => {
            devTools.setNamedActionDefinitions({
                searchProducts: { handler: 'navigate', params: {} },
            });
            devTools.trackNamedActionRef({
                actionRefName: 'searchProducts',
                resolvedHandler: 'navigate',
                timestamp: Date.now(),
            });

            devTools.clearNamedActionTracking();

            const info = devTools.getNamedActionTrackingInfo();
            expect(info.stats.totalDefinitions).toBe(0);
            expect(info.refLogs.length).toBe(0);
        });
    });

    describe('모달 정의 누락 감지 (missing-definition)', () => {
        it('레이아웃 modals에 없는 모달 열기 시 missing-definition 이슈를 기록해야 한다', () => {
            // 레이아웃에 modal_a만 정의
            devTools.trackLayoutLoad(
                'admin/test_page',
                'sirsoft-admin_basic',
                {
                    layout_name: 'test_page',
                    components: [],
                    modals: [
                        { id: 'modal_a', type: 'composite', name: 'Modal', children: [] },
                    ],
                },
                'api'
            );

            // modal_b를 열기 시도 (레이아웃에 없음)
            devTools.trackModalOpen({
                modalId: 'modal_b',
                modalName: 'modal_b',
            });

            const trackingInfo = devTools.getModalStateScopeTrackingInfo();
            const missingIssues = trackingInfo.issues.filter(i => i.type === 'missing-definition');
            expect(missingIssues).toHaveLength(1);
            expect(missingIssues[0].modalId).toBe('modal_b');
            expect(missingIssues[0].severity).toBe('error');
            expect(missingIssues[0].description).toContain('modal_b');
            expect(missingIssues[0].description).toContain('modals 섹션');
        });

        it('레이아웃 modals에 정의된 모달 열기 시 missing-definition 이슈가 없어야 한다', () => {
            devTools.trackLayoutLoad(
                'admin/test_page',
                'sirsoft-admin_basic',
                {
                    layout_name: 'test_page',
                    components: [],
                    modals: [
                        { id: 'modal_confirm', type: 'composite', name: 'Modal', children: [] },
                    ],
                },
                'api'
            );

            devTools.trackModalOpen({
                modalId: 'modal_confirm',
                modalName: 'modal_confirm',
            });

            const trackingInfo = devTools.getModalStateScopeTrackingInfo();
            const missingIssues = trackingInfo.issues.filter(i => i.type === 'missing-definition');
            expect(missingIssues).toHaveLength(0);
        });

        it('레이아웃에 modals 섹션 자체가 없으면 missing-definition 이슈를 기록해야 한다', () => {
            devTools.trackLayoutLoad(
                'admin/test_page',
                'sirsoft-admin_basic',
                {
                    layout_name: 'test_page',
                    components: [],
                },
                'api'
            );

            devTools.trackModalOpen({
                modalId: 'modal_x',
                modalName: 'modal_x',
            });

            const trackingInfo = devTools.getModalStateScopeTrackingInfo();
            const missingIssues = trackingInfo.issues.filter(i => i.type === 'missing-definition');
            expect(missingIssues).toHaveLength(1);
            expect(missingIssues[0].description).toContain('modals 섹션이 없습니다');
        });

        it('stats에서 missing-definition 카운트가 반영되어야 한다', () => {
            devTools.trackLayoutLoad(
                'admin/test_page',
                'sirsoft-admin_basic',
                { layout_name: 'test_page', components: [], modals: [] },
                'api'
            );

            devTools.trackModalOpen({ modalId: 'modal_missing', modalName: 'modal_missing' });

            const trackingInfo = devTools.getModalStateScopeTrackingInfo();
            expect(trackingInfo.stats.issuesByType['missing-definition']).toBe(1);
            expect(trackingInfo.stats.issuesBySeverity.error).toBeGreaterThanOrEqual(1);
        });
    });
});
