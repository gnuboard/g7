// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate·data-key 디바운스 폼 자동바인딩·ActionDispatcher flush·computed 정의)로 디바운스 창 안 액션의 `_computed`·2단 디바운스 사슬을 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 엔진 매트릭스(디바운스 폼 입력 직후 저장)가 덮는다
/**
 * @file ActionDispatcher.flushComputedChain.test.tsx
 * @description 디바운스 창 안에서 누른 액션이 flush 뒤의 `_computed` 와 2단 디바운스 사슬(자동바인딩 → 그 칸의 debounce change 동작)의 결과를 읽는다 (engine-v1.81.2, 7.1.1)
 *
 * 비디바운스 액션 직전 flush 는 대기 중 디바운스를 실행하고 액션의 `_local` 을 최신으로 바꾸지만(사례 51), ① `_computed` 는 렌더 시점
 * 값이었다 — 식 평가는 `_computed` 를 액션 문맥의 `computedRef.current`(마지막 렌더 값)로 다시 덮는다. 단일 apiCall 본문
 * `{{_computed.payload}}` 가 마지막 입력을 빼고 나갔다. ② flush 는 레지스트리 복사본을 한 번만 돌아, 실행한 대기 쓰기가 새로 건 디바운스
 * (그 칸의 `debounce` change 동작)는 이 액션 뒤 자기 타이머에 실행됐다. 둘 다 7.1.0 에도 있다.
 *
 * 하네스는 저장소 B 를 손으로 채우지 않는다 — 실제 자동바인딩 입력(`fireEvent.change`)과 단추 클릭만 거친다.
 *
 * @scenario-manifest tests/scenarios/flush-computed-chain.yaml
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

const InputStandIn = ({ name, value, onChange }: any) =>
    React.createElement('input', { 'data-testid': `input-${name}`, name, value: value ?? '', onChange: onChange ?? (() => {}) });

const captured: Array<Record<string, any>> = [];
let echoCount = 0;

/** 정가 칸(디바운스 폼) — change 동작은 표시용 파생 값을 디바운스로 계산한다(2단 사슬) */
const PAGE: Node[] = [
    {
        id: 'form', type: 'basic', name: 'Div', dataKey: 'form', debounce: 300, children: [
            {
                id: 'price', type: 'basic', name: 'Input', props: { name: 'price' },
                actions: [{ type: 'change', handler: 'setState', debounce: 300, params: { target: 'local', derived: '{{"D" + $event.target.value}}' } }],
            },
            // 동작이 없는 칸 — await 단계 사례용
            { id: 'note', type: 'basic', name: 'Input', props: { name: 'note' } },
            // 같은 핸들러를 다시 거는 디바운스 동작 — flush 회차 제한이 끝없는 실행을 막는지 본다
            {
                id: 'echo', type: 'basic', name: 'Input', props: { name: 'echo' },
                actions: [{ type: 'change', handler: 'test.echoDebounce', debounce: 300 }],
            },
        ],
    },
    button('save', [{ type: 'click', handler: 'test.capture', params: { body: '{{_computed.payload}}', price: '{{_local.form.price}}', derived: '{{_local.derived}}' } }]),
    button('save-seq', [{ type: 'click', handler: 'sequence', actions: [{ handler: 'test.capture', params: { body: '{{_computed.payload}}' } }] }]),
    // 저장 응답이 값을 고친 뒤(await) 뒤 단계가 computed 를 읽는 모양
    button('save-await', [{ type: 'click', handler: 'sequence', actions: [
        { handler: 'setState', params: { target: 'local', 'form.note': '999' } },
        { handler: 'test.wait' },
        { handler: 'test.capture', params: { body: '{{_computed.notePayload}}' } },
    ] }]),
];

function button(text: string, actions: any[]): Node {
    return { id: `btn_${text}`, type: 'basic', name: 'Button', text, actions };
}

interface Mounted {
    app: any;
    stop: () => void;
}

async function mount(): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    captured.length = 0;
    echoCount = 0;
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
        Button: { component: passthrough('button'), metadata: {} },
        Input: { component: InputStandIn, metadata: {} },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));
    dispatcher.registerHandler('test.capture', (action: any) => { captured.push({ ...(action.params ?? {}) }); });
    dispatcher.registerHandler('test.wait', () => new Promise((r) => setTimeout(r, 80)));
    // 실행될 때마다 같은 키로 디바운스를 다시 건다(확장이 setLocal debounce 를 되풀이하는 모양)
    dispatcher.registerHandler('test.echoDebounce', () => {
        const loop = (): void => {
            echoCount += 1;
            if (echoCount < 20) dispatcher.debouncedCall('test-echo-loop', 300, loop);
        };
        dispatcher.debouncedCall('test-echo-loop', 300, loop);
    });

    app.setGlobalState({ _local: { form: { price: '100', echo: '', note: '' }, derived: 'D100' } }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: { version: '1.0.0', layout_name: 'test/flush-computed-chain', components: PAGE } as any,
        dataContext: {
            _global: g,
            _local: g._local,
            _globalSetState: (u: any) => app.setGlobalState(u),
            _computedDefinitions: { payload: "{{'P' + _local.form.price}}", notePayload: "{{'N' + _local.form.note}}" },
        },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="input-price"]')).not.toBeNull());

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

