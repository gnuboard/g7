// e2e:allow 실제 엔진 구조(TemplateApp·renderTemplate·DynamicRenderer·DataSourceManager·G7CoreGlobals)에 하네스 레이아웃을 올려 오류 경계의 재설정을 잰다 — 브라우저 확인은 관리자 히어로섹션 관리에서 미리보기 응답을 깨뜨린 뒤 위치 탭만 바꿔 「컴포넌트 로드 실패」 박스가 사라지는지 본다
/**
 * @file DynamicRenderer.errorBoundaryRouteReset.test.tsx
 * @description 컴포넌트 오류 경계는 화면의 경로·쿼리가 바뀌면 다시 그려 본다 (engine-v1.94.0)
 *
 * 노드마다 감싸는 `ComponentErrorBoundary` 는 렌더 오류를 잡으면 「컴포넌트 로드 실패」 박스를 그리고, 되돌리는 경로가 없었다.
 * 같은 레이아웃 안에서 SPA 로 쿼리만(`navigate replace` → `updateQueryParams`) 또는 경로만 바뀌면 노드가 다시 마운트되지 않으므로
 * 새 쿼리의 데이터가 정상이어도 박스가 새로고침 전까지 남았다(관리자 히어로섹션 관리의 위치 탭 전환).
 *
 * 계약:
 *   1) 오류 상태의 경계는 렌더 문맥의 `route`·`query` 값이 오류 때와 달라지면 자식을 다시 그린다.
 *   2) 정상 경계는 경로·쿼리가 바뀌어도 자식을 다시 마운트하지 않는다.
 *   3) 다시 그린 자식이 또 실패하면 박스로 돌아가고, 다음 경로·쿼리 변경 전까지 다시 시도하지 않는다(렌더 루프 없음).
 *   4) 값이 같은 `query` 객체가 새로 실려도(같은 탭을 다시 누름) 다시 시도하지 않는다.
 *
 * 하네스: 실물 `TemplateApp`(레이아웃 로더만 대역) · 실물 `renderTemplate`·`DynamicRenderer`·`DataSourceManager`·`G7CoreGlobals`.
 * API 는 ApiClient 대역. 쿼리 변경은 실제 `updateQueryParams`(주소 교체 → 다시 받기 → 화면 갱신), 경로 변경은 실제 `handleRouteChange`.
 *
 * @scenario-manifest tests/scenarios/engine-error-boundary-route-reset.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

const net = vi.hoisted(() => ({
    respond: (_url: string): any => ({ success: true, data: {} }),
}));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        // 응답은 조금 늦게 온다 — 주소 교체(replaceState) 뒤, 화면 갱신 전에 사이가 있는 실제 순서
        get: (url: string) => new Promise((resolve) => {
            const body = net.respond(url);
            // 보조 소스(aux)가 먼저 와서, 주 소스(hero)가 오기 전에 화면이 한 번 더 그려진다(실제 화면의 여러 progressive 소스)
            setTimeout(() => resolve(body), url.startsWith('/test/aux') ? 10 : 40);
        }),
        post: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue('t'),
        setOnUnauthorized: vi.fn(),
    }),
}));

import { TemplateApp } from '../../TemplateApp';
import * as engine from '../../template-engine';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { ComponentRegistry } from '../ComponentRegistry';
import { AuthManager } from '../../auth/AuthManager';
import { transitionManager } from '../TransitionManager';

type Node = Record<string, any>;

/** 측정값 — 렌더 시도 수(오류 포함)와 마운트 수 */
const counters = { fragileAttempts: 0, fragileMounts: 0, healthyMounts: 0, staleThrows: [] as string[] };

const Div = ({ children, className, id }: any) => React.createElement('div', { className, id }, children);

