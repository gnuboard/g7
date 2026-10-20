// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 의 루트 렌더러·저장소 A/B 병합)로 상품 상세 옵션 칸을 그린다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(비회원 상품 상세 「네이비」→「L」→「블랙」)이 덮는다.
/**
 * @file shop-option-select-reset-after-add.test.tsx
 * @description 상품 상세 2단 옵션 — 한 줄이 담긴 뒤 옵션 칸이 비고, 윗칸만 다시 골라도 앞 줄의 아랫칸 값으로 줄이 담기지 않는다
 *
 * 종전: 「네이비」→「L」 로 줄이 담기면 핸들러가 `currentSelection: {}`(shallow)를 썼고 저장소 B·forced·getLocal 은 `{}` 였는데,
 * 화면 칸은 네이비·L 그대로였다. 커스텀 핸들러의 쓰기는 엔진이 `setLocal` 로 승격하고, 그 저장소 A 함수형 갱신 결과가
 * 앞 상태와 다시 깊게 병합돼(빈 객체는 키를 지우지 못한다) A 에 앞 선택이 남는다. 화면 문맥 `deepMerge(B, A)` 가 그 값을
 * 보여 주고, 「블랙」 만 고르면 그 문맥의 옛 「사이즈: L」 과 합쳐져 「블랙/L」 이 저절로 담겼다(7.1.1 7.1.0 에도 있음).
 * 선택칸 컴포넌트는 원인이 아니다 — 실물 Select 는 `value` prop 만 그리는 제어 드롭다운이고, 아래 대역도 같다.
 * 같은 원인의 두 번째 경로: 그 상태에서 담긴 줄의 수량 증가·감소·삭제·추가옵션 변경을 누르면 그 핸들러의 깊은 쓰기가
 * `setLocal` 에서 렌더 문맥 `_local` 의 빠진 잎 키를 저장소 B 에 보충해(addMissingLeafKeys) getLocal·B 까지 앞 선택으로 되살아났다.
 * 고침: 핸들러가 null 을 먼저 쓰고 `{}` 를 쓴다(엔진 무변경) — A 에 앞 선택이 남지 않으니 두 경로가 함께 닫힌다.
 *
 * @scenario-manifest tests/scenarios/shop-option-select-reset-after-add.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import React from 'react';
import { fireEvent, waitFor } from '@testing-library/react';

import purchaseCard from '../../../layouts/partials/shop/detail/_purchase_card.json';
import {
    addSelectedItemIfCompleteHandler,
    updateSelectedItemQuantityHandler,
    removeSelectedItemHandler,
    setBlockAdditionalOptionHandler,
} from '../../handlers/productOptions';
import { TemplateApp } from '@core/TemplateApp';
import * as engine from '@core/template-engine';
import { initializeG7CoreGlobals } from '@core/template-engine/G7CoreGlobals';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

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

type Node = Record<string, any>;

/**
 * 트리에서 조건에 맞는 첫 노드
 *
 * @param x 탐색 대상
 * @param pred 조건
 * @returns 노드 또는 null
 */
function findNode(x: any, pred: (n: Node) => boolean): Node | null {
    if (Array.isArray(x)) {
        for (const y of x) {
            const r = findNode(y, pred);
            if (r) return r;
        }

        return null;
    }
    if (!x || typeof x !== 'object') return null;
    if (x.type && x.name && pred(x)) return x;
    for (const v of Object.values(x)) {
        const r = findNode(v, pred);
        if (r) return r;
    }

    return null;
}

/** 실물 옵션 선택 섹션(옵션 그룹별 Select 반복) */
const optionSection = findNode(purchaseCard, (n) => n.id === 'option-select')!;

/** 실물 「선택한 상품」 줄 목록(줄 삭제·추가옵션·수량 조절) — 구매 카드에서 `selectedOptionItems.length > 0` 일 때 서는 블록 */
const selectedItemsSection = (purchaseCard as any).children.find((n: Node) =>
    n.name === 'Div' && String(n.if ?? '').includes('_local.selectedOptionItems.length > 0'))!;

