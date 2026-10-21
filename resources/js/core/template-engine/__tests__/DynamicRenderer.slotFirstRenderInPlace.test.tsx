// e2e:allow 운영 렌더 경로(TemplateApp + renderTemplate 의 SlotProvider)로 슬롯 노드의 첫 그림을 잰다 — 브라우저 축은 7.1.1 출시 전 브라우저 재실측(2026-10-19, 관리자·사용자 머리글 통화 단추 개수 1·Tab 순서)과 E2E 가 덮는다
/**
 * @file DynamicRenderer.slotFirstRenderInPlace.test.tsx
 * @description `slot` 이 있는 노드가 첫 그림에서 제자리(주입 앵커)에도 한 벌 그려지던 결함 (7.1.1 engine-v1.81.1)
 *
 * 슬롯 노드는 렌더 때 `window.__slotContextValue?.isEnabled` 가 참일 때만 제자리 그리기를 건너뛴다. 그 값은 SlotProvider 의
 * `useLayoutEffect` 가 첫 커밋 **뒤에** 열어, 첫 렌더 패스에서는 슬롯 노드가 제자리(관리자·사용자 `_base` 의 주입 앵커 — 문서 앞)에도
 * 그려졌다. 등록(`useEffect`)은 정상이라 SlotContainer 에도 한 벌이 생겨, 다음 다시 그리기까지 같은 단추가 둘이었다(Tab 순서 맨 앞 사본,
 * 그 사본에서 동작하면 다시 그리기에서 빠지며 초점이 BODY 로). SlotProvider 의 React 문맥 값은 첫 렌더 패스부터 있으므로 그것도 함께 본다.
 *
 * @scenario-manifest tests/scenarios/slot-first-render-in-place.yaml
 *
 * @effects slot_node_never_rendered_in_place_under_provider
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

import { TemplateApp } from '../../TemplateApp';
import * as engine from '../../template-engine';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { ComponentRegistry } from '../ComponentRegistry';
import { useSlotComponents } from '../SlotContext';

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

/** 그려질 때마다 「어디에 그려졌나」 를 남긴다 — 주입 앵커 안이면 제자리 사본 */
const placements: string[] = [];
function Probe({ children }: any) {
    const ref = React.useRef<HTMLButtonElement>(null);
    React.useLayoutEffect(() => {
        placements.push(ref.current?.closest('[data-testid="anchor"]') ? 'in_place' : 'slot_host');
    }, []);

    return React.createElement('button', { ref, type: 'button', 'data-probe': '1' }, children ?? 'KRW');
}

/** SlotContainer 대역 — 그 슬롯에 등록된 수만큼 자리를 그린다(실물 SlotContainer 의 등록 구독과 같은 훅) */
function SlotHost({ slotId }: any) {
    const regs = useSlotComponents(slotId);
    // 앞 형제(주입 앵커)의 등록 효과가 이 구독보다 먼저 돌 수 있다 — 실물 SlotContainer 처럼 마운트 뒤 한 번 다시 읽는다
    const [, rerender] = React.useState(0);
    React.useEffect(() => { rerender((n) => n + 1); }, []);

    return React.createElement('div', { 'data-testid': 'slot-host' },
        regs.map((r: any) => React.createElement(Probe, { key: r.componentDef?.id ?? r.id })));
}

const passthrough = (tag: string) => ({ children, text, ...rest }: any) =>
    React.createElement(tag, { 'data-testid': rest['data-testid'] }, children ?? text);

function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Div: c(passthrough('div')),
        Probe: c(Probe),
        SlotHost: c(SlotHost),
    };
}

async function mount(): Promise<() => void> {
    document.body.innerHTML = '<div id="app"></div>';
    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    delete (window as any).__slotContextValue; // 첫 진입 — 아직 SlotProvider 가 문맥을 열지 않았다
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = registryEntries();
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/slot-first-render',
            components: [{
                id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' },
                children: [
                    // `_admin_base` 의 머리글 통화 주입 앵커와 같은 모양 — 확장 조각이 슬롯 표식을 달고 문서 앞에 들어온다
                    { id: 'header_currency_inject_anchor', type: 'basic', name: 'Div', props: { 'data-testid': 'anchor' },
                        children: [{ id: 'ext_admin_header_currency_selector', type: 'basic', name: 'Probe', slot: 'header_currency_slot' }] },
                    { id: 'header', type: 'basic', name: 'Div', props: { 'data-testid': 'header' },
                        children: [{ id: 'header_currency_slot_host', type: 'basic', name: 'SlotHost', props: { slotId: 'header_currency_slot' } }] },
                ],
            }],
        } as any,
        dataContext: { _global: g, _local: g._local ?? {} },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="page"]')).not.toBeNull());

    return () => {
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
        document.body.innerHTML = '';
    };
}

describe('[case:components-misc-slot-first-render-in-place] 슬롯 노드 — SlotProvider 아래 첫 그림에서도 제자리에 그리지 않는다', () => {
    let stop: (() => void) | null = null;

    afterEach(() => {
        stop?.();
        stop = null;
        placements.length = 0;
    });

    // @scenario entry=first_load
    // @effects slot_node_never_rendered_in_place_under_provider
    it('첫 진입: 슬롯 조각은 슬롯 자리에 한 벌만 그려지고, 주입 앵커에는 한 번도 그려지지 않는다', async () => {
        stop = await mount();
        await waitFor(() => expect(document.querySelectorAll('[data-testid="slot-host"] [data-probe]').length).toBe(1));
        await new Promise((r) => setTimeout(r, 50));

        // 종전: 첫 렌더 패스에 앵커 안에 사본 한 벌(in_place) — 다음 다시 그리기까지 같은 단추가 둘
        expect(placements.filter((p) => p === 'in_place')).toEqual([]);
        expect(document.querySelectorAll('[data-probe]').length).toBe(1);
        expect(document.querySelector('[data-testid="anchor"] [data-probe]')).toBeNull();
    });
});

describe('[case:components-misc-slot-first-render-in-place] 슬롯 노드 — SlotProvider 밖(문맥 기본값)은 종전대로', () => {
    afterEach(() => {
        placements.length = 0;
        document.body.innerHTML = '';
    });

    // @scenario entry=outside_provider
    // @effects slot_node_never_rendered_in_place_under_provider
    it('SlotProvider 도 전역 슬롯 문맥도 없으면 슬롯 노드를 제자리에 그린다(편집기·단독 렌더 종전 동작)', async () => {
        const { render } = await import('@testing-library/react');
        const { default: DynamicRenderer } = await import('../DynamicRenderer');
        const { DataBindingEngine } = await import('../DataBindingEngine');
        const { TranslationEngine } = await import('../TranslationEngine');
        const { ActionDispatcher } = await import('../ActionDispatcher');
        delete (window as any).__slotContextValue;
        const registry = ComponentRegistry.getInstance() as any;
        registry.registry = registryEntries();
        render(React.createElement(DynamicRenderer, {
            componentDef: { id: 'anchor', type: 'basic', name: 'Div', props: { 'data-testid': 'anchor' },
                children: [{ id: 'slot_probe', type: 'basic', name: 'Probe', slot: 'some_slot' }] } as any,
            dataContext: {},
            translationContext: { templateId: 't', locale: 'ko' },
            registry,
            bindingEngine: new DataBindingEngine(),
            translationEngine: new TranslationEngine(),
            actionDispatcher: new ActionDispatcher({}),
        }));
        expect(document.querySelector('[data-testid="anchor"] [data-probe]')).not.toBeNull();
        expect(placements).toEqual(['in_place']);
    });
});
