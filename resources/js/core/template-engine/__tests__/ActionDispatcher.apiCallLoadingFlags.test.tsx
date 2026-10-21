// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 루트 렌더러·저장소 A React 상태·apiCall fetch)로 로딩 플래그의 시작·해제를 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(주문서 배송지 「선택」·글쓰기 「등록」 반복의 `_local` 길이)이 덮는다
/**
 * @file ActionDispatcher.apiCallLoadingFlags.test.tsx
 * @description apiCall 이 끝나면 로딩 플래그(`_local.loadingActions[id]`)가 저장소 A·B 모두에서 사라진다 (engine-v1.81.2, 7.1.1)
 *
 * apiCall 은 시작할 때 발화한 컴포넌트 상태에 `loadingActions[id] = true` 를 넣고 끝나면 뺀다. 종전에는 해제를 「클릭 시점 사본에서 키를
 * 생략한 객체」 로 썼는데 렌더러의 기본 깊은 병합은 키 생략을 「바꿀 키 없음」 으로 처리해, 끝난 플래그가 호출마다 쌓였다(7.0.0-beta.1 부터).
 * 렌더 문맥에 남은 플래그는 setState·setLocal 의 A 전용 키 보충으로 저장소 B 에도 옮겨져 `G7Core.state.get()._local` 이 단조 증가했고
 * (주문서 배송지 「선택」 10회 1571→2250자), 「하나라도 true 면 잠금」 으로 읽는 소비자(관리자 LoginForm 꼴)는 첫 요청 뒤 영구히 잠겼다.
 *
 * 하네스는 저장소 B 를 손으로 채우지 않는다 — 실제 writer(apiCall·setState 액션·`TemplateApp.setGlobalState`)만 거친다.
 *
 * @scenario-manifest tests/scenarios/apicall-loading-flags.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
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

/** LoginForm 꼴 복합 컴포넌트 — 주입된 `loadingActions` 에 true 가 하나라도 있으면 잠근다 */
const LoadingProbe: React.FC<any> = ({ loadingActions = {}, onClick }) => {
    const busy = Object.values(loadingActions).some((v) => v === true);

    return (
        <button type="button" data-testid="composite" disabled={busy} onClick={onClick}>
            {busy ? 'composite-busy' : 'composite-idle'}
        </button>
    );
};

function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        Button: c(passthrough('button')),
        LoadingProbe: c(LoadingProbe, { type: 'composite' }),
    };
}

const BUSY = 'Object.values(_local.loadingActions ?? {}).some(v => v === true)';
const show = (id: string, expr: string): Node => ({ id: `show_${id}`, type: 'basic', name: 'Span', props: { 'data-testid': id }, text: `{{${expr}}}` });
/** apiCall 단추 — lock 이면 진행 중 플래그가 하나라도 있으면 잠근다(스피너·잠금 단추 소비자) */
const apiButton = (text: string, target: string, lock = true): Node => ({
    id: `btn_${text}`, type: 'basic', name: 'Button', text, props: lock ? { disabled: `{{${BUSY}}}` } : {},
    actions: [{
        type: 'click', handler: 'apiCall', target, params: { method: 'POST', body: { n: 1 } },
        onSuccess: [
            { handler: 'setState', params: { target: 'local', saved: '{{response.data.n}}' } },
            { handler: 'setState', params: { target: 'local', savedAt: '{{response.data.at}}' } },
        ],
        onError: [{ handler: 'setState', params: { target: 'local', failed: '{{error.status ?? true}}' } }],
    }],
});