/** 담긴 줄을 화면 문맥으로 읽는 탐침 — 실물 줄 목록과 같은 `_local.selectedOptionItems` 를 읽는다 */
const itemsProbe: Node = {
    id: 'items_probe',
    type: 'basic',
    name: 'Span',
    props: { 'data-testid': 'items-probe' },
    text: "{{(_local.selectedOptionItems ?? []).map(i => i.id).join(',')}}",
};

/** 개발 DB 상품 58 과 같은 모양 — 색상(네이비·블랙) × 사이즈(M·L) */
const COMBOS = [['네이비', 'M'], ['네이비', 'L'], ['블랙', 'M'], ['블랙', 'L']];
const PRODUCT = {
    id: 58,
    sales_status: 'on_sale',
    has_options: true,
    option_groups: [
        { name: '색상', name_localized: '색상', values_localized: ['네이비', '블랙'] },
        { name: '사이즈', name_localized: '사이즈', values_localized: ['M', 'L'] },
    ],
    options: COMBOS.map(([color, size], i) => ({
        id: 900 + i,
        is_active: true,
        stock_quantity: 10,
        selling_price: 10000,
        option_values: { 색상: color, 사이즈: size },
        option_values_localized: { 색상: color, 사이즈: size },
    })),
    additional_options: [],
};

/** 같은 상품에 비필수 추가옵션 그룹(포장) 하나 — 줄 안 추가옵션 변경 축에서만 쓴다 */
const PRODUCT_WITH_ADDITIONAL = {
    ...PRODUCT,
    additional_options: [{
        id: 7,
        name: '포장',
        is_required: false,
        values: [
            { id: 70, name: '기본포장', price_adjustment: 0, is_default: true },
            { id: 71, name: '선물포장', price_adjustment: 2000, is_default: false },
        ],
    }],
};

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('aria-') || k.startsWith('data-') || ['id', 'role'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

/**
 * basic Select 대역 — 실물(components/basic/Select.tsx)은 options 가 있으면 브라우저 select 를 쓰지 않는 제어 드롭다운이다:
 * 보이는 글자는 오직 `value` prop 으로 고른 선택지 라벨이고(내부 값 상태 없음), 고르면 `onChange({ target: { value } })` 를 부른다.
 * 대역도 같은 두 가지만 한다 — 화면 값 = 받은 `value` prop. 실물을 그대로 쓰지 않는 것은 템플릿 패키지의 React 와 코어 엔진의
 * React 가 시험 환경에서 서로 다른 사본으로 풀리기 때문이다.
 *
 * @param props 선택칸 속성
 * @returns 선택칸
 */
function SelectStandIn({ name, value, onChange, options, disabled, 'aria-label': aria, 'data-testid': testId }: any) {
    return React.createElement('select', {
        name,
        'aria-label': aria,
        'data-testid': testId,
        disabled: !!disabled,
        value: value ?? '',
        onChange: (e: any) => onChange?.({ target: { value: e.target.value }, preventDefault: () => {}, stopPropagation: () => {}, type: 'change' }),
    }, (options ?? []).map((o: any) => React.createElement('option', { key: String(o.value), value: o.value }, o.label)));
}

/**
 * basic QuantitySelector 대역 — 실물(components/composite/QuantitySelector.tsx)처럼 「-」·「+」 가 `onChange(value ∓ 1)` 을 부른다.
 *
 * @param props 수량 선택기 속성
 * @returns 수량 선택기
 */
function QuantitySelectorStandIn({ value, onChange }: any) {
    const n = Number(value) || 1;

    return React.createElement('div', { 'data-qty': String(n) },
        React.createElement('button', { type: 'button', 'data-qty-dec': '', onClick: () => onChange?.(n - 1) }, '-'),
        React.createElement('button', { type: 'button', 'data-qty-inc': '', onClick: () => onChange?.(n + 1) }, '+'));
}

function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        Label: c(passthrough('label')),
        H4: c(passthrough('h4')),
        Button: c(({ children, text, className, onClick, ...rest }: any) =>
            React.createElement('button', { type: 'button', className, onClick, 'aria-label': rest['aria-label'] }, children ?? text)),
        Icon: c(() => null, { autoBinding: false }),
        Input: c(({ name, value, onChange, onInput }: any) =>
            React.createElement('input', { name, value: value ?? '', onChange: onChange ?? (() => {}), onInput })),
        Select: c(SelectStandIn),
        QuantitySelector: c(QuantitySelectorStandIn),
    };
}

