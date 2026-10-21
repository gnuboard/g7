// e2e:allow 실제 렌더 경로(TemplateApp·renderTemplate·SlotProvider·슬롯 주입) 렌더 단언 — 브라우저 축은 이커머스 Playwright `tests/Playwright/specs/admin/admin-click-only-keyboard.spec.ts`(관리자 머리글 통화 선택 Enter 뒤 초점·Tab 으로 항목)가 덮는다.
/**
 * @file headerCurrencySelectorSlotOpenFocus.test.tsx
 * @description 헤더 통화 선택을 키보드로 연 뒤 초점이 통화 단추에 남는다 — 슬롯 주입 실물 구조 (7.1.1 E2E 결함)
 *
 * 결함(관리자 머리글, E2E `admin-click-only-keyboard.spec.ts:134`): 통화 단추에서 Enter 로 목록을 열면 초점이 단추를 떠나
 * Tab 으로 항목에 닿지 못했다.
 *
 * 원인(엔진 렌더 순서): 슬롯 노드는 `window.__slotContextValue` 가 열려 있을 때만 제자리 그리기를 건너뛴다
 * (`DynamicRenderer.tsx` 「slot 속성이 있는 컴포넌트는 원래 위치에서 렌더링하지 않음」). 그런데 그 값은 `SlotProvider` 의
 * `useLayoutEffect` 가 첫 그림 **뒤에** 연다(`SlotContext.tsx`). 그래서 첫 그림에서 조각이 주입 앵커 자리에 한 벌 그려지고,
 * 등록을 받은 SlotContainer 에도 한 벌 그려진다. 앵커 쪽 사본은 다음 다시 그리기 때까지 남는다(앞 순서라 Tab·`.first()` 가 먼저 닿는다).
 * 그 사본의 단추로 목록을 열면 상태 갱신으로 앵커가 다시 그려지며 사본이 빠지고, 초점이 문서 몸통으로 떨어진다.
 *
 * 고침(엔진 무변경 — 엔진의 사본 문제는 넘김): 통화 단추 클릭에 `sirsoft-ecommerce.focusElement`(`defer`)를 이어,
 * 다시 그린 뒤 초점을 잃었으면 살아 있는 통화 단추로 옮긴다.
 *
 * 시험은 운영 렌더 경로(TemplateApp + renderTemplate — SlotProvider 포함)로 앵커 + SlotContainer 를 그린다. 관리자 SlotContainer
 * 는 템플릿 폴더의 React 사본과 섞이지 않게 같은 동작(구독·빈 슬롯 null·root id 스코프 key)을 시험 안에 옮겼다.
 *
 * 축 요약(마커 아님 — 평문): surface=admin_extension. 매핑은 테스트마다 단다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import userEvent from '@testing-library/user-event';
import { waitFor } from '@testing-library/react';
import { TemplateApp } from '@core/TemplateApp';
import * as engine from '@core/template-engine';
import { initializeG7CoreGlobals } from '@core/template-engine/G7CoreGlobals';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import DynamicRenderer from '@core/template-engine/DynamicRenderer';
import { handlerMap } from '../../handlers';

vi.mock('@core/api/ApiClient', () => ({
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
        if (k.startsWith('aria-') || k.startsWith('data-') || ['id', 'type', 'disabled', 'onClick', 'onKeyDown', 'role'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

/**
 * admin_basic `SlotContainer.tsx` 와 같은 동작 — 슬롯 구독, 빈 슬롯이면 null, root id 를 `{id}__{컨테이너 id}` 로 스코프한 key.
 *
 * @param props slotId·id·className
 * @returns 슬롯 내용
 */
function SlotContainer({ slotId, id, className }: { slotId: string; id?: string; className?: string }) {
    const [regs, setRegs] = React.useState<any[]>([]);
    const [, force] = React.useState({});
    React.useEffect(() => {
        const ctx = (window as any).__slotContextValue;
        if (!ctx || !ctx.isEnabled) return undefined;
        setRegs(ctx.getSlotComponents(slotId));

        return ctx.subscribeToSlot(slotId, () => {
            setRegs(ctx.getSlotComponents(slotId));
            force({});
        });
    }, [slotId]);
    if (regs.length === 0) return null;
    const g = (window as any).G7Core;

    return React.createElement('div', { className, id }, regs.map((r: any) => {
        const def = id && typeof r.componentDef.id === 'string' ? { ...r.componentDef, id: `${r.componentDef.id}__${id}` } : r.componentDef;
        const env = r.getRenderEnv?.() ?? null;

        return React.createElement(DynamicRenderer as any, {
            key: def.id,
            componentDef: def,
            dataContext: r.dataContext,
            translationContext: r.translationContext,
            registry: env?.componentRegistry ?? g.getComponentRegistry(),
            bindingEngine: env?.bindingEngine ?? g.getDataBindingEngine(),
            translationEngine: env?.translationEngine ?? g.getTranslationEngine(),
            actionDispatcher: env?.actionDispatcher ?? g.getActionDispatcher(),
            parentComponentContext: r.getParentComponentContext?.(),
            parentFormContextProp: r.parentFormContext,
            isRootRenderer: false,
            componentPath: r.componentPath,
        });
    }));
}

/**
 * 조각을 운영 렌더 경로로 그린다 — 주입 앵커(조각 루트, slot=header_currency) + 머리글 SlotContainer.
 *
 * @param name 조각 이름
 */
