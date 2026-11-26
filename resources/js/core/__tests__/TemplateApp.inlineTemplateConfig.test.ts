/**
 * TemplateApp 첫 화면 요청 사슬 — 템플릿 설정 인라인(`G7Config.templateConfig`)과 레이아웃 미리 요청
 *
 * 비로그인 첫 방문이 첫 글자까지 PHP 왕복을 줄줄이 기다리던 것 중 두 단계를 걷는다.
 *  - 템플릿 설정(config) API 1왕복: 서버가 template.json 의 `expose` 선언 키만 셸 HTML 에 싣고, 엔진은 부팅이 읽는 키
 *    (`TEMPLATE_BOOT_CONFIG_KEYS`)가 모두 실려 있으면(값이 null 이어도) API 를 부르지 않는다. 하나라도 없으면
 *    종전처럼 API 를 1회 부른다(선언 누락 시 값을 조용히 잃지 않게).
 *  - 레이아웃: routes 를 받은 직후(인증 확인과 함께) 현재 경로의 레이아웃 요청을 시작하고, 확장 번들 로드 뒤의
 *    첫 라우트 처리는 LayoutLoader 의 Promise 캐시로 같은 요청을 쓴다.
 *
 * 시나리오 매니페스트: tests/scenarios/engine-first-paint-request-chain.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { getErrorHandlingResolver } from '../error';
import { ErrorPageHandler } from '../template-engine/ErrorPageHandler';

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

vi.mock('../routing/Router', () => {
    /** 라우터 가짜 — 정적 매칭만 실물처럼 경로 일치로 흉내 낸다 */
    const Router: any = vi.fn(function (this: any) {
        this.loadRoutes = vi.fn().mockResolvedValue(undefined);
        this.setRoutes = vi.fn();
        this.on = vi.fn();
        this.navigateToCurrentPath = vi.fn();
        this.getRoutes = vi.fn().mockReturnValue([]);
    });
    Router.matchRoutes = vi.fn((routes: any[], pathname: string) => {
        const route = routes.find((r) => r.path === pathname);
        return route ? { route, params: {} } : null;
    });
    return { Router };
});

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

/** JSON 응답 */
function jsonResponse(body: unknown, status = 200): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        statusText: 'OK',
        headers: new Headers({ 'content-type': 'application/json' }),
        json: async () => body,
        clone() { return this; },
    } as unknown as Response;
}

/** 첫 방문 경로의 routes */
const ROUTES = [
    { path: '/shop/products', layout: 'shop/index' },
    { path: '/old', redirect: '/shop/products' },
    { path: '/mypage', layout: 'mypage/index', auth_required: true },
];

/** config API 가 돌려주는 값 */
const API_CONFIG = {
    scroll_restoration: { enabled: true, from: 'api' },
    errorHandling: { 500: { handler: 'toast', from: 'api' } },
    error_config: { layouts: { 404: 'errors/404', default: 'errors/500' } },
    cache_version: 7,
};

/**
 * fetch 스텁 — URL 종류별 정상 응답을 돌려주고 호출 URL 을 기록한다.
 *
 * @returns fetch 가짜
 */