interface Mounted {
    app: any;
    stop: () => void;
}

/**
 * 운영과 같은 구조(TemplateApp 전역 상태 + renderTemplate 루트 렌더러)로 옵션 섹션을 띄운다.
 * 페이지 `_local`(저장소 B) 초기값은 상품 상세 init(`show.json` 의 `selectedOptionItems: []`·`currentSelection: {}`)과 같다.
 *
 * @returns 띄운 앱
 */
async function mount(product: Record<string, any> = PRODUCT): Promise<Mounted> {
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
    (ComponentRegistry.getInstance() as any).registry = registryEntries();
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));
    dispatcher.registerHandler('sirsoft-basic.addSelectedItemIfComplete', addSelectedItemIfCompleteHandler as any);
    dispatcher.registerHandler('sirsoft-basic.updateSelectedItemQuantity', updateSelectedItemQuantityHandler as any);
    dispatcher.registerHandler('sirsoft-basic.removeSelectedItem', removeSelectedItemHandler as any);
    dispatcher.registerHandler('sirsoft-basic.setBlockAdditionalOption', setBlockAdditionalOptionHandler as any);

    app.setGlobalState({ _local: { selectedOptionItems: [], currentSelection: {} } }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/shop-option-select-reset',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: [optionSection, selectedItemsSection, itemsProbe] }],
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates), product: { data: product } },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="option-group-0"]')).not.toBeNull());

    return {
        app,
        stop: () => {
            try { engine.destroyTemplate(); } catch { /* 정리 */ }
            delete (window as any).__templateApp;
            delete (window as any).G7Core;
            document.body.innerHTML = '';
        },
    };
}

const selectAt = (i: number): HTMLSelectElement => document.querySelector(`[data-testid="option-group-${i}"]`) as HTMLSelectElement;
const probe = (): string => document.querySelector('[data-testid="items-probe"]')?.textContent ?? '';

/** 칸 하나를 고른다(실물 Select 의 handleSelect 와 같은 onChange) */
async function choose(i: number, value: string): Promise<void> {
    fireEvent.change(selectAt(i), { target: { value } });
    await new Promise((r) => setTimeout(r, 30));
}

