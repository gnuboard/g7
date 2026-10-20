// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 의 modals 섹션 렌더러·미뤄진 전역 다시 그리기)로 전역 자동바인딩 폼의 쓰기 base 를 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인(관리자 메뉴 폼)(번들 밖 같은 꼴 폼 재현 시나리오) 재실측이 덮는다
/**
 * @file DynamicRenderer.globalAutoBindingWriteBase.test.tsx
 * @description `data-key="_global.*"` 폼 자동바인딩이 칸마다 렌더 시점 `_global` 사본을 통째로 쓰던 결함 (7.1.1 원인)
 *
 * 자동바인딩 쓰기(`DynamicRenderer` `performStateUpdate`)의 base 는 `parentFormContext.state` 다. 전역 폼이면 그 값은
 * 「마지막 렌더 때의 `dataContext._global` 스냅샷」 이고, 엔진은 그 위에 이번 칸만 얹은 **전역 전체 사본**을
 * `_globalSetState`(TemplateApp.setGlobalState, `{...globalState, ...updates}`)로 넘겼다. 전역 다시 그리기는 동적 import 뒤
 * `startTransition` 으로 미뤄지므로, 다음 칸의 첫 글자가 그 렌더보다 먼저 오면 앞 칸 값이 없는 사본이 앞 칸을 지웠다
 * (주문서 4칸 중 1칸만 남음). 같은 사본에 `_local`(저장소 B)도 들어 있어 그사이 플러그인이 `setLocal` 로 쓴 B 도
 * 되돌렸고, 그 사본을 `__g7PendingLocalState`(지역 상태 캐시)에 실어 그 칸의 지역 setState 가 전역 키 전부를 `_local` 에 보충했다.
 *
 * 고침: 전역 폼 칸은 쓰기 시점 상태를 받는 함수형 갱신(`prev => setNestedValue(prev, 경로, 값)`)으로 쓰고 pending 을 건드리지
 * 않는다 — 같은 파일 `_localInit` 동기화(engine-v1.54.5)와 같은 원칙(「base 는 렌더 시점 스냅샷이 아니라 쓰기 시점 prev」).
 *
 * @scenario-manifest tests/scenarios/global-autobinding-write-base.yaml
 *
 * @effects rapid_field_switch_keeps_all_fields, global_form_write_keeps_store_b, global_form_local_action_no_pending_leak
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

/**
 * 창 대역 — 닫히면 아무것도 그리지 않고(창 루트 렌더러는 영구 마운트), 닫기 단추가 onClose 를 부른다.
 *
 * @param props 창 속성
 * @returns 창
 */
function ModalStandIn({ isOpen, onClose, children }: any) {
    if (!isOpen) return null;

    return React.createElement('section', { role: 'dialog' },
        React.createElement('button', { type: 'button', 'aria-label': 'close', onClick: (e: any) => onClose?.(e) }, 'x'),
        children);
}

/** 부품 — 창은 대역, 나머지는 DOM 대역 */
function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Modal: c(ModalStandIn),
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        Button: c(passthrough('button')),
        Input: c(({ name, value, onChange }: any) =>
            React.createElement('input', { name, value: value ?? '', onChange: onChange ?? (() => {}) })),
    };
}

const FIELDS = ['name', 'recipient_name', 'recipient_phone', 'address_detail'];
const inputNode = (name: string, actions?: any[]): Node => ({ id: `in_${name}`, type: 'basic', name: 'Input', props: { name }, ...(actions ? { actions } : {}) });

/** 창 — `data-key="_global.addrModal.editing"` 폼 4칸 + 저장(POST 본문 = 폼 값) */
const addressModal: Node = {
    id: 'addr_modal',
    type: 'composite',
    name: 'Modal',
    props: {},
    children: [
        {
            id: 'addr_form',
            type: 'basic',
            name: 'Div',
            dataKey: '_global.addrModal.editing',
            children: FIELDS.map((f) => inputNode(f)),
        },
        {
            id: 'addr_save',
            type: 'basic',
            name: 'Button',
            text: 'save',
            actions: [{ type: 'click', handler: 'apiCall', target: '/api/test/addresses', params: { method: 'POST', body: '{{_global.addrModal.editing}}' } }],
        },
    ],
};

/** 페이지 — 「수정」(앞 배송지 185 를 채워 연다)·「새 배송지」(빈 등록 폼으로 연다). 여는 쪽은 null 을 먼저 쓴다(번들 표준) */
const openEdit: Node = {
    id: 'open_edit', type: 'basic', name: 'Button', text: 'open-edit',
    actions: [{
        type: 'click', handler: 'sequence', actions: [
            { handler: 'setState', params: { target: 'global', addrModal: null } },
            { handler: 'setState', params: { target: 'global', addrModal: { editing: { id: 185, name: '별장', recipient_name: '홍길동', recipient_phone: '010-1111-2222', address_detail: '1층' } } } },
            { handler: 'openModal', target: 'addr_modal' },
        ],
    }],
};
const openNew: Node = {
    id: 'open_new', type: 'basic', name: 'Button', text: 'open-new',
    actions: [{
        type: 'click', handler: 'sequence', actions: [
            { handler: 'setState', params: { target: 'global', addrModal: null } },
            { handler: 'setState', params: { target: 'global', addrModal: { editing: { is_default: false } } } },
            { handler: 'openModal', target: 'addr_modal' },
        ],
    }],
};