const PAGE: Node[] = [
    apiButton('save', '/api/save'),
    apiButton('other', '/api/other', false),
    // 요청 중에 누르는 다른 동작 — 렌더 문맥(진행 중 플래그 포함)을 사본으로 쓰는 setState
    { id: 'btn_pick', type: 'basic', name: 'Button', text: 'pick', actions: [{ type: 'click', handler: 'setState', params: { target: 'local', picked: 'yes' } }] },
    // onSuccess 없는 요청 — 응답 뒤 setState(대체 스냅샷을 지우는 쓰기)가 없다
    { id: 'btn_plain', type: 'basic', name: 'Button', text: 'plain', actions: [{ type: 'click', handler: 'apiCall', target: '/api/plain', params: { method: 'GET' } }] },
    // 요청 중에 눌러, 자기 요청이 끝난 뒤(await 뒤) 클릭 시점 사본으로 쓰는 동작 — 사본에는 겹친 요청의 진행 중 플래그가 있다
    { id: 'btn_late', type: 'basic', name: 'Button', text: 'late', actions: [{ type: 'click', handler: 'sequence', actions: [
        { handler: 'apiCall', target: '/api/late', params: { method: 'GET' } },
        { handler: 'setState', params: { target: 'local', after: 'done' } },
    ] }] },
    // 요청 중 커스텀 핸들러의 setLocal(render:false — 대체 스냅샷이 렌더 정리 없이 남는다) — 화면 문맥(진행 중 플래그 포함)을 A 전용 키 보충으로 B·getLocal 대체 스냅샷에 싣는다
    { id: 'btn_note', type: 'basic', name: 'Button', text: 'note', actions: [{ type: 'click', handler: 'test.setLocalNote' }] },
    { id: 'probe', type: 'composite', name: 'LoadingProbe', actions: [{ type: 'click', handler: 'apiCall', target: '/api/login', params: { method: 'POST' }, onError: [{ handler: 'setState', params: { target: 'local', loginFailed: true } }] }] },
    show('la', 'JSON.stringify(_local.loadingActions ?? {})'),
    show('busy', `${BUSY} ? 'busy' : 'idle'`),
];

/** 보류 중인 fetch — 대상별로 응답 시점을 시험이 정한다 */
interface Pending { url: string; resolve: (r: any) => void; reject: (e: any) => void }
let pending: Pending[] = [];

const okResponse = (data: any) => ({ ok: true, status: 200, statusText: 'OK', json: async () => ({ data }) });
const errorResponse = (status: number) => ({ ok: false, status, statusText: 'ERR', json: async () => ({ message: 'boom' }) });

/** 대상 주소의 가장 오래된 보류 요청에 응답한다 */
async function respond(urlPart: string, response: any): Promise<void> {
    const i = pending.findIndex((p) => p.url.includes(urlPart));
    if (i < 0) throw new Error(`no pending request for ${urlPart}`);
    const [p] = pending.splice(i, 1);
    p.resolve(response);
    await settle(80);
}

async function fail(urlPart: string, error: any): Promise<void> {
    const i = pending.findIndex((p) => p.url.includes(urlPart));
    if (i < 0) throw new Error(`no pending request for ${urlPart}`);
    const [p] = pending.splice(i, 1);
    p.reject(error);
    await settle(80);
}

interface Mounted {
    app: any;
    stop: () => void;
}

async function mount(local: Record<string, any> = { form: { title: '' } }): Promise<Mounted> {
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
    dispatcher.registerHandler('test.setLocalNote', () => {
        (window as any).G7Core.state.setLocal({ note: 'x' }, { render: false });
    });

    app.setGlobalState({ _local: local }, { render: false });
    await renderPage(app, 'test/apicall-loading-flags', PAGE);

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

async function renderPage(app: any, layoutName: string, children: Node[]): Promise<void> {
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: layoutName,
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children }],
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates) },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="page"]')).not.toBeNull());
}

const text = (id: string): string => (document.querySelector(`[data-testid="${id}"]`)?.textContent ?? '');
const flags = (): Record<string, any> => JSON.parse(text('la') || '{}');
const settle = (ms = 40): Promise<void> => new Promise((r) => setTimeout(r, ms));
const buttonOf = (label: string): HTMLButtonElement =>
    Array.from(document.querySelectorAll('button')).find((x) => (x.textContent ?? '') === label) as HTMLButtonElement;
async function click(label: string): Promise<void> {
    fireEvent.click(buttonOf(label));
    await settle();
}
const liveB = (app: any): Record<string, any> => app.getGlobalState()._local;
const engineFlagKeys = (obj: Record<string, any> | undefined): string[] => Object.keys(obj ?? {}).filter((k) => k.startsWith('apiCall_'));

