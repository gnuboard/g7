// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 루트 렌더러·data-key 디바운스 폼 자동바인딩·ActionDispatcher)로 디바운스 대기 중 액션의 `_local` 을 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(관리자 상품 수정 정가 빠른 입력 → 0.1초 안 「저장」)이 덮는다
/**
 * @file DynamicRenderer.autoBindingDebounceFlush.test.tsx
 * @description 디바운스 폼(`data-key` + `data-debounce`)에서 디바운스가 끝나기 전에 누른 액션이 화면 입력과 같은 `_local` 을 읽는다 (engine-v1.81.2, 7.1.1)
 *
 * 자동바인딩 칸의 대기 쓰기는 입력칸 인스턴스의 지역 타이머였다. 비디바운스 액션 직전에 대기 중 디바운스를 실행하는 장치
 * (`ActionDispatcher.flushPendingDebounceTimers`)는 디스패처에 등록된 것만 실행해 이 타이머를 몰랐고, flush 를 해도 액션의
 * `_local` 은 렌더 시점 문맥(`componentContext.state`)이었다. 관리자 상품 수정에서 정가를 빠르게 친 뒤 0.1초 안에 「저장」 하면
 * 요청 본문에 옛 값이 실렸다(7.1.0 에도 있음).
 *
 * 하네스는 저장소 B 를 손으로 채우지 않는다 — 실제 자동바인딩 입력(`fireEvent.change`)과 버튼 클릭만 거친다.
 *
 * @scenario-manifest tests/scenarios/autobinding-debounce-flush.yaml
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

/** 자동바인딩 입력칸 — name·value·onChange·onKeyDown 을 그대로 DOM 에 넘긴다 */
const InputStandIn = ({ name, value, onChange, onKeyDown }: any) =>
    React.createElement('input', { 'data-testid': `input-${name}`, name, value: value ?? '', onChange: onChange ?? (() => {}), onKeyDown });

function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        Button: c(passthrough('button')),
        Input: c(InputStandIn),
    };
}

const captured: Array<Record<string, any>> = [];
/** 칸 change 동작이 불린 순서 */
const changed: string[] = [];

/** 관리자 상품 수정과 같은 모양 — `data-key="form" data-debounce="300"` 안의 정가 칸 + 「저장」(sequence: isSaving → 본문 캡처) */
const PAGE: Node[] = [{
    id: 'product_form_content',
    type: 'basic',
    name: 'Div',
    dataKey: 'form',
    debounce: 300,
    props: { 'data-testid': 'form' },
    children: [
        {
            id: 'list_price_input',
            type: 'basic',
            name: 'Input',
            props: { name: 'list_price' },
            actions: [
                { type: 'keydown', key: 'Enter', handler: 'test.capture', params: { via: 'enter', body: '{{Object.assign({}, _local.form)}}' } },
                // 칸의 change 동작(비디바운스) — 자동바인딩 대기 쓰기 끝에 불린다
                { type: 'change', handler: 'test.changed', params: { field: 'list_price' } },
            ],
        },
        { id: 'memo_input', type: 'basic', name: 'Input', props: { name: 'memo' }, actions: [{ type: 'change', handler: 'test.changed', params: { field: 'memo' } }] },
        // 배열 원소 경로 칸 — setLocal 이 `form.items.0.price` 를 숫자 키 객체로 바꾼다
        { id: 'item_price_input', type: 'basic', name: 'Input', props: { name: 'items.0.price' } },
    ],
}, {
    id: 'btn_save',
    type: 'basic',
    name: 'Button',
    text: 'save',
    actions: [{
        type: 'click',
        handler: 'sequence',
        actions: [
            { handler: 'setState', params: { target: 'local', isSaving: true } },
            { handler: 'test.capture', params: { via: 'save', body: '{{Object.assign({}, _local.form)}}', saving: '{{_local.isSaving}}' } },
        ],
    }],
}, {
    // 저장 응답이 서버에 저장된 값을 폼에 되돌려 싣는 화면(쿠폰 수정 등)과 같은 모양 — 응답 = 보낸 본문
    id: 'btn_save_echo',
    type: 'basic',
    name: 'Button',
    text: 'save-echo',
    actions: [{
        type: 'click',
        handler: 'sequence',
        actions: [
            { handler: 'setState', params: { target: 'local', isSaving: true } },
            { handler: 'test.capture', params: { via: 'save-echo', body: '{{Object.assign({}, _local.form)}}' } },
            { handler: 'test.serverEcho' },
            { handler: 'setState', params: { target: 'local', isSaving: false } },
        ],
    }],
}, {
    id: 'btn_peek',
    type: 'basic',
    name: 'Button',
    text: 'peek',
    actions: [{ type: 'click', handler: 'test.capture', params: { via: 'peek', price: '{{_local.form.list_price}}', items: '{{_local.form.items}}' } }],
}];