/** 페이지 안 전역 폼(창 밖) — 칸 하나는 change 동작으로 지역 상태도 쓴다(pending 오염 경로) */
const pageGlobalForm: Node = {
    id: 'page_form',
    type: 'basic',
    name: 'Div',
    dataKey: '_global.menuForm',
    children: [
        inputNode('slug', [{ type: 'change', handler: 'setState', params: { target: 'local', slugTouched: true } }]),
        inputNode('url'),
    ],
};

interface Mounted {
    app: any;
    fetchCalls: Array<{ url: string; method: string; body: any }>;
    stop: () => void;
}

/**
 * 운영과 같은 구조(TemplateApp 전역 상태 + renderTemplate 의 modals 섹션 렌더러)로 띄운다.
 *
 * @param page 페이지 노드
 * @param modals 창 노드
 * @returns 띄운 앱과 요청 기록
 */
async function mount(page: Node[], modals: Node[] = []): Promise<Mounted> {
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

    app.setGlobalState({ currentUser: { uuid: 'u-1' }, _local: {} }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/global-autobinding-write-base',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: page }],
            modals,
        } as any,
        // 운영 TemplateApp 이 렌더 문맥에 싣는 전역 갱신기(TemplateApp.ts _globalSetState) — 전역 폼 자동바인딩이 이것으로 쓴다
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

const dialog = (): HTMLElement | null => document.querySelector('[role="dialog"]');
const pageEl = (): HTMLElement => document.querySelector('[data-testid="page"]') as HTMLElement;
const buttonByText = (scope: ParentNode, text: string): HTMLElement =>
    Array.from(scope.querySelectorAll('button')).find((b) => (b.textContent ?? '') === text) as HTMLElement;

/**
 * 칸들을 차례로 친다 — 칸 사이 쉼 없이, 글자 사이 대기 없이(같은 매크로태스크 — 미뤄진 전역 렌더가 끼지 않는다).
 *
 * @param scope 칸을 찾을 영역
 * @param fields [칸 이름, 칠 문자열]
 * @param prefix 칸에 이미 있던 값(끝에 덧붙여 친다)
 */
async function typeFieldsNoGap(scope: () => ParentNode, fields: Array<[string, string]>, prefix: Record<string, string> = {}): Promise<void> {
    for (const [name, text] of fields) {
        const base = prefix[name] ?? '';
        for (let i = 1; i <= text.length; i += 1) {
            const el = scope().querySelector(`input[name="${name}"]`) as HTMLInputElement;
            fireEvent.change(el, { target: { value: base + text.slice(0, i) } });
        }
    }
    await new Promise((r) => setTimeout(r, 200));
}

const FOUR: Array<[string, string]> = [['name', '별장2'], ['recipient_name', '이몽룡'], ['recipient_phone', '010-5555-6666'], ['address_detail', '101동 1203호']];

describe('[case:state-setstate-46] 전역 data-key 자동바인딩 — 칸마다 렌더 시점 사본이 아니라 쓰기 시점 전역에 쓴다', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    // @scenario store=global, flow=edit_close_then_add, typing=no_gap
    // @effects rapid_field_switch_keeps_all_fields
    it('수정 창을 닫고 「새 배송지」 로 연 뒤 4칸을 쉼 없이 치면 상태·POST 본문에 4칸이 모두 남고 앞 배송지 값이 섞이지 않는다', async () => {
        m = await mount([openEdit, openNew], [addressModal]);
        fireEvent.click(buttonByText(pageEl(), 'open-edit'));
        await waitFor(() => expect((dialog()?.querySelector('input[name="recipient_name"]') as HTMLInputElement | null)?.value).toBe('홍길동'));
        fireEvent.click(dialog()!.querySelector('button[aria-label="close"]') as HTMLElement);
        await waitFor(() => expect(dialog()).toBeNull());

        fireEvent.click(buttonByText(pageEl(), 'open-new'));
        await waitFor(() => expect((dialog()?.querySelector('input[name="recipient_name"]') as HTMLInputElement | null)?.value).toBe(''));

        await typeFieldsNoGap(() => dialog()!, FOUR);

        const editing = m.app.getGlobalState().addrModal?.editing ?? {};
        // 종전: 마지막 칸만 남고 앞 3칸은 undefined (4칸 중 1칸만 남음)
        expect(FOUR.map(([k]) => editing[k])).toEqual(FOUR.map(([, v]) => v));
        expect(editing.id ?? null).toBeNull();
        expect(editing.is_default).toBe(false);
        await waitFor(() => expect((dialog()!.querySelector('input[name="name"]') as HTMLInputElement).value).toBe('별장2'));

        fireEvent.click(buttonByText(dialog()!, 'save'));
        await waitFor(() => expect(m!.fetchCalls.some((c) => c.method === 'POST')).toBe(true));
        const body = m.fetchCalls.find((c) => c.method === 'POST')!.body;
        expect(FOUR.map(([k]) => body?.[k])).toEqual(FOUR.map(([, v]) => v));
        expect(body?.id ?? null).toBeNull();
    });

    // @scenario store=global, flow=edit, typing=no_gap
    // @effects rapid_field_switch_keeps_all_fields
    it('수정 폼에서 배송지명을 고치고 쉼 없이 다음 칸을 치면 바뀐 배송지명이 남는다', async () => {
        m = await mount([openEdit], [addressModal]);
        fireEvent.click(buttonByText(pageEl(), 'open-edit'));
        await waitFor(() => expect((dialog()?.querySelector('input[name="name"]') as HTMLInputElement | null)?.value).toBe('별장'));

        await typeFieldsNoGap(() => dialog()!, [['name', ' 2층'], ['recipient_name', 'A'], ['address_detail', ' B']], { name: '별장', recipient_name: '홍길동', address_detail: '1층' });

        const editing = m.app.getGlobalState().addrModal?.editing ?? {};
        // 종전: name 이 「별장」 그대로(「배송지명 변경이 PUT 에서 빠짐」)
        expect([editing.name, editing.recipient_name, editing.address_detail]).toEqual(['별장 2층', '홍길동A', '1층 B']);
        expect(editing.id).toBe(185);
    });

    // @scenario store=global, flow=plugin_setlocal_between_keys, typing=no_gap
    // @effects global_form_write_keeps_store_b
    it('전역 폼 칸 입력 사이에 플러그인이 setLocal(render:false) 로 쓴 저장소 B 값을 다음 키가 되돌리지 않는다', async () => {
        m = await mount([pageGlobalForm]);
        const url = () => pageEl().querySelector('input[name="url"]') as HTMLInputElement;
        fireEvent.change(url(), { target: { value: '/a' } });
        (window as any).G7Core.state.setLocal({ editorBody: '<p>본문</p>' }, { render: false, selfManaged: true });
        fireEvent.change(url(), { target: { value: '/ab' } });
        await new Promise((r) => setTimeout(r, 200));

        // 종전: 렌더 시점 전역 사본의 `_local`(본문 없음)이 B 를 통째로 되돌렸다
        expect(m.app.getGlobalState()._local?.editorBody).toBe('<p>본문</p>');
        expect(m.app.getGlobalState().menuForm?.url).toBe('/ab');
    });

    // @scenario store=global, flow=local_action_on_global_field, typing=single
    // @effects global_form_local_action_no_pending_leak
    it('전역 폼 칸의 change 동작이 지역 setState 를 해도 `_local` 에 전역 키가 섞이지 않는다', async () => {
        m = await mount([pageGlobalForm]);
        const slug = () => pageEl().querySelector('input[name="slug"]') as HTMLInputElement;
        fireEvent.change(slug(), { target: { value: 'menu-a' } });
        await waitFor(() => expect(m!.app.getGlobalState()._local?.slugTouched).toBe(true));
        await new Promise((r) => setTimeout(r, 100));

        const local = m.app.getGlobalState()._local ?? {};
        // 종전: 전역 사본을 실은 pending 이 지역 setState 의 base 가 되어 currentUser·menuForm·_local 이 `_local` 에 보충됐다
        expect(Object.keys(local).filter((k) => ['currentUser', 'menuForm', '_local'].includes(k))).toEqual([]);
        const viaGetLocal = (window as any).G7Core.state.getLocal() ?? {};
        expect(Object.keys(viaGetLocal).filter((k) => ['currentUser', 'menuForm', '_local'].includes(k))).toEqual([]);
        expect(m.app.getGlobalState().menuForm?.slug).toBe('menu-a');
    });

    // @scenario store=global, flow=track_changes, typing=single
    // @effects rapid_field_switch_keeps_all_fields
    it('trackChanges 전역 폼은 종전처럼 `_global.hasChanges` 를 세운다', async () => {
        const tracked: Node = { ...pageGlobalForm, trackChanges: true, children: [inputNode('url')] };
        m = await mount([tracked]);
        fireEvent.change(pageEl().querySelector('input[name="url"]') as HTMLInputElement, { target: { value: '/x' } });
        await new Promise((r) => setTimeout(r, 100));
        expect(m.app.getGlobalState().hasChanges).toBe(true);
        expect(m.app.getGlobalState().menuForm?.url).toBe('/x');
    });
});