/** 확장 컴포넌트 모형 — 데이터가 깨져 있으면 렌더 중에 던진다(히어로 미리보기가 깨진 배너 데이터에서 던지던 모양) */
const Fragile = ({ broken, label, screen }: any) => {
    counters.fragileAttempts++;
    React.useEffect(() => {
        counters.fragileMounts++;
    }, []);
    if (broken === true || broken === 'true') {
        // 화면(경로·쿼리)은 이미 다른 위치인데 앞 위치의 값으로 다시 그려 던진 렌더 — 전환(startTransition) 렌더에서 이렇게 던지고
        // 새 응답이 그 사이 오면 React 가 루트를 동기로 다시 그려 회복 오류(#520)를 알린다
        if (label !== undefined && screen !== undefined && String(label) !== String(screen)) {
            counters.staleThrows.push(`${label}@${screen}`);
        }
        throw new Error('preview data is broken');
    }

    return React.createElement('div', { 'data-testid': 'fragile' }, String(label ?? ''));
};

/**
 * 빠른 서버 모형 — 이 렌더(앞 위치 값을 이어 그린 전환 렌더)가 아직 진행 중일 때 새 위치 응답이 모두 와서
 * 화면 갱신이 예약되고 「받는 중」 표시가 꺼진 상황을 만든다. 오류 경계보다 트리 앞에 있어 같은 렌더 안에서 먼저 그려진다.
 */
const settleDuringRender: { pending: null | (() => void) } = { pending: null };
const Settler = ({ label, screen }: any) => {
    const run = settleDuringRender.pending;
    // 화면은 새 위치인데 데이터는 앞 위치인 렌더(이어 그린 첫 렌더)에서만
    if (run && label !== undefined && screen !== undefined && String(label) !== String(screen)) {
        settleDuringRender.pending = null;
        run();
    }

    return null;
};

/** 같은 화면의 정상 컴포넌트 — 다시 마운트되는지 잰다 */
const Healthy = () => {
    React.useEffect(() => {
        counters.healthyMounts++;
    }, []);

    return React.createElement('div', { 'data-testid': 'healthy' }, 'ok');
};

const LAYOUT: Node = {
    layout_name: 'hero_admin',
    data_sources: [
        { id: 'hero', type: 'api', endpoint: "/api/test/hero/{{route.position ?? query.position ?? 'main'}}", auth_mode: 'required' },
        { id: 'aux', type: 'api', endpoint: "/api/test/aux/{{query.position ?? 'main'}}", auth_mode: 'required' },
    ],
    components: [{
        id: 'root', type: 'basic', name: 'Div', props: { id: 'main_content' }, children: [
            { id: 'settler', type: 'basic', name: 'Settler', props: { label: '{{hero?.data?.label}}', screen: "{{route.position ?? query.position ?? 'main'}}" } },
            { id: 'hero_preview', type: 'composite', name: 'Fragile', props: { broken: '{{hero?.data?.broken}}', label: '{{hero?.data?.label}}', screen: "{{route.position ?? query.position ?? 'main'}}" } },
            { id: 'healthy_panel', type: 'basic', name: 'Healthy' },
        ],
    }],
};

/** 위치별 응답 — main·broken2 는 깨진 데이터, sub·side 는 정상 */
function respond(url: string): any {
    if (url.startsWith('/test/aux')) return { success: true, data: { ok: true } };
    const position = url.match(/^\/test\/hero\/([^/?]+)/)?.[1] ?? 'main';
    const broken = position === 'main' || position === 'broken2';

    return { success: true, data: { broken, label: position } };
}

const waitLong = <T,>(cb: () => T): Promise<T> => waitFor(cb, { timeout: 10_000, interval: 15 });
const hasErrorBox = (): boolean => (document.body.textContent ?? '').includes('컴포넌트 로드 실패');
const fragileText = (): string | null => document.querySelector('[data-testid="fragile"]')?.textContent ?? null;
const settle = (ms = 80): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * 실물 엔진을 올리고 히어로 관리 모형 화면으로 들어간다.
 *
 * @param position 첫 진입 위치 쿼리
 * @returns 앱과 경로 이동 함수
 */
