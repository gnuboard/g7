// e2e:allow 확장용 경로 세대 공개 API 의 값 출처 잠금 — 화면 검증은 7.1.1 출시 전 캠페인 재실측(클레임 수정 주소 응답 지연 중 다른 메뉴 → 같은 주소 재진입)이 한다.
/**
 * @file G7CoreGlobals.routeGeneration.test.ts
 * @description `G7Core.navigation.getRouteGeneration()` — 확장 핸들러가 비동기 응답 뒤 「출처 화면」 을 알아보는 공개 API (7.1.1 확장 보완)
 *
 * 엔진은 늦은 showErrorPage 를 경로 세대(`TemplateApp.getRouteChangeId`)로 거른다. 확장 핸들러가 자기 API 를 기다린 뒤
 * 오류 화면·상태 쓰기를 할 때는 그 세대를 읽을 공개 수단이 없어, 「경로+쿼리」 표식만으로는 나갔다가 같은 주소로 돌아온 경우
 * (A → B → A)에 지난 응답을 새 화면에 썼다. 실 TemplateApp 의 라우트 변경 처리(handleRouteChange)를 밟아 값이 엔진 세대와 같고,
 * 같은 주소로 다시 들어와도 는다는 것을 잠근다.
 *
 * @scenario-manifest tests/scenarios/navigation-route-generation.yaml
 *
 * @effects route_generation_matches_engine_and_advances_on_reentry
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher } = vi.hoisted(() => ({
    sharedActionDispatcher: {
        setNavigate: vi.fn(),
        setGlobalState: vi.fn(),
        setDefaultContext: vi.fn(),
        setGlobalStateUpdater: vi.fn(),
        registerHandler: vi.fn(),
        customHandlers: new Map(),
    },
}));

vi.mock('../template-engine', () => ({
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn().mockResolvedValue(undefined),
    destroyTemplate: vi.fn(),
    releaseTemplateRoot: vi.fn(),
    updateTemplateData: vi.fn(),
    getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
    getState: vi.fn().mockReturnValue({ actionDispatcher: sharedActionDispatcher, reactRoot: null, currentLayoutJson: null }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
    transitionManager: {
        setPending: vi.fn(),
        getIsPending: vi.fn(() => false),
        subscribe: vi.fn(() => vi.fn()),
        clearSubscribers: vi.fn(),
    },
}));

vi.mock('../routing/Router', () => ({
    Router: vi.fn(function (this: any) {
        this.loadRoutes = vi.fn().mockResolvedValue(undefined);
        this.on = vi.fn();
        this.navigateToCurrentPath = vi.fn();
        this.getRoutes = vi.fn().mockReturnValue([]);
        this.setRoutes = vi.fn();
        this.match = vi.fn().mockReturnValue(null);
    }),
}));

vi.mock('../template-engine/LayoutLoader', () => ({
    LayoutLoader: vi.fn(function (this: any) {
        this.loadLayout = vi.fn().mockResolvedValue({ layout_name: 'test/layout', components: [] });
        this.setCacheVersion = vi.fn();
        this.clear = vi.fn();
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

    return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';
import { initializeG7CoreGlobals } from '../template-engine/G7CoreGlobals';
import type { G7CoreDependencies } from '../template-engine/G7CoreGlobals';

/** G7Core 전역을 세우는 최소 의존성 */
function makeDependencies(extra: Partial<G7CoreDependencies> = {}): G7CoreDependencies {
    return {
        getState: vi.fn(() => ({
            translationEngine: { translate: vi.fn((key: string) => key) } as any,
            translationContext: { templateId: 'sirsoft-basic', locale: 'ko' },
            bindingEngine: {} as any,
            actionDispatcher: {} as any,
            templateMetadata: { locales: ['ko'] },
        })),
        transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
        responsiveManager: {},
        webSocketManager: {} as any,
        ...extra,
    };
}