async function mount(name: string): Promise<void> {
    document.body.innerHTML = '<div id="app"></div>';
    delete (window as any).__slotContextValue;
    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
    const c = (component: any) => ({ component, metadata: {} });
    (ComponentRegistry.getInstance() as any).registry = {
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        Button: c(passthrough('button')),
        Icon: c(() => React.createElement('i', { 'aria-hidden': 'true' })),
        SlotContainer: c(SlotContainer),
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((u: any, o?: any) => app.setGlobalState(u, o));
    // 운영처럼 모듈 핸들러를 `sirsoft-ecommerce.` 접두로 싣는다
    Object.entries(handlerMap).forEach(([n, h]) => dispatcher.registerHandler(`sirsoft-ecommerce.${n}`, h as any));
    app.setGlobalState({
        availableCurrencies: [{ code: 'KRW', symbol: 'W', flag: '' }, { code: 'USD', symbol: '$', flag: '' }],
        availableShippingCountries: [],
        modules: {},
        _local: {},
    }, { render: false });
    const g = app.getGlobalState();
    const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, `../../../extensions/${name}.json`), 'utf8')).injections[0].components[0];
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: `test/${name}-slot-open-focus`,
            components: [
                { id: 'header_currency_inject_anchor', type: 'basic', name: 'Div', children: [fragment] },
                { id: 'header_right', type: 'basic', name: 'Div', children: [
                    { id: 'language_trigger', type: 'basic', name: 'Button', props: { type: 'button', 'aria-haspopup': 'listbox', 'aria-label': 'language' }, text: 'KO' },
                    { id: 'header_currency_slot_desktop', type: 'composite', name: 'SlotContainer', props: { slotId: 'header_currency', className: 'flex items-center' } },
                ] },
            ],
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (u: any) => app.setGlobalState(u) },
    } as any);
    await waitFor(() => expect(document.querySelectorAll('[data-currency-selector]').length).toBeGreaterThan(0));
    await new Promise((r) => setTimeout(r, 300));
}

/** 통화 트리거 목록(문서 순서) */
const triggers = (): HTMLElement[] => Array.from(document.querySelectorAll<HTMLElement>('[data-currency-selector] [aria-haspopup="listbox"]'));

afterEach(() => {
    try {
        engine.destroyTemplate();
    } catch {
        /* 정리 */
    }
    delete (window as any).__templateApp;
    delete (window as any).G7Core;
    document.body.innerHTML = '';
});

describe.each([
    ['관리자', 'header-currency-selector-admin'],
    ['사용자', 'header-currency-selector-user'],
])('%s 헤더 통화 선택 — 슬롯 실물 구조에서 Enter 로 연 뒤 초점 (E2E 실측)', (_label, name) => {
    // @scenario surface=admin_extension
    // @effects currency_dropdown_keeps_focus_on_open
    it('문서 앞쪽 통화 단추(첫 그림 앵커 사본 포함)에서 Enter 로 열면, 다시 그린 뒤에도 초점이 살아 있는 통화 단추에 있고 Tab 으로 항목에 닿는다', async () => {
        await mount(name);
        const user = userEvent.setup();
        const first = triggers()[0];
        expect(first).toBeTruthy();
        first.focus();
        expect(document.activeElement).toBe(first);

        await user.keyboard('{Enter}');
        await waitFor(() => expect(document.querySelectorAll('[role="option"]').length).toBeGreaterThan(0));
        await new Promise((r) => setTimeout(r, 400));

        const live = triggers().filter((t) => t.isConnected);
        expect(live.length).toBeGreaterThan(0);
        expect(document.activeElement, `초점: ${document.activeElement?.tagName}#${(document.activeElement as HTMLElement)?.id ?? ''}`).toBe(live[0]);
        expect(live[0].getAttribute('aria-expanded')).toBe('true');

        await user.tab();
        expect(document.activeElement?.getAttribute('role')).toBe('option');
    });
});

describe('focusElement defer — 다시 그린 뒤 초점을 잃었을 때만 옮긴다', () => {
    // 연 뒤 다시 그리기로 사본이 빠졌을 때 살아 있는 통화 단추로 초점을 옮기는 defer 단위 단언.
    // @scenario surface=admin_extension
    // @effects currency_dropdown_keeps_focus_on_open
    it('사본 상자가 빠져 초점이 몸통으로 떨어지면 붙어 있는 같은 상자의 트리거로 옮긴다', async () => {
        document.body.innerHTML = '<div class="box" id="stale"><button aria-haspopup="listbox">stale</button></div><div class="box" id="live"><button aria-haspopup="listbox">live</button></div>';
        const staleTrigger = document.querySelector<HTMLElement>('#stale button')!;
        staleTrigger.focus();
        expect((handlerMap as any).focusElement({ params: { selector: '[aria-haspopup="listbox"]', closest: '.box', defer: true } }, { event: { target: staleTrigger } })).toBe(true);
        document.getElementById('stale')!.remove();
        await new Promise((r) => setTimeout(r, 200));
        expect(document.activeElement?.textContent).toBe('live');
    });

    // defer 가 사용자가 직접 옮긴 초점은 빼앗지 않는다.
    // @scenario surface=admin_extension
    // @effects currency_dropdown_keeps_focus_on_open
    it('사용자가 그사이 초점을 다른 곳으로 옮겼으면 건드리지 않는다', async () => {
        document.body.innerHTML = '<div class="box"><button aria-haspopup="listbox">t</button></div><input id="other">';
        const trigger = document.querySelector<HTMLElement>('.box button')!;
        trigger.focus();
        (handlerMap as any).focusElement({ params: { selector: '[aria-haspopup="listbox"]', closest: '.box', defer: true } }, { event: { target: trigger } });
        document.getElementById('other')!.focus();
        await new Promise((r) => setTimeout(r, 400));
        expect(document.activeElement?.id).toBe('other');
    });
});
