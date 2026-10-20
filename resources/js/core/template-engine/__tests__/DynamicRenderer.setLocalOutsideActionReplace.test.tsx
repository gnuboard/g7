// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 루트 렌더러·저장소 A React 상태·액션 밖 G7Core.state.setLocal)로 렌더 병합을 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 엔진 매트릭스(관리자·사용자 템플릿, 콘솔 setLocal 로 액션 밖 shallow 교체)가 덮는다
/**
 * @file DynamicRenderer.setLocalOutsideActionReplace.test.tsx
 * @description 액션 밖(컴포넌트 코드·타이머·웹소켓 콜백)의 `G7Core.state.setLocal(값, { merge: 'shallow' | 'replace' })` 가 화면·저장소 B 에서 그 의미대로 남는다 (engine-v1.81.2, 7.1.1)
 *
 * setLocal 은 액션 문맥(`__g7ActionContext`)이 있으면 저장소 A 도 같은 방식으로 고치지만, 액션 밖에서는 저장소 B 만 바꾼다. 그 키 아래에
 * 저장소 A 가 setState 동작으로 쓴 하위 키가 있으면 렌더 병합(A 우선)이 그것을 되살려 화면 ≠ B 였고, 그 화면 문맥을 입력으로 쓰는 다음
 * 동작의 잎 키 보충(addMissingLeafKeys)이 B 에까지 되썼다(7.1.0 에도 있음). 엔진은 교체한 최상위 키를 표지로 남겨 페이지 루트가 그 키를
 * 정본 B 값으로 그리고, B 사본이 따라온 뒤 저장소 A 에서 그 키를 지운다.
 *
 * 하네스는 저장소 B 를 손으로 채우지 않는다 — 실제 writer(setState 동작·`G7Core.state.setLocal`·`TemplateApp.setGlobalState`)만 거친다.
 *
 * @scenario-manifest tests/scenarios/setlocal-outside-action-replace.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import React from 'react';
import { fireEvent, waitFor } from '@testing-library/react';

import { TemplateApp } from '../../TemplateApp';
import * as engine from '../../template-engine';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { ComponentRegistry } from '../ComponentRegistry';
import { applySetLocalReplacedKeys, pruneSetLocalReplacedKeys, renderBaseCaughtUpReplacedKeys } from '../DynamicRenderer';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        post: vi.fn().mockResolvedValue({}),
        get: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue(null),
        setOnUnauthorized: vi.fn(),
    }),
}));

type Node = Record<string, any>;

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('aria-') || k.startsWith('data-') || ['id', 'type', 'disabled', 'onClick', 'role'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

const captured: Array<Record<string, any>> = [];

const show = (id: string, expr: string): Node => ({ id: `show_${id}`, type: 'basic', name: 'Span', props: { 'data-testid': id }, text: `{{${expr}}}` });
const button = (text: string, actions: any[]): Node => ({ id: `btn_${text}`, type: 'basic', name: 'Button', text, actions });

/** 필터 화면과 같은 모양 — setState 동작으로 하위 필터를 고르고, 액션 밖 코드가 필터 묶음을 통째로 비운다 */
const PAGE: Node[] = [
    button('pick', [{ type: 'click', handler: 'setState', params: { target: 'local', filters: { status: 'paid', method: 'card' }, keep: 'x' } }]),
    button('peek', [{ type: 'click', handler: 'test.capture', params: { filters: '{{JSON.stringify(_local.filters ?? null)}}' } }]),
    // 화면 문맥을 입력으로 쓰는 다음 동작 — 액션 안 deep setLocal(잎 키 보충이 도는 경로)
    button('other', [{ type: 'click', handler: 'test.deepSetLocal', params: { updates: { page: 2 } } }]),
    // 액션 안 shallow 비우기 — 대조군(저장소 A 는 4단계가 고친다)
    button('clear-in-action', [{ type: 'click', handler: 'test.shallowClear' }]),
    // 다른 키만 쓰는 setState 동작 — 루트의 저장소 A 만 바뀌어 다시 그려진다(B 사본은 그대로)
    button('tab', [{ type: 'click', handler: 'setState', params: { target: 'local', tab: 'b' } }]),
    show('filters', 'JSON.stringify(_local.filters ?? null)'),
    show('keep', 'JSON.stringify(_local.keep ?? null)'),
];

