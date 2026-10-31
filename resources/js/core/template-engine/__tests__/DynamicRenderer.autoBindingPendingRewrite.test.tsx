// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate·data-key 디바운스 폼 자동바인딩·ActionDispatcher·startTransition 루트 다시 그리기)로 디바운스 칸의 표시 값을 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 엔진 매트릭스(디바운스 폼 칸 입력)가 덮는다
/**
 * @file DynamicRenderer.autoBindingPendingRewrite.test.tsx
 * @description 디바운스 자동바인딩 칸의 대기 값이 「다시 쓰인 상태」·「버린 대기 값」과 섞여 화면 ≠ 상태가 되지 않는다 (engine-v1.81.2, 7.1.1)
 *
 * ① 타이머가 쓴 뒤에도 상태가 대기 값과 같아질 때까지 칸은 대기 값을 보인다(쓰기 ~ 반영 사이 렌더의 옛 값 깜빡임 방지). 그 칸의 change
 *    동작이 같은 칸을 다른 값(대문자·되돌리기)으로 다시 쓰면 상태가 대기 값이 되는 일이 없어 칸은 친 글자, 상태·저장 본문은 다시 쓴 값이었다
 *    (자동바인딩 디바운스 `8951f1faf1` 부터, 7.1.0 포함).
 * ② `_global` 디바운스 폼은 경로 이동으로 대기 쓰기를 버려도 전역 칸 표시용 대기 값(같은 입력)이 남아 그 값을 다시 보였다.
 * ③ 경로 이동으로 버린 대기 값의 다시 마운트 차례를 렌더(useMemo) 안에서 올려, 끊겨 버려진 전환 렌더의 증가가 남았다 — 그 사이 새로 친
 *    칸이 다음 커밋에서 다시 마운트돼 친 글자가 사라졌다.
 *
 * 하네스는 저장소 B 를 손으로 채우지 않는다 — 실제 자동바인딩 입력(`fireEvent.change`)·setState 동작·`TemplateApp.setGlobalState` 만 거친다.
 *
 * @scenario-manifest tests/scenarios/autobinding-pending-rewrite.yaml
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

/** 자동바인딩 칸 — 받은 value 를 그대로 보인다(제어 입력) */
const InputStandIn = ({ name, value, onChange }: any) =>
    React.createElement('input', { 'data-testid': `input-${name}`, name, value: value ?? '', onChange: onChange ?? (() => {}) });

/** 느린 형제 — 전환 렌더가 칸을 계산한 뒤 이 노드에서 양보하게 한다. 「1」 차례 렌더 수를 센다 */
const Slow = ({ text }: any) => {
    if (String(text) === '1') (window as any).__g52SlowRenders = ((window as any).__g52SlowRenders ?? 0) + 1;
    const t = Date.now();
    while (Date.now() - t < 25) { /* 바쁜 렌더 */ }

    return React.createElement('span', null, text);
};

const show = (id: string, expr: string): Node => ({ id: `show_${id}`, type: 'basic', name: 'Span', props: { 'data-testid': id }, text: `{{${expr}}}` });

interface Mounted {
    app: any;
    stop: () => void;
}

async function mount(components: Node[], local: Record<string, any>): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
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
        Div: { component: passthrough('div'), metadata: {} },
        Span: { component: passthrough('span'), metadata: {} },
        Input: { component: InputStandIn, metadata: {} },
        Slow: { component: Slow, metadata: {} },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));

    app.setGlobalState({ _local: local }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: { version: '1.0.0', layout_name: 'test/autobinding-pending-rewrite', components } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (u: any) => app.setGlobalState(u) },
    } as any);
    await waitFor(() => expect(document.querySelector('#app')!.innerHTML.length).toBeGreaterThan(0));

    return {
        app,
        stop: () => {
            try { engine.destroyTemplate(); } catch { /* 정리 */ }
            delete (window as any).__templateApp;
            delete (window as any).G7Core;
            delete (window as any).__g52SlowRenders;
            (window as any).__g7PendingLocalState = null;
            (window as any).__g7ForcedLocalFields = undefined;
            (window as any).__g7SetLocalOverrideKeys = undefined;
            (window as any).__g7LastSetLocalSnapshot = undefined;
            (window as any).__g7AutoBindingPaths = undefined;
            window.history.replaceState({}, '', '/');
            document.body.innerHTML = '';
        },
    };
}

const text = (id: string): string => (document.querySelector(`[data-testid="${id}"]`)?.textContent ?? '');
const settle = (ms = 40): Promise<void> => new Promise((r) => setTimeout(r, ms));
const input = (name: string): HTMLInputElement => document.querySelector(`[data-testid="input-${name}"]`) as HTMLInputElement;

/** 코드 칸 — change 동작이 같은 칸을 다시 쓴다 */
const codeForm = (writeBack: string): Node[] => [
    {
        id: 'form', type: 'basic', name: 'Div', dataKey: 'form', debounce: 300, children: [
            { id: 'code', type: 'basic', name: 'Input', props: { name: 'code' }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'form.code': writeBack } }] },
            { id: 'memo', type: 'basic', name: 'Input', props: { name: 'memo' } },
        ],
    },
    show('code', '_local.form.code'),
];

