/**
 * TemplateApp 네트워크 복원력 테스트 (#463)
 *
 * 페이지 로드 요청 1건의 일시 실패가 앱 전체를 죽이던 결함의 회귀를 막는다.
 *
 * 검증 대상:
 *  - routes.json / components.json 의 네트워크 실패 재시도 (HTTP 에러는 재시도 안 함)
 *  - 문서 이탈(pagehide) 중 실패 시 에러 화면 미렌더 + bfcache 복귀 시 가드 해제
 *  - 확장 번들 실패 확정 시 waitForHandlers 가 5초 블로킹하지 않음 (백지 제거)
 *  - 미등록 핸들러 오류가 사용자 대상 표시 계층으로 나가지 않음 (raw 영문 노출 제거)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { resetUnloadGuardForTesting } from '../template-engine/networkResilience';
import { ErrorDisplay } from '../template-engine/ErrorDisplay';

const mockApiClient = {
    post: vi.fn().mockResolvedValue({}),
    get: vi.fn().mockResolvedValue({}),
    removeToken: vi.fn(),
    setToken: vi.fn(),
    getToken: vi.fn().mockReturnValue(null),
    setOnUnauthorized: vi.fn(),
};

vi.mock('../api/ApiClient', () => ({
    getApiClient: () => mockApiClient,
}));

const { sharedActionDispatcher } = vi.hoisted(() => ({
    sharedActionDispatcher: {
        setNavigate: vi.fn(),
        setGlobalState: vi.fn(),
        setDefaultContext: vi.fn(),
        setGlobalStateUpdater: vi.fn(),
        registerHandler: vi.fn(),
        createHandler: vi.fn(() => vi.fn()),
        customHandlers: new Map<string, unknown>(),
    },
}));

vi.mock('../template-engine', () => ({
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn().mockResolvedValue(undefined),
    destroyTemplate: vi.fn(),
    updateTemplateData: vi.fn(),
    getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
    getState: vi.fn().mockReturnValue({
        actionDispatcher: sharedActionDispatcher,
        reactRoot: null,
        currentLayoutJson: null,
    }),
}));

vi.mock('../routing/Router', () => ({
    Router: vi.fn(function (this: any) {
        this.loadRoutes = vi.fn().mockResolvedValue(undefined);
        this.setRoutes = vi.fn();
        this.on = vi.fn();
        this.navigateToCurrentPath = vi.fn();
        this.getRoutes = vi.fn().mockReturnValue([]);
    }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
    const mockInstance = {
        loadComponents: vi.fn().mockResolvedValue(undefined),
        getComponent: vi.fn().mockReturnValue(() => null),
        hasComponent: vi.fn().mockReturnValue(true),
        getInstance: vi.fn(),
    };
    mockInstance.getInstance.mockReturnValue(mockInstance);
    return {
        ComponentRegistry: { getInstance: vi.fn(() => mockInstance) },
    };
});

/** fetch 스펙상 네트워크 오류 = TypeError (응답 자체가 없음) */
function networkError(): TypeError {
    return new TypeError('Failed to fetch');
}

/** routes.json 정상 응답 */
function routesOk(): Response {
    return {
        ok: true,
        status: 200,
        json: async () => ({ success: true, data: { routes: [] } }),
    } as unknown as Response;
}

/** config.json 정상 응답 (선택적 — null 폴백 경로) */
function configOk(): Response {
    return {
        ok: true,
        status: 200,
        json: async () => ({ success: true, data: {} }),
    } as unknown as Response;
}

function makeConfig(): TemplateAppConfig {
    return {
        templateId: 'sirsoft-basic',
        templateType: 'user',
        locale: 'ko',
        debug: false,
    };
}

