/**
 * TemplateApp 방문자 통계 가드 — 관리자 템플릿에서는 트래커를 만들지 않는다.
 *
 * 관리자 화면까지 세면 인기 페이지 1위가 관리자 대시보드가 되어 통계가 쓸모없어진다.
 * 이 가드는 「아무 일도 안 일어남」이라 깨져도 화면에 증상이 없다 — 전역 노출 여부로
 * 직접 단언한다.
 *
 * @scenario visitor=admin, source=beacon
 * @effects admin_template_does_not_track
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';

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

const { routerBus } = vi.hoisted(() => ({
    routerBus: { handlers: new Map<string, Array<(...args: any[]) => any>>() },
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
    releaseTemplateRoot: vi.fn(),
    updateTemplateData: vi.fn(),
    getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
    getState: vi.fn().mockReturnValue({
        actionDispatcher: sharedActionDispatcher,
        reactRoot: null,
        currentLayoutJson: null,
    }),
}));

// 실제 라우터는 첫 화면에서도 `navigateToCurrentPath()` 로 routeChange 를 낸다. 그 사실을
// 흉내 내지 않으면 「첫 로드에 비콘이 몇 건 나가는가」를 여기서 잴 수 없다.
vi.mock('../routing/Router', () => ({
    Router: vi.fn(function (this: any) {
        this.loadRoutes = vi.fn().mockResolvedValue(undefined);
        this.setRoutes = vi.fn();
        this.on = vi.fn((event: string, handler: (...args: any[]) => any) => {
            const list = routerBus.handlers.get(event) ?? [];
            list.push(handler);
            routerBus.handlers.set(event, list);
        });
        this.match = vi.fn((path: string) => ({ route: { path, layout: 'home', meta: {} }, params: {} }));
        this.navigateToCurrentPath = vi.fn(async () => {
            const path = window.location.pathname;
            await Promise.all((routerBus.handlers.get('routeChange') ?? []).map((handler) => handler({ path, layout: 'home', meta: {}, params: {}, query: {} })));
        });
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

/** 정상 JSON 응답 */
function ok(data: Record<string, unknown> = {}): Response {
    return {
        ok: true,
        status: 200,
        json: async () => ({ success: true, data }),
    } as unknown as Response;
}

/** 수집 비콘 요청 수 */
function collectCalls(): number {
    return (globalThis.fetch as any).mock.calls.filter((call: any[]) => String(call[0]).includes('/api/analytics/collect')).length;
}

/** 템플릿 종류별 부팅 설정 */
function makeConfig(templateType: 'user' | 'admin'): TemplateAppConfig {
    return {
        templateId: templateType === 'user' ? 'sirsoft-basic' : 'sirsoft-admin_basic',
        templateType,
        locale: 'ko',
        debug: false,
    };
}

