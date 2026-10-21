// e2e:allow 실제 엔진 구조(TemplateApp·renderTemplate·data-key 디바운스 폼 자동바인딩·ActionDispatcher apiCall)에 실물 레이아웃의 폼 감싸개 속성과 「저장」 단추 동작을 그대로 올려 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(상품 정가·쿠폰 최소주문금액 빠른 저장)이 덮는다
/**
 * @file adminDebounceFormQuickSave.test.tsx
 * @description 관리자 이커머스 디바운스 폼(상품·쿠폰·배송 정책)에서 입력 직후(디바운스 300ms 안) 「저장」 해도 요청 본문에 마지막 입력이 실린다 (engine-v1.81.2, 7.1.1)
 *
 * 종전: 자동바인딩 디바운스 대기 쓰기가 비디바운스 액션 직전 flush 대상이 아니어서, 정가·최소주문금액을 친 뒤 0.1초 안에 「저장」 하면
 * 본문이 옛 값이었고(상품 5/5·쿠폰 3/3), 서버가 옛 값을 저장해 칸까지 옛 값으로 돌아갔다(7.1.0 에도 있음).
 * 이 시험은 실물 레이아웃 JSON 에서 폼 감싸개(`data-key="form" data-debounce="300"`)와 「저장」 단추의 동작(sequence — setState → apiCall
 * body)을 그대로 가져와 입력칸 하나와 함께 그린다. 저장소 B 는 손으로 채우지 않는다(실제 자동바인딩 입력·클릭만).
 *
 * @scenario-manifest tests/scenarios/admin-debounce-form-quick-save.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import React from 'react';
import { fireEvent, waitFor } from '@testing-library/react';
import { TemplateApp } from '@core/TemplateApp';
import * as engine from '@core/template-engine';
import { initializeG7CoreGlobals } from '@core/template-engine/G7CoreGlobals';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import productForm from '../../../layouts/admin/admin_ecommerce_product_form.json';
import couponForm from '../../../layouts/admin/admin_ecommerce_promotion_coupon_form.json';
import shippingPolicyForm from '../../../layouts/admin/admin_ecommerce_shipping_policy_form.json';

vi.mock('@core/api/ApiClient', () => ({
    getApiClient: () => ({
        post: vi.fn().mockResolvedValue({}),
        get: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue('t'),
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
    if (x.name && pred(x)) return x;
    for (const v of Object.values(x)) {
        const r = findNode(v, pred);
        if (r) return r;
    }

    return null;
}

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('aria-') || k.startsWith('data-') || ['id', 'type', 'disabled', 'onClick', 'role'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

const InputStandIn = ({ name, value, onChange }: any) =>
    React.createElement('input', { 'data-testid': `input-${name}`, name, value: value ?? '', onChange: onChange ?? (() => {}) });

function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        Button: c(passthrough('button')),
        Icon: c(() => null, { autoBinding: false }),
        Input: c(InputStandIn),
    };
}

interface Specimen {
    label: string;
    layout: Node;
    saveButtonId: string;
    field: string;
    route: Record<string, any>;
    form: Record<string, any>;
    typed: string[];
}

/** 실물 레이아웃 3곳 — 폼 감싸개 속성·저장 단추 동작은 JSON 그대로, 입력칸은 그 화면의 실제 칸 이름 */
const SPECIMENS: Specimen[] = [
    { label: 'product_list_price', layout: productForm, saveButtonId: 'footer_save_button', field: 'list_price', route: { itemCode: 'P-5' }, form: { list_price: 10000, name: { ko: '상품' } }, typed: ['100001', '1000012', '10000123'] },
    { label: 'coupon_min_order_amount', layout: couponForm, saveButtonId: 'save_button_top', field: 'min_order_amount', route: { id: '50' }, form: { min_order_amount: 1000, name: { ko: '쿠폰' } }, typed: ['10001', '100012', '1000123'] },
    { label: 'shipping_policy_base_fee', layout: shippingPolicyForm, saveButtonId: 'footer_save_button', field: 'base_fee', route: { id: '3' }, form: { base_fee: 3000, name: { ko: '정책' } }, typed: ['30001', '300012', '3000123'] },
];

