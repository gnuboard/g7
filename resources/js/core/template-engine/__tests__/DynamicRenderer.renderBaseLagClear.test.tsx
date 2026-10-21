// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 루트 렌더러·저장소 A React 상태·커스텀 핸들러 승격)로 렌더 병합의 늦은 B 사본을 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(상품 상세 2단 옵션 「줄 담김 → 추가옵션·「+」」)이 덮는다
/**
 * @file DynamicRenderer.renderBaseLagClear.test.tsx
 * @description 비운 값(shallow `{}`·setState `{}`·shallow 로 키를 줄인 객체)이 늦은 B 사본에 덮여 화면에 남고, 줄 동작이 그것을 저장소 B 에 되쓰지 않는다 (engine-v1.81.2, 7.1.1)
 *
 * 화면 문맥 `_local` 은 `deepMergeState(dataContext._local, 저장소 A)` 다. `dataContext._local` 은 마지막 루트 다시 그리기 때의 B 사본이고,
 * 그 뒤의 B 쓰기(setState 미러·커스텀 핸들러 setLocal)는 render:false 라 사본은 B 보다 늦다. 색상을 고른 뒤 다른 전역 쓰기(데이터 도착 등)로
 * 루트가 다시 그려지면 사본에 `{색상: 네이비}` 가 실리고, 핸들러가 A·B 를 모두 `{}` 로 비워도 깊은 병합이 `{}` 로 그 키를 지우지 못해
 * 색상 칸만 앞 값이 남았다. 그 상태에서 줄 동작(「+」)을 누르면 setLocal 의 잎 키 보충(addMissingLeafKeys)이 화면 문맥의 앞 선택을
 * B 에 되써 getLocal 까지 되살아났다(6/106, 간격 무관). 또 setLocal 의 shallow 쓰기는 저장소 A 함수형 갱신의 결과가
 * 다시 깊게 병합돼(`{}` 무효) A 에 앞 선택이 남았다.
 *
 * 하네스는 저장소 B 를 손으로 채우지 않는다 — 실제 writer(setState 액션·커스텀 핸들러 `context.setState`·`G7Core.state.setLocal`·
 * `TemplateApp.setGlobalState`)만 거친다. 「루트 다시 그리기」 는 운영과 같은 `TemplateApp.setGlobalState(…)`(render 기본)다.
 *
 * @scenario-manifest tests/scenarios/render-base-lag-clear.yaml
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

function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        Button: c(passthrough('button')),
    };
}

/** 값 표시 — 렌더된 화면 문맥(B 사본 ⊕ 저장소 A ⊕ forced)을 읽는다 */
const show = (id: string, expr: string): Node => ({ id: `show_${id}`, type: 'basic', name: 'Span', props: { 'data-testid': id }, text: `{{${expr}}}` });
const button = (text: string, actions: any[]): Node => ({ id: `btn_${text}`, type: 'basic', name: 'Button', text, actions: [{ type: 'click', handler: 'sequence', actions }] });

/** 상품 상세 옵션 칸과 같은 모양 — 칸 하나를 고르면 setState 로 선택을 쓰고, 핸들러가 다 골랐는지 보고 줄을 담은 뒤 선택을 비운다 */
const pick = (group: string, value: string): Node => button(`${group}:${value}`, [
    { handler: 'setState', params: { target: 'local', currentSelection: `{{Object.assign({}, _local.currentSelection ?? {}, { '${group}': '${value}' })}}` } },
    { handler: 'test.addIfComplete', params: { currentSelection: '{{_local.currentSelection ?? {}}}', items: '{{_local.items ?? []}}', newGroupName: group, newValue: value } },
]);

/** 줄 동작(「+」) — 줄 목록만 깊게 쓴다(`currentSelection` 을 싣지 않는다) */
const plus = (): Node => button('plus', [{ handler: 'test.bump', params: { items: '{{_local.items ?? []}}' } }]);

const PAGE: Node[] = [
    pick('색상', '네이비'), pick('색상', '블랙'), pick('사이즈', 'L'), pick('사이즈', 'M'), plus(),
    button('reset-deep', [{ handler: 'setState', params: { target: 'local', currentSelection: {} } }]),
    button('pick-black-shallow', [{ handler: 'test.shallowPick' }]),
    button('setlocal-shallow-clear', [{ handler: 'test.setLocalShallowClear' }]),
    button('setlocal-shallow-twice', [{ handler: 'test.setLocalShallowTwice' }]),
    button('clear-filters', [{ handler: 'test.clearFilters' }]),
    show('cs', 'JSON.stringify(_local.currentSelection ?? {})'),
    show('items', "(_local.items ?? []).map(i => i.id + 'x' + i.qty).join(',')"),
    show('filters', 'JSON.stringify(_local.filters ?? null)'),
];

/**
 * 핸들러 등록 — 줄 담기는 회피 수정(null 선행) 없이 shallow `{}` 한 번만 쓴다(엔진이 그 의미를 지켜야 한다)
 *
 * @param dispatcher 디스패처
 */
