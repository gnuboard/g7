// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate·컴포넌트 이벤트 핸들러·named_actions)로 sequence 안 apiCall target 의 `_global` 해석을 잰다 — 브라우저 축은 관리자 템플릿 목록 「레이아웃 갱신」 첫 열기 실측(#888)이 덮는다
/**
 * @file ActionDispatcher.sequenceGlobalFreshness.test.tsx
 * @description 액션 `target` 의 `{{_global.*}}` 를 params 와 같이 지금의 전역 상태로 푼다 (engine-v1.96.2)
 *
 * 컴포넌트 이벤트 핸들러의 `context.data._global` 은 렌더 시점 사본이다. 액션 params 는 `evaluateExpression` 이
 * 저장소(`G7Core.state.get()`)의 지금 값으로 바꿔 끼워 읽지만, apiCall `target`(`resolveValue`)은 그 사본을 그대로 읽었다.
 * 그래서 관리자 템플릿 목록의 「레이아웃 갱신」 첫 열기에서 앞 단계가 쓴 `layoutPreviewRequest.url` 이 apiCall target 에서
 * 빈 값이 되어 요청이 현재 페이지 주소(`POST /admin/templates/user` → 405)로 나갔다. 다시 시도(새 이벤트)는 맞게 갔다.
 *
 * 단계 `if`·`conditions` 분기 판정은 바꾸지 않는다(이벤트 때 사본) — 번들 레이아웃이 그 동작에 기댄다: sirsoft-basic 로그인
 * 폼의 1·2단계 상호배타, 게시판 삭제 onSuccess 의 「deleteModal 비우기 → 댓글/글 분기」.
 *
 * 모양: switch case 배열 → 전역 setState → openModal → actionRef(named sequence) → apiCall target(실물 경로 재현)
 * · 복합 target · 앞 단계 onSuccess 가 쓴 값 · 단계 `if`/conditions 는 이벤트 때 값(삭제 분기·로그인 폼 보존)
 * · 전역을 읽지 않는 target·전역 쓰기 없는 sequence 무변화.
 *
 * @scenario-manifest tests/scenarios/action-target-latest-global.yaml
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

const show = (id: string, expr: string): Node => ({ id: `show_${id}`, type: 'basic', name: 'Span', props: { 'data-testid': id }, text: `{{${expr}}}` });
const button = (text: string, actions: any[]): Node => ({ id: `btn_${text}`, type: 'basic', name: 'Button', text, actions });

interface Mounted {
    app: any;
    fetchCalls: Array<{ url: string; method: string; body: any }>;
    stop: () => void;
}

interface MountOptions {
    modals?: Node[];
    namedActions?: Record<string, any>;
    /** 첫 렌더 전에 둔 전역 상태 — 이벤트 핸들러의 렌더 사본에 실린다 */
    initialGlobal?: Record<string, any>;
}

