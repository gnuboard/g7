// e2e:allow 실제 엔진 구조(TemplateApp.handleRouteChange · executeInitActions · renderTemplate · DynamicRenderer · ActionDispatcher)로 init 을 끝까지 기다리는지 잰다 — 브라우저 축은 총괄의 빌드 뒤 실측(장바구니 키 발급 요청 보류, 핸들러 3초 상한)이 덮는다
/**
 * @file TemplateApp.initActionsTimeout.test.tsx
 * @description 레이아웃 init 동작이 끝날 때까지 첫 화면을 그리지 않는다 — 엔진 init 대기 상한 되돌림 (설계 확정 2026-11-09, #869)
 *
 * 엔진은 init 동작(`<init>`)을 첫 렌더 전에 끝까지 기다린다(종전 동작). #869 에서 8초 상한(`INIT_ACTIONS_MAX_WAIT_MS`)을 두고 상한 뒤
 * 그린 화면의 입력을 잠그는 방식까지 넣었다가, 늦게 끝나는 init 의 쓰기와 오류 화면, 경로 이동이 얽혀 경계 결함이 이어져 되돌렸다.
 * 이 시험은 init 이 8초를 넘겨도 첫 화면이 init 결과로 그려지는 종전 동작을 잠근다. 끝없이 보류될 수 있는 부수 요청(장바구니 키 발급)은
 * 핸들러가 따로 상한을 둔다(storageHandlers.test.ts).
 *
 * @scenario-manifest tests/scenarios/init-actions-timeout.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        get: vi.fn().mockResolvedValue({ success: true, data: {} }),
        post: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue(null),
        setOnUnauthorized: vi.fn(),
    }),
}));

import { TemplateApp } from '../../TemplateApp';
import * as engine from '../../template-engine';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { ComponentRegistry } from '../ComponentRegistry';

const passthrough = (tag: string) => ({ children, text, ...rest }: any) => {
    const domProps: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('data-') || k === 'id') domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

const ROUTE = { path: '/init-timeout', layout: 'init_timeout', params: {}, query: {} };
vi.setConfig({ testTimeout: 30_000 });

/** 되돌린 엔진 상한(8초)을 넘기는 init 길이 — 이만큼 걸려도 첫 화면은 init 이 끝난 뒤에 선다 */
const SLOW_INIT_MS = 8_500;

/**
 * 실물 엔진을 올리고, 주어진 init 동작을 둔 화면으로 들어간다.
 *
 * @param initHandler init 동작 핸들러
 * @returns 앱과 화면 전환 약속
 */
async function mount(initHandler: () => Promise<void>): Promise<{ app: any; route: Promise<void> }> {
    document.body.innerHTML = '<div id="app"></div>';
    window.history.replaceState({}, '', ROUTE.path);
    vi.spyOn(globalThis, 'fetch' as any).mockImplementation(async () => ({ ok: true, json: async () => ({ success: true, data: {} }) }) as any);
    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = {
        Div: { component: passthrough('div'), metadata: {} },
        Span: { component: passthrough('span'), metadata: {} },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    dispatcher.registerHandler('test.slowInit', initHandler);
    (app as any).layoutLoader = {
        loadLayout: async () => ({
            version: '1.0.0',
            layout_name: 'init_timeout',
            init_actions: [{ handler: 'test.slowInit' }],
            components: [{ id: 'page', type: 'basic', name: 'Div', children: [
                { id: 'key', type: 'basic', name: 'Span', text: "{{_global.cartKey ?? 'none'}}" },
            ] }],
        }),
    };
    const route = (app as any).handleRouteChange(ROUTE);

    return { app, route };
}

const settle = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * 요소가 처음 문서에 붙는 순간의 글자를 돌려준다 — 「첫 화면이 무엇으로 그려졌는가」 를 시각과 무관하게 잰다.
 *
 * 고정 시간 뒤에 읽으면 init 을 기다리지 않고 그린 화면도 늦은 다시 그리기로 이미 바뀌어 있어 가리지 못한다. 판정은 첫 커밋의 글자로 한다.
 * 20초 안에 붙지 않으면 실패한다.
 *
 * @param id 요소 id
 * @returns 처음 붙었을 때의 글자
 */
function firstText(id: string): Promise<string | null> {
    return new Promise((resolve, reject) => {
        const done = (value: string | null): void => {
            observer.disconnect();
            clearTimeout(limit);
            resolve(value);
        };
        const observer = new MutationObserver(() => {
            const el = document.getElementById(id);
            if (el) done(el.textContent);
        });
        observer.observe(document.body, { childList: true, subtree: true });
        const limit = setTimeout(() => {
            observer.disconnect();
            reject(new Error(`#${id} 가 20초 안에 그려지지 않았다`));
        }, 20_000);
    });
}

describe('[case:network-init-action-hang-blocks-first-render] init 동작을 끝까지 기다린 뒤 첫 화면을 그린다 (엔진 상한 되돌림, 설계 확정 2026-11-09)', () => {
    afterEach(() => {
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    // @scenario init=settles_after_eight_seconds
    // @effects first_render_waits_for_init, init_runs_before_first_render
    it('init 이 8초를 넘겨도 끝날 때까지 첫 화면을 그리지 않고, 끝난 뒤 init 이 쓴 값으로 첫 화면을 그린다', async () => {
        let appRef: any;
        const first = firstText('key');
        const { route } = await mount(async () => {
            await settle(SLOW_INIT_MS);
            appRef.setGlobalState({ cartKey: 'ck_slow' });
        });
        appRef = (window as any).__templateApp;

        // 되돌린 상한(8초)이 지난 뒤에도 화면은 아직 없다
        await settle(8_200);
        expect(document.getElementById('page')).toBeNull();

        await route;
        expect(await first).toBe('ck_slow');
    });

    // @scenario init=settles_quickly
    // @effects init_runs_before_first_render
    it('빨리 끝나는 init 동작도 첫 렌더 전에 상태를 쓴다', async () => {
        let appRef: any;
        const first = firstText('key');
        const { route } = await mount(async () => {
            await settle(50);
            appRef.setGlobalState({ cartKey: 'ck_early' });
        });
        appRef = (window as any).__templateApp;
        await route;

        expect(await first).toBe('ck_early');
    });
});
