// e2e:allow 실제 엔진 구조(TemplateApp init 동작 실행기·전역 상태·renderTemplate 루트 렌더러·저장소 A React 상태)로 타이머 틱의 읽기·쓰기를 잰다 — 브라우저 축은 본인인증 전체화면 실측(이력 KVE 조치 3차 매트릭스)이 덮는다
/**
 * @file DynamicRenderer.intervalTickFromInit.test.tsx
 * @description `init_actions` 에서 등록한 `startInterval` 의 틱이 현재 화면 `_local` 을 읽고, 화면이 읽는 자리에 쓴다 (engine-v1.91.1)
 *
 * 틱은 등록 시점 문맥으로 돌았다. init 문맥은 렌더 전 데이터 스냅숏이고 컴포넌트 writer 가 없어, 틱의 식은 렌더 뒤에 바뀐 값을
 * 못 보고(남은 시간이 처음부터 0), 틱의 setState 는 렌더 전 경로(저장소 B 만)로 써서 클릭이 저장소 A 에 쓴 같은 키에 가려졌다
 * (재전송 대기 60초가 줄지 않음). 본인인증 전체화면(sirsoft-basic·sirsoft-admin_basic `auth/identity_challenge`)의 카운트다운이
 * 이 경로였다(engine-v1.45.0 부터).
 *
 * 하네스는 저장소 B 를 손으로 채우지 않는다 — 실제 writer(TemplateApp init 동작 실행기·setState 동작·`TemplateApp.setGlobalState`)만 거친다.
 *
 * @scenario-manifest tests/scenarios/interval-tick-live-local.yaml
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

const show = (id: string, expr: string): Node => ({ id: `show_${id}`, type: 'basic', name: 'Span', props: { 'data-testid': id }, text: `{{${expr}}}` });

/** 본인인증 전체화면과 같은 모양 — 재전송 버튼이 대기 시간을 저장소 A 에 쓰고, 틱이 남은 시간·대기를 줄인다 */
const PAGE: Node[] = [
    { id: 'btn_resend', type: 'basic', name: 'Button', text: 'resend', actions: [{ type: 'click', handler: 'setState', params: { target: 'local', cooldown: 5 } }] },
    show('remaining', '_local.remaining ?? -1'),
    show('cooldown', '_local.cooldown ?? -1'),
];

/** 전체화면 init 의 틱 — `_local.expiresAt` 로 남은 초를, `_local.cooldown` 을 1씩 줄인다 */
const TICK = {
    handler: 'startInterval',
    params: {
        id: 'test_identity_tick',
        intervalMs: 40,
        actions: [
            {
                handler: 'setState',
                params: {
                    target: 'local',
                    remaining: '{{_local.expiresAt ? Math.max(0, Math.floor((new Date(_local.expiresAt).getTime() - Date.now()) / 1000)) : 0}}',
                    cooldown: '{{Math.max(0, (_local.cooldown ?? 0) - 1)}}',
                },
            },
        ],
    },
};

interface Mounted {
    app: any;
    stop: () => void;
}

/**
 * 렌더 전 init 동작으로 타이머를 등록하고, 그 뒤 서버 응답처럼 만료 시각을 채운 다음 화면을 그립니다.
 *
 * @return 장착한 앱과 정리 함수
 */