describe('[case:state-setstate-55] apiCall 로딩 플래그 — 끝나면 저장소 A·B 에서 사라진다 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;
    let originalFetch: typeof fetch;

    beforeEach(() => {
        pending = [];
        originalFetch = globalThis.fetch;
        globalThis.fetch = vi.fn((url: any) => {
            const u = String(url);
            // 시험이 응답 시점을 정하는 대상만 보류한다(번역·CSRF 등 나머지는 곧바로 응답)
            if (!/\/api\/(save|other|login|late|plain|g1|g2)/.test(u)) return Promise.resolve(okResponse({}) as any);

            return new Promise((resolve, reject) => { pending.push({ url: u, resolve, reject }); });
        }) as any;
    });

    afterEach(() => {
        m?.stop();
        m = null;
        globalThis.fetch = originalFetch;
    });

    /**
     * @scenario case=repeat_success_with_onsuccess_setstate
     * @effects flags_cleared_in_store_a, flags_cleared_in_store_b, local_size_stable
     */
    it('성공 응답(onSuccess setState 2단)을 10회 반복해도 플래그 0 · `_local` 길이가 그대로다', async () => {
        m = await mount();
        const sizes: number[] = [];
        for (let i = 0; i < 10; i++) {
            await click('save');
            await respond('/api/save', okResponse({ n: 1, at: 'x' }));
            sizes.push(JSON.stringify(liveB(m.app)).length);
        }

        expect(flags()).toEqual({});
        expect(engineFlagKeys(liveB(m.app).loadingActions)).toEqual([]);
        expect(new Set(sizes).size, `_local 길이 ${sizes.join(',')}`).toBe(1);
        expect((window as any).G7Core.state.getLocal().loadingActions ?? {}).toEqual({});
    });

    /**
     * @scenario case=in_flight_spinner_and_lock
     * @effects in_flight_flag_visible, lock_released_after_completion
     */
    it('요청 중에는 플래그·잠금이 보이고, 끝나면 풀린다', async () => {
        m = await mount();
        await click('save');

        expect(Object.keys(flags())).toHaveLength(1);
        expect(text('busy')).toBe('busy');
        expect(buttonOf('save').disabled).toBe(true);

        await respond('/api/save', okResponse({ n: 1 }));

        expect(text('busy')).toBe('idle');
        expect(buttonOf('save').disabled).toBe(false);
    });

    /**
     * @scenario case=overlapping_calls
     * @effects other_in_flight_flag_kept, flags_cleared_in_store_a, flags_cleared_in_store_b
     */
    it('두 요청이 겹치면 먼저 끝난 쪽 플래그만 빠지고 다른 쪽은 남는다 — 나중 요청의 onSuccess 가 끝난 플래그를 되살리지 않는다', async () => {
        m = await mount();
        await click('save');
        const [first] = Object.keys(flags());
        await click('other');
        expect(Object.keys(flags())).toHaveLength(2);

        await respond('/api/save', okResponse({ n: 1 }));
        expect(Object.keys(flags()), '먼저 끝난 요청 플래그만 빠진다').toHaveLength(1);
        expect(Object.keys(flags())).not.toContain(first);
        expect(text('busy')).toBe('busy');

        await respond('/api/other', okResponse({ n: 2 }));
        expect(flags()).toEqual({});
        expect(engineFlagKeys(liveB(m.app).loadingActions)).toEqual([]);
    });

    /**
     * @scenario case=write_during_flight_copies_flag_to_b
     * @effects flags_cleared_in_store_b, lock_released_after_completion
     */
    it('요청 중에 누른 다른 setState 가 진행 중 플래그를 저장소 B 로 옮겨 적어도, 끝나면 B·화면 모두에서 빠진다', async () => {
        m = await mount();
        await click('save');
        await click('pick');
        // 렌더 문맥 사본의 A 전용 키 보충으로 B 에 진행 중 플래그가 옮겨진다(정상 — 진행 중이다)
        expect(engineFlagKeys(liveB(m.app).loadingActions)).toHaveLength(1);

        await respond('/api/save', okResponse({ n: 1 }));

        expect(engineFlagKeys(liveB(m.app).loadingActions)).toEqual([]);
        expect(flags()).toEqual({});
        expect(text('busy')).toBe('idle');
        expect(liveB(m.app).picked).toBe('yes');
    });

    /**
     * @scenario case=late_write_after_overlap
     * @effects flags_cleared_in_store_a, flags_cleared_in_store_b, lock_released_after_completion
     */
    it('겹친 요청 중에 누른 동작이 자기 요청 뒤(await 뒤) 클릭 시점 사본으로 쓴 뒤에도 끝난 플래그가 남지 않는다', async () => {
        m = await mount();
        await click('save');
        await click('late');
        expect(Object.keys(flags())).toHaveLength(2);

        await respond('/api/save', okResponse({ n: 1 }));
        // late 의 setState 는 save 진행 중 사본(save 플래그 포함)으로 쓴다 — save 가 끝난 뒤
        await respond('/api/late', okResponse({}));

        expect(liveB(m.app).after).toBe('done');
        expect(engineFlagKeys(liveB(m.app).loadingActions), '늦은 사본 쓰기가 B 에 되살린 끝난 플래그').toEqual([]);
        expect(engineFlagKeys((window as any).G7Core.state.getLocal().loadingActions)).toEqual([]);
        expect(flags()).toEqual({});
        expect(buttonOf('save').disabled).toBe(false);
    });

    /**
     * @scenario case=setlocal_snapshot_during_flight
     * @effects flags_cleared_in_store_b
     */
    it('요청 중 setLocal 이 플래그를 실은 getLocal 대체 스냅샷을 남겨도, 끝나면 getLocal() 에 플래그가 되살아나지 않는다', async () => {
        m = await mount();
        await click('plain');
        await click('note');
        expect(engineFlagKeys((window as any).G7Core.state.getLocal().loadingActions)).toHaveLength(1);

        await respond('/api/plain', okResponse({}));

        expect(engineFlagKeys((window as any).G7Core.state.getLocal().loadingActions)).toEqual([]);
        expect(engineFlagKeys(liveB(m.app).loadingActions)).toEqual([]);
        expect((window as any).G7Core.state.getLocal().note).toBe('x');
        expect(flags()).toEqual({});
    });

    /**
     * @scenario case=error_response
     * @effects flags_cleared_in_store_a, lock_released_after_completion
     */
    it('실패 응답(500)·네트워크 실패·중단 뒤에도 플래그 0 이다', async () => {
        m = await mount();
        await click('save');
        await respond('/api/save', errorResponse(500));
        expect(flags()).toEqual({});
        expect(buttonOf('save').disabled).toBe(false);

        await click('save');
        await fail('/api/save', new TypeError('Failed to fetch'));
        expect(flags()).toEqual({});

        await click('save');
        await fail('/api/save', new DOMException('aborted', 'AbortError'));
        expect(flags()).toEqual({});
        expect(engineFlagKeys(liveB(m.app).loadingActions)).toEqual([]);
    });

    /**
     * @scenario case=composite_consumer_lock
     * @effects lock_released_after_completion
     */
    it('주입된 loadingActions 로 잠그는 복합 컴포넌트(LoginForm 꼴)가 실패 응답 뒤 다시 풀린다', async () => {
        m = await mount();
        fireEvent.click(document.querySelector('[data-testid="composite"]') as HTMLElement);
        await settle();
        expect(text('composite')).toBe('composite-busy');

        await respond('/api/login', errorResponse(422));

        expect(text('composite')).toBe('composite-idle');
        expect((document.querySelector('[data-testid="composite"]') as HTMLButtonElement).disabled).toBe(false);
    });

    /**
     * @scenario case=custom_key_kept
     * @effects non_engine_keys_kept
     */
    it('레이아웃이 직접 넣은 다른 로딩 키는 apiCall 해제가 지우지 않는다', async () => {
        m = await mount({ form: { title: '' }, loadingActions: { upload: true } });
        // 'save' 는 upload 로 이미 잠겨 있다 — 잠금 식이 없는 단추로 낸다
        await click('other');
        expect(Object.keys(flags())).toHaveLength(2);
        await respond('/api/other', okResponse({ n: 1 }));

        expect(flags()).toEqual({ upload: true });
        expect(liveB(m.app).loadingActions).toEqual({ upload: true });
    });

    /**
     * @scenario case=complete_after_navigation
     * @effects flags_cleared_in_store_b
     */
    it('요청 중 다른 화면으로 옮긴 뒤 응답이 와도 새 화면 `_local` 에 플래그가 남지 않는다', async () => {
        m = await mount();
        await click('save');
        await click('pick');
        // 화면 이동 — handleRouteChange 처럼 `_local` 을 비우고 다른 레이아웃을 그린다
        m.app.setGlobalState({ _local: {} }, { render: false });
        await renderPage(m.app, 'test/apicall-loading-flags-next', [show('la', 'JSON.stringify(_local.loadingActions ?? {})')]);

        await respond('/api/save', okResponse({ n: 1 }));

        expect(flags()).toEqual({});
        expect(engineFlagKeys(liveB(m.app).loadingActions)).toEqual([]);
    });

    /**
     * @scenario case=modal_fallback_functional_writer
     * @effects flags_cleared_in_store_b
     */
    it('setState 없는 문맥이 연 창의 부모 문맥 writer(폴백)가 함수형 갱신을 지금 `_local` 로 풀어 쓴다 — `_local` 자리에 함수가 들어가지 않는다', async () => {
        m = await mount({ form: { title: 'A' } });
        const dispatcher = engine.getActionDispatcher()!;
        // DataSourceManager onSuccess 처럼 setState 없는 문맥에서 openModal — 스택에 폴백 writer 가 실린다
        await dispatcher.dispatchAction({ handler: 'openModal', target: 'probe_modal' } as any, { data: { _local: liveB(m.app) } } as any);
        const stack = (window as any).__g7LayoutContextStack;
        expect(stack.length).toBeGreaterThan(0);

        stack[stack.length - 1].setState((prev: any) => ({ ...prev, fromFn: 1 }));

        expect(typeof liveB(m.app)).toBe('object');
        expect(liveB(m.app).fromFn).toBe(1);
        expect(liveB(m.app).form).toEqual({ title: 'A' });
    });

    /**
     * @scenario case=global_fallback_overlap
     * @effects other_in_flight_flag_kept
     */
    it('액션 문맥 밖 전역 폴백 문맥(G7Core.dispatch·웹소켓 onReceive 와 같은 꼴) apiCall 두 개가 겹쳐도 먼저 끝난 쪽만 `_global.loadingActions` 에서 빠진다', async () => {
        m = await mount();
        const app = m.app;
        const dispatcher = engine.getActionDispatcher()!;
        // G7Core.dispatch 의 액션 문맥 없는 폴백(G7CoreGlobals)·TemplateApp 웹소켓 onReceive 와 같은 문맥 — setState 는 전역 상태 writer, state 는 그 순간 사본
        const fallback = () => ({ setState: (u: any) => app.setGlobalState(u), state: app.getGlobalState(), data: { ...app.getGlobalState() }, _isDispatchFallbackContext: true } as any);
        const p1 = dispatcher.dispatchAction({ handler: 'apiCall', target: '/api/g1', params: { method: 'GET' } } as any, fallback());
        await settle();
        const p2 = dispatcher.dispatchAction({ handler: 'apiCall', target: '/api/g2', params: { method: 'GET' } } as any, fallback());
        await settle();
        expect(engineFlagKeys(m.app.getGlobalState().loadingActions)).toHaveLength(2);

        await respond('/api/g1', okResponse({}));
        const left = engineFlagKeys(m.app.getGlobalState().loadingActions);
        expect(left, '겹친 다른 요청 플래그가 남아야 한다').toHaveLength(1);
        expect(left[0]).toContain('g2');

        await respond('/api/g2', okResponse({}));
        await Promise.all([p1, p2]);
        expect(engineFlagKeys(m.app.getGlobalState().loadingActions)).toEqual([]);
    });
});
