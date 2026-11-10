// e2e:allow 실제 엔진 구조(TemplateApp.changeLocale · destroyTemplate · initTemplateEngine · renderTemplate · DynamicRenderer)로 언어 전환 뒤 포커스 자리를 잰다 — 브라우저 축은 총괄의 빌드 뒤 실측(모바일 서랍 언어 칩, 관리자 머리 언어 단추)이 덮는다
/**
 * @file TemplateApp.localeSwitchFocus.test.tsx
 * @description 언어 전환(엔진을 내리고 같은 화면을 새로 마운트) 뒤 포커스가 body 로 빠지지 않고 전환 직전 요소로 돌아오는지 (engine-v1.94.0, #869)
 *
 * 레이아웃의 `<set-locale>` 단추(모바일 서랍 언어 칩 등)와 서드파티 레이아웃은 부품 훅(`G7Core.locale.handoff`)이 닿지 않는다.
 * 엔진이 전환 직전 포커스 요소의 식별자(렌더러가 싣는 노드 id, 없으면 id 있는 조상과 순번)를 맡겼다가 다시 그린 뒤 되돌린다.
 * 요소가 없으면 본문 시작점, 그 사이 사용자·부품 훅이 포커스를 옮겼으면 건드리지 않는다.
 *
 * @scenario-manifest tests/scenarios/locale-switch-focus.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        get: vi.fn().mockResolvedValue({ success: true, data: {} }),
        post: vi.fn().mockResolvedValue({}),
        put: vi.fn().mockResolvedValue({}),
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

type Node = Record<string, any>;

const passthrough = (tag: string) => ({ children, text, ...rest }: any) => {
    const domProps: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('data-') || ['id', 'type', 'tabIndex'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

const LAYOUT: Node = {
    version: '1.0.0',
    layout_name: 'locale_focus',
    components: [{
        id: 'main_content', type: 'basic', name: 'Div', children: [
            { id: 'chip_ko', type: 'basic', name: 'Button', text: 'KO' },
            { id: 'chip_en', type: 'basic', name: 'Button', text: 'EN' },
            // id 없는 단추 둘을 품은 묶음 — 묶음 id 와 그 안 순번으로 찾는다
            { id: 'drawer_chips', type: 'basic', name: 'Div', children: [
                { type: 'basic', name: 'Button', text: 'A' },
                { type: 'basic', name: 'Button', text: 'B' },
            ] },
        ],
    }],
};

const ROUTE = { path: '/locale-focus', layout: 'locale_focus', params: {}, query: {} };

/**
 * 엔진의 포커스 되돌리기(`restoreFocusAfterLocaleSwitch`)가 끝날 때를 알려 주는 약속을 건다. 언어 전환 전에 부른다.
 *
 * 되돌리기는 다시 그린 요소가 나타날 때까지 16ms 간격으로 30번까지 다시 보고, 끝내 없으면 본문 시작점으로 보낸다. 종전에는
 * 실제 시간 3초(또는 600ms)를 기다린 뒤 읽었는데, 여러 시험 파일을 함께 돌리는 부하에서는 16ms 타이머들이 밀려 되돌리기가 끝나기
 * 전에 읽고 깨졌다(본문 시작점 사례). 그래서 시간 대신 되돌리기 한 번이 「다시 보기를 예약하지 않고 끝났다」 를 신호로 쓴다.
 * 한 번의 호출 안에서 새로 건 setTimeout 이 다시 보기 예약이다. 포커스를 옮긴 호출은 예약 여부와 상관없이 끝으로 본다.
 *
 * @param app 앱
 * @returns 되돌리기가 끝나면 풀리는 약속
 */
function restoreSettled(app: any): Promise<void> {
    return new Promise((resolve) => {
        const original = app.restoreFocusAfterLocaleSwitch.bind(app);
        vi.spyOn(app, 'restoreFocusAfterLocaleSwitch').mockImplementation((...args: any[]) => {
            const realSetTimeout = globalThis.setTimeout;
            let scheduled = false;
            globalThis.setTimeout = ((fn: any, ms?: number, ...rest: any[]) => {
                scheduled = true;

                return realSetTimeout(fn, ms, ...rest);
            }) as typeof setTimeout;
            try {
                original(...args);
            } finally {
                globalThis.setTimeout = realSetTimeout;
            }
            const active = document.activeElement;
            const focusMoved = !!active && active !== document.body && active.isConnected;
            if (!scheduled || focusMoved) resolve();
        });
    });
}
vi.setConfig({ testTimeout: 30_000 });

/**
 * 실물 엔진을 올리고 화면을 그린다.
 *
 * @param afterReentry 언어 전환 재진입 직후, 엔진이 되돌리기 전에 할 일(사용자·부품 훅의 포커스 이동 흉내)
 * @returns 앱
 */