async function mount(position: string): Promise<{ app: any; go: (path: string, params: Record<string, string>, query: Record<string, string>) => Promise<void> }> {
    document.body.innerHTML = '<div id="app"></div>';
    net.respond = respond;
    vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = {
        Div: { component: Div, metadata: {} },
        Fragile: { component: Fragile, metadata: {} },
        Healthy: { component: Healthy, metadata: {} },
        Settler: { component: Settler, metadata: {} },
    };
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    (app as any).layoutLoader = {
        loadLayout: async () => JSON.parse(JSON.stringify({ version: '1.0.0', ...LAYOUT })),
    };
    const go = async (path: string, params: Record<string, string>, query: Record<string, string>): Promise<void> => {
        const qs = new URLSearchParams(query).toString();
        window.history.pushState({}, '', path + (qs ? `?${qs}` : ''));
        await (app as any).handleRouteChange({ path, layout: 'hero_admin', params, query });
    };
    await go('/admin/hero', {}, { position });
    await waitLong(() => expect(document.querySelector('[data-testid="healthy"]')).not.toBeNull());

    return { app, go };
}

describe('[case:components-misc-error-boundary-route-reset] 컴포넌트 오류 경계는 경로·쿼리가 바뀌면 다시 그려 본다 (engine-v1.94.0)', { timeout: 60_000 }, () => {
    let consoleError: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        counters.fragileAttempts = 0;
        counters.fragileMounts = 0;
        counters.healthyMounts = 0;
        counters.staleThrows = [];
        settleDuringRender.pending = null;
        // 경계가 잡은 렌더 오류는 React 가 console.error 로 알린다 — 출력만 막고 내용은 렌더 루프 판정에 쓴다
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        engine.destroyTemplate?.();
        vi.restoreAllMocks();
        delete (window as any).__templateApp;
    });

    /**
     * @scenario case=query_change_recovers
     * @effects error_box_cleared_after_query_change, recovered_component_shows_new_data
     */
    it('깨진 위치에서 오류 박스가 뜬 뒤 쿼리만 바꾸는 이동(updateQueryParams)으로 정상 위치에 가면 박스가 사라지고 새 데이터로 그린다', async () => {
        const { app } = await mount('main');
        await waitLong(() => expect(hasErrorBox()).toBe(true));
        expect(fragileText()).toBeNull();

        await app.updateQueryParams('/admin/hero?position=sub');
        await waitLong(() => expect(fragileText()).toBe('sub')); // 결함: 박스가 남아 fragile 이 없다
        expect(hasErrorBox()).toBe(false);
        expect(counters.staleThrows).toEqual([]);
    });

    /**
     * @scenario case=same_screen_navigate_recovers
     * @effects error_box_cleared_after_query_change, recovered_component_shows_new_data, no_render_loop
     */
    it('위치 탭처럼 링크 이동(handleRouteChange)으로 쿼리만 바꾸면 — 첫 렌더가 앞 위치의 깨진 값을 이어 그리고 보조 소스가 먼저 와도 — 새 위치 값이 오면 박스가 사라진다', async () => {
        const { go } = await mount('main');
        await waitLong(() => expect(hasErrorBox()).toBe(true));
        const healthyBefore = counters.healthyMounts;

        // 같은 경로·같은 레이아웃이라 앞 화면의 progressive 값(깨진 hero)을 이어 그린 뒤 새 값을 받는다
        await go('/admin/hero', {}, { position: 'sub' });
        await waitLong(() => expect(fragileText()).toBe('sub')); // 결함: 이어 그린 깨진 값으로 다시 실패한 채 새 값이 와도 박스
        expect(hasErrorBox()).toBe(false);
        // 받는 중의 렌더(앞 위치 값을 이어 그린 렌더)에서는 다시 그리지 않는다 — 앞 위치 값으로 던진 렌더 0
        expect(counters.staleThrows).toEqual([]); // 결함(2차 이전): ['main@sub']
        expect(counters.healthyMounts).toBe(healthyBefore);
        const loopErrors = consoleError.mock.calls.filter((args: unknown[]) => String(args[0] ?? '').includes('Maximum update depth'));
        expect(loopErrors).toHaveLength(0);
    });

    /**
     * @scenario case=round_trip_no_stale_retry
     * @effects error_box_cleared_after_query_change, error_box_kept_when_retry_fails, no_stale_data_retry, no_render_loop
     */
    it('깨진 위치 ↔ 정상 위치를 링크 이동으로 오가도 — 두 번째 이동부터는 앞 위치의 깨진 값을 이어 그린다 — 앞 위치 값으로 다시 그려 던지지 않는다', async () => {
        const { go } = await mount('main');
        await waitLong(() => expect(hasErrorBox()).toBe(true));

        for (let round = 1; round <= 2; round++) {
            await go('/admin/hero', {}, { position: 'sub' });
            await waitLong(() => expect(fragileText()).toBe('sub'));
            expect(hasErrorBox()).toBe(false);
            await go('/admin/hero', {}, { position: 'main' });
            await waitLong(() => expect(hasErrorBox()).toBe(true));
            await settle();
            expect(hasErrorBox()).toBe(true);
        }
        // 결함(2차 이전): 두 번째 [정상 위치] 이동의 첫 렌더가 깨진 main 값을 sub 화면에서 다시 그려 던졌다 — ['main@sub', …]
        expect(counters.staleThrows).toEqual([]);
        const loopErrors = consoleError.mock.calls.filter((args: unknown[]) => String(args[0] ?? '').includes('Maximum update depth'));
        expect(loopErrors).toHaveLength(0);
    });

    /**
     * @scenario case=settle_without_parent_render
     * @effects error_box_cleared_after_query_change, no_stale_data_retry
     */
    it('받는 중에 새 쿼리와 새 데이터가 한 번에 그려지고 그 뒤 화면 갱신이 없어도, 받기가 끝나면 박스가 사라진다', async () => {
        await mount('main');
        await waitLong(() => expect(hasErrorBox()).toBe(true));
        await settle();

        // 쿼리 갱신 경로와 같은 순서 — 받는 중 표시를 켠 채 새 query·새 데이터를 동기로 한 번 그리고, 받는 중 표시만 끈다
        transitionManager.setPending(true);
        // TemplateApp 의 쿼리 갱신처럼 받은 소스·query 의 바인딩 캐시를 비우고 싣는다
        engine.getState().bindingEngine?.invalidateCacheByKeys(['hero', 'query']);
        engine.updateTemplateData({ query: { position: 'sub' }, hero: { success: true, data: { broken: false, label: 'sub' } } }, { sync: true });
        await settle();
        // 받는 중에는 다시 그리지 않는다
        expect(hasErrorBox()).toBe(true);
        transitionManager.setPending(false);
        await waitLong(() => expect(fragileText()).toBe('sub')); // 받기 끝 알림이 없으면 박스가 남는다
        expect(hasErrorBox()).toBe(false);
        expect(counters.staleThrows).toEqual([]);
    });

    /**
     * @scenario case=settled_during_stale_render
     * @effects error_box_cleared_after_query_change, no_stale_data_retry, no_render_loop
     */
    it('앞 위치 값을 이어 그린 렌더가 끝나기 전에 새 위치 응답이 모두 오고 받는 중 표시가 꺼져도 — 그 렌더에서 다시 그려 던지지 않는다', async () => {
        const { go } = await mount('main');
        await waitLong(() => expect(hasErrorBox()).toBe(true));
        await settle();

        // 첫 렌더(깨진 main 값 + sub 쿼리) 도중: 새 값 화면 갱신을 예약하고(TemplateApp progressive 응답과 같은 전환 갱신) 받는 중을 끈다
        settleDuringRender.pending = () => {
            engine.getState().bindingEngine?.invalidateCacheByKeys(['hero']);
            engine.updateTemplateData({ hero: { success: true, data: { broken: false, label: 'sub' } } });
            transitionManager.setPending(false);
        };
        await go('/admin/hero', {}, { position: 'sub' });
        await waitLong(() => expect(fragileText()).toBe('sub'));
        expect(hasErrorBox()).toBe(false);
        // 결함(2차 1안): 경계가 렌더 중에 지금의 받는 중 표시(꺼짐)만 보고 이 렌더의 옛 값으로 다시 그려 던졌다 — ['main@sub', …]
        expect(counters.staleThrows).toEqual([]);
        const loopErrors = consoleError.mock.calls.filter((args: unknown[]) => String(args[0] ?? '').includes('Maximum update depth'));
        expect(loopErrors).toHaveLength(0);
    });

    /**
     * @scenario case=path_change_recovers
     * @effects error_box_cleared_after_path_change, recovered_component_shows_new_data
     */
    it('같은 레이아웃의 다른 경로로 SPA 이동하면(노드 다시 마운트 없음) 오류 박스가 사라진다', async () => {
        const { go } = await mount('main');
        await waitLong(() => expect(hasErrorBox()).toBe(true));
        const healthyBefore = counters.healthyMounts;

        await go('/admin/hero/side', { position: 'side' }, {});
        await waitLong(() => expect(fragileText()).toBe('side'));
        expect(hasErrorBox()).toBe(false);
        expect(counters.staleThrows).toEqual([]);
        // 같은 레이아웃이라 정상 노드는 그대로다 — 이 이동은 다시 마운트로 오류를 지우는 경로가 아니다
        expect(counters.healthyMounts).toBe(healthyBefore);
    });

    /**
     * @scenario case=healthy_boundary_not_remounted
     * @effects healthy_boundary_not_remounted
     */
    it('정상 경계는 쿼리가 바뀌어도 자식을 다시 마운트하지 않는다(마운트 수·DOM 노드 그대로)', async () => {
        const { app } = await mount('sub');
        await waitLong(() => expect(fragileText()).toBe('sub'));
        expect(counters.fragileMounts).toBe(1);
        expect(counters.healthyMounts).toBe(1);
        const fragileNode = document.querySelector('[data-testid="fragile"]');
        const healthyNode = document.querySelector('[data-testid="healthy"]');

        await app.updateQueryParams('/admin/hero?position=side');
        await waitLong(() => expect(fragileText()).toBe('side'));
        await app.updateQueryParams('/admin/hero?position=sub');
        await waitLong(() => expect(fragileText()).toBe('sub'));

        expect(counters.fragileMounts).toBe(1);
        expect(counters.healthyMounts).toBe(1);
        expect(document.querySelector('[data-testid="fragile"]')).toBe(fragileNode);
        expect(document.querySelector('[data-testid="healthy"]')).toBe(healthyNode);
        expect(hasErrorBox()).toBe(false);
    });

    /**
     * @scenario case=retry_fails_again
     * @effects error_box_kept_when_retry_fails, no_retry_without_route_change, no_render_loop
     */
    it('재설정 뒤에도 실패하면 박스로 돌아가고, 화면 받기가 끝난 뒤에는 경로·쿼리가 다시 바뀌기 전까지(같은 값의 쿼리 재적용·전역 상태 갱신 포함) 다시 시도하지 않는다', async () => {
        const { app } = await mount('main');
        await waitLong(() => expect(hasErrorBox()).toBe(true));
        await settle();
        const firstFailureAttempts = counters.fragileAttempts;
        expect(firstFailureAttempts).toBeGreaterThan(0);

        // 다른 깨진 위치로 — 다시 그려 보고(시도 수 증가) 다시 박스
        await app.updateQueryParams('/admin/hero?position=broken2');
        await waitLong(() => expect(counters.fragileAttempts).toBeGreaterThan(firstFailureAttempts)); // 결함: 다시 시도하지 않는다
        await settle();
        expect(hasErrorBox()).toBe(true);

        // 받는 중에 난 실패는 확정이 아니다 — 받기가 끝난 뒤 첫 화면 갱신에서 한 번 더 그려 볼 수 있다. 그 뒤로는 시도하지 않는다
        app.setGlobalState({ unrelatedFlag: 1 });
        await settle();
        const settled = counters.fragileAttempts;
        // 위치 하나를 옮긴 비용은 첫 진입 실패 몇 번 분량 안이다(React 의 동기 재시도 포함) — 루프면 끝없이 는다
        expect(settled - firstFailureAttempts).toBeLessThanOrEqual(firstFailureAttempts * 3);

        app.setGlobalState({ unrelatedFlag: 2 });
        await settle();
        // 같은 쿼리를 다시 적용(값이 같은 새 query 객체 + 다시 받은 같은 데이터)도 다시 시도하지 않는다
        await app.updateQueryParams('/admin/hero?position=broken2');
        await settle();
        app.setGlobalState({ unrelatedFlag: 3 });
        await settle();
        expect(counters.fragileAttempts).toBe(settled);
        expect(hasErrorBox()).toBe(true);

        const loopErrors = consoleError.mock.calls.filter((args: unknown[]) => String(args[0] ?? '').includes('Maximum update depth'));
        expect(loopErrors).toHaveLength(0);
    });
});
