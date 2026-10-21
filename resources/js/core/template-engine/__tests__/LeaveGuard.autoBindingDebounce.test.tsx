/**
 * @file LeaveGuard.autoBindingDebounce.test.tsx
 *
 * @description 레이아웃 이탈 가드 — 자동바인딩 디바운스 대기 입력을 읽기 전용으로 「변경 있음」 으로 본다 (engine-v1.82.0, 7.1.1)
 *
 * 디바운스 폼(`data-key` + `data-debounce`)에서 마지막 입력 뒤 디바운스 시간 안에는 그 입력이 아직 상태(저장소 B·pending)에 없다.
 * 링크·단추 이동은 디스패처가 먼저 flush 하지만 브라우저 뒤로·새로고침·닫기는 액션이 아니라 flush 가 없다. 그래서 가드가
 * 대기 예약의 **존재만** 읽어(실행·정리하지 않음) 묻는다. 실물 TemplateApp·엔진·DynamicRenderer 자동바인딩으로 밟는다.
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
import { installLeaveGuardUnloadListener, resetLeaveGuardForTests } from '../../routing/LeaveGuard';

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

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('data-') || ['id', 'type', 'onClick'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

const InputStandIn = ({ name, value, onChange }: any) =>
    React.createElement('input', { 'data-testid': `input-${name}`, name, value: value ?? '', onChange: onChange ?? (() => {}) });

/** 글쓰기 폼과 같은 모양 — 칸은 디바운스 자동바인딩, 변경 표시(hasChanges)는 저장 성공 등 다른 동작이 다룬다 */
const PAGE = [{
    id: 'post_form',
    type: 'basic',
    name: 'Div',
    dataKey: 'form',
    debounce: 300,
    children: [{ id: 'title_input', type: 'basic', name: 'Input', props: { name: 'title' } }],
}];

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));
const input = (name: string): HTMLInputElement => document.querySelector(`[data-testid="input-${name}"]`) as HTMLInputElement;

/** 상태 경로 전부의 사본 — 판정 전후 비교용 */
const snapshotStateStores = (app: any): string => JSON.stringify({
    globalState: app.getGlobalState(),
    pending: (window as any).__g7PendingLocalState ?? null,
    forced: (window as any).__g7ForcedLocalFields ?? null,
    override: (() => {
        const keys = (window as any).__g7SetLocalOverrideKeys;

        return keys instanceof Set ? [...keys] : keys ?? null;
    })(),
    lastSnapshot: (window as any).__g7LastSetLocalSnapshot ?? null,
    sequenceSync: (window as any).__g7SequenceLocalSync ?? null,
});

const fireUnload = (): Event => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);

    return event;
};

async function mount(meta: Record<string, any>): Promise<{ app: any; stop: () => void }> {
    resetLeaveGuardForTests();
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
        Input: { component: InputStandIn, metadata: {} },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));
    installLeaveGuardUnloadListener();

    app.setGlobalState({ _local: { form: { title: '' }, hasChanges: false } }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: { version: '1.0.0', layout_name: 'board/form', meta, components: PAGE } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates) },
    } as any);
    await waitFor(() => expect(input('title')).not.toBeNull());

    return {
        app,
        stop: () => {
            try { engine.destroyTemplate(); } catch { /* 정리 */ }
            resetLeaveGuardForTests();
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

describe('레이아웃 이탈 가드 — 자동바인딩 디바운스 대기 입력 (engine-v1.82.0)', () => {
    let m: { app: any; stop: () => void } | null = null;

    afterEach(async () => {
        await settle(350);
        m?.stop();
        m = null;
    });

    // @scenario exit_path=pending_debounce
    // @effects pending_debounce_counts_as_dirty, guard_reads_state_without_writing
    it('마지막 입력 뒤 디바운스 시간 안 새로고침·닫기 — 대기 입력만으로 묻고, 상태는 판정 전후 같다', async () => {
        m = await mount({ leave_guard: '{{_local.hasChanges === true}}' });
        fireEvent.change(input('title'), { target: { value: '첫 문장' } });
        await settle(40);
        expect(m.app.getGlobalState()._local.form.title, '아직 디바운스 대기 중').toBe('');
        const before = snapshotStateStores(m.app);
        const shownBefore = input('title').value;

        expect((window as any).G7Core.navigation.isLeaveGuarded()).toBe(true);
        expect(fireUnload().defaultPrevented).toBe(true);

        expect(snapshotStateStores(m.app), '판정이 대기 쓰기를 flush·정리하면 안 된다').toBe(before);
        expect(input('title').value, '칸 표시 그대로').toBe(shownBefore);

        // 대기 쓰기는 자기 시간에 그대로 실행된다
        await settle(350);
        expect(m.app.getGlobalState()._local.form.title).toBe('첫 문장');
    });

    // @scenario exit_path=pending_debounce
    // @effects clean_leave_does_not_ask
    it('대기 입력이 상태에 닿은 뒤·변경 표시 거짓 — 묻지 않는다', async () => {
        m = await mount({ leave_guard: '{{_local.hasChanges === true}}' });
        fireEvent.change(input('title'), { target: { value: '첫 문장' } });
        await settle(350);

        expect((window as any).G7Core.navigation.isLeaveGuarded()).toBe(false);
        expect(fireUnload().defaultPrevented).toBe(false);
    });

    // @scenario exit_path=pending_debounce
    // @effects clean_leave_does_not_ask
    it('입력 없음 — 묻지 않는다', async () => {
        m = await mount({ leave_guard: '{{_local.hasChanges === true}}' });

        expect(fireUnload().defaultPrevented).toBe(false);
    });

    // @scenario exit_path=pending_debounce
    // @effects undeclared_guard_keeps_previous_behavior
    it('가드를 선언하지 않은 화면은 대기 중이어도 묻지 않는다 (종전 동작)', async () => {
        m = await mount({});
        fireEvent.change(input('title'), { target: { value: '첫 문장' } });
        await settle(40);

        expect(fireUnload().defaultPrevented).toBe(false);
    });
});
