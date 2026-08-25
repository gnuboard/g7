/**
 * TemplateApp 캐시 버전 시드 테스트 (#122 이중 로드 제거)
 *
 * blade 는 이미 `window.G7Config.cache_version` 을 주입하는데 TemplateApp 이
 * localStorage 만 읽어, stale localStorage 를 가진 재방문자의 첫 burst 가
 * 구버전 `?v` 로 나가고 config 핸드셰이크가 routes + lang(ko·en, `_=` 버스터)을
 * 통째로 다시 내려받던 결함의 회귀를 막는다 (~500KB 중복, 부트 ~1.3s 연장).
 *
 * 검증 대상:
 *  - A-1: 시드 우선순위 — blade 주입값 > localStorage
 *  - A-2: 핸드셰이크 가드 기준 — "이번 burst 가 실제 사용한 버전" 과 비교
 *  - 기존 시맨틱 보존: 주입 부재 시 localStorage 폴백, 렌더~부트 사이 bump 복구
 *
 * @effects no_duplicate_boot_requests, handshake_reload_preserved_on_version_mismatch
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { initTemplateEngine } from '../template-engine';
import { ComponentRegistry } from '../template-engine/ComponentRegistry';

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

/** routes.json 정상 응답 */
function routesOk(): Response {
    return {
        ok: true,
        status: 200,
        json: async () => ({ success: true, data: { routes: [] } }),
    } as unknown as Response;
}

/** config.json 정상 응답 (cache_version 포함) */
function configOk(cacheVersion?: number): Response {
    return {
        ok: true,
        status: 200,
        json: async () => ({
            success: true,
            data: cacheVersion !== undefined ? { cache_version: cacheVersion } : {},
        }),
    } as unknown as Response;
}

/** lang 응답 (사전 raw JSON) */
function langOk(): Response {
    return {
        ok: true,
        status: 200,
        json: async () => ({}),
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

/**
 * fetch 스텁 — URL 종류별 정상 응답을 돌려주고 호출 URL 을 기록한다.
 */
function stubFetch(configVersion?: number): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockImplementation((url: string) => {
        const u = String(url);
        if (u.includes('/routes')) return Promise.resolve(routesOk());
        if (u.includes('/config')) return Promise.resolve(configOk(configVersion));
        if (u.includes('/lang/')) return Promise.resolve(langOk());
        return Promise.resolve(configOk());
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

function callsMatching(fetchMock: ReturnType<typeof vi.fn>, needle: string): string[] {
    return fetchMock.mock.calls
        .map((c: any[]) => String(c[0]))
        .filter((u: string) => u.includes(needle));
}

describe('TemplateApp 캐시 버전 시드 (#122)', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="app"></div>';
        vi.clearAllMocks();
        localStorage.clear();
        (window as any).G7Config = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        localStorage.clear();
        delete (window as any).G7Config;
    });

    it('blade 주입값이 stale localStorage 보다 우선한다 — 첫 routes 요청이 주입 버전으로 나간다', async () => {
        (window as any).G7Config = { cache_version: 7 };
        localStorage.setItem('g7_cache_version', '3');
        const fetchMock = stubFetch(7);

        const app = new TemplateApp(makeConfig());
        await app.init();

        const routesCalls = callsMatching(fetchMock, '/routes');
        expect(routesCalls.length).toBeGreaterThanOrEqual(1);
        expect(routesCalls[0]).toContain('v=7');
        expect(routesCalls[0]).not.toContain('v=3');
    });

    it('주입 버전과 config 응답 버전이 일치하면 routes 재로드도 `_=` 버스터 재로드도 없다', async () => {
        (window as any).G7Config = { cache_version: 7 };
        localStorage.setItem('g7_cache_version', '3');
        const fetchMock = stubFetch(7);

        const app = new TemplateApp(makeConfig());
        await app.init();

        // 이중 로드 부재 — routes 는 정확히 1회
        expect(callsMatching(fetchMock, '/routes')).toHaveLength(1);

        // `_=` 캐시 버스터 재로드 0건 (lang ko·en 재다운로드 부재)
        const busterCalls = fetchMock.mock.calls
            .map((c: any[]) => String(c[0]))
            .filter((u: string) => /[?&]_=/.test(u));
        expect(busterCalls).toHaveLength(0);

        // localStorage 는 config 응답으로 치유된다
        expect(localStorage.getItem('g7_cache_version')).toBe('7');
    });

    it('렌더~부트 사이 bump(주입 7 → config 9)는 핸드셰이크로 여전히 복구된다 (기존 시맨틱 보존)', async () => {
        (window as any).G7Config = { cache_version: 7 };
        localStorage.setItem('g7_cache_version', '3');
        const fetchMock = stubFetch(9);

        const app = new TemplateApp(makeConfig());
        await app.init();

        // 첫 burst 는 v=7, 핸드셰이크 재로드는 v=9 — 정확히 2회
        const routesCalls = callsMatching(fetchMock, '/routes');
        expect(routesCalls).toHaveLength(2);
        expect(routesCalls[0]).toContain('v=7');
        expect(routesCalls[1]).toContain('v=9');

        expect(localStorage.getItem('g7_cache_version')).toBe('9');
    });

    it('blade 주입이 없으면 localStorage 로 폴백한다 (기존 시맨틱 보존)', async () => {
        (window as any).G7Config = {};
        localStorage.setItem('g7_cache_version', '5');
        const fetchMock = stubFetch(5);

        const app = new TemplateApp(makeConfig());
        await app.init();

        const routesCalls = callsMatching(fetchMock, '/routes');
        expect(routesCalls).toHaveLength(1);
        expect(routesCalls[0]).toContain('v=5');
    });

    it('최초 방문(주입 0/부재 + localStorage 없음)은 무버전 요청 + 재로드 없음 (기존 시맨틱 보존)', async () => {
        (window as any).G7Config = {};
        const fetchMock = stubFetch(7);

        const app = new TemplateApp(makeConfig());
        await app.init();

        // 무버전 첫 요청 1회, previousVersion=null 이므로 config 버전 수신 후에도 재로드 없음
        const routesCalls = callsMatching(fetchMock, '/routes');
        expect(routesCalls).toHaveLength(1);
        expect(routesCalls[0]).not.toContain('v=');

        // 수신한 버전은 저장된다
        expect(localStorage.getItem('g7_cache_version')).toBe('7');
    });

    it('initTemplateEngine 과 loadComponents 에 같은 시드 버전이 전달된다', async () => {
        (window as any).G7Config = { cache_version: 7 };
        localStorage.setItem('g7_cache_version', '3');
        stubFetch(7);

        const app = new TemplateApp(makeConfig());
        await app.init();

        expect(vi.mocked(initTemplateEngine)).toHaveBeenCalledWith(
            expect.objectContaining({ cacheVersion: 7 })
        );

        const registry = ComponentRegistry.getInstance() as any;
        expect(registry.loadComponents).toHaveBeenCalledWith('sirsoft-basic', 'user', 7);
    });
});