describe('TemplateApp 네트워크 복원력 (#463)', () => {
    let errorSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        document.body.innerHTML = '<div id="app"></div>';
        resetUnloadGuardForTesting();
        vi.clearAllMocks();
        sharedActionDispatcher.customHandlers.clear();

        errorSpy = vi
            .spyOn(ErrorDisplay, 'renderFromError')
            .mockImplementation(() => undefined as any);

        (window as any).G7Config = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        resetUnloadGuardForTesting();
        delete (window as any).G7Config;
    });

    describe('초기화 fetch 재시도', () => {
        it('routes.json 이 1회 네트워크 실패 후 성공하면 에러 화면을 렌더하지 않는다', async () => {
            const fetchMock = vi.fn().mockImplementation((url: string) => {
                if (url.includes('routes.json')) {
                    // 첫 호출만 실패
                    const calls = fetchMock.mock.calls.filter((c: any[]) =>
                        String(c[0]).includes('routes.json')
                    ).length;
                    return calls === 1 ? Promise.reject(networkError()) : Promise.resolve(routesOk());
                }
                return Promise.resolve(configOk());
            });
            vi.stubGlobal('fetch', fetchMock);

            const app = new TemplateApp(makeConfig());
            await app.init();

            // 재시도로 복구 → 에러 화면 없음
            expect(errorSpy).not.toHaveBeenCalled();

            const routesCalls = fetchMock.mock.calls.filter((c: any[]) =>
                String(c[0]).includes('routes.json')
            );
            expect(routesCalls).toHaveLength(2);

            // 재시도로 받아온 routes 가 **실제로 라우터에 반영**돼야 한다.
            // "에러 화면이 안 떴다" 만으로는 복구를 증명하지 못한다 — 라우트가 비어도
            // 에러 화면은 안 뜬다. 복구의 본체는 데이터가 흘러 들어갔다는 사실이다.
            const routerInstance = (app as any).router;
            expect(routerInstance?.setRoutes).toHaveBeenCalled();
        });

        it('routes.json 이 3회 연속 네트워크 실패하면 에러 화면을 1회 렌더한다', async () => {
            const fetchMock = vi.fn().mockImplementation((url: string) => {
                if (url.includes('routes.json')) {
                    return Promise.reject(networkError());
                }
                return Promise.resolve(configOk());
            });
            vi.stubGlobal('fetch', fetchMock);

            const app = new TemplateApp(makeConfig());
            await app.init();

            // 재시도를 소진한 진짜 실패는 사용자에게 알려야 한다
            expect(errorSpy).toHaveBeenCalledTimes(1);

            // 상한(총 3시도)을 지킨다 — 무한루프 부재
            const routesCalls = fetchMock.mock.calls.filter((c: any[]) =>
                String(c[0]).includes('routes.json')
            );
            expect(routesCalls).toHaveLength(3);
        });

        it('components.json 이 1회 네트워크 실패 후 성공하면 앱이 생존한다', async () => {
            // ComponentRegistry 는 vi.mock 으로 대체돼 있으므로, 매니페스트 fetch 재시도는
            // ComponentRegistry 자체 경로에서 검증된다. 여기서는 초기화 전체가 살아남는지 본다.
            const fetchMock = vi.fn().mockImplementation((url: string) => {
                if (url.includes('components.json')) {
                    const calls = fetchMock.mock.calls.filter((c: any[]) =>
                        String(c[0]).includes('components.json')
                    ).length;
                    return calls === 1 ? Promise.reject(networkError()) : Promise.resolve(configOk());
                }
                if (url.includes('routes.json')) return Promise.resolve(routesOk());
                return Promise.resolve(configOk());
            });
            vi.stubGlobal('fetch', fetchMock);

            const app = new TemplateApp(makeConfig());
            await app.init();

            expect(errorSpy).not.toHaveBeenCalled();
        });

        it('routes.json 이 HTTP 500 이면 재시도 없이 즉시 에러 화면을 띄운다 (기존 동작 보존)', async () => {
            const fetchMock = vi.fn().mockImplementation((url: string) => {
                if (url.includes('routes.json')) {
                    return Promise.resolve({
                        ok: false,
                        status: 500,
                        statusText: 'Internal Server Error',
                    } as unknown as Response);
                }
                return Promise.resolve(configOk());
            });
            vi.stubGlobal('fetch', fetchMock);

            const app = new TemplateApp(makeConfig());
            await app.init();

            expect(errorSpy).toHaveBeenCalledTimes(1);

            // HTTP 응답은 재시도 대상이 아니다 (fetch 는 4xx/5xx 로 reject 하지 않는다)
            const routesCalls = fetchMock.mock.calls.filter((c: any[]) =>
                String(c[0]).includes('routes.json')
            );
            expect(routesCalls).toHaveLength(1);
        });
    });

    describe('문서 이탈 가드', () => {
        it('pagehide 이후 초기화가 실패하면 에러 화면을 렌더하지 않는다', async () => {
            const fetchMock = vi.fn().mockRejectedValue(networkError());
            vi.stubGlobal('fetch', fetchMock);

            const app = new TemplateApp(makeConfig());

            // init 이 unload guard 를 설치하므로, 설치 후 이탈을 발생시킨다
            const initPromise = app.init();
            window.dispatchEvent(new Event('pagehide'));
            await initPromise;

            // 버려지는 문서에 "초기화 실패" 를 그리지 않는다
            expect(errorSpy).not.toHaveBeenCalled();
        });

        it('bfcache 복귀(pageshow persisted) 후 초기화가 실패하면 에러 화면을 렌더한다 (가드 해제)', async () => {
            const fetchMock = vi.fn().mockRejectedValue(networkError());
            vi.stubGlobal('fetch', fetchMock);

            const app = new TemplateApp(makeConfig());

            const initPromise = app.init();
            window.dispatchEvent(new Event('pagehide'));

            const pageshow = new Event('pageshow') as PageTransitionEvent;
            Object.defineProperty(pageshow, 'persisted', { value: true });
            window.dispatchEvent(pageshow);

            await initPromise;

            // 복귀한 문서는 살아있다 → 실패는 사용자에게 알려야 한다
            expect(errorSpy).toHaveBeenCalledTimes(1);
        });
    });

    describe('확장 번들 실패 시 5초 백지 제거 (waitForHandlers)', () => {
        it('번들 로드가 실패로 확정되면 maxWait 를 기다리지 않고 즉시 반환한다', async () => {
            const { getModuleAssetLoader } = await import('../modules');
            const loader = getModuleAssetLoader();

            // 번들 로드 실패를 확정된 사실로 만든다
            (loader as any).failedJsAssets = new Set(['module']);
            expect(loader.hasFailedJsAssets()).toBe(true);

            const app = new TemplateApp(makeConfig());

            // 해당 확장 소유 핸들러는 영원히 등록되지 않는다 (customHandlers 비어 있음)
            const started = Date.now();
            await (app as any).waitForHandlers(
                sharedActionDispatcher,
                ['sirsoft-ecommerce.initPreferredCurrency'],
                5000
            );
            const elapsed = Date.now() - started;

            // 5초 폴링 없이 즉시 반환 (백지 제거)
            expect(elapsed).toBeLessThan(1000);

            (loader as any).failedJsAssets = new Set();
        });

        it('번들이 정상이면 종전대로 핸들러 등록을 기다린다 (기존 동작 보존)', async () => {
            const { getModuleAssetLoader } = await import('../modules');
            const loader = getModuleAssetLoader();
            (loader as any).failedJsAssets = new Set();

            const app = new TemplateApp(makeConfig());

            // 이미 등록된 핸들러 → 즉시 반환
            sharedActionDispatcher.customHandlers.set('sirsoft-ecommerce.initPreferredCurrency', vi.fn());

            await expect(
                (app as any).waitForHandlers(
                    sharedActionDispatcher,
                    ['sirsoft-ecommerce.initPreferredCurrency'],
                    5000
                )
            ).resolves.toBeUndefined();
        });

        /**
         * 기존 동작 보존 가드 — `executeInitActions` 루프는 액션별 try/catch 로 이미
         * throw 를 삼키므로 미등록 핸들러가 있어도 **렌더는 진행된다**. 이번 수정이
         * 그 동작을 깨뜨리지 않았는지 잠근다("ActionError 가 렌더를 차단한다" 는 오해).
         */
        it('미등록 핸들러가 있어도 init_actions 루프가 삼키고 초기화가 에러 화면 없이 완주한다 (기존 동작 보존)', async () => {
            const { getModuleAssetLoader } = await import('../modules');
            const loader = getModuleAssetLoader();
            (loader as any).failedJsAssets = new Set(['module']);

            // 미등록 핸들러를 호출하면 throw 하는 dispatcher
            sharedActionDispatcher.customHandlers.clear();
            sharedActionDispatcher.createHandler.mockReturnValue(
                vi.fn(async () => {
                    throw new Error(
                        'Unknown action handler: sirsoft-ecommerce.initPreferredCurrency'
                    );
                })
            );

            const app = new TemplateApp(makeConfig());

            // 루프가 throw 를 삼켜 executeInitActions 자체는 reject 하지 않아야 한다
            await expect(
                (app as any).executeInitActions(
                    [{ handler: 'sirsoft-ecommerce.initPreferredCurrency' }],
                    {}
                )
            ).resolves.toBeUndefined();

            // 그리고 **초기화가 에러 화면 없이 완주해야 한다** — 이것이 계획서가 지정한
            // "기존 동작 보존" 의 본체다. "executeInitActions 가 reject 하지 않는다" 만으로는
            // 앱이 살아남았음을 보이지 못한다(그 뒤 init 체인이 죽어도 통과한다).
            // 확장 부재는 **조용한 기능 열화**로 끝나야 하고 전면 에러 화면이 되면 안 된다.
            //
            // (renderTemplate 자체를 단언하지는 않는다 — 그 호출은 라우터 매칭 → 레이아웃
            //  로드를 거치는 별개 경로라, 여기서 모킹으로 재현하면 검증 대상이 아니라
            //  모킹을 시험하는 테스트가 된다. 렌더까지의 실제 도달은 E2E '모듈 번들 상시
            //  부재 → 5초 블로킹 없이 렌더' 가 실브라우저에서 잠근다.)
            vi.stubGlobal(
                'fetch',
                vi.fn((url: string) =>
                    Promise.resolve(String(url).includes('routes.json') ? routesOk() : configOk())
                )
            );

            await app.init();

            expect(errorSpy).not.toHaveBeenCalled();

            (loader as any).failedJsAssets = new Set();
        });
    });
});