async function mount(page: Node[], options: MountOptions = {}): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    const fetchCalls: Mounted['fetchCalls'] = [];
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (url: string, init?: RequestInit) => {
        fetchCalls.push({ url: String(url), method: String(init?.method ?? 'GET').toUpperCase(), body: init?.body ? JSON.parse(String(init.body)) : null });

        return { ok: true, status: 200, json: async () => ({ success: true, data: {} }), text: async () => '{}', headers: new Headers({ 'Content-Type': 'application/json' }) } as Response;
    }) as any;

    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = registryEntries();
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));
    dispatcher.setNamedActions(options.namedActions ?? {});

    app.setGlobalState({ ...(options.initialGlobal ?? {}), _local: {} }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/sequence-global-freshness',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: page }],
            modals: options.modals ?? [],
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
const posts = (m: Mounted) => m.fetchCalls.filter((c) => c.method === 'POST');
const pathOf = (url: string) => new URL(url, 'http://localhost').pathname;
const settle = () => new Promise((r) => setTimeout(r, 80));

/** 관리자 템플릿 목록 `fetchLayoutPreview` 와 같은 모양의 named action */
const FETCH_PREVIEW = {
    handler: 'sequence',
    actions: [
        { handler: 'setState', params: { target: 'global', previewState: 'loading' } },
        {
            handler: 'apiCall',
            target: '{{_global.previewRequest?.url}}',
            params: {
                method: 'POST',
                body: '{{Object.assign({}, _global.previewRequest?.body ?? {}, {strategy: _global.syncStrategy ?? \'keep\'})}}',
            },
        },
    ],
};

describe('[case:state-setstate-57] 액션 target 의 `_global` 은 지금의 전역 상태 — 단계 if·conditions 는 이벤트 때 값', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    // @scenario source=nested_named_sequence, reader=action_target
    // @scenario source=step_set_state_global, reader=action_target
    // @effects target_reads_latest_global
    it('switch case → 전역 setState → openModal → actionRef(named sequence) 의 apiCall target 이 첫 클릭부터 새 url 로 나간다', async () => {
        m = await mount(
            [
                button('refresh', [{
                    type: 'click',
                    handler: 'switch',
                    params: { value: 'refresh_layouts' },
                    cases: {
                        refresh_layouts: [
                            { handler: 'setState', params: { target: 'global', syncStrategy: 'keep', previewRequest: "{{({url: '/api/admin/templates/refresh-layouts/preview', body: {template_name: 'sirsoft-basic'}})}}" } },
                            { handler: 'openModal', target: 'preview_modal' },
                            { actionRef: 'fetchPreview' },
                        ],
                    },
                }]),
            ],
            {
                modals: [{ id: 'preview_modal', type: 'composite', name: 'Modal', children: [show('state', "_global.previewState ?? ''")] }],
                namedActions: { fetchPreview: FETCH_PREVIEW },
            },
        );

        click('refresh');
        await waitFor(() => expect(posts(m!).length).toBe(1));

        // 종전: target 이 렌더 사본의 `_global` 로 풀려 빈 값 → 현재 페이지 주소로 POST (body 는 종전에도 새 값)
        const call = posts(m)[0];
        expect(pathOf(call.url)).toBe('/api/admin/templates/refresh-layouts/preview');
        expect(call.body).toEqual({ template_name: 'sirsoft-basic', strategy: 'keep' });
    });

    // @scenario source=step_set_state_global, reader=composite_target
    // @effects target_reads_latest_global
    it('복합 target(`/api/x/{{_global.id}}/touch`)도 앞 단계가 쓴 값을 쓴다', async () => {
        m = await mount([
            button('go', [{
                type: 'click',
                handler: 'sequence',
                actions: [
                    { handler: 'setState', params: { target: 'global', pickedId: 42 } },
                    { handler: 'apiCall', target: '/api/items/{{_global.pickedId}}/touch', params: { method: 'POST', body: {} } },
                ],
            }]),
        ]);

        click('go');
        await waitFor(() => expect(posts(m!).length).toBe(1));
        expect(pathOf(posts(m)[0].url)).toBe('/api/items/42/touch');
    });

    // @scenario source=prior_on_success, reader=action_target
    // @effects target_reads_latest_global
    it('앞 단계 apiCall 의 onSuccess 가 쓴 전역 값도 다음 단계 target 이 읽는다', async () => {
        m = await mount([
            button('chain', [{
                type: 'click',
                handler: 'sequence',
                actions: [
                    {
                        handler: 'apiCall',
                        target: '/api/first',
                        params: { method: 'POST', body: {} },
                        onSuccess: [{ handler: 'setState', params: { target: 'global', nextRequest: { url: '/api/second' } } }],
                    },
                    { handler: 'apiCall', target: '{{_global.nextRequest?.url}}', params: { method: 'POST', body: {} } },
                ],
            }]),
        ]);

        click('chain');
        await waitFor(() => expect(posts(m!).length).toBe(2));
        expect(posts(m).map((c) => pathOf(c.url))).toEqual(['/api/first', '/api/second']);
    });

    // @scenario source=step_set_state_global, reader=step_if
    // @scenario source=step_set_state_global, reader=conditions_branch
    // @effects step_condition_keeps_event_snapshot
    it('단계 `if`·conditions 는 종전처럼 이벤트 때 값으로 가른다 — 게시판 삭제 onSuccess 「deleteModal 비우기 → 댓글/글 분기」', async () => {
        m = await mount(
            [
                show('byIf', "_local.byIf ?? 'none'"),
                show('byCond', "_local.byCond ?? 'none'"),
                button('delete', [{
                    type: 'click',
                    handler: 'sequence',
                    actions: [
                        { handler: 'setState', params: { target: 'global', deleteModal: null } },
                        { handler: 'setState', if: "{{_global.deleteModal?.type === 'comment'}}", params: { target: 'local', byIf: 'comment' } },
                        {
                            handler: 'conditions',
                            conditions: [
                                { if: "{{_global.deleteModal?.type === 'comment'}}", then: { handler: 'setState', params: { target: 'local', byCond: 'comment' } } },
                                { then: { handler: 'setState', params: { target: 'local', byCond: 'post' } } },
                            ],
                        },
                    ],
                }]),
            ],
            { initialGlobal: { deleteModal: { type: 'comment' } } },
        );

        click('delete');
        await waitFor(() => expect(text('byCond')).not.toBe('none'), { timeout: 5_000 });
        expect(text('byIf')).toBe('comment');
        expect(text('byCond')).toBe('comment');
        expect(m.app.getGlobalState().deleteModal).toBeNull();
    });

    // @scenario source=prior_on_success, reader=step_if
    // @effects step_condition_keeps_event_snapshot
    it('로그인 폼처럼 앞 단계 onSuccess 가 심은 판정 키로 상호배타를 짠 단계는 한 제출에 하나만 돈다', async () => {
        m = await mount([
            button('submit', [{
                type: 'click',
                handler: 'sequence',
                actions: [
                    { handler: 'setState', params: { target: 'global', isLoggingIn: true } },
                    {
                        handler: 'apiCall',
                        if: '{{!_global.twoFactor?.required}}',
                        target: '/api/auth/login',
                        params: { method: 'POST', body: {} },
                        onSuccess: [{ handler: 'setState', params: { target: 'global', twoFactor: { required: true } } }],
                    },
                    { handler: 'apiCall', if: '{{_global.twoFactor?.required}}', target: '/api/auth/two-factor', params: { method: 'POST', body: {} } },
                ],
            }]),
        ]);

        click('submit');
        await waitFor(() => expect(posts(m!).length).toBe(1));
        await settle();
        expect(posts(m).map((c) => pathOf(c.url))).toEqual(['/api/auth/login']);
        expect(m.app.getGlobalState().twoFactor).toEqual({ required: true });
    });

    // @scenario source=none, reader=action_target
    // @scenario source=none, reader=composite_target
    // @effects target_reads_latest_global
    it('전역을 읽지 않는 target·전역 쓰기 없는 sequence 는 종전과 같다', async () => {
        m = await mount(
            [
                button('plain', [{
                    type: 'click',
                    handler: 'sequence',
                    actions: [
                        { handler: 'apiCall', target: "/api/plain/{{_global.missing ?? 'none'}}", params: { method: 'POST', body: {} } },
                        { handler: 'apiCall', target: '/api/plain/{{_global.fixed}}', params: { method: 'POST', body: {} } },
                        { handler: 'apiCall', target: '/api/plain/literal', params: { method: 'POST', body: {} } },
                    ],
                }]),
            ],
            { initialGlobal: { fixed: 'same' } },
        );

        click('plain');
        await waitFor(() => expect(posts(m!).length).toBe(3));
        expect(posts(m).map((c) => pathOf(c.url))).toEqual(['/api/plain/none', '/api/plain/same', '/api/plain/literal']);
    });
});