describe('상품 상세 2단 옵션 — 담긴 뒤 옵션 칸 초기화 (7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    /**
     * @scenario step=after_add
     * @effects option_selects_cleared_after_line_added
     */
    it('「네이비」→「L」 로 한 줄이 담기면 두 칸이 모두 비어 보인다', async () => {
        m = await mount();
        await choose(0, '네이비');
        await waitFor(() => expect(selectAt(1).disabled).toBe(false));
        await choose(1, 'L');
        await waitFor(() => expect(probe()).toBe('네이비_L'));

        await waitFor(() => {
            expect(selectAt(0).value).toBe('');
            expect(selectAt(1).value).toBe('');
        });
    });

    /**
     * @scenario step=reselect_upper_only
     * @effects upper_reselect_does_not_reuse_previous_lower_value
     */
    it('담긴 뒤 「블랙」 만 고르면 줄이 늘지 않고, 「M」 까지 고르면 블랙/M 이 담긴다', async () => {
        m = await mount();
        await choose(0, '네이비');
        await waitFor(() => expect(selectAt(1).disabled).toBe(false));
        await choose(1, 'L');
        await waitFor(() => expect(probe()).toBe('네이비_L'));

        await choose(0, '블랙');
        await new Promise((r) => setTimeout(r, 50));
        expect(probe()).toBe('네이비_L');
        expect(selectAt(0).value).toBe('블랙');
        expect(selectAt(1).value).toBe('');

        await choose(1, 'M');
        await waitFor(() => expect(probe()).toBe('네이비_L,블랙_M'));
    });

    /**
     * @scenario step=duplicate_reselect
     * @effects option_selects_cleared_after_line_added
     */
    it('이미 담긴 조합을 다시 고르면 줄이 늘지 않고 두 칸이 비어 보인다', async () => {
        m = await mount();
        await choose(0, '네이비');
        await waitFor(() => expect(selectAt(1).disabled).toBe(false));
        await choose(1, 'L');
        await waitFor(() => expect(probe()).toBe('네이비_L'));
        await waitFor(() => expect(selectAt(1).disabled).toBe(true));

        await choose(0, '네이비');
        await waitFor(() => expect(selectAt(1).disabled).toBe(false));
        await choose(1, 'L');
        await new Promise((r) => setTimeout(r, 50));
        expect(probe()).toBe('네이비_L');
        await waitFor(() => {
            expect(selectAt(0).value).toBe('');
            expect(selectAt(1).value).toBe('');
        });
    });

    /**
     * 줄을 담은 뒤 그 줄을 만지는 동작 — 수량 증가·감소·줄 삭제·추가옵션 변경. 각 동작 뒤에도 옵션 칸은 비어 있어야 하고
     * (앞 선택이 되살아나지 않는다), 윗칸 「블랙」 만 골랐을 때 앞 아랫칸 「L」 로 줄이 담기지 않아야 한다(실측 — 수량 증가 뒤 되살아남).
     */
    const LINE_ACTIONS: Array<{ step: string; product: Record<string, any>; act: () => Promise<void>; linesAfter: string }> = [
        {
            step: 'quantity_increase_after_add',
            product: PRODUCT,
            act: async () => {
                fireEvent.click(document.querySelector('[data-qty-inc]') as HTMLElement);
                await waitFor(() => expect(document.querySelector('[data-qty]')?.getAttribute('data-qty')).toBe('2'));
            },
            linesAfter: '네이비_L',
        },
        {
            step: 'quantity_decrease_after_add',
            product: PRODUCT,
            act: async () => {
                fireEvent.click(document.querySelector('[data-qty-inc]') as HTMLElement);
                await waitFor(() => expect(document.querySelector('[data-qty]')?.getAttribute('data-qty')).toBe('2'));
                fireEvent.click(document.querySelector('[data-qty-dec]') as HTMLElement);
                await waitFor(() => expect(document.querySelector('[data-qty]')?.getAttribute('data-qty')).toBe('1'));
            },
            linesAfter: '네이비_L',
        },
        {
            step: 'remove_item_after_add',
            product: PRODUCT,
            act: async () => {
                fireEvent.click(document.querySelector('button[aria-label*="delete"]') as HTMLElement);
                await waitFor(() => expect(probe()).toBe(''));
            },
            linesAfter: '',
        },
        {
            step: 'additional_option_change_after_add',
            product: PRODUCT_WITH_ADDITIONAL,
            act: async () => {
                fireEvent.change(document.querySelector('[data-testid="add-option-0-7"]') as HTMLSelectElement, { target: { value: '71' } });
                await waitFor(() => expect((document.querySelector('[data-testid="add-option-0-7"]') as HTMLSelectElement).value).toBe('71'));
            },
            linesAfter: '네이비_L',
        },
    ];

    // 마커 파서는 주석 블록 하나에서 시나리오 조합을 하나만 읽으므로 축 값마다 블록을 둔다
    /**
     * @scenario step=quantity_increase_after_add
     * @effects line_action_does_not_revive_previous_selection, upper_reselect_does_not_reuse_previous_lower_value
     */
    /**
     * @scenario step=quantity_decrease_after_add
     * @effects line_action_does_not_revive_previous_selection, upper_reselect_does_not_reuse_previous_lower_value
     */
    /**
     * @scenario step=remove_item_after_add
     * @effects line_action_does_not_revive_previous_selection, upper_reselect_does_not_reuse_previous_lower_value
     */
    /**
     * @scenario step=additional_option_change_after_add
     * @effects line_action_does_not_revive_previous_selection, upper_reselect_does_not_reuse_previous_lower_value
     */
    it.each(LINE_ACTIONS)('$step: 줄을 만진 뒤에도 옵션 칸이 비어 있고, 「블랙」 만 골라도 줄이 담기지 않는다', async ({ product, act, linesAfter }) => {
        m = await mount(product);
        await choose(0, '네이비');
        await waitFor(() => expect(selectAt(1).disabled).toBe(false));
        await choose(1, 'L');
        await waitFor(() => expect(probe()).toBe('네이비_L'));
        await waitFor(() => expect(selectAt(0).value).toBe(''));

        await act();
        await new Promise((r) => setTimeout(r, 50));
        expect(selectAt(0).value).toBe('');
        expect(selectAt(1).value).toBe('');
        expect((window as any).G7Core.state.getLocal().currentSelection ?? {}).toEqual({});

        await choose(0, '블랙');
        await new Promise((r) => setTimeout(r, 50));
        expect(probe()).toBe(linesAfter);
        expect(selectAt(1).value).toBe('');
    });
});