function registerHandlers(dispatcher: any): void {
    dispatcher.registerHandler('test.addIfComplete', (action: any, ctx: any) => {
        const p = action.params ?? {};
        const sel = { ...(p.currentSelection ?? {}), [p.newGroupName]: p.newValue };
        if (!sel['색상'] || !sel['사이즈']) return;
        const items = [...(p.items ?? []), { id: `${sel['색상']}_${sel['사이즈']}`, qty: 1 }];
        ctx.setState({ items, currentSelection: {}, __mergeMode: 'shallow' });
    });
    dispatcher.registerHandler('test.bump', (action: any, ctx: any) => {
        const items = (action.params?.items ?? []).map((i: any) => ({ ...i, qty: i.qty + 1 }));
        ctx.setState({ items });
    });
    dispatcher.registerHandler('test.shallowPick', (_a: any, ctx: any) => {
        ctx.setState({ currentSelection: { 색상: '블랙' }, __mergeMode: 'shallow' });
    });
    dispatcher.registerHandler('test.setLocalShallowClear', () => {
        (window as any).G7Core.state.setLocal({ currentSelection: {} }, { merge: 'shallow' });
    });
    // 같은 핸들러 안에서 setLocal shallow 를 두 번(고름 → 비움) — forced·override 키가 shallow 로 남아야 다음 쓰기·화면이 앞 값을 못 되살린다
    dispatcher.registerHandler('test.setLocalShallowTwice', () => {
        const core = (window as any).G7Core;
        core.state.setLocal({ currentSelection: { 색상: '네이비', 사이즈: 'L' } }, { merge: 'shallow', render: false });
        core.state.setLocal({ currentSelection: {} }, { merge: 'shallow', render: false });
    });
    dispatcher.registerHandler('test.clearFilters', (_a: any, ctx: any) => {
        ctx.setState({ filters: {}, __mergeMode: 'shallow' });
    });
}

interface Mounted {
    app: any;
    stop: () => void;
}

async function mount(local: Record<string, any> = { items: [], currentSelection: {} }): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = registryEntries();
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));
    registerHandlers(dispatcher);

    app.setGlobalState({ _local: local }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/render-base-lag-clear',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: PAGE }],
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates) },
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
            (window as any).__g7LayoutContextStack = [];
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
/** 운영의 「다른 전역 쓰기로 루트가 다시 그려짐」(데이터 도착·전역 setState) — 그 순간의 B 가 렌더 사본이 된다 */
async function rootRerender(app: any, tick: number): Promise<void> {
    app.setGlobalState({ __probeTick: tick });
    await settle(60);
}
const liveB = (app: any): Record<string, any> => app.getGlobalState()._local;

