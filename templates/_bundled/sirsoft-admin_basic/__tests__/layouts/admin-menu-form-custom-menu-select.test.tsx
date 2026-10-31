// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·미뤄진 전역 다시 그리기·renderTemplate)로 메뉴 폼 「사용자 정의 메뉴」 선택칸을 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(새 메뉴에서 칸 입력 직후 사용자 정의 메뉴 선택)이 덮는다
/**
 * @file admin-menu-form-custom-menu-select.test.tsx
 * @description 관리자 메뉴 관리 › 새 메뉴 — 「사용자 정의 메뉴」 를 고르면 주소 칸이 채워지고, 직전에 친 다른 칸은 남는다 (7.1.1 관찰 7)
 *
 * 선택칸(`data-key="_global.formData"` 폼의 자동바인딩 칸)의 change 동작은 지금도
 * `formData="{{Object.assign({}, _global.formData, {custom_menu_key, url})}}"` 로 **폼 전체**를 다시 쓴다(점 경로로 바꾸지 않았다 —
 * `_panel_form.g7.html` 의 `form_custom_menu_select`). 그래도 앞 칸을 되돌리지 않는 것은 동작 식의 `_global.formData` 가 렌더 시점
 * 사본이 아니라 액션 실행 시점의 최신 전역(ActionDispatcher evaluateExpression 의 `G7Core.state.get()`)으로 평가되기 때문이다 —
 * 전역 다시 그리기가 미뤄져도 슬러그를 친 직후(렌더 전) 고르면 방금 친 슬러그가 남는다. 이 시험은 그 실물 동작을 실 TemplateApp 으로
 * 잠근다. 주소 칸 채움 자체는 엔진 engine-v1.81.1 의 자동바인딩 칸 이벤트 사본(`selectedOptions` 보존) 수정으로 풀렸다.
 *
 * @scenario-manifest tests/scenarios/admin-menu-form-custom-menu-select.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import React from 'react';
import { fireEvent, waitFor } from '@testing-library/react';

import panelForm from '../../layouts/partials/admin_menu_list/_panel_form.json';
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

/** 실물 메뉴 폼(`data-key="_global.formData"`) */
const menuForm = findNode(panelForm, (n) => n.id === 'menu_form')!;

/** 이름 없는 부품은 DOM 대역 — 표 칸·아이콘 등은 그리기만 한다 */
const CoreReact = () => (window as any).React ?? React;
const passthrough = (tag: string) => ({ children, text }: any) => CoreReact().createElement(tag, {}, children ?? text);
const registryEntries = (): Record<string, { component: any; metadata: any }> => {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });
    const base: Record<string, { component: any; metadata: any }> = {
        Input: c(({ name, value, onChange }: any) => CoreReact().createElement('input', { name, value: value ?? '', onChange: onChange ?? (() => {}) })),
        Select: c(({ name, value, onChange, children }: any) => CoreReact().createElement('select', { name, value: value ?? '', onChange: onChange ?? (() => {}) }, children)),
        Option: c(({ value, children, text, ...rest }: any) => CoreReact().createElement('option', { value, 'data-url': rest['data-url'] }, children ?? text)),
        Optgroup: c(({ label, children }: any) => CoreReact().createElement('optgroup', { label }, children)),
        Icon: c(() => null, { autoBinding: false }),
        Toggle: c(() => null, { autoBinding: false }),
        MultilingualInput: c(() => null, { autoBinding: false }),
        IconSelect: c(() => null, { autoBinding: false }),
        TagInput: c(() => null, { autoBinding: false }),
    };

    return new Proxy(base, {
        get: (t, k: string) => t[k] ?? c(passthrough('div')),
        has: () => true,
    });
};

interface Mounted {
    app: any;
    stop: () => void;
}

async function mount(): Promise<Mounted> {
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
    (ComponentRegistry.getInstance() as any).registry = registryEntries();
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));

    app.setGlobalState({
        panelMode: 'create',
        formData: { name: { ko: '' }, slug: '', url: '', icon: '', parent_id: null, custom_menu_key: null, is_active: true, roles: [] },
        _local: {},
    }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/admin-menu-form-custom-menu',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: {}, children: [menuForm] }],
        } as any,
        dataContext: {
            _global: g,
            _local: g._local,
            _globalSetState: (updates: any) => app.setGlobalState(updates),
            modules: { data: { custom_menus: { 'sirsoft-ecommerce': { module_name: '이커머스', menus: [{ key: 'ecommerce.shop', name: '쇼핑몰', url: '/shop' }, { key: 'ecommerce.nourl', name: '주소 없음' }] } } } },
            roles: { data: { data: [] } },
            menus: { data: { data: [] } },
        },
    } as any);
    await waitFor(() => expect(document.querySelector('select[name="custom_menu_key"]')).not.toBeNull());

    return {
        app,
        stop: () => {
            try { engine.destroyTemplate(); } catch { /* 정리 */ }
            delete (window as any).__templateApp;
            delete (window as any).G7Core;
            (window as any).__g7PendingLocalState = null;
            (window as any).__g7LayoutContextStack = [];
            document.body.innerHTML = '';
        },
    };
}

const field = (name: string) => document.querySelector(`[name="${name}"]`) as HTMLInputElement | HTMLSelectElement;
const settle = () => new Promise((r) => setTimeout(r, 200));

describe('관리자 메뉴 폼 — 사용자 정의 메뉴 선택칸 (7.1.1 관찰 7)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    // @scenario timing=after_fast_typing, option=with_url
    // @effects custom_menu_select_fills_url_keeps_other_fields
    it('슬러그를 친 직후(렌더 전) 사용자 정의 메뉴를 고르면 주소 칸이 채워지고 방금 친 슬러그는 남는다', async () => {
        m = await mount();
        // 같은 매크로태스크 — 미뤄진 전역 렌더가 끼지 않는다
        fireEvent.change(field('slug'), { target: { value: 'my-shop' } });
        fireEvent.change(field('custom_menu_key'), { target: { value: 'ecommerce.shop' } });
        await settle();

        const formData = m.app.getGlobalState().formData;
        // 종전: 렌더 시점 사본(slug '')으로 폼 전체를 다시 써 slug 가 '' 로 돌아갔다
        expect(formData.slug).toBe('my-shop');
        expect(formData.url).toBe('/shop');
        expect(formData.custom_menu_key).toBe('ecommerce.shop');
    });

    // @scenario timing=after_fast_typing, option=without_url
    // @effects custom_menu_select_fills_url_keeps_other_fields
    it('주소가 없는 항목을 고르면 직전에 친 주소를 그대로 둔다', async () => {
        m = await mount();
        fireEvent.change(field('url'), { target: { value: '/typed' } });
        fireEvent.change(field('custom_menu_key'), { target: { value: 'ecommerce.nourl' } });
        await settle();

        const formData = m.app.getGlobalState().formData;
        expect(formData.url).toBe('/typed');
        expect(formData.custom_menu_key).toBe('ecommerce.nourl');
    });

    // @scenario timing=settled, option=none
    // @effects custom_menu_select_fills_url_keeps_other_fields
    it('「선택 안 함」 으로 되돌리면 custom_menu_key 가 null 이고 주소는 남는다', async () => {
        m = await mount();
        fireEvent.change(field('custom_menu_key'), { target: { value: 'ecommerce.shop' } });
        await settle();
        fireEvent.change(field('custom_menu_key'), { target: { value: '' } });
        await settle();

        const formData = m.app.getGlobalState().formData;
        expect(formData.custom_menu_key).toBeNull();
        expect(formData.url).toBe('/shop');
    });
});