interface Mounted {
    app: any;
    stop: () => void;
}

async function mount(): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    captured.length = 0;
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
        Button: { component: passthrough('button'), metadata: {} },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));
    dispatcher.registerHandler('test.capture', (action: any) => { captured.push({ ...(action.params ?? {}) }); });
    dispatcher.registerHandler('test.deepSetLocal', (action: any) => { (window as any).G7Core.state.setLocal(action.params.updates); });
    dispatcher.registerHandler('test.shallowClear', () => { (window as any).G7Core.state.setLocal({ filters: {} }, { merge: 'shallow' }); });

    app.setGlobalState({ _local: { filters: {} } }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/setlocal-outside-action-replace',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: PAGE }],
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (u: any) => app.setGlobalState(u) },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="page"]')).not.toBeNull());

    return {
        app,
        stop: () => {
            try { engine.destroyTemplate(); } catch { /* 정리 */ }
            delete (window as any).__templateApp;
            delete (window as any).G7Core;
            (window as any).__g7PendingLocalState = null;
            (window as any).__g7ForcedLocalFields = undefined;
            (window as any).__g7SetLocalOverrideKeys = undefined;
            (window as any).__g7LastSetLocalSnapshot = undefined;
            (window as any).__g7SetLocalReplacedKeys = undefined;
            document.body.innerHTML = '';
        },
    };
}

const text = (id: string): string => (document.querySelector(`[data-testid="${id}"]`)?.textContent ?? '');
const settle = (ms = 40): Promise<void> => new Promise((r) => setTimeout(r, ms));
async function click(label: string): Promise<void> {
    const b = Array.from(document.querySelectorAll('button')).find((x) => (x.textContent ?? '') === label) as HTMLElement;
    fireEvent.click(b);
    await settle();
}
const liveB = (app: any): Record<string, any> => app.getGlobalState()._local;