function stubFetch(): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => {
        const u = String(url);
        if (u.includes('/routes')) return Promise.resolve(jsonResponse({ success: true, data: { routes: ROUTES } }));
        if (u.includes('/config')) return Promise.resolve(jsonResponse({ success: true, data: API_CONFIG }));
        if (u.includes('/api/layouts/')) {
            return Promise.resolve(jsonResponse({ success: true, data: { version: '1.0.0', layout_name: 'shop/index', components: [] } }));
        }
        return Promise.resolve(jsonResponse({}));
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

/**
 * 호출된 URL 중 needle 을 품은 것.
 *
 * @param fetchMock fetch 가짜
 * @param needle 찾을 문자열
 * @returns URL 목록
 */
function callsMatching(fetchMock: ReturnType<typeof vi.fn>, needle: string): string[] {
    return fetchMock.mock.calls.map((c: any[]) => String(c[0])).filter((u: string) => u.includes(needle));
}

/** 사용자 템플릿 앱 설정 */
function makeConfig(): TemplateAppConfig {
    return { templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false };
}

/** 셸이 실은 설정 — 서버가 expose 선언 키만 싣는다 */
function shell(templateConfig?: Record<string, unknown>): void {
    (window as any).G7Config = templateConfig === undefined ? { cache_version: 7 } : { cache_version: 7, templateConfig };
}

describe('TemplateApp 템플릿 설정 인라인', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="app"></div>';
        vi.clearAllMocks();
        localStorage.clear();
        window.history.replaceState(null, '', '/shop/products');
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        localStorage.clear();
        delete (window as any).G7Config;
        window.history.replaceState(null, '', '/');
    });

    // @scenario boot_case=inline_complete
    // @effects first_paint_skips_template_config_roundtrip
    it('(a) 부팅 키가 모두 실려 있으면(null 포함) config API 를 부르지 않고 실린 값을 쓴다', async () => {
        shell({ scroll_restoration: { enabled: false, from: 'inline' }, errorHandling: null, error_config: null });
        const fetchMock = stubFetch();
        const setTemplateConfig = vi.spyOn(getErrorHandlingResolver(), 'setTemplateConfig');

        const app = new TemplateApp(makeConfig());
        await app.init();

        expect(callsMatching(fetchMock, '/config')).toHaveLength(0);
        expect((app as any).templateScrollRestoration).toEqual({ enabled: false, from: 'inline' });
        // errorHandling 이 null(선언했고 값 없음)이면 등록하지 않는다
        expect(setTemplateConfig).not.toHaveBeenCalled();
    });

    // @scenario boot_case=inline_complete
    // @effects first_paint_skips_template_config_roundtrip
    it('(a) 실린 errorHandling 은 API 없이 등록된다', async () => {
        shell({ scroll_restoration: null, errorHandling: { 500: { handler: 'toast', from: 'inline' } }, error_config: null });
        const fetchMock = stubFetch();
        const setTemplateConfig = vi.spyOn(getErrorHandlingResolver(), 'setTemplateConfig');

        const app = new TemplateApp(makeConfig());
        await app.init();

        expect(callsMatching(fetchMock, '/config')).toHaveLength(0);
        expect((app as any).templateScrollRestoration).toBeNull();
        expect(setTemplateConfig).toHaveBeenCalledWith({ 500: { handler: 'toast', from: 'inline' } });
    });

    // @scenario boot_case=inline_partial
    // @effects template_config_fallback_preserves_values
    it('(b) 부팅 키 하나가 빠지면 config API 를 1회 불러 값을 보존한다(선언 누락)', async () => {
        shell({ errorHandling: null, error_config: null });
        const fetchMock = stubFetch();

        const app = new TemplateApp(makeConfig());
        await app.init();

        expect(callsMatching(fetchMock, '/config')).toHaveLength(1);
        expect((app as any).templateScrollRestoration).toEqual({ enabled: true, from: 'api' });
    });

    // @scenario boot_case=inline_absent
    // @effects template_config_fallback_preserves_values
    it('(c) templateConfig 가 없으면(옛 셸·expose 없는 템플릿) config API 를 1회 부른다', async () => {
        shell();
        const fetchMock = stubFetch();

        const app = new TemplateApp(makeConfig());
        await app.init();

        expect(callsMatching(fetchMock, '/config')).toHaveLength(1);
        expect((app as any).templateScrollRestoration).toEqual({ enabled: true, from: 'api' });
    });

    // @scenario boot_case=inline_complete
    // @effects first_paint_skips_template_config_roundtrip
    it('(d) 인라인이면 캐시 버전은 G7Config.cache_version 그대로이고 routes 재로드가 없다', async () => {
        shell({ scroll_restoration: null, errorHandling: null, error_config: null });
        localStorage.setItem('g7_cache_version', '3');
        const fetchMock = stubFetch();

        const app = new TemplateApp(makeConfig());
        await app.init();

        expect((app as any).extensionCacheVersion).toBe(7);
        expect(localStorage.getItem('g7_cache_version')).toBe('7');
        expect(callsMatching(fetchMock, '/routes')).toHaveLength(1);
    });

    // @scenario boot_case=error_config_inline
    // @effects first_paint_skips_template_config_roundtrip, template_config_fallback_preserves_values
    it('(e) 오류 화면 설정: error_config 키가 실려 있으면 API 없이 쓰고, 없으면 API 를 1회 부른다', async () => {
        const fetchMock = stubFetch();
        const make = () => new ErrorPageHandler({ templateId: 'sirsoft-basic', layoutLoader: {} as any, debug: false } as any);

        (window as any).G7Config = { cache_version: 7, templateConfig: { error_config: { layouts: { 404: 'errors/inline' } } } };
        expect(await make().loadConfig()).toEqual({ layouts: { 404: 'errors/inline' } });
        expect(callsMatching(fetchMock, '/config')).toHaveLength(0);

        // 선언했고 값 없음(null) — 오류 레이아웃 없음으로 보고 API 를 부르지 않는다
        (window as any).G7Config = { cache_version: 7, templateConfig: { error_config: null } };
        expect(await make().loadConfig()).toBeNull();
        expect(callsMatching(fetchMock, '/config')).toHaveLength(0);

        // 실리지 않았으면 종전처럼 API
        (window as any).G7Config = { cache_version: 7, templateConfig: { scroll_restoration: null } };
        expect(await make().loadConfig()).toEqual(API_CONFIG.error_config);
        expect(callsMatching(fetchMock, '/config')).toHaveLength(1);
    });
});