interface Mounted {
    app: any;
    stop: () => void;
}

async function mount(): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    captured.length = 0;
    changed.length = 0;
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
    dispatcher.registerHandler('test.capture', (action: any) => {
        captured.push({ ...(action.params ?? {}) });
    });
    dispatcher.registerHandler('test.changed', (action: any) => {
        changed.push(String(action.params?.field));
    });
    // 서버가 받은 본문을 저장하고 응답으로 돌려준 값을 폼에 싣는다(운영의 onSuccess setState form=response.data 와 같은 쓰기)
    dispatcher.registerHandler('test.serverEcho', (_a: any, ctx: any) => {
        const saved = captured[captured.length - 1]?.body ?? {};
        ctx.setState({ form: { ...saved } });
    });

    app.setGlobalState({ _local: { form: { list_price: '1000', memo: '', items: [{ price: '500', sku: 'A' }] }, isSaving: false } }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/autobinding-debounce-flush',
            components: PAGE,
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates) },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="input-list_price"]')).not.toBeNull());

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
            (window as any).__g7AutoBindingPaths = undefined;
            document.body.innerHTML = '';
        },
    };
}

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));
const input = (name: string): HTMLInputElement => document.querySelector(`[data-testid="input-${name}"]`) as HTMLInputElement;
/** 40ms 간격으로 한 글자씩 친다(실측 조작과 같은 간격) */
async function typeFast(name: string, values: string[]): Promise<void> {
    for (const v of values) {
        fireEvent.change(input(name), { target: { value: v } });
        await settle(40);
    }
}
const clickLabel = (label: string): void => {
    const b = Array.from(document.querySelectorAll('button')).find((x) => (x.textContent ?? '') === label) as HTMLElement;
    fireEvent.click(b);
};
const liveLocal = (app: any): Record<string, any> => app.getGlobalState()._local;