async function mount(afterReentry?: () => Promise<void>): Promise<any> {
    document.body.innerHTML = '<div id="app"></div>';
    // 앞 사례의 언어 전환이 저장한 언어(g7_locale)가 남으면 새 앱이 그 언어로 시작해 전환이 「이미 그 언어」 로 끝난다
    try { localStorage.clear(); } catch { /* 저장소 없음 */ }
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
    const registry = {
        Div: { component: passthrough('div'), metadata: {} },
        Button: { component: passthrough('button'), metadata: {} },
    };
    (ComponentRegistry.getInstance() as any).registry = registry;
    (app as any).layoutLoader = { loadLayout: async () => JSON.parse(JSON.stringify(LAYOUT)), clear: () => {} };
    (app as any).router = {
        navigateToCurrentPath: async () => {
            // 언어 전환은 엔진을 다시 띄우므로 레지스트리를 다시 채운다(실제로는 컴포넌트 번들이 다시 등록)
            (ComponentRegistry.getInstance() as any).registry = registry;
            await (app as any).handleRouteChange(ROUTE);
            await afterReentry?.();
        },
    };
    await (app as any).handleRouteChange(ROUTE);
    await waitFor(() => expect(document.getElementById('chip_en')).not.toBeNull());

    return app;
}

describe('언어 전환 뒤 포커스 자리 (engine-v1.94.0)', () => {
    afterEach(() => {
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    // @scenario target=element_with_id
    // @effects focus_returns_to_same_element
    it('id 가 있는 단추(레이아웃 set-locale 칩)에 있던 포커스가 다시 그린 같은 단추로 돌아온다', async () => {
        const app = await mount();
        const before = document.getElementById('chip_en') as HTMLButtonElement;
        before.focus();
        const settled = restoreSettled(app);

        await app.changeLocale('en', { persist: false });

        await settled;
        expect(document.activeElement?.id).toBe('chip_en');
        expect(document.activeElement).not.toBe(before);
    });

    // @scenario target=element_without_id
    // @effects focus_returns_to_same_element
    it('id 없는 단추는 id 있는 묶음과 그 안 순번으로 같은 자리에 돌아온다', async () => {
        const app = await mount();
        const second = document.querySelectorAll('#drawer_chips button')[1] as HTMLButtonElement;
        second.focus();
        const settled = restoreSettled(app);

        await app.changeLocale('en', { persist: false });

        await settled;
        expect(document.activeElement?.textContent).toBe('B');
        expect(document.activeElement).not.toBe(second);
        expect(document.activeElement?.parentElement?.id).toBe('drawer_chips');
    });

    // @scenario target=element_gone
    // @effects focus_falls_back_to_content_start
    it('맡긴 요소가 다시 그린 화면에 없으면 본문 시작점으로 보낸다', async () => {
        const app = await mount();
        const chip = document.getElementById('chip_en') as HTMLButtonElement;
        chip.focus();
        // 다시 그린 화면에는 없는 식별자로 맡겨지게 한다
        chip.id = 'chip_gone';
        const settled = restoreSettled(app);

        await app.changeLocale('en', { persist: false });

        await settled;
        expect(document.activeElement?.id).toBe('main_content');
    });

    // @scenario target=moved_by_user_or_component
    // @effects focus_left_alone_when_moved
    it('다시 그린 뒤 사용자·부품 훅이 포커스를 이미 옮겼으면 건드리지 않는다(두 번 옮기지 않음)', async () => {
        const app = await mount(async () => {
            // 다시 그린 단추가 나타나면(부품 훅의 마운트 효과 시점) 다른 단추로 옮긴다
            await waitFor(() => expect(document.getElementById('chip_ko')).not.toBeNull());
            (document.getElementById('chip_ko') as HTMLButtonElement).focus();
        });
        (document.getElementById('chip_en') as HTMLButtonElement).focus();
        const settled = restoreSettled(app);

        await app.changeLocale('en', { persist: false });
        // 고정 600ms 대신 되돌리기가 끝난 뒤 읽는다 — 그 전에 읽으면 되돌리기가 옮기기 전이라 늘 통과했다
        await settled;

        expect(document.activeElement?.id).toBe('chip_ko');
    });

    // @scenario target=body
    // @effects focus_left_alone_when_moved
    it('전환 직전 포커스가 화면에 없었으면(body) 아무 데도 옮기지 않는다', async () => {
        const app = await mount();
        (document.activeElement as HTMLElement | null)?.blur();
        const settled = restoreSettled(app);

        await app.changeLocale('en', { persist: false });
        await settled;

        expect(document.activeElement === document.body || document.activeElement === null).toBe(true);
    });
});