/**
 * 표시 잔존 갈래(7.1.1 출시 전 실측 106회 중 6회 재현, 간격 무관): 색상을 고른 뒤 다른 전역 쓰기(데이터 도착 등)로 루트가 다시 그려지면 렌더 사본
 * `dataContext._local` 에 `{색상: …}` 이 실린다. 줄이 담기며 A·B 가 `{}` 로 비어도 깊은 병합이 사본의 키를 지우지 못해 색상 칸만
 * 앞 값이 남았고, 그 상태에서 줄을 다시 그리는 동작(추가옵션·「+」)이 그 값을 B 에 되썼다. 핸들러의 null 선행(회피 수정)으로는
 * 닫히지 않는 엔진 갈래다 — engine-v1.81.2 렌더 병합(늦은 B 사본 전용 키 제거)이 닫는다.
 */
describe('상품 상세 2단 옵션 — 고르는 사이 루트가 다시 그려져도 앞 선택이 남거나 되살아나지 않는다 (7.1.1 engine-v1.81.2)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
        // 엔진 전역 보조 캐시는 stop() 이 지우지 않는다 — 다음 사례로 넘기지 않는다
        (window as any).__g7PendingLocalState = null;
        (window as any).__g7ForcedLocalFields = undefined;
        (window as any).__g7SetLocalOverrideKeys = undefined;
        (window as any).__g7LastSetLocalSnapshot = undefined;
    });

    const REDRAW_ACTIONS: Array<{ step: string; act: () => Promise<void> }> = [
        {
            step: 'additional_option_change_after_rerender',
            act: async () => {
                fireEvent.change(document.querySelector('[data-testid="add-option-0-7"]') as HTMLSelectElement, { target: { value: '71' } });
                await waitFor(() => expect((document.querySelector('[data-testid="add-option-0-7"]') as HTMLSelectElement).value).toBe('71'));
            },
        },
        {
            step: 'quantity_increase_after_rerender',
            act: async () => {
                fireEvent.click(document.querySelector('[data-qty-inc]') as HTMLElement);
                await waitFor(() => expect(document.querySelector('[data-qty]')?.getAttribute('data-qty')).toBe('2'));
            },
        },
    ];

    /**
     * @scenario step=additional_option_change_after_rerender
     * @effects option_selects_cleared_after_line_added, line_action_does_not_revive_previous_selection
     */
    /**
     * @scenario step=quantity_increase_after_rerender
     * @effects option_selects_cleared_after_line_added, line_action_does_not_revive_previous_selection
     */
    it.each(REDRAW_ACTIONS)('$step: 「네이비」 → (루트 다시 그리기) → 「L」 로 담긴 뒤 칸이 비고, 줄 동작 뒤에도 getLocal 이 빈 값이다', async ({ act }) => {
        m = await mount(PRODUCT_WITH_ADDITIONAL);
        await choose(0, '네이비');
        await waitFor(() => expect(selectAt(1).disabled).toBe(false));
        // 운영의 「다른 전역 쓰기로 루트가 다시 그려짐」 — 실제 writer(TemplateApp.setGlobalState, render 기본)
        m.app.setGlobalState({ __probeTick: 1 });
        await new Promise((r) => setTimeout(r, 60));
        await choose(1, 'L');
        await waitFor(() => expect(probe()).toBe('네이비_L'));
        await new Promise((r) => setTimeout(r, 60));

        expect(selectAt(0).value).toBe('');
        expect(selectAt(1).value).toBe('');

        await act();
        await new Promise((r) => setTimeout(r, 50));
        expect(selectAt(0).value).toBe('');
        expect(m.app.getGlobalState()._local.currentSelection).toEqual({});
        expect((window as any).G7Core.state.getLocal().currentSelection ?? {}).toEqual({});

        // 윗칸이 비었으니 아랫칸은 잠겨 있다 — 앞 「색상」 이 되살아났다면 풀린다
        expect(selectAt(1).disabled).toBe(true);
        expect(probe()).toBe('네이비_L');
    });
});