describe('TemplateApp 레이아웃 미리 요청', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="app"></div>';
        vi.clearAllMocks();
        localStorage.clear();
        shell({ scroll_restoration: null, errorHandling: null, error_config: null });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        localStorage.clear();
        delete (window as any).G7Config;
        delete (window as any).G7Error;
        window.history.replaceState(null, '', '/');
    });

    // @scenario boot_case=prefetch_public_route
    // @effects initial_layout_requested_before_extension_bundles
    it('(f) 비로그인 /shop/products — 레이아웃 요청이 확장 번들 로드가 끝나기 전에 시작되고, 첫 라우트 처리는 같은 요청을 쓴다', async () => {
        window.history.replaceState(null, '', '/shop/products');
        const fetchMock = stubFetch();
        let layoutCallsWhenBundlesStarted = -1;
        let releaseBundles!: () => void;
        vi.spyOn(TemplateApp.prototype as any, 'loadExtensionAssets').mockImplementation(() => {
            layoutCallsWhenBundlesStarted = callsMatching(fetchMock, '/api/layouts/').length;

            return new Promise<void>((resolve) => { releaseBundles = resolve; });
        });

        const app = new TemplateApp(makeConfig());
        const booting = app.init();
        await vi.waitFor(() => expect(layoutCallsWhenBundlesStarted).toBeGreaterThanOrEqual(0));

        // 번들 로드가 시작될 때 이미 레이아웃 요청이 나가 있다
        expect(layoutCallsWhenBundlesStarted).toBe(1);
        expect(callsMatching(fetchMock, '/api/layouts/')[0]).toContain('/api/layouts/sirsoft-basic/shop/index.json');

        releaseBundles();
        await booting;
        // 첫 라우트 처리(handleRouteChange)가 부르는 loadLayout 은 새 요청 없이 같은 결과를 쓴다
        const layout = await (app as any).layoutLoader.loadLayout('sirsoft-basic', 'shop/index');
        expect(layout.layout_name).toBe('shop/index');
        expect(callsMatching(fetchMock, '/api/layouts/')).toHaveLength(1);
    });

    // @scenario boot_case=prefetch_skipped
    // @effects layout_prefetch_skipped_when_unsafe
    it('(f) 리다이렉트·인증 필요·매칭 없음·서버 오류 셸 경로는 미리 요청하지 않는다', async () => {
        const fetchMock = stubFetch();
        for (const path of ['/old', '/mypage', '/nowhere']) {
            window.history.replaceState(null, '', path);
            await new TemplateApp(makeConfig()).init();
        }
        expect(callsMatching(fetchMock, '/api/layouts/')).toHaveLength(0);

        // 토큰은 있는데 인증 확인이 통과하지 못했으면(만료 토큰) 미리 요청하지 않는다 — 첫 레이아웃 요청이 401 → 토큰 제거 →
        // 토큰 없이 재시도하며 남기는 「토큰이 있었다」 표시(세션 만료 안내)가 본 요청에 그대로 남게 종전 경로에 맡긴다
        window.history.replaceState(null, '', '/shop/products');
        mockApiClient.getToken.mockReturnValue('expired-token');
        mockApiClient.get.mockRejectedValue(Object.assign(new Error('401'), { response: { status: 401 } }));
        await new TemplateApp(makeConfig()).init();
        expect(callsMatching(fetchMock, '/api/layouts/')).toHaveLength(0);
        mockApiClient.getToken.mockReturnValue(null);
        mockApiClient.get.mockResolvedValue({});

        // 셸이 캐시 버전을 주지 않으면(옛 셸 — 저장소 버전은 낡았을 수 있어 핸드셰이크가 바꿀 수 있다) 미리 요청하지 않는다
        window.history.replaceState(null, '', '/shop/products');
        (window as any).G7Config = {};
        localStorage.setItem('g7_cache_version', '3');
        await new TemplateApp(makeConfig()).init();
        expect(callsMatching(fetchMock, '/api/layouts/')).toHaveLength(0);
        localStorage.clear();
        shell({ scroll_restoration: null, errorHandling: null, error_config: null });

        (window as any).G7Error = { code: 503, layout: 'errors/503', data: [] };
        vi.spyOn(TemplateApp.prototype as any, 'handleServerError').mockResolvedValue(true);
        await new TemplateApp(makeConfig()).init();
        expect(callsMatching(fetchMock, '/api/layouts/sirsoft-basic/shop/index')).toHaveLength(0);
    });
});