const settle = (ms = 40): Promise<void> => new Promise((r) => setTimeout(r, ms));
async function click(label: string): Promise<void> {
    const b = Array.from(document.querySelectorAll('button')).find((x) => (x.textContent ?? '') === label) as HTMLElement;
    fireEvent.click(b);
    await settle();
}
const type = (name: string, value: string): void => {
    fireEvent.change(document.querySelector(`[data-testid="input-${name}"]`) as HTMLInputElement, { target: { value } });
};

describe('[case:state-setstate-53] 디바운스 창 안 액션 — flush 뒤 `_computed`·2단 디바운스 사슬 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(async () => {
        await settle(350);
        m?.stop();
        m = null;
    });

    /**
     * @scenario case=single_action_computed
     * @effects action_reads_flushed_computed
     */
    it('정가를 친 직후(0.06초) 누른 단일 동작의 `{{_computed.payload}}` 가 마지막 입력으로 계산된다', async () => {
        m = await mount();
        type('price', '777');
        await settle(60);
        await click('save');

        expect(captured[0]?.price).toBe('777');
        expect(captured[0]?.body).toBe('P777');
    });

    /**
     * @scenario case=sequence_first_step_computed
     * @effects action_reads_flushed_computed
     */
    it('setState 로 시작하지 않는 sequence 의 첫 단계도 flush 뒤 `_computed` 를 읽는다', async () => {
        m = await mount();
        type('price', '888');
        await settle(60);
        await click('save-seq');

        expect(captured[0]?.body).toBe('P888');
    });

    /**
     * flush 뒤 다시 계산한 `_computed` 는 렌더 전까지만 쓴다 — 액션이 await 하는 동안 렌더가 돌면 뒤 단계는 렌더된(최신) 값을 읽는다.
     * 고정 값을 실으면 액션이 끝날 때까지 flush 시점 값(N777)에 묶인다.
     *
     * @scenario case=await_step_reads_live_computed
     * @effects later_step_reads_live_computed
     */
    it('flush 뒤 sequence 가 상태를 바꾸고 await 한 다음 단계는 다시 그려진 최신 `_computed` 를 읽는다', async () => {
        m = await mount();
        type('note', '777');
        await settle(60);
        await click('save-await');
        await settle(300);

        expect(captured[0]?.body).toBe('N999');
    });

    /**
     * @scenario case=no_flush_control
     * @effects no_flush_unchanged
     */
    it('대조군: 디바운스가 끝난 뒤 누른 동작은 종전처럼 렌더된 `_computed` 를 읽는다', async () => {
        m = await mount();
        type('price', '555');
        await settle(450);
        await click('save');

        expect(captured[0]?.body).toBe('P555');
    });

    /**
     * @scenario case=two_stage_chain
     * @effects action_reads_second_stage
     */
    it('자동바인딩 대기 쓰기 → 그 칸의 debounce change 동작(2단 사슬)까지 같은 flush 에서 실행돼 저장이 파생 값을 읽는다', async () => {
        m = await mount();
        type('price', '5');
        await settle(60);
        await click('save');

        expect(captured[0]?.price).toBe('5');
        expect(captured[0]?.derived).toBe('D5');
        await settle(400);
        expect(m.app.getGlobalState()._local.derived).toBe('D5');
    });

    /**
     * @scenario case=self_rescheduling_bounded
     * @effects flush_terminates
     */
    it('실행될 때마다 같은 디바운스를 다시 거는 동작도 flush 가 끝난다(회차 제한) — 남은 예약은 자기 타이머에 실행된다', async () => {
        m = await mount();
        type('echo', 'e');
        await settle(60);
        await click('save');

        expect(captured).toHaveLength(1);
        // 1회차: 자동바인딩 쓰기 · 2회차: change 동작(예약) · 3~5회차: 예약 실행이 같은 키로 다음 예약을 건다 — 5회차에서 멈춘다
        expect(echoCount).toBe(3);
    });
});
