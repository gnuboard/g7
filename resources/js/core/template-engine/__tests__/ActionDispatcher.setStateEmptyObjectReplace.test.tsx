// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate·modals 섹션 렌더러·저장소 A React 상태)로 setState 빈 객체 교체를 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인(상품평 필터·구매 옵션 SPA 이동)(정책 창·통화 줄) 재실측이 덮는다
/**
 * @file ActionDispatcher.setStateEmptyObjectReplace.test.tsx
 * @description setState 기본(deep) 병합에서 빈 객체 `{}` 값이 기존 값을 비운다 (7.1.1 엔진 대안)
 *
 * deep 병합은 `{}` 를 「바꿀 키 없음」 으로 병합해 기존 값을 지우지 못했다. 같은 레이아웃의 다른 상품으로 옮긴 뒤 init 의
 * `reviewOptionFilters: {}` 가 앞 상품의 `{색상: 블랙}` 을 남겼다(7.1.0 의 레이아웃 빈 객체 보존부터 드러남 — 그 전에는 `[]` 로
 * 와서 통째로 덮였다). 번들 레이아웃은 그 키를 `null` 로 먼저 쓰는 2단계로 피했고, 엔진이 같은 2단계를 대신한다 —
 * 저장소 A(React 상태, 렌더 병합에서 이긴다)·저장소 B(`_global._local`)·`getLocal()`·sequence 다음 동작 모두 `{}` 를 본다.
 *
 * 모양: 최상위 `{}` · 중첩 `{}` · 점 경로 키 `{}` · 프로토타입 없는 빈 객체 · 비어 있지 않은 객체(종전 deep 유지) · `errors`(종전 교체)
 * · 배열(종전 교체). 대상: local(컴포넌트 문맥) · local(문맥 없는 dispatch — init 경로와 같은 전역 갱신기 갈래) · global · `$parent._local`
 * · `scope: parent` · isolated · 점 경로 target(종전부터 교체). merge shallow/replace 는 무변경.
 *
 * @scenario-manifest tests/scenarios/setstate-empty-object-replace.yaml
 *
 * @effects empty_object_clears_previous_value, non_empty_object_still_deep_merges
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

function ModalStandIn({ isOpen, onClose, children }: any) {
    if (!isOpen) return null;

    return React.createElement('section', { role: 'dialog' },
        React.createElement('button', { type: 'button', 'aria-label': 'close', onClick: (e: any) => onClose?.(e) }, 'x'),
        children);
}

function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Modal: c(ModalStandIn),
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        Button: c(passthrough('button')),
    };
}

/** 값 표시 — 렌더된 화면(저장소 A 가 이기는 병합 결과)을 읽는다 */
const show = (id: string, expr: string): Node => ({ id: `show_${id}`, type: 'basic', name: 'Span', props: { 'data-testid': id }, text: `{{${expr}}}` });
const button = (text: string, actions: any[]): Node => ({ id: `btn_${text}`, type: 'basic', name: 'Button', text, actions: [{ type: 'click', handler: 'sequence', actions }] });
const setLocal = (params: Record<string, any>) => ({ handler: 'setState', params: { target: 'local', ...params } });

interface Mounted {
    app: any;
    fetchCalls: Array<{ url: string; method: string; body: any }>;
    stop: () => void;
}

async function mount(page: Node[], modals: Node[] = [], local: Record<string, any> = {}): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    const fetchCalls: Mounted['fetchCalls'] = [];
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (url: string, init?: RequestInit) => {
        fetchCalls.push({ url: String(url), method: String(init?.method ?? 'GET').toUpperCase(), body: init?.body ? JSON.parse(String(init.body)) : null });

        return { ok: true, status: 200, json: async () => ({ success: true, data: {} }), text: async () => '{}', headers: new Headers({ 'Content-Type': 'application/json' }) } as Response;
    }) as any;

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
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));

    app.setGlobalState({ _local: local }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/setstate-empty-object-replace',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: page }],
            modals,
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates) },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="page"]')).not.toBeNull());

    return {
        app,
        fetchCalls,
        stop: () => {
            try { engine.destroyTemplate(); } catch { /* 정리 */ }
            globalThis.fetch = originalFetch;
            delete (window as any).__templateApp;
            delete (window as any).G7Core;
            (window as any).__g7PendingLocalState = null;
            (window as any).__g7ForcedLocalFields = undefined;
            (window as any).__g7SetLocalOverrideKeys = undefined;
            (window as any).__g7LayoutContextStack = [];
            document.body.innerHTML = '';
        },
    };
}