describe('[case:state-setstate-52] 액션 밖 shallow·replace setLocal 이 화면·B 에서 그 의미대로 남는다 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    /**
     * @scenario case=outside_shallow_empty
     * @effects screen_matches_canonical_after_outside_replace
     */
    it('액션 밖 shallow `{}` 로 비운 필터가 화면에도 빈 값이다 (setState 동작이 저장소 A 에 쓴 하위 키가 되살아나지 않음)', async () => {
        m = await mount();
        await click('pick');
        expect(JSON.parse(text('filters'))).toEqual({ status: 'paid', method: 'card' });

        (window as any).G7Core.state.setLocal({ filters: {} }, { merge: 'shallow' });
        await settle(120);

        expect(liveB(m.app).filters).toEqual({});
        expect(text('filters')).toBe('{}');
    });

    /**
     * @scenario case=outside_shallow_empty_then_actions
     * @effects next_action_reads_canonical, line_action_does_not_write_back
     */
    it('비운 뒤 동작이 읽는 `_local` 도 빈 값이고, 잎 키 보충이 있는 다음 setLocal 이 B 에 하위 키를 되쓰지 않는다', async () => {
        m = await mount();
        await click('pick');
        (window as any).G7Core.state.setLocal({ filters: {} }, { merge: 'shallow' });
        await settle(120);

        await click('peek');
        expect(captured[0]?.filters).toBe('{}');

        await click('other');
        await settle(80);
        expect(liveB(m.app).page).toBe(2);
        expect(liveB(m.app).filters, '화면 문맥의 하위 키가 잎 키 보충으로 B 에 되써지면 안 된다').toEqual({});
        expect(text('filters')).toBe('{}');
    });

    /**
     * @scenario case=outside_replace
     * @effects screen_matches_canonical_after_outside_replace
     */
    it('액션 밖 replace 는 화면도 B 와 같다 — 저장소 A 에만 남은 키가 보이지 않는다', async () => {
        m = await mount();
        await click('pick');
        expect(text('keep')).toBe('"x"');

        (window as any).G7Core.state.setLocal({ filters: {} }, { merge: 'replace' });
        await settle(120);

        expect(liveB(m.app)).toEqual({ filters: {} });
        expect(text('filters')).toBe('{}');
        expect(text('keep')).toBe('null');
    });

    /**
     * @scenario case=outside_shallow_render_false_then_root_render
     * @effects screen_matches_canonical_after_outside_replace, marker_cleared_after_catch_up
     */
    it('render:false 로 비운 뒤 다른 전역 쓰기로 루트가 다시 그려지면 그때 화면이 빈 값이고, 표지는 사본이 따라온 뒤 지워진다', async () => {
        m = await mount();
        await click('pick');

        (window as any).G7Core.state.setLocal({ filters: {} }, { merge: 'shallow', render: false });
        expect((window as any).__g7SetLocalReplacedKeys?.keys).toEqual(['filters']);
        m.app.setGlobalState({ __tick: 1 });
        await settle(120);

        expect(text('filters')).toBe('{}');
        expect((window as any).__g7SetLocalReplacedKeys, '사본이 정본을 따라온 뒤 표지를 비운다').toBeUndefined();

        // 정리 뒤 다시 고른 하위 키는 종전처럼 보인다(저장소 A 정리가 이후 쓰기를 막지 않음)
        await click('pick');
        expect(JSON.parse(text('filters'))).toEqual({ status: 'paid', method: 'card' });
    });

    /**
     * render:false 라 B 사본이 따라오지 않은 채(저장소 A 정리 전) 다른 동작이 루트를 다시 그려도 화면·다음 동작이 정본 값을 쓴다.
     *
     * @scenario case=outside_shallow_render_false_then_local_write
     * @effects screen_matches_canonical_after_outside_replace, line_action_does_not_write_back
     */
    it('render:false 로 비운 뒤 다른 키만 쓰는 setState 동작으로 다시 그려져도 필터가 빈 값이고, 다음 setLocal 이 하위 키를 되쓰지 않는다', async () => {
        m = await mount();
        await click('pick');

        (window as any).G7Core.state.setLocal({ filters: {} }, { merge: 'shallow', render: false });
        // 루트 다시 그리기(B 사본 갱신)보다 먼저 오는 저장소 A 쓰기 렌더 — 그 렌더의 화면·다음 동작 입력이 정본 값이어야 한다
        const tab = Array.from(document.querySelectorAll('button')).find((x) => (x.textContent ?? '') === 'tab') as HTMLElement;
        fireEvent.click(tab);
        await settle(0);
        expect(text('filters')).toBe('{}');
        await settle(80);
        expect(text('filters')).toBe('{}');

        await click('other');
        await settle(80);
        expect(liveB(m.app).filters).toEqual({});
    });

    /**
     * @scenario case=in_action_shallow_control
     * @effects in_action_path_unchanged
     */
    it('대조군: 액션 안 shallow 는 표지 없이 종전 경로(4단계 저장소 A 갱신)로 비운다', async () => {
        m = await mount();
        await click('pick');
        await click('clear-in-action');
        await settle(80);

        expect((window as any).__g7SetLocalReplacedKeys).toBeUndefined();
        expect(liveB(m.app).filters).toEqual({});
        expect(text('filters')).toBe('{}');
    });

    /**
     * @scenario case=outside_deep_control
     * @effects deep_setlocal_unchanged
     */
    it('대조군: 액션 밖 deep(기본) setLocal 은 표지를 만들지 않고 종전처럼 깊게 병합한다', async () => {
        m = await mount();
        await click('pick');

        (window as any).G7Core.state.setLocal({ filters: { status: 'ready' } });
        await settle(120);

        expect((window as any).__g7SetLocalReplacedKeys).toBeUndefined();
        expect(liveB(m.app).filters).toEqual({ status: 'ready', method: 'card' });
        expect(JSON.parse(text('filters'))).toEqual({ status: 'ready', method: 'card' });
    });
});

