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
        this.match = vi.fn().mockReturnValue(null);
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

/** 정상 JSON 응답 */
function ok(data: Record<string, unknown> = {}): Response {
    return {
        ok: true,
        status: 200,
        json: async () => ({ success: true, data }),
    } as unknown as Response;
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
        localStorage.clear();
        vi.stubGlobal('fetch', vi.fn().mockImplementation((url: string) => {
            const u = String(url);
            if (u.includes('/routes')) return Promise.resolve(ok({ routes: [] }));
            return Promise.resolve(ok());
        }));
        (window as any).G7Config = { settings: { analytics: { enabled: true, privacy_mode: 'full' } } };
        delete (window as any).G7Core;
    });

    afterEach(() => {
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

    // @scenario visitor=guest, exclusion=disabled
    // @effects excluded_requests_record_nothing
    it('수집이 꺼져 있으면 사용자 템플릿도 트래커를 만들지 않는다', async () => {
        (window as any).G7Config = { settings: { analytics: { enabled: false } } };

        const app = new TemplateApp(makeConfig('user'));
        await app.init();

        expect((window as any).G7Core?.analytics).toBeUndefined();
    });
});