async function mount(): Promise<Mounted> {
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
    (ComponentRegistry.getInstance() as any).registry = {
        Div: { component: passthrough('div'), metadata: {} },
        Span: { component: passthrough('span'), metadata: {} },
        Button: { component: passthrough('button'), metadata: {} },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));

    // init-local — 질의값이 없어 만료 시각은 비어 있다
    app.setGlobalState({ _local: { expiresAt: '', remaining: 0, cooldown: 0 } }, { render: false });
    // 렌더 전 init 동작 — TemplateApp 의 실제 실행기로 타이머를 등록한다(등록 문맥 = 이 시점 스냅숏)
    const initGlobal = app.getGlobalState();
    await (app as any).executeInitActions([TICK], { _global: initGlobal, _local: initGlobal._local });
    // 상태 조회 응답이 만료 시각을 채운다(렌더 전 init 경로의 실제 writer)
    app.setGlobalState({ _local: { ...app.getGlobalState()._local, expiresAt: new Date(Date.now() + 120_000).toISOString() } }, { render: false });

    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/interval-tick-from-init',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: PAGE }],
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (u: any) => app.setGlobalState(u) },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="page"]')).not.toBeNull());

    return {
        app,
        stop: () => {
            try { dispatcher.stopAllIntervals(); } catch { /* 정리 */ }
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

describe('[case:state-closure-interval-tick] init 에서 등록한 타이머 틱이 현재 화면 _local 을 읽고 화면에 쓴다 (engine-v1.91.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    /**
     * @scenario case=component_setState
     *
     * @effects tick_reads_live_local, tick_write_reaches_screen
     */
    it('렌더 뒤 채워진 만료 시각으로 남은 시간을 세고, 클릭이 저장소 A 에 쓴 대기 시간도 줄인다', async () => {
        m = await mount();

        // 렌더 뒤 채워진 만료 시각을 읽는다 — 종전에는 등록 시점 스냅숏(빈 값)으로 계속 0
        await waitFor(() => expect(Number(text('remaining'))).toBeGreaterThan(100), { timeout: 2000 });

        // 재전송 — 컴포넌트 setState 가 저장소 A 에 대기 5 를 쓴다
        fireEvent.click(Array.from(document.querySelectorAll('button')).find((b) => b.textContent === 'resend') as HTMLElement);
        await waitFor(() => expect(text('cooldown')).not.toBe('-1'));

        // 틱이 화면의 대기를 줄여 0 까지 간다 — 종전에는 틱의 쓰기가 저장소 B 에만 가 화면은 5 에 멈췄다
        await waitFor(() => expect(text('cooldown')).toBe('0'), { timeout: 2000 });
        expect(Number(text('remaining'))).toBeGreaterThan(100);
        expect(m.app.getGlobalState()._local.cooldown).toBe(0);
    });

    /**
     * 다른 동작이 await 하는 동안(그 동작의 `__g7ActionContext` 가 걸린 동안) 틱이 돌아도 그 동작의 컴포넌트 writer 에 쓰지 않는다 —
     * 모달 버튼의 apiCall 대기 중이면 모달 저장소 A 에 페이지 키가 들어갈 수 있었다(사례 대조 S3 state-advanced 사례 12 조건).
     *
     * @scenario case=action_pending
     *
     * @effects tick_does_not_write_into_pending_action_writer
     */
    it('다른 동작이 대기 중일 때 돈 틱은 그 동작의 writer 를 건드리지 않고 동작 문맥도 되돌린다', async () => {
        m = await mount();
        await waitFor(() => expect(Number(text('remaining'))).toBeGreaterThan(100), { timeout: 2000 });

        // createHandler 가 동작을 await 하는 동안 걸어 두는 문맥과 같은 모양 — 모달 버튼의 writer 를 흉내 낸다
        const foreignWriter = vi.fn();
        const pending = { setState: foreignWriter, state: {}, data: {} };
        (window as any).__g7ActionContext = pending;
        try {
            await new Promise((r) => setTimeout(r, 200)); // 틱 여러 번
            expect(foreignWriter).not.toHaveBeenCalled();
            expect((window as any).__g7ActionContext).toBe(pending);
        } finally {
            (window as any).__g7ActionContext = undefined;
        }
        expect(Number(text('remaining'))).toBeGreaterThan(100);
    });

    /**
     * 틱 쓰기의 병합 규칙은 렌더 전 경로와 같다 — 빈 객체는 교체(errors 비우기)이고, 대기 중인 sequence 의 동기화 변수를 건드리지 않는다
     * (사례 대조 S1 state-setstate 사례 15·16 조건).
     *
     * @scenario case=merge_rules
     *
     * @effects tick_merge_matches_engine_rules, tick_keeps_pending_sequence_sync
     */
    it('틱이 빈 객체로 errors 를 비우고, 대기 중인 sequence 동기화 변수는 그대로 둔다', async () => {
        m = await mount();
        await waitFor(() => expect(Number(text('remaining'))).toBeGreaterThan(100), { timeout: 2000 });

        // 앞 오류가 남은 상태에서 init 이 「오류 비우기」 틱을 하나 더 등록한다(같은 init 실행기)
        m.app.setGlobalState({ _local: { ...m.app.getGlobalState()._local, errors: { code: ['틀렸습니다'] } } }, { render: false });
        const g = m.app.getGlobalState();
        await (m.app as any).executeInitActions([
            { handler: 'startInterval', params: { id: 'test_clear_errors', intervalMs: 40, actions: [{ handler: 'setState', params: { target: 'local', errors: {} } }] } },
        ], { _global: g, _local: g._local });

        const sentinel = { owner: 'pending-sequence' };
        (window as any).__g7SequenceLocalSync = sentinel;
        try {
            await waitFor(() => expect(m!.app.getGlobalState()._local.errors).toEqual({}), { timeout: 2000 });
            expect((window as any).__g7SequenceLocalSync).toBe(sentinel);
            // getLocal 대체 스냅숏을 남기지 않는다 — 남으면 뒤이은 다른 B 쓰기 위에 틱 시점 값이 얹힌다(state-setstate 사례 49)
            expect((window as any).__g7LastSetLocalSnapshot).toBeUndefined();
        } finally {
            (window as any).__g7SequenceLocalSync = undefined;
        }
    });

    /**
     * 틱이 비어 있지 않은 객체·점 경로를 쓰면 깊게 병합한다 — 최상위 키를 통째로 바꾸면 같은 키 아래 다른 하위 값이 사라진다
     * (사례 대조 S2 state-setstate 사례 22 조건). 스칼라는 그대로 교체된다.
     *
     * @scenario case=merge_rules
     *
     * @effects tick_merge_matches_engine_rules
     */
    it('틱의 객체·점 경로 쓰기는 같은 최상위 키 아래 다른 하위 값을 남긴다', async () => {
        m = await mount();
        await waitFor(() => expect(Number(text('remaining'))).toBeGreaterThan(100), { timeout: 2000 });

        m.app.setGlobalState({ _local: { ...m.app.getGlobalState()._local, ui: { panel: 'open', clock: 0 }, form: { name: 'kept', step: 1 } } }, { render: false });
        const g = m.app.getGlobalState();
        await (m.app as any).executeInitActions([
            {
                handler: 'startInterval',
                params: {
                    id: 'test_nested_tick',
                    intervalMs: 40,
                    actions: [{ handler: 'setState', params: { target: 'local', ui: { clock: '{{(_local.ui?.clock ?? 0) + 1}}' }, 'form.step': 2 } }],
                },
            },
        ], { _global: g, _local: g._local });

        await waitFor(() => expect(m!.app.getGlobalState()._local.ui.clock).toBeGreaterThan(1), { timeout: 2000 });
        expect(m.app.getGlobalState()._local.ui.panel).toBe('open');
        expect(m.app.getGlobalState()._local.form).toEqual({ name: 'kept', step: 2 });
    });

    /**
     * `render: false` 틱의 깊은 쓰기는 루트를 다시 그리지 않으므로 getLocal 대체 스냅숏을 엔진이 직접 지워야 한다 — 남으면 뒤이은 다른
     * B 쓰기 위에 틱 시점 값이 얹힌다(사례 대조 S2 state-setstate 사례 49 조건).
     *
     * @scenario case=merge_rules
     *
     * @effects tick_merge_matches_engine_rules
     */
    it('render:false 틱의 깊은 쓰기 뒤에도 getLocal 대체 스냅숏이 남지 않는다', async () => {
        m = await mount();
        await waitFor(() => expect(Number(text('remaining'))).toBeGreaterThan(100), { timeout: 2000 });

        // 루트를 다시 그리는 다른 틱을 멈춘다 — 그 렌더의 커밋이 스냅숏을 지워 이 모양을 가린다
        (engine.getActionDispatcher() as any).handleStopInterval({ id: 'test_identity_tick' });
        m.app.setGlobalState({ _local: { ...m.app.getGlobalState()._local, meter: { seen: 0, label: 'x' } } }, { render: false });
        const g = m.app.getGlobalState();
        await (m.app as any).executeInitActions([
            {
                handler: 'startInterval',
                params: {
                    id: 'test_silent_tick',
                    intervalMs: 40,
                    actions: [{ handler: 'setState', render: false, params: { target: 'local', meter: { seen: '{{(_local.meter?.seen ?? 0) + 1}}' } } }],
                },
            },
        ], { _global: g, _local: g._local });

        await waitFor(() => expect(m!.app.getGlobalState()._local.meter.seen).toBeGreaterThan(1), { timeout: 2000 });
        expect((window as any).__g7LastSetLocalSnapshot).toBeUndefined();
        expect(m.app.getGlobalState()._local.meter.label).toBe('x');
    });

    /**
     * 화면을 떠나면(경로 세대가 바뀌면) init 에서 등록한 타이머는 스스로 멈추고 다음 화면 `_local` 에 쓰지 않는다.
     * 종전에는 `stopAllIntervals` 를 부르는 곳이 없어 타이머가 계속 돌며 다음 화면 저장소 B 에 앞 화면 키를 썼다.
     *
     * @scenario case=route_changed
     *
     * @effects tick_stops_after_route_change
     */
    it('경로 세대가 바뀌면 타이머가 멈추고 다음 화면 _local 에 앞 화면 키를 쓰지 않는다', async () => {
        m = await mount();
        await waitFor(() => expect(Number(text('remaining'))).toBeGreaterThan(100), { timeout: 2000 });
        const dispatcher = engine.getActionDispatcher()! as any;
        expect(dispatcher.intervals.has('test_identity_tick')).toBe(true);

        // 경로 전환의 첫 단계와 같다 — 세대를 올리고 다른 레이아웃의 빈 _local 로 바꾼다
        m.app.currentRouteChangeId += 1;
        m.app.setGlobalState({ _local: { other: 'page' } }, { render: false });

        await waitFor(() => expect(dispatcher.intervals.has('test_identity_tick')).toBe(false), { timeout: 2000 });
        await new Promise((r) => setTimeout(r, 200));
        expect(m.app.getGlobalState()._local).toEqual({ other: 'page' });
    });
});
