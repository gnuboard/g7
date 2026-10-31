// e2e:allow 실 TemplateApp·자동바인딩·setState 로 G7Core.state.getLocal() 이 낡은 setLocal 스냅샷을 돌려주던 결함을 잰다 — 브라우저 축은 7.1.1 출시 전 브라우저 재실측(이커머스 설정 › 통화 추가 → 이름 입력 → 취소 → getLocal)이 덮는다
/**
 * @file G7CoreGlobals.getLocalStaleSnapshot.test.tsx
 * @description 자동바인딩으로 친 뒤 명시적 setState 로 비우면 getLocal() 이 친 값을 계속 돌려주던 결함 (7.1.1)
 *
 * 자동바인딩은 칸마다 `G7Core.state.setLocal({render:false})` 로 저장소 B 에 쓰고, setLocal 은 그 결과를 `__g7LastSetLocalSnapshot` 에도
 * 남긴다(await 뒤 getLocal 대체 값, engine-v1.21.2). 이 스냅샷은 setLocal 덮어쓰기 기록이 있는 렌더에서만 지워져, 그 뒤 명시적 setState 가
 * 저장소 B 를 고쳐도 남는다. getLocal() 은 `deepMerge(B, 스냅샷)` 으로 낡은 값을 다시 얹었다 — 화면·저장소 B 는 빈 값인데 getLocal() 만
 * 앞 입력(통화 추가 → 이름 입력 → 취소). 커스텀 핸들러가 getLocal() 로 읽으면 지운 값이 되살아난다.
 *
 * @scenario-manifest tests/scenarios/getlocal-stale-setlocal-snapshot.yaml
 *
 * @effects getlocal_reflects_setstate_after_autobinding
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
        MultilingualInput: c(({ name, value, onChange }: any) => React.createElement('input', { 'data-testid': `ml-${name}`, value: value?.ko ?? '', onChange: (e: any) => onChange?.({ target: { name, value: { ...(value ?? {}), ko: e.target.value } }, _changedKeys: ['ko'] }) })),
        Input: c(({ name, value, onChange }: any) => React.createElement('input', { name, value: value ?? '', onChange: onChange ?? (() => {}) })),
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


describe('[case:state-setstate-49] 자동바인딩 입력 뒤 명시적 setState 로 비우면 getLocal() 도 빈 값이다', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    const formNode = {
        id: 'currency_form', type: 'basic', name: 'Div', dataKey: 'form',
        children: [{ id: 'new_name', type: 'basic', name: 'Input', props: { name: 'newCurrency.name' } }],
    };

    /** 이커머스 설정 › 언어·통화 「통화 추가」 줄과 같은 모양 — 여는 단추(null → 객체), if 로 그리는 줄, 다국어 이름 칸, 취소 */
    const currencyForm = {
        id: 'settings_content', type: 'basic', name: 'Div', dataKey: 'form', trackChanges: true,
        children: [
            show('nc', "_local.form?.newCurrency ? 'SET' : 'NONE'"),
            button('add', [
                setLocal({ 'form.newCurrency': null }),
                setLocal({ isAddingCurrency: true, 'form.newCurrency': { code: '', name: { ko: '', en: '' }, symbol: '' } }),
            ]),
            {
                id: 'add_row', type: 'basic', name: 'Div', if: '{{_local.isAddingCurrency}}',
                children: [
                    { id: 'new_name_ml', type: 'composite', name: 'MultilingualInput', props: { name: 'newCurrency.name', value: "{{_local.form?.newCurrency?.name ?? {} }}" } },
                    button('cancel', [setLocal({ isAddingCurrency: false, 'form.newCurrency': null })]),
                ],
            },
        ],
    };

    // @scenario writer=setstate_dot_key
    // @effects getlocal_reflects_setstate_after_autobinding
    it('통화 추가 줄: 이름 입력 → 취소(form.newCurrency=null) → getLocal().form.newCurrency 가 null', async () => {
        m = await mount([currencyForm], [], { form: { language_currency: { currencies: [{ code: 'KRW' }] } } });
        click('add');
        await settle();
        fireEvent.change(document.querySelector('[data-testid="ml-newCurrency.name"]') as HTMLElement, { target: { value: '원화' } });
        await settle();
        expect((window as any).G7Core.state.getLocal().form.newCurrency.name.ko).toBe('원화');

        click('cancel');
        await settle();

        expect(m.app.getGlobalState()._local.form.newCurrency).toBeNull();
        expect(text('nc')).toBe('NONE');
        // 종전: 낡은 setLocal 스냅샷(__g7LastSetLocalSnapshot)이 다시 얹혀 { name: { ko: '원화' } … }
        expect((window as any).G7Core.state.getLocal().form.newCurrency).toBeNull();
    });

    // 점 경로 target 갈래(`target: '_local.a.b'`)도 live B 기반으로 B 를 쓰므로 같은 스냅샷을 지워야 한다 — 취소가 이 갈래 하나로만
    // 쓰게 해(`target: 'local'` 쓰기를 섞으면 그쪽 갈래가 먼저 지워 이 갈래를 가린다) 그 지움 줄을 따로 잠근다.
    // @scenario writer=setstate_dot_path_target
    // @effects getlocal_reflects_setstate_after_autobinding
    it('통화 추가 줄: 이름 입력 → 점 경로 target(_local.form.newCurrency) 으로 null → getLocal().form.newCurrency 가 null', async () => {
        const dotPathForm = {
            ...currencyForm,
            children: currencyForm.children.map((child: any) => (child.id === 'add_row'
                ? {
                    ...child,
                    children: [
                        child.children[0],
                        button('cancel-dot', [{ handler: 'setState', params: { target: '_local.form.newCurrency', value: null } }]),
                    ],
                }
                : child)),
        };
        m = await mount([dotPathForm], [], { form: { language_currency: { currencies: [{ code: 'KRW' }] } } });
        click('add');
        await settle();
        fireEvent.change(document.querySelector('[data-testid="ml-newCurrency.name"]') as HTMLElement, { target: { value: '원화' } });
        await settle();
        expect((window as any).G7Core.state.getLocal().form.newCurrency.name.ko).toBe('원화');

        click('cancel-dot');
        await settle();

        expect(m.app.getGlobalState()._local.form.newCurrency).toBeNull();
        // 화면(저장소 A 병합)은 여기서 단언하지 않는다 — 이 갈래는 자동바인딩이 남긴 __g7ForcedLocalFields(form.newCurrency.name)를
        // 비우지 않아 화면이 앞 입력을 계속 보인다(2026-10-19 실측 'SET', 이 시험의 스냅샷 축과 별개 — 같은 날 7.1.1 출시 점검 보고에 후보 결함으로 올림)
        // 이 갈래가 스냅샷을 남기면 getLocal() 이 deepMerge(B, 스냅샷)으로 { name: { ko: '원화' } … } 를 되살린다
        expect((window as any).G7Core.state.getLocal().form.newCurrency).toBeNull();
        // 형제 키는 그대로
        expect((window as any).G7Core.state.getLocal().form.language_currency.currencies).toEqual([{ code: 'KRW' }]);
    });

    // @scenario writer=setstate_target_local
    // @effects getlocal_reflects_setstate_after_autobinding
    it('폼 초기화: 입력 → form=null setState → getLocal().form 이 null, 다른 키는 남는다', async () => {
        m = await mount([
            formNode,
            button('reset', [setLocal({ form: null })]),
        ], [], { form: { newCurrency: { name: '' } }, keep: 'k' });
        fireEvent.change(document.querySelector('input[name="newCurrency.name"]') as HTMLInputElement, { target: { value: '엔' } });
        await settle();
        click('reset');
        await settle();

        expect((window as any).G7Core.state.getLocal().form).toBeNull();
        expect((window as any).G7Core.state.getLocal().keep).toBe('k');
    });

    // @scenario writer=setlocal_after_await
    // @effects getlocal_reflects_setstate_after_autobinding
    it('setLocal 뒤 렌더가 지나도 getLocal() 은 setLocal 값을 돌려준다(종전 대체 값 계약 유지)', async () => {
        m = await mount([formNode], [], { form: { newCurrency: { name: '' } } });
        (window as any).G7Core.state.setLocal({ draftNote: 'memo' });
        await settle();
        expect((window as any).G7Core.state.getLocal().draftNote).toBe('memo');
    });
});
