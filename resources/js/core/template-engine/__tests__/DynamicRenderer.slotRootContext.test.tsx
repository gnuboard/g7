// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 루트 렌더러·SlotProvider 등록·SlotContainer 계약 그대로의 대역)로 루트급 slot 노드의 슬롯 내용 문맥을 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 엔진 매트릭스(슬롯 화면)가 덮는다
/**
 * @file DynamicRenderer.slotRootContext.test.tsx
 * @description 레이아웃 최상위(루트급) 노드가 `slot` 이면 슬롯 내용이 그 노드의 화면 문맥을 받는다 — 비운 값이 슬롯 안에서만 되살아나지 않는다 (engine-v1.81.2, 7.1.1)
 *
 * 루트급 렌더러는 늦은 B 사본에만 남은 키를 화면 문맥에서 뺀다(사례 50 `dropLaggingRenderBaseKeys`). slot 노드는 원래 자리에 그려지지 않고
 * SlotContext 에 등록돼 SlotContainer 가 그린다. 등록 문맥이 받은 prop(늦은 사본)이라, 슬롯 내용(자식 렌더러)이 그 사본과 이 노드의
 * 상태를 다시 깊게 병합해 루트가 뺀 키가 슬롯 안에서만 되살아났다. 자식 노드의 slot 은 이미 부모 화면 문맥을 받아 해당 없다.
 *
 * SlotContainer 대역은 번들 템플릿(`templates/_bundled/sirsoft-basic/src/components/composite/SlotContainer.tsx`)과 같은 계약이다 — 등록의 dataContext·getParentComponentContext 로
 * G7Core.getDynamicRenderer() 를 그린다. 저장소 B 는 손으로 채우지 않는다.
 *
 * @scenario-manifest tests/scenarios/slot-root-context.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import React, { useEffect, useState } from 'react';
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

/** 번들 SlotContainer 와 같은 계약의 대역 */
const SlotContainerStandIn = ({ slotId }: any) => {
    const [regs, setRegs] = useState<any[]>([]);
    useEffect(() => {
        const ctx = (window as any).__slotContextValue;
        if (!ctx?.isEnabled) return undefined;
        setRegs(ctx.getSlotComponents(slotId));

        return ctx.subscribeToSlot(slotId, () => setRegs([...ctx.getSlotComponents(slotId)]));
    }, [slotId]);
    const g7 = (window as any).G7Core;
    const DR = g7?.getDynamicRenderer?.();
    if (!DR) return null;

    return React.createElement('div', { 'data-testid': `slot-${slotId}` }, regs.map((r: any) => React.createElement(DR, {
        key: r.componentDef.id,
        componentDef: r.componentDef,
        dataContext: r.dataContext,
        translationContext: r.translationContext,
        registry: g7.getComponentRegistry(),
        bindingEngine: g7.getDataBindingEngine(),
        translationEngine: g7.getTranslationEngine(),
        actionDispatcher: g7.getActionDispatcher(),
        parentComponentContext: r.getParentComponentContext?.(),
        parentFormContextProp: r.parentFormContext,
        isRootRenderer: false,
    })));
};

const show = (id: string, expr: string): Node => ({ id: `show_${id}`, type: 'basic', name: 'Span', props: { 'data-testid': id }, text: `{{${expr}}}` });
const button = (text: string, actions: any[]): Node => ({ id: `btn_${text}`, type: 'basic', name: 'Button', text, actions });

/** 머리글 자리(슬롯 컨테이너)와, 최상위에서 그 슬롯으로 가는 옵션 선택 조각 */
const LAYOUT: Node[] = [
    { id: 'header', type: 'basic', name: 'Div', children: [{ id: 'slot_top', type: 'basic', name: 'SlotContainer', props: { slotId: 'top' } }] },
    {
        id: 'picker', type: 'basic', name: 'Div', slot: 'top', children: [
            button('pick', [{ type: 'click', handler: 'setState', params: { target: 'local', sel: { color: 'navy' } } }]),
            button('clear', [{ type: 'click', handler: 'test.shallowClear' }]),
            show('slot-sel', 'JSON.stringify(_local.sel ?? null)'),
        ],
    },
];

interface Mounted {
    app: any;
    stop: () => void;
}

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
        SlotContainer: { component: SlotContainerStandIn, metadata: {} },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));
    // 커스텀 핸들러의 shallow 비우기(사례 50 과 같은 쓰기 — 저장소 B 는 render:false)
    dispatcher.registerHandler('test.shallowClear', (_a: any, ctx: any) => { ctx.setState({ sel: {}, __mergeMode: 'shallow' }); });

    app.setGlobalState({ _local: { sel: {} } }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: { version: '1.0.0', layout_name: 'test/slot-root-context', components: LAYOUT } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (u: any) => app.setGlobalState(u) },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="slot-sel"]')).not.toBeNull());

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
            document.body.innerHTML = '';
        },
    };
}

const text = (id: string): string => (document.querySelector(`[data-testid="${id}"]`)?.textContent ?? '');
const settle = (ms = 40): Promise<void> => new Promise((r) => setTimeout(r, ms));
async function click(label: string): Promise<void> {
    const b = Array.from(document.querySelectorAll('button')).find((x) => (x.textContent ?? '') === label) as HTMLElement;
    fireEvent.click(b);
    await settle(80);
}

describe('[case:components-misc-root-slot-lagging-render-base] 루트급 slot 노드의 슬롯 내용은 그 노드의 화면 문맥을 받는다 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    /**
     * @scenario case=root_slot_shallow_clear_after_root_render
     * @effects slot_content_matches_owner_screen
     */
    it('고른 뒤 루트가 다시 그려지고(늦은 사본에 선택) 핸들러가 shallow `{}` 로 비우면 슬롯 안 표시도 빈 값이다', async () => {
        m = await mount();
        await click('pick');
        expect(text('slot-sel')).toBe('{"color":"navy"}');
        m.app.setGlobalState({ __tick: 1 });
        await settle(80);

        await click('clear');

        expect(m.app.getGlobalState()._local.sel).toEqual({});
        expect(text('slot-sel')).toBe('{}');
    });

    /**
     * @scenario case=root_slot_pick_control
     * @effects slot_content_matches_owner_screen
     */
    it('대조군: 슬롯 안에서 고른 값은 슬롯 안에 그대로 보인다(등록 문맥이 바뀌어도 종전과 같은 값)', async () => {
        m = await mount();
        await click('pick');
        m.app.setGlobalState({ __tick: 2 });
        await settle(80);

        expect(text('slot-sel')).toBe('{"color":"navy"}');
    });
});