describe('TemplateApp 방문자 통계 가드', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="app"></div>';
        vi.clearAllMocks();
        routerBus.handlers.clear();
        localStorage.clear();
        vi.stubGlobal('fetch', vi.fn().mockImplementation((url: string) => {
            const u = String(url);
            if (u.includes('/routes')) return Promise.resolve(ok({ routes: [] }));
            return Promise.resolve(ok());
        }));
        // jsdom 에는 keepalive 를 가진 Request 가 없다 — 없으면 트래커가 sendBeacon 으로 내려가
        // 요청이 fetch 에 잡히지 않는다. 브라우저와 같은 조건으로 맞춘다.
        vi.stubGlobal('Request', class {
            keepalive = false;
        });
        (window as any).G7Config = { settings: { analytics: { enabled: true, privacy_mode: 'full' } } };
        delete (window as any).G7Core;
    });

    afterEach(async () => {
        // 트래커는 전송을 다음 틱으로 미룬다 — 흘려 보내지 않으면 앞 테스트의 첫 비콘이
        // 다음 테스트의 fetch 에 잡혀 「첫 화면 비콘 수」를 잴 수 없다.
        await new Promise((resolve) => setTimeout(resolve, 0));

        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        localStorage.clear();
        delete (window as any).G7Config;
        delete (window as any).G7Core;
    });

    // @scenario visitor=admin
    // @effects admin_template_does_not_track
    it('관리자 템플릿은 수집이 켜져 있어도 트래커를 만들지 않는다', async () => {
        const app = new TemplateApp(makeConfig('admin'));
        await app.init();

        expect((window as any).G7Core?.analytics).toBeUndefined();
    });

    // @scenario visitor=guest, source=beacon
    // @effects tracker_sends_beacon_on_route_change
    it('사용자 템플릿은 트래커를 만들고 전역 API 를 연다', async () => {
        const app = new TemplateApp(makeConfig('user'));
        await app.init();

        const api = (window as any).G7Core?.analytics;

        expect(api).toBeDefined();
        expect(typeof api.trackGoal).toBe('function');
        expect(typeof api.isOptedOut).toBe('function');
    });

    // @scenario visitor=guest, source=beacon, visit_state=new
    // @effects first_screen_sends_exactly_one_pageview
    it('첫 화면은 비콘을 한 번만 보내고, 이후 이동은 그대로 센다', async () => {
        const app = new TemplateApp(makeConfig('user'));
        await app.init();
        await vi.waitFor(() => expect(collectCalls()).toBeGreaterThan(0));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(collectCalls()).toBe(1);

        const bodies = (globalThis.fetch as any).mock.calls
            .filter((call: any[]) => String(call[0]).includes('/api/analytics/collect'))
            .map((call: any[]) => JSON.parse(call[1].body));
        expect(bodies[0].init).toBe(true);

        const handlers = routerBus.handlers.get('routeChange') ?? [];
        await Promise.all(handlers.map((handler) => handler({ path: '/board/free', layout: 'board', meta: {}, params: {}, query: {} })));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(collectCalls()).toBe(2);
    });

    // @scenario visitor=guest, source=beacon, visit_state=open
    // @effects tracker_defers_sending_so_navigation_is_not_delayed
    it('통계의 routeChange 핸들러는 undefined 를 동기로 돌려준다 — 라우터가 기다릴 것이 없다', async () => {
        // 라우터는 routeChange 핸들러들을 Promise.all 로 기다린다. 누군가 이 화살표를 async 로
        // 바꾸면 화면 전환이 통계 처리를 기다리게 된다 — 반환값으로 직접 잠근다(#682 R6-D1).
        const admin = new TemplateApp(makeConfig('admin'));
        await admin.init();
        const withoutAnalytics = (routerBus.handlers.get('routeChange') ?? []).length;

        routerBus.handlers.clear();
        delete (window as any).G7Core;

        const app = new TemplateApp(makeConfig('user'));
        await app.init();
        await new Promise((resolve) => setTimeout(resolve, 0));

        const handlers = routerBus.handlers.get('routeChange') ?? [];
        // 사용자 템플릿에만 더 붙는 한 개가 통계 구독이다(등록 순서상 마지막).
        expect(handlers).toHaveLength(withoutAnalytics + 1);
        const analyticsHandler = handlers[handlers.length - 1];

        const before = collectCalls();
        const returned = analyticsHandler({ path: '/board/notice', layout: 'board', meta: {}, params: {}, query: {} });

        expect(returned).toBeUndefined();

        // 그 핸들러가 정말 통계 구독인지 — 이동을 한 건 센다.
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(collectCalls()).toBe(before + 1);
    });

    // @scenario visitor=guest, exclusion=disabled
    // @effects excluded_requests_record_nothing
    it('수집이 꺼져 있으면 사용자 템플릿도 트래커를 만들지 않는다', async () => {
        (window as any).G7Config = { settings: { analytics: { enabled: false } } };

        const app = new TemplateApp(makeConfig('user'));
        await app.init();

        expect((window as any).G7Core?.analytics).toBeUndefined();
    });
});