describe('[case:state-setstate-54] 디바운스 칸의 대기 값이 다시 쓰인 상태·버린 대기 값과 섞이지 않는다 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(async () => {
        await settle(350);
        m?.stop();
        m = null;
    });

    /**
     * @scenario case=change_action_transforms_same_field
     * @effects field_shows_rewritten_state
     */
    it('change 동작이 같은 칸을 대문자로 다시 쓰면 칸도 다시 쓴 값을 보인다', async () => {
        m = await mount(codeForm('{{String($event.target.value).toUpperCase()}}'), { form: { code: '', memo: '' } });
        fireEvent.change(input('code'), { target: { value: 'abc' } });
        await settle(500);

        expect(m.app.getGlobalState()._local.form.code).toBe('ABC');
        expect(text('code')).toBe('ABC');
        expect(input('code').value).toBe('ABC');
    });

    /**
     * @scenario case=change_action_reverts_same_field
     * @effects field_shows_rewritten_state
     */
    it('change 동작이 같은 칸을 입력 전 값으로 되돌리면 칸도 되돌린 값을 보인다', async () => {
        m = await mount(codeForm('old'), { form: { code: 'old', memo: '' } });
        fireEvent.change(input('code'), { target: { value: 'oldx' } });
        await settle(500);

        expect(m.app.getGlobalState()._local.form.code).toBe('old');
        expect(input('code').value).toBe('old');
    });

    /**
     * @scenario case=plain_typing_control
     * @effects plain_typing_unchanged
     */
    it('대조군: change 동작이 없는 칸은 입력 값이 그대로 확정된다(대기 → 쓰기 → 확정)', async () => {
        m = await mount(codeForm('{{String($event.target.value).toUpperCase()}}'), { form: { code: '', memo: '' } });
        fireEvent.change(input('memo'), { target: { value: 'm1' } });
        await settle(60);
        fireEvent.change(input('memo'), { target: { value: 'm12' } });
        await settle(500);

        expect(m.app.getGlobalState()._local.form.memo).toBe('m12');
        expect(input('memo').value).toBe('m12');
    });

});

describe('[case:state-closure-dropped-debounce-display-edges] 경로 이동으로 버린 디바운스 대기 값 — 전역 폼·끊긴 전환 렌더 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(async () => {
        await settle(350);
        m?.stop();
        m = null;
    });

    /**
     * @scenario case=global_debounce_route_switch
     * @effects global_field_shows_global_state
     */
    it('`_global` 디바운스 폼: 친 뒤 대기 중에 경로가 바뀌면 칸이 전역 상태(버려진 쓰기 전 값)를 보인다', async () => {
        window.history.replaceState({}, '', '/a');
        m = await mount([
            { id: 'gform', type: 'basic', name: 'Div', dataKey: '_global.search', debounce: 300, children: [
                { id: 'q', type: 'basic', name: 'Input', props: { name: 'q' } },
            ] },
        ], {});
        m.app.setGlobalState({ search: { q: 'old' } });
        await settle(100);
        expect(input('q').value).toBe('old');

        fireEvent.change(input('q'), { target: { value: 'abc' } });
        await settle(60);
        window.history.pushState({}, '', '/b');
        m.app.setGlobalState({ __tick: 1 });
        await settle(100);

        expect(m.app.getGlobalState().search.q).toBe('old');
        expect(input('q').value).toBe('old');
        await settle(400);
        expect(m.app.getGlobalState().search.q).toBe('old');
        expect(input('q').value).toBe('old');
    });

    /**
     * @scenario case=interrupted_transition_retype
     * @effects retyped_field_not_remounted
     */
    it('경로가 바뀐 뒤 칸을 계산한 전환 렌더가 양보하는 사이 같은 칸을 다시 치면 칸이 다시 마운트되지 않는다(친 글자 유지)', async () => {
        window.history.replaceState({}, '', '/a');
        m = await mount([
            { id: 'form', type: 'basic', name: 'Div', dataKey: 'form', debounce: 300, children: [
                { id: 'price', type: 'basic', name: 'Input', props: { name: 'price' } },
                { id: 'slow1', type: 'basic', name: 'Slow', props: { text: '{{_global.__tick}}' } },
                { id: 'slow2', type: 'basic', name: 'Slow', props: { text: '{{_global.__tick}}' } },
                { id: 'slow3', type: 'basic', name: 'Slow', props: { text: '{{_global.__tick}}' } },
            ] },
        ], { form: { price: '1000' } });
        await settle(100);
        const before = input('price');
        fireEvent.change(before, { target: { value: '32222' } });
        await settle(60);
        // flush 없는 경로 이동 뒤 다른 전역 쓰기로 루트가 다시 그려진다(startTransition) — 칸이 버린 대기 값을 보고 다시 마운트를 판정한다
        window.history.pushState({}, '', '/b');
        m.app.setGlobalState({ __tick: 1 });
        let fired = false;
        for (let i = 0; i < 60 && !fired; i++) {
            await new Promise((r) => setTimeout(r, 1));
            if (((window as any).__g52SlowRenders ?? 0) >= 1) {
                // 칸은 이미 이번 전환 렌더에서 계산됐고 느린 형제에서 양보 중이다 — 그 사이 같은 칸을 다시 친다
                fireEvent.change(input('price'), { target: { value: 'x' } });
                fired = true;
            }
        }
        expect(fired, '전환 렌더 도중에 쳤다').toBe(true);
        await settle(200);

        expect(input('price') === before, '칸 요소 유지(다시 마운트 없음)').toBe(true);
        expect(input('price').value).toBe('x');
        await settle(400);
        expect(m.app.getGlobalState()._local.form.price).toBe('x');
    });
});