interface Mounted {
    app: any;
    bodies: Array<Record<string, any>>;
    stop: () => void;
}

async function mount(s: Specimen): Promise<Mounted> {
    const formWrapper = findNode(s.layout, (n) => n.dataKey === 'form' && n.debounce === 300)!;
    const saveButton = findNode(s.layout, (n) => n.id === s.saveButtonId)!;
    expect(formWrapper, '실물 폼 감싸개').toBeTruthy();
    expect(saveButton, '실물 저장 단추').toBeTruthy();

    document.body.innerHTML = '<div id="app"></div>';
    const bodies: Mounted['bodies'] = [];
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (_url: string, init?: RequestInit) => {
        const method = String(init?.method ?? 'GET').toUpperCase();
        if (method === 'GET') {
            // CSRF 쿠키 등 사전 요청은 바로 답한다
            return { ok: true, status: 200, json: async () => ({}), text: async () => '{}', headers: new Headers({ 'Content-Type': 'application/json' }) } as Response;
        }
        if (init?.body) bodies.push(JSON.parse(String(init.body)));

        // 저장 응답은 끝나지 않게 둔다 — 운영의 onSuccess(목록 이동 등)는 이 시험의 축이 아니다
        return new Promise(() => {}) as any;
    }) as any;

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
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));

    app.setGlobalState({ _local: { form: { ...s.form }, isSaving: false, errors: null } }, { render: false });
    const g = app.getGlobalState();
    const { children: _children, ...wrapperAttrs } = formWrapper;
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: `test/debounce-quick-save-${s.label}`,
            components: [
                { ...wrapperAttrs, children: [{ id: `in_${s.field}`, type: 'basic', name: 'Input', props: { name: s.field } }] },
                { ...saveButton, if: undefined, props: { ...(saveButton.props ?? {}), disabled: undefined }, children: undefined, text: 'save' },
            ],
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates), route: s.route },
    } as any);
    await waitFor(() => expect(document.querySelector(`[data-testid="input-${s.field}"]`)).not.toBeNull());

    return {
        app,
        bodies,
        stop: () => {
            try { engine.destroyTemplate(); } catch { /* 정리 */ }
            globalThis.fetch = originalFetch;
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

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

describe('관리자 이커머스 디바운스 폼 — 입력 직후 「저장」 본문에 마지막 입력 (engine-v1.81.2, 7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(async () => {
        await settle(350);
        m?.stop();
        m = null;
    });

    // 마커 파서는 주석 블록 하나에서 조합 하나만 읽으므로 표본마다 블록을 둔다
    /**
     * @scenario specimen=product_list_price
     * @effects quick_save_body_has_last_input, input_not_reverted_after_save
     */
    /**
     * @scenario specimen=coupon_min_order_amount
     * @effects quick_save_body_has_last_input, input_not_reverted_after_save
     */
    /**
     * @scenario specimen=shipping_policy_base_fee
     * @effects quick_save_body_has_last_input, input_not_reverted_after_save
     */
    it.each(SPECIMENS)('$label: 40ms 간격으로 친 뒤 0.1초 안에 실물 「저장」 동작 → 요청 본문·저장소 B 가 마지막 입력', async (s) => {
        m = await mount(s);
        const input = document.querySelector(`[data-testid="input-${s.field}"]`) as HTMLInputElement;
        for (const v of s.typed) {
            fireEvent.change(input, { target: { value: v } });
            await settle(40);
        }
        await settle(60);

        const save = Array.from(document.querySelectorAll('button')).find((b) => (b.textContent ?? '') === 'save') as HTMLElement;
        fireEvent.click(save);
        await waitFor(() => expect(m!.bodies.length).toBe(1));

        const last = s.typed[s.typed.length - 1];
        expect(String(m.bodies[0][s.field])).toBe(last);
        expect(String(m.app.getGlobalState()._local.form[s.field])).toBe(last);

        // 저장 뒤 늦게 끝나는 디바운스가 칸·상태를 옛 값으로 되돌리지 않는다
        await settle(400);
        expect(String(m.app.getGlobalState()._local.form[s.field])).toBe(last);
        expect(input.value).toBe(last);
    });
});