describe('[case:state-setstate-50] 렌더 병합의 늦은 B 사본 — 비운 값이 화면·B 에 되살아나지 않는다 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    /**
     * @scenario case=handler_shallow_empty
     * @effects shallow_write_honored_in_store_a
     */
    it('커스텀 핸들러의 shallow `{}` 한 번으로 선택이 비고 화면도 빈 값이다 (저장소 A 함수형 갱신이 shallow 를 지킴)', async () => {
        m = await mount();
        await click('색상:네이비');
        await click('사이즈:L');
        await waitFor(() => expect(text('items')).toBe('네이비_Lx1'));

        expect(liveB(m.app).currentSelection).toEqual({});
        expect((window as any).G7Core.state.getLocal().currentSelection).toEqual({});
        expect(text('cs')).toBe('{}');
    });

    /**
     * @scenario case=handler_shallow_empty_after_root_rerender
     * @effects lagging_render_base_does_not_revive_cleared_keys
     */
    it('색상과 사이즈 사이에 루트가 다시 그려져도(늦은 B 사본에 색상) 줄이 담긴 뒤 화면은 빈 값이다', async () => {
        m = await mount();
        await click('색상:네이비');
        await rootRerender(m.app, 1);
        await click('사이즈:L');
        await waitFor(() => expect(text('items')).toBe('네이비_Lx1'));

        expect(liveB(m.app).currentSelection).toEqual({});
        expect(text('cs')).toBe('{}');
    });

    /**
     * @scenario case=handler_shallow_empty_after_root_rerender_then_line_action
     * @effects line_action_does_not_write_back_lagging_keys
     */
    it('그 상태에서 줄 동작(「+」)을 눌러도 B·getLocal·화면에 앞 선택이 되살아나지 않고, 윗칸만 다시 골라도 줄이 담기지 않는다', async () => {
        m = await mount();
        await click('색상:네이비');
        await rootRerender(m.app, 1);
        await click('사이즈:L');
        await waitFor(() => expect(text('items')).toBe('네이비_Lx1'));

        await click('plus');
        await waitFor(() => expect(text('items')).toBe('네이비_Lx2'));
        expect(liveB(m.app).currentSelection).toEqual({});
        expect((window as any).G7Core.state.getLocal().currentSelection).toEqual({});
        expect(text('cs')).toBe('{}');

        await click('색상:블랙');
        expect(text('items')).toBe('네이비_Lx2');
        expect(JSON.parse(text('cs'))).toEqual({ 색상: '블랙' });
        await click('사이즈:M');
        await waitFor(() => expect(text('items')).toBe('네이비_Lx2,블랙_Mx1'));
        expect(text('cs')).toBe('{}');
    });

    /**
     * @scenario case=setstate_deep_empty_after_root_rerender
     * @effects lagging_render_base_does_not_revive_cleared_keys
     */
    it('레이아웃 setState `{}`(deep, engine-v1.81.1 교체) 도 늦은 B 사본 위에서 화면을 비운다', async () => {
        m = await mount();
        await click('색상:네이비');
        await rootRerender(m.app, 1);
        await click('reset-deep');

        expect(liveB(m.app).currentSelection).toEqual({});
        expect(text('cs')).toBe('{}');
    });

    /**
     * @scenario case=handler_shallow_object_after_root_rerender
     * @effects lagging_render_base_does_not_revive_cleared_keys
     */
    it('shallow 로 키를 줄인 객체(`{색상: 블랙}`)가 늦은 B 사본의 사이즈를 되살리지 않는다', async () => {
        m = await mount();
        await click('색상:네이비');
        // 사이즈를 setState 로만 써서 줄이 담기지 않게: 색상·사이즈를 담은 뒤 사본을 만든다
        (window as any).G7Core.state.setLocal({ currentSelection: { 색상: '네이비', 사이즈: 'L' } });
        await rootRerender(m.app, 1);
        expect(JSON.parse(text('cs'))).toEqual({ 색상: '네이비', 사이즈: 'L' });

        await click('pick-black-shallow');
        expect(liveB(m.app).currentSelection).toEqual({ 색상: '블랙' });
        expect(JSON.parse(text('cs'))).toEqual({ 색상: '블랙' });
    });

    /**
     * @scenario case=setlocal_shallow_empty
     * @effects shallow_write_honored_in_store_a
     */
    it('핸들러가 G7Core.state.setLocal(merge: shallow) 로 `{}` 를 써도 저장소 A·forced 가 앞 선택을 지키지 않는다', async () => {
        m = await mount();
        await click('색상:네이비');
        await click('setlocal-shallow-clear');
        await settle(60);

        expect(liveB(m.app).currentSelection).toEqual({});
        expect(text('cs')).toBe('{}');
    });

    /**
     * @scenario case=setlocal_shallow_twice_then_line_action
     * @effects shallow_write_honored_in_store_a, line_action_does_not_write_back_lagging_keys
     */
    it('한 핸들러가 setLocal shallow 로 고른 뒤 바로 `{}` 로 비우면 화면·forced 덧칠·다음 줄 동작 모두 빈 값이다', async () => {
        m = await mount({ items: [{ id: '네이비_L', qty: 1 }], currentSelection: {} });
        await click('setlocal-shallow-twice');
        expect(liveB(m.app).currentSelection).toEqual({});
        expect(text('cs')).toBe('{}');

        await click('plus');
        await waitFor(() => expect(text('items')).toBe('네이비_Lx2'));
        expect(text('cs')).toBe('{}');
        expect((window as any).G7Core.state.getLocal().currentSelection).toEqual({});
    });

    /**
     * @scenario case=canonical_b_key_kept
     * @effects canonical_b_keys_survive_render_merge
     */
    it('정본 B 에 있는 키(selfManaged 플러그인이 B 에만 쓴 값)는 저장소 A 가 `{}` 여도 화면에 남는다 (금지 「A 의 {} 가 B 서버 값을 지움」 회피)', async () => {
        m = await mount({ items: [], currentSelection: {}, filters: { brand: 'old' } });
        await click('clear-filters');
        expect(text('filters')).toBe('{}');

        (window as any).G7Core.state.setLocal({ 'filters.brand': 'acme' }, { render: false, selfManaged: true });
        await rootRerender(m.app, 1);

        expect(liveB(m.app).filters).toEqual({ brand: 'acme' });
        expect(JSON.parse(text('filters'))).toEqual({ brand: 'acme' });
    });

    /**
     * @scenario case=setlocal_deep_empty_noop
     * @effects setlocal_deep_empty_stays_noop
     */
    it('setLocal 기본(deep) 의 `{}` 는 종전처럼 아무것도 바꾸지 않는다 (공개 계약 무변경)', async () => {
        m = await mount({ items: [], currentSelection: {}, filters: { brand: 'acme' } });
        (window as any).G7Core.state.setLocal({ filters: {} });
        await settle(60);

        expect(liveB(m.app).filters).toEqual({ brand: 'acme' });
        expect(JSON.parse(text('filters'))).toEqual({ brand: 'acme' });
    });
});