const text = (id: string): string => (document.querySelector(`[data-testid="${id}"]`)?.textContent ?? '');
const click = (label: string): void => {
    const b = Array.from(document.querySelectorAll('button')).find((x) => (x.textContent ?? '') === label) as HTMLElement;
    fireEvent.click(b);
};
const settle = () => new Promise((r) => setTimeout(r, 80));

const KEYS = "Object.keys(_local.filters ?? {}).join(',') || 'EMPTY'";

describe('[case:state-setstate-47] setState deep 병합 — 빈 객체 `{}` 가 기존 값을 비운다', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    // @scenario target=local_component, shape=top_level_empty
    // @effects empty_object_clears_previous_value
    it('local(컴포넌트 문맥): 필터를 고른 뒤 `filters: {}` 로 비우면 화면·저장소 B·getLocal() 이 모두 비고 다른 키는 남는다', async () => {
        m = await mount([
            show('keys', KEYS),
            show('keep', "_local.keep ?? ''"),
            button('pick', [setLocal({ filters: { 색상: '블랙' }, keep: 'k' })]),
            button('clear', [setLocal({ filters: {} })]),
        ]);
        click('pick');
        await waitFor(() => expect(text('keys')).toBe('색상'));
        click('clear');
        await settle();

        // 종전: 화면 「색상」(저장소 A 가 이김) · B `{색상:블랙}`
        expect(text('keys')).toBe('EMPTY');
        expect(m.app.getGlobalState()._local.filters).toEqual({});
        expect((window as any).G7Core.state.getLocal().filters).toEqual({});
        expect(text('keep')).toBe('k');
        expect(m.app.getGlobalState()._local.keep).toBe('k');
    });

    // @scenario target=local_component, shape=sequence_next_action
    // @effects empty_object_clears_previous_value
    it('같은 sequence 의 다음 apiCall 본문도 비운 값을 싣는다', async () => {
        m = await mount([
            show('keys', KEYS),
            button('pick', [setLocal({ filters: { 색상: '블랙' } })]),
            button('clear-send', [setLocal({ filters: {} }), { handler: 'apiCall', target: '/api/test/reviews', params: { method: 'POST', body: { filters: '{{_local.filters}}' } } }]),
        ]);
        click('pick');
        await waitFor(() => expect(text('keys')).toBe('색상'));
        click('clear-send');
        await waitFor(() => expect(m!.fetchCalls.some((c) => c.method === 'POST')).toBe(true));
        expect(m.fetchCalls.find((c) => c.method === 'POST')!.body).toEqual({ filters: {} });
    });

    // @scenario target=local_component, shape=nested_and_dot_key
    // @effects empty_object_clears_previous_value
    it('중첩 `{}`·점 경로 키 `{}`·프로토타입 없는 빈 객체도 그 자리만 비우고 형제 키는 남긴다', async () => {
        m = await mount([
            show('meta', "Object.keys(_local.form?.meta ?? {}).join(',') || 'EMPTY'"),
            show('opts', "Object.keys(_local.form?.opts ?? {}).join(',') || 'EMPTY'"),
            show('name', "_local.form?.name ?? ''"),
            button('fill', [setLocal({ form: { name: 'n', meta: { x: 1 }, opts: { y: 2 } } })]),
            button('clear-nested', [setLocal({ form: { meta: {} } })]),
            button('clear-dot', [setLocal({ 'form.opts': {} })]),
        ]);
        click('fill');
        await waitFor(() => expect(text('meta')).toBe('x'));
        click('clear-nested');
        await settle();
        expect(text('meta')).toBe('EMPTY');
        expect(text('opts')).toBe('y');
        expect(text('name')).toBe('n');
        click('clear-dot');
        await settle();
        expect(text('opts')).toBe('EMPTY');
        expect(m.app.getGlobalState()._local.form).toEqual({ name: 'n', meta: {}, opts: {} });

        // 프로토타입 없는 빈 객체 — 레이아웃 식 `Object.assign(Object.create(null), …)` 의 결과
        await (window as any).G7Core.dispatch({ handler: 'setState', params: { target: 'local', form: { meta: { z: 1 } } } });
        await settle();
        await (window as any).G7Core.dispatch({ handler: 'setState', params: { target: 'local', form: { meta: Object.create(null) } } });
        await settle();
        expect(m.app.getGlobalState()._local.form.meta).toEqual({});
    });

    // @scenario target=local_component, shape=non_empty_unchanged
    // @effects non_empty_object_still_deep_merges
    it('비어 있지 않은 객체는 종전처럼 deep 병합(형제 보존)하고, errors 는 종전처럼 통째 교체한다', async () => {
        m = await mount([
            show('keys', KEYS),
            show('errs', "Object.keys(_local.errors ?? {}).join(',') || 'EMPTY'"),
            button('pick', [setLocal({ filters: { 색상: '블랙' }, errors: { name: ['필수'] } })]),
            button('add', [setLocal({ filters: { 크기: 'L' }, errors: { email: ['필수'] } })]),
            button('clear-errors', [setLocal({ errors: {} })]),
        ]);
        click('pick');
        await waitFor(() => expect(text('keys')).toBe('색상'));
        click('add');
        await settle();
        expect(text('keys')).toBe('색상,크기');
        expect(text('errs')).toBe('email');
        click('clear-errors');
        await settle();
        expect(text('errs')).toBe('EMPTY');
        expect(text('keys')).toBe('색상,크기');
    });

    // @scenario target=local_component, shape=nested_and_dot_key
    // @effects empty_object_clears_previous_value
    it('펼침 키 `...` 로 들어온 `{}` 도 화면(저장소 A)·저장소 B 를 함께 비운다', async () => {
        m = await mount([
            show('keys', KEYS),
            button('pick', [setLocal({ filters: { 색상: '블랙' } })]),
            button('spread-clear', [setLocal({ '...': { filters: {} }, touched: true })]),
        ]);
        click('pick');
        await waitFor(() => expect(text('keys')).toBe('색상'));
        click('spread-clear');
        await settle();
        // 펼친 값을 선행 쓰기에서 빼면 B·getLocal 만 `{}` 이고 화면은 「색상」 으로 갈린다
        expect(text('keys')).toBe('EMPTY');
        expect(m.app.getGlobalState()._local.filters).toEqual({});
        expect(m.app.getGlobalState()._local.touched).toBe(true);
    });

    // @scenario target=local_dispatch_fallback, shape=top_level_empty
    // @effects empty_object_clears_previous_value
    it('컴포넌트 문맥 없는 dispatch(init 경로와 같은 전역 갱신기 갈래)도 비운다', async () => {
        // init_actions 처럼 저장소 B 에만 쓰는 갈래 — 고를 때도 같은 갈래로 쓴다(저장소 A 에 값이 없게)
        m = await mount([show('keys', KEYS)]);
        await (window as any).G7Core.dispatch({ handler: 'setState', params: { target: 'local', filters: { 색상: '블랙' } } });
        await waitFor(() => expect(text('keys')).toBe('색상'));
        await (window as any).G7Core.dispatch({ handler: 'setState', params: { target: 'local', filters: {} } });
        await settle();
        expect(m.app.getGlobalState()._local.filters).toEqual({});
        expect(text('keys')).toBe('EMPTY');
    });

    // @scenario target=global, shape=top_level_empty
    // @effects empty_object_clears_previous_value
    it('global: `prefs: {}` 가 앞 전역 값을 비운다', async () => {
        m = await mount([
            show('prefs', "Object.keys(_global.prefs ?? {}).join(',') || 'EMPTY'"),
            button('g-pick', [{ handler: 'setState', params: { target: 'global', prefs: { a: 1 } } }]),
            button('g-clear', [{ handler: 'setState', params: { target: 'global', prefs: {} } }]),
        ]);
        click('g-pick');
        await waitFor(() => expect(text('prefs')).toBe('a'));
        click('g-clear');
        await waitFor(() => expect(text('prefs')).toBe('EMPTY'));
        expect(m.app.getGlobalState().prefs).toEqual({});
    });

    // @scenario target=parent_local, shape=top_level_empty
    // @effects empty_object_clears_previous_value
    it('창의 `$parent._local`·`scope: parent` 쓰기도 페이지 저장소 A 의 값을 비운다', async () => {
        // 이 두 갈래는 페이지 저장소 A(레이아웃 컨텍스트 스택)에만 쓰고 저장소 B 는 일부러 쓰지 않는다(사례 29). 그래서 고를 때도
        // 같은 갈래로 써서 값이 저장소 A 에만 있게 한다 — 저장소 B 에 같은 키가 있으면 화면은 B 값이고 `null` → `{}` 2단계로도
        // 지워지지 않는 것은 종전과 같다(이 갈래의 구조 범위 밖)
        const modal: Node = {
            id: 'f_modal', type: 'composite', name: 'Modal', props: {},
            children: [
                button('parent-pick', [{ handler: 'setState', params: { target: '$parent._local', picked: { 색상: '블랙' } } }]),
                button('parent-clear', [{ handler: 'setState', params: { target: '$parent._local', picked: {} } }]),
                button('scope-pick', [{ handler: 'setState', params: { target: 'local', scope: 'parent', sort: { by: 'date' } } }]),
                button('scope-clear', [{ handler: 'setState', params: { target: 'local', scope: 'parent', sort: {} } }]),
            ],
        };
        m = await mount([
            show('picked', "Object.keys(_local.picked ?? {}).join(',') || 'EMPTY'"),
            show('sort', "Object.keys(_local.sort ?? {}).join(',') || 'EMPTY'"),
            button('open', [{ handler: 'openModal', target: 'f_modal' }]),
        ], [modal]);
        click('open');
        await waitFor(() => expect(document.querySelector('[role="dialog"]')).not.toBeNull());
        click('parent-pick');
        await waitFor(() => expect(text('picked')).toBe('색상'));
        click('parent-clear');
        await waitFor(() => expect(text('picked')).toBe('EMPTY'));
        click('scope-pick');
        await waitFor(() => expect(text('sort')).toBe('by'));
        click('scope-clear');
        await waitFor(() => expect(text('sort')).toBe('EMPTY'));
    });

    // 창에서 페이지 전역(`$parent._global`)을 쓰는 갈래 — 전역 갱신기로 가는 별도 병합(handleParentScopeSetState)이라 따로 잠근다.
    // 이 갈래는 두 겹으로 막혀 있다: 선행 null 쓰기(handleSetState 첫머리)와 본 쓰기 병합의 emptyObjectReplaces. 본 쓰기의 base 는
    // 선행 쓰기 직후의 live 전역이라 한 겹만 꺼서는 green 이고, 둘 다 꺼야 red('a')다(2026-10-19 실측) — 이 시험은 두 겹의 합을 잠근다.
    // @scenario target=parent_global, shape=top_level_empty
    // @effects empty_object_clears_previous_value
    it('창의 `$parent._global` 쓰기도 `{}` 로 앞 전역 값을 비우고 다른 전역 키는 남긴다', async () => {
        const modal: Node = {
            id: 'g_modal', type: 'composite', name: 'Modal', props: {},
            children: [
                button('pg-pick', [{ handler: 'setState', params: { target: '$parent._global', prefs: { a: 1 }, keepGlobal: 'g' } }]),
                button('pg-clear', [{ handler: 'setState', params: { target: '$parent._global', prefs: {} } }]),
            ],
        };
        m = await mount([
            show('prefs', "Object.keys(_global.prefs ?? {}).join(',') || 'EMPTY'"),
            show('keepGlobal', "_global.keepGlobal ?? ''"),
            button('open', [{ handler: 'openModal', target: 'g_modal' }]),
        ], [modal]);
        click('open');
        await waitFor(() => expect(document.querySelector('[role="dialog"]')).not.toBeNull());
        click('pg-pick');
        await waitFor(() => expect(text('prefs')).toBe('a'));
        click('pg-clear');
        await settle();
        expect(text('prefs')).toBe('EMPTY');
        expect(m.app.getGlobalState().prefs).toEqual({});
        expect((window as any).G7Core.state.get().prefs).toEqual({});
        expect(text('keepGlobal')).toBe('g');
    });

    // @scenario target=scope_parent, shape=stale_stack_snapshot
    // @effects empty_object_clears_previous_value
    it('`scope: parent` 의 병합 base(창을 열 때의 스택 스냅샷)에 앞 값이 있어도 같은 sequence 다음 동작은 비운 값을 본다', async () => {
        // 선행 쓰기는 스택 스냅샷을 갱신하지 않으므로, 본 쓰기 병합이 `{}` 를 그대로 두는 장치(emptyObjectReplaces)가 필요한 갈래
        const modal: Node = {
            id: 's_modal', type: 'composite', name: 'Modal', props: {},
            children: [
                button('scope-clear-send', [
                    { handler: 'setState', params: { target: 'local', scope: 'parent', sort: {} } },
                    { handler: 'apiCall', target: '/api/test/sort', params: { method: 'POST', body: { sort: '{{_local.sort}}' } } },
                ]),
            ],
        };
        m = await mount([
            show('sort', "Object.keys(_local.sort ?? {}).join(',') || 'EMPTY'"),
            button('pick', [setLocal({ sort: { by: 'date' } })]),
            button('open', [{ handler: 'openModal', target: 's_modal' }]),
        ], [modal]);
        click('pick');
        await waitFor(() => expect(text('sort')).toBe('by'));
        click('open');
        await waitFor(() => expect(document.querySelector('[role="dialog"]')).not.toBeNull());
        click('scope-clear-send');
        await waitFor(() => expect(m!.fetchCalls.some((c) => c.method === 'POST')).toBe(true));
        expect(m.fetchCalls.find((c) => c.method === 'POST')!.body).toEqual({ sort: {} });
    });

    // @scenario target=isolated, shape=top_level_empty
    // @effects empty_object_clears_previous_value
    it('isolated: 격리 상태의 `{}` 도 앞 값을 비운다', async () => {
        m = await mount([{
            id: 'iso', type: 'basic', name: 'Div', isolatedState: { picked: { a: 1 } }, isolatedScopeId: 'iso-f02',
            children: [
                show('iso', "Object.keys(_isolated.picked ?? {}).join(',') || 'EMPTY'"),
                button('iso-clear', [{ handler: 'setState', params: { target: 'isolated', picked: {} } }]),
            ],
        }]);
        await waitFor(() => expect(text('iso')).toBe('a'));
        click('iso-clear');
        await waitFor(() => expect(text('iso')).toBe('EMPTY'));
    });

    // @scenario target=local_component, shape=merge_modes_unchanged
    // @effects non_empty_object_still_deep_merges
    it('merge shallow·replace 와 점 경로 target 은 종전 동작 그대로다', async () => {
        m = await mount([
            show('keys', KEYS),
            show('keep', "_local.keep ?? ''"),
            button('pick', [setLocal({ filters: { 색상: '블랙' }, keep: 'k' })]),
            button('shallow', [{ handler: 'setState', params: { target: 'local', merge: 'shallow', filters: {} } }]),
            button('dot-target', [{ handler: 'setState', params: { target: '_local.filters', value: {} } }]),
        ]);
        click('pick');
        await waitFor(() => expect(text('keys')).toBe('색상'));
        click('shallow');
        await settle();
        expect(text('keys')).toBe('EMPTY');
        expect(text('keep')).toBe('k');
        click('pick');
        await waitFor(() => expect(text('keys')).toBe('색상'));
        click('dot-target');
        await settle();
        expect(text('keys')).toBe('EMPTY');
    });
});