describe('[case:state-setstate-51] 디바운스 폼 — 디바운스가 끝나기 전에 누른 액션이 화면 입력과 같은 값을 읽는다 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(async () => {
        // 이 사례에서 남은 디바운스 예약이 다음 사례의 앱에 쓰지 않게 끝날 때까지 기다린다
        await settle(350);
        m?.stop();
        m = null;
    });

    /**
     * @scenario case=click_sequence_within_debounce
     * @effects action_reads_flushed_input, store_b_holds_flushed_input
     */
    it('정가를 빠르게 친 뒤 300ms 안에 「저장」(sequence) 하면 본문에 마지막 입력이 실린다', async () => {
        m = await mount();
        await typeFast('list_price', ['10001', '100012', '1000123']);
        expect(liveLocal(m.app).form.list_price, '아직 디바운스 대기 중').toBe('1000');

        clickLabel('save');
        await waitFor(() => expect(captured.length).toBe(1));

        expect(captured[0].body.list_price).toBe('1000123');
        expect(captured[0].saving).toBe(true);
        expect(liveLocal(m.app).form.list_price).toBe('1000123');
        expect((window as any).G7Core.state.getLocal().form.list_price).toBe('1000123');
    });

    /**
     * @scenario case=click_sequence_server_echo
     * @effects action_reads_flushed_input, input_not_reverted_after_save
     */
    it('빠른 저장 뒤 응답이 폼에 실려도, 늦게 끝나는 디바운스·렌더가 칸을 옛 값으로 되돌리지 않는다 (쿠폰 최소주문금액)', async () => {
        m = await mount();
        await typeFast('list_price', ['70', '700', '7000']);

        clickLabel('save-echo');
        await waitFor(() => expect(captured.length).toBe(1));
        expect(captured[0].body.list_price).toBe('7000');

        await settle(450);
        expect(liveLocal(m.app).form.list_price).toBe('7000');
        expect(input('list_price').value).toBe('7000');
        expect(captured.length).toBe(1);
    });

    /**
     * @scenario case=click_sequence_two_pending_fields
     * @effects action_reads_flushed_input, flush_runs_each_pending_write_once
     */
    it('두 칸이 대기 중일 때 「저장」 하면 두 칸의 대기 쓰기와 change 동작이 한 번씩만 실행되고 본문에 둘 다 실린다(안쪽 flush 재진입)', async () => {
        m = await mount();
        fireEvent.change(input('list_price'), { target: { value: '81' } });
        await settle(20);
        fireEvent.change(input('memo'), { target: { value: '메모81' } });
        await settle(20);
        expect(changed).toEqual([]);

        clickLabel('save');
        await waitFor(() => expect(captured.length).toBe(1));
        expect(captured[0].body).toMatchObject({ list_price: '81', memo: '메모81' });
        await settle(400);
        expect(changed.slice().sort()).toEqual(['list_price', 'memo']);
    });

    /**
     * @scenario case=timer_fired_change_action_while_other_field_pending
     * @effects debounce_kept_for_non_matching_keys, flush_runs_each_pending_write_once
     */
    it('앞 칸의 디바운스가 끝나며 부른 change 동작은, 아직 치고 있는 다른 칸의 대기 쓰기를 앞당기지 않는다(관리자 상품 정가 → 판매가)', async () => {
        m = await mount();
        fireEvent.change(input('list_price'), { target: { value: '91' } });
        await settle(150);
        fireEvent.change(input('memo'), { target: { value: '메모91' } });
        // 정가 칸 타이머(300ms)가 끝나 그 칸의 change 동작이 불린 직후 — 메모 칸은 아직 디바운스 안
        await settle(200);
        expect(liveLocal(m.app).form.list_price).toBe('91');
        expect(changed).toEqual(['list_price']);
        expect(liveLocal(m.app).form.memo, '메모 칸의 대기 쓰기가 앞당겨지면 안 된다').toBe('');

        await settle(250);
        expect(liveLocal(m.app).form.memo).toBe('메모91');
        expect(changed).toEqual(['list_price', 'memo']);
    });

    /**
     * @scenario case=click_single_within_debounce
     * @effects action_reads_flushed_input
     */
    it('sequence 가 아닌 단일 액션도 디바운스 대기 값을 읽는다', async () => {
        m = await mount();
        await typeFast('list_price', ['2', '25']);

        clickLabel('peek');
        await waitFor(() => expect(captured.length).toBe(1));
        expect(captured[0].price).toBe('25');
    });

    /**
     * @scenario case=click_single_array_element_path
     * @effects action_reads_flushed_input
     */
    it('배열 원소 경로 칸(items.0.price)의 대기 값도 액션이 배열 모양 그대로 읽는다(렌더러와 같은 병합)', async () => {
        m = await mount();
        await typeFast('items.0.price', ['5', '55', '555']);

        clickLabel('peek');
        await waitFor(() => expect(captured.length).toBe(1));
        expect(Array.isArray(captured[0].items)).toBe(true);
        expect(captured[0].items).toEqual([{ price: '555', sku: 'A' }]);
    });

    /**
     * @scenario case=keydown_matching_key
     * @effects action_reads_flushed_input
     */
    it('같은 칸의 Enter 동작(키 필터 일치)도 대기 값을 읽는다', async () => {
        m = await mount();
        await typeFast('list_price', ['3', '33']);

        fireEvent.keyDown(input('list_price'), { key: 'Enter' });
        await waitFor(() => expect(captured.length).toBe(1));
        expect(captured[0].body.list_price).toBe('33');
    });

    /**
     * @scenario case=keydown_other_key
     * @effects debounce_kept_for_non_matching_keys
     */
    it('키 필터가 맞지 않는 키는 대기 쓰기를 앞당기지 않는다(디바운스 유지) — 대기 값은 디바운스 뒤 반영된다', async () => {
        m = await mount();
        await typeFast('list_price', ['4']);
        fireEvent.keyDown(input('list_price'), { key: 'a' });
        await settle(10);
        expect(liveLocal(m.app).form.list_price).toBe('1000');

        await settle(400);
        expect(liveLocal(m.app).form.list_price).toBe('4');
        expect(captured.length).toBe(0);
    });

    /**
     * @scenario case=click_sequence_after_debounce
     * @effects action_reads_flushed_input
     */
    it('디바운스가 끝난 뒤 저장은 종전처럼 정상이고, 두 칸을 이어 친 대기 값도 모두 실린다', async () => {
        m = await mount();
        await typeFast('list_price', ['5', '55']);
        await settle(400);
        await typeFast('memo', ['메', '메모']);

        clickLabel('save');
        await waitFor(() => expect(captured.length).toBe(1));
        expect(captured[0].body).toMatchObject({ list_price: '55', memo: '메모' });
        expect(input('list_price').value).toBe('55');
        expect(input('memo').value).toBe('메모');
    });

    /**
     * @scenario case=no_action
     * @effects debounce_kept_for_non_matching_keys
     */
    it('액션이 없으면 디바운스 동안 저장소에 쓰지 않고, 디바운스 뒤 반영된다(종전 디바운스)', async () => {
        m = await mount();
        await typeFast('list_price', ['6', '66']);
        expect(liveLocal(m.app).form.list_price).toBe('1000');
        await settle(400);
        expect(liveLocal(m.app).form.list_price).toBe('66');
        expect(input('list_price').value).toBe('66');
    });
});