/**
 * 렌더 단계 맞춤(applySetLocalReplacedKeys)의 입력 모양 — 루트 다시 그리기(B 사본 갱신)보다 먼저 오는 저장소 A 쓰기 렌더에서만 화면을
 * 정하는 방어라(액션 밖 render:false 쓰기도 엔진이 루트를 바로 다시 그린다) 실물 렌더러로는 그 틈을 고정하기 어렵다. 모양별로 순수 함수를 잠근다.
 */
describe('[case:state-setstate-52] 렌더 단계 정본 맞춤 — 입력 모양 (engine-v1.81.2)', () => {
    afterEach(() => {
        delete (window as any).__templateApp;
    });

    /**
     * @scenario case=apply_shapes
     * @effects screen_matches_canonical_after_outside_replace
     */
    it('표지 키 가운데 저장소 A 가 가진 키만 정본 값으로 바꾸고, 정본에 없으면 빼며, 표지 밖·A 에 없는 키·다른 세대 표지는 그대로 둔다', () => {
        (window as any).__templateApp = { getRouteChangeId: () => 3 };
        const merged = { filters: { status: 'paid' }, keep: 'x', other: { a: 1 }, notInA: { z: 1 } };
        const written = { filters: { status: 'paid' }, keep: 'x', other: { a: 1 } };
        const canonical = { filters: {}, other: { a: 1 }, notInA: { z: 2 } };

        const shallow = applySetLocalReplacedKeys(merged, written, canonical, { all: false, keys: ['filters', 'notInA'], generation: 3 });
        expect(shallow.filters).toBe(canonical.filters);
        expect(shallow.keep).toBe('x');
        expect(shallow.notInA, 'A 에 없는 키는 사본 값 그대로').toEqual({ z: 1 });

        const replaced = applySetLocalReplacedKeys(merged, written, canonical, { all: true, keys: ['filters'], generation: 3 });
        expect(replaced.filters).toEqual({});
        expect('keep' in replaced, '정본에 없는 A 키는 뺀다').toBe(false);
        expect(replaced.other).toBe(canonical.other);

        expect(applySetLocalReplacedKeys(merged, written, canonical, { all: true, keys: [], generation: 2 }), '다른 세대 표지').toBe(merged);
        expect(applySetLocalReplacedKeys(merged, written, canonical, undefined), '표지 없음').toBe(merged);
        expect(applySetLocalReplacedKeys(merged, written, canonical, { all: false, keys: ['zzz'], generation: 3 }), '바꿀 것 없음').toBe(merged);
    });

    /**
     * @scenario case=prune_shapes
     * @effects marker_cleared_after_catch_up
     */
    it('정리는 표지 키만 지우고(없으면 원본 참조), 사본이 표지 키를 정본과 같은 참조로 가질 때만 따라왔다고 본다', () => {
        const target = { filters: { a: 1 }, keep: 1 };
        expect(pruneSetLocalReplacedKeys(target, { all: false, keys: ['filters'] })).toEqual({ keep: 1 });
        expect(pruneSetLocalReplacedKeys(target, { all: false, keys: ['none'] })).toBe(target);
        expect(pruneSetLocalReplacedKeys(target, { all: true, keys: [] })).toEqual({});

        const empty = {};
        expect(renderBaseCaughtUpReplacedKeys({ filters: empty }, { filters: empty }, { all: false, keys: ['filters'] })).toBe(true);
        expect(renderBaseCaughtUpReplacedKeys({ filters: { a: 1 } }, { filters: empty }, { all: false, keys: ['filters'] })).toBe(false);
        expect(renderBaseCaughtUpReplacedKeys({ filters: empty, x: 1 }, { filters: empty }, { all: true, keys: [] }), 'replace 는 모든 키').toBe(false);
    });
});