describe('[case:components-misc-extension-route-generation] [확장 보완] G7Core.navigation.getRouteGeneration — 실 TemplateApp 경로 전환', () => {
    let app: any;
    let routeChange: (route: any) => Promise<void>;

    beforeEach(async () => {
        // routes·config 등 부팅 요청은 성공으로 — routes 가 실패하면 init 이 Router 를 만들기 전에 끝난다
        vi.mocked(globalThis.fetch).mockResolvedValue({
            ok: true,
            status: 200,
            statusText: 'OK',
            headers: new Headers({ 'Content-Type': 'application/json' }),
            json: async () => ({ success: true, data: { routes: [] } }),
            text: async () => '{}',
        } as unknown as Response);
        delete (window as any).G7Core;
        document.body.innerHTML = '<div id="app"></div>';
        app = new TemplateApp({ templateId: 'test-template', templateType: 'admin', locale: 'ko', debug: false });
        (window as any).__templateApp = app;
        await app.init();
        initializeG7CoreGlobals(makeDependencies());
        const handler = vi.mocked(app.getRouter().on).mock.calls.find((call: any[]) => call[0] === 'routeChange')?.[1];
        routeChange = (route: any) => Promise.resolve(handler(route)).catch(() => undefined);
    });

    afterEach(() => {
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
        document.body.innerHTML = '';
    });

    const route = (path: string) => ({ path, params: {}, query: {}, layout: 'test/layout', layoutName: 'test/layout' });

    // @scenario source=template_app, flow=a_b_a
    // @effects route_generation_matches_engine_and_advances_on_reentry
    it('경로 전환마다 1 씩 늘고, 같은 주소로 다시 들어와도 늘며, 엔진 세대와 같은 값이다', async () => {
        const nav = (window as any).G7Core.navigation;
        // 종전: getRouteGeneration 이 없어 확장은 세대를 읽을 수 없었다(TypeError)
        expect(typeof nav.getRouteGeneration).toBe('function');

        await routeChange(route('/mypage/claims/9999/edit'));
        const atA = nav.getRouteGeneration();
        expect(atA).toBe(app.getRouteChangeId());

        await routeChange(route('/mypage/mileage'));
        const atB = nav.getRouteGeneration();
        expect(atB).toBe(atA + 1);

        await routeChange(route('/mypage/claims/9999/edit'));
        const backToA = nav.getRouteGeneration();
        // 주소는 A 와 같지만 세대는 다르다 — 첫 진입 때 잡아 둔 확장 핸들러가 지난 응답을 버릴 수 있다
        expect(backToA).toBe(atA + 2);
        expect(backToA).toBe(app.getRouteChangeId());
    });

    // @scenario source=template_app, flow=same_route
    // @effects route_generation_matches_engine_and_advances_on_reentry
    it('경로 전환이 없으면 값이 그대로다(같은 화면의 응답은 종전대로 쓴다)', async () => {
        await routeChange(route('/mypage/claims/9999/edit'));
        const nav = (window as any).G7Core.navigation;
        const captured = nav.getRouteGeneration();
        await new Promise((r) => setTimeout(r, 30));
        expect(nav.getRouteGeneration()).toBe(captured);
    });
});

describe('[확장 보완] G7Core.navigation.getRouteGeneration — 값 출처', () => {
    afterEach(() => {
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
    });

    // @scenario source=none, flow=same_route
    // @effects route_generation_matches_engine_and_advances_on_reentry
    it('TemplateApp 이 없으면 0 이다', () => {
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
        initializeG7CoreGlobals(makeDependencies());
        expect((window as any).G7Core.navigation.getRouteGeneration()).toBe(0);
    });

    // @scenario source=injected, flow=same_route
    // @effects route_generation_matches_engine_and_advances_on_reentry
    it('주입한 getRouteChangeId 가 있으면 그 값을 쓰고, 수가 아니면 0 이다', () => {
        delete (window as any).G7Core;
        let id: any = 7;
        initializeG7CoreGlobals(makeDependencies({ getRouteChangeId: () => id }));
        expect((window as any).G7Core.navigation.getRouteGeneration()).toBe(7);
        id = undefined;
        expect((window as any).G7Core.navigation.getRouteGeneration()).toBe(0);
    });
});
