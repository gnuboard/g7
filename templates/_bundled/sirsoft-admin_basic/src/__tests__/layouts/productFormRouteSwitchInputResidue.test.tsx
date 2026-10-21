// e2e:allow 실제 엔진 구조(TemplateApp.handleRouteChange·renderTemplate·data-key 디바운스 폼 자동바인딩·ActionDispatcher·G7Core.dispatch)에 이 템플릿의 실물 Input·Textarea 와 이커머스 상품 폼의 구운 JSON 을 올려 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(상품 수정 정가 입력 → 0.06초 뒤 등록 화면 이동 → 뒤로·앞으로)이 덮는다
/**
 * @file productFormRouteSwitchInputResidue.test.tsx
 * @description 디바운스 폼에서 친 칸이 대기 중일 때 같은 레이아웃의 다른 경로로 옮기면, 새 화면 칸에 이전 화면 입력이 남지 않는다 (engine-v1.81.2)
 *
 * 관리자 상품 수정에서 정가를 지우고 「32222」 를 친 뒤 0.06초 안에 등록 화면으로 앱 내 이동하면, 등록 폼 상태는 비는데
 * 정가 칸 화면에는 「32222」 가 남았다(7.1.0 에도 있음). 디바운스 대기 중에는 렌더러가 칸을 다시 그리지 않아 칸이 받은
 * `value` 는 치기 전 값에 멈춰 있고, 글자는 칸 컴포넌트의 지역 표시값이 들고 있다. 엔진은 경로 이동 때 대기 쓰기를 버리지만
 * (engine-v1.79.0) 지역 표시값은 남는다 — 새 화면 값이 치기 전 값과 같으면 `value` 가 안 바뀌어 따라오지 않고, 다르면
 * 포커스 중 「내가 보낸 값의 메아리」 판정(비우고 친 `''`)에 걸린다. 엔진이 버린 칸만 다시 마운트해 닫는다.
 *
 * 하네스: 실물 `TemplateApp.handleRouteChange`(레이아웃 로더만 대역 — 구운 JSON 의 폼 감싸개·정가 칸·`product` 데이터소스·init
 * setState 를 그대로 돌려줌) · 실물 `renderTemplate`·`ActionDispatcher`·`G7Core.dispatch` · 이 템플릿의 실물 `Input`·`Textarea`.
 * `_local` 은 손으로 채우지 않는다(데이터소스 initLocal·init 동작·자동바인딩만).
 *
 * @scenario-manifest tests/scenarios/route-switch-input-residue.yaml
 *
 * @vitest-environment jsdom
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { afterEach, describe, expect, it, vi } from 'vitest';

// 엔진(코어)은 저장소 루트의 react 를, 이 템플릿의 부품은 템플릿 node_modules 의 react 를 읽어 사본이 둘이 된다 — 훅을 쓰는
// 실제 부품을 엔진 아래에서 그리면 「Invalid hook call」 이 난다. 이 파일에서만 템플릿 쪽 react 를 루트 사본으로 바꿔 낀다
// (shippingPolicyFormLabelNames.test.tsx 와 같은 방식)
vi.mock('react', async () => {
    const p = await import('path');
    const u = await import('url');
    const dir = p.dirname(u.fileURLToPath(import.meta.url));

    return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../../node_modules/react/index.js')).href);
});

vi.mock('@core/api/ApiClient', () => ({
    getApiClient: () => ({
        post: vi.fn().mockResolvedValue({}),
        get: vi.fn().mockImplementation(async (url: string) => {
            // 수정 화면 원본 — `(globalThis).__g29Product` 로 사례마다 정가를 바꾼다
            if (String(url).includes('/admin/products/P-5')) {
                return { success: true, data: { id: 5, product_code: 'P-5', name: { ko: '상품' }, memo: '', ...((globalThis as any).__g29Product ?? {}) } };
            }
            // 「복사해서 등록」 원본(copy_source — `products/{copy_id}/copy`) — 복사본이라 id·상품코드는 없다
            if (String(url).includes('/admin/products/5/copy')) {
                return { success: true, data: { name: { ko: '상품' }, memo: '' } };
            }

            return { success: true, data: {} };
        }),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue('t'),
        setOnUnauthorized: vi.fn(),
    }),
}));

import React from 'react';
import { fireEvent, waitFor } from '@testing-library/react';
import { TemplateApp } from '@core/TemplateApp';
import * as engine from '@core/template-engine';
import { initializeG7CoreGlobals } from '@core/template-engine/G7CoreGlobals';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { AuthManager } from '@core/auth/AuthManager';
import { Input } from '../../components/basic/Input';
import { Textarea } from '../../components/basic/Textarea';
import { MultilingualInput } from '../../components/composite/MultilingualInput';

const here = path.dirname(fileURLToPath(import.meta.url));
/** 저장소 루트 (`templates/_bundled/sirsoft-admin_basic/src/__tests__/layouts` 기준 여섯 단계 위) */
const ROOT = path.resolve(here, '../../../../../..');
const LAYOUTS = path.join(ROOT, 'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin');
const productForm = JSON.parse(fs.readFileSync(path.join(LAYOUTS, 'admin_ecommerce_product_form.json'), 'utf8'));
const salesInfo = JSON.parse(fs.readFileSync(path.join(LAYOUTS, 'partials/admin_ecommerce_product_form/_partial_sales_info.json'), 'utf8'));
const basicInfo = JSON.parse(fs.readFileSync(path.join(LAYOUTS, 'partials/admin_ecommerce_product_form/_partial_basic_info.json'), 'utf8'));

const EDIT = '/admin/ecommerce/products/P-5/edit';
const CREATE = '/admin/ecommerce/products/create';

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

/**
 * 구운 JSON 에서 상품 폼 레이아웃을 줄여 만든다 — 폼 감싸개·정가 칸·데이터소스·init setState 는 원본 그대로
 *
 * @param debounce 폼 감싸개의 `data-debounce` 를 둘지(없애면 디바운스 없는 폼 대조군)
 * @param multilingual 상품명 다국어 칸(`_partial_basic_info.json` 의 MultilingualInput)과 「복사해서 등록」 원본 데이터소스(copy_source)를 실을지
 * @param changeActions 정가 칸의 change 동작(디바운스 쓰기가 끝난 뒤 실행된다 — 없으면 동작 없음)
 * @param listPriceKey 정가 칸 props 의 `key`(레이아웃이 칸에 key 를 준 경우 — 없으면 key 없음)
 * @returns 레이아웃 JSON
 */
function buildLayout(debounce: boolean, multilingual = false, changeActions?: Node[], listPriceKey?: string): Node {
    const wrapper = findNode(productForm.slots, (n) => n.dataKey === 'form' && n.debounce === 300)!;
    const listPrice = findNode(salesInfo, (n) => n.name === 'Input' && n.props?.name === 'list_price')!;
    const productName = findNode(basicInfo, (n) => n.name === 'MultilingualInput' && n.props?.name === 'name')!;
    expect(wrapper, '실물 폼 감싸개').toBeTruthy();
    expect(listPrice, '실물 정가 칸').toBeTruthy();
    expect(productName, '실물 상품명 다국어 칸').toBeTruthy();
    const { children: _children, ...wrapperAttrs } = wrapper;

    return {
        version: '1.0.0',
        layout_name: productForm.layout_name,
        data_sources: productForm.data_sources.filter((d: Node) => d.id === 'product' || (multilingual && d.id === 'copy_source')),
        // 모듈 핸들러(setDefaultShippingPolicy)는 이 하네스에 없다 — 상태를 정하는 setState 만 원본 그대로 싣는다
        init_actions: productForm.init_actions.filter((a: Node) => a.handler === 'setState'),
        components: [
            {
                ...(debounce ? wrapperAttrs : { ...wrapperAttrs, debounce: undefined }),
                children: [
                    // 칸의 change 동작(이커머스 핸들러)은 이 축이 아니다 — changeActions 사례만 시험용 동작을 단다
                    {
                        ...listPrice,
                        actions: changeActions,
                        props: { ...listPrice.props, disabled: undefined, 'data-testid': 'list_price', ...(listPriceKey ? { key: listPriceKey } : {}) },
                    },
                    { id: 'memo_textarea', type: 'basic', name: 'Textarea', props: { name: 'memo', 'data-testid': 'memo' } },
                    ...(multilingual ? [{ ...productName, props: { ...productName.props, disabled: undefined } }] : []),
                ],
            },
            { id: 'nav_create', type: 'basic', name: 'Button', text: 'go-create', actions: [{ type: 'click', handler: 'navigate', params: { path: CREATE } }] },
        ],
    };
}

/**
 * 주소 → 라우트 (routes.json 의 두 경로와 같은 모양)
 *
 * @param p 경로(쿼리 포함 가능)
 * @returns 라우트
 */
function routeFor(p: string): any {
    const [pathname, search = ''] = p.split('?');
    const m = pathname.match(/^\/admin\/ecommerce\/products\/([^/]+)\/edit$/);

    return { path: pathname, layout: 'admin_ecommerce_product_form', params: m ? { itemCode: m[1] } : {}, query: Object.fromEntries(new URLSearchParams(search)) };
}

interface Mounted {
    app: any;
    /** 라우터 navigate 와 같은 일 — 주소를 바꾸고 화면 전환을 끝까지 기다린다 */
    go: (p: string) => Promise<void>;
}

interface MountOptions {
    debounce?: boolean;
    /** 레이아웃 로드 지연(ms) — 디바운스 타이머가 새 화면 렌더보다 먼저 터지는 경우 */
    loadDelay?: number;
    /** 상품명 다국어 칸·copy_source 를 실을지 */
    multilingual?: boolean;
    /** 정가 칸의 change 동작(디바운스 쓰기가 끝난 뒤 실행) */
    changeActions?: Node[];
    /** 정가 칸 props 의 `key` */
    listPriceKey?: string;
}

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

/**
 * 실물 엔진을 올리고 수정 화면으로 들어간다.
 *
 * @param opts 선택
 * @returns 앱과 이동 함수
 */
async function mount(opts: MountOptions = {}): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    window.history.replaceState({}, '', '/admin');
    vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
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
        Input: { component: Input, metadata: { name: 'Input', type: 'basic', bindingType: 'checkable' } },
        Textarea: { component: Textarea, metadata: { name: 'Textarea', type: 'basic' } },
        MultilingualInput: { component: MultilingualInput, metadata: { name: 'MultilingualInput', type: 'composite' } },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    const layout = buildLayout(opts.debounce !== false, opts.multilingual === true, opts.changeActions, opts.listPriceKey);
    (app as any).layoutLoader = {
        loadLayout: async () => {
            if (opts.loadDelay) await settle(opts.loadDelay);

            return JSON.parse(JSON.stringify(layout));
        },
    };
    // Router.executeNavigation 과 같은 순서: pushState → 화면 전환
    const go = async (p: string): Promise<void> => {
        window.history.pushState({}, '', p);
        await (app as any).handleRouteChange(routeFor(p));
    };
    const router = { navigate: (p: string) => { void go(p); } };
    (app as any).router = router;
    dispatcher.setDefaultContext({ navigate: router.navigate } as any);
    await go(EDIT);
    await settle(400);

    return { app, go };
}

const field = (id = 'list_price'): HTMLInputElement => document.querySelector(`[data-testid="${id}"]`) as HTMLInputElement;
/** 상품명 다국어 칸의 한국어 입력칸 (MultilingualInput 이 `name[ko]` 로 그린다) */
const nameKo = (): HTMLInputElement => document.querySelector('input[name="name[ko]"]') as HTMLInputElement;
const form = (app: any): Record<string, any> => app.getGlobalState()._local?.form ?? {};

/**
 * 조작과 같은 입력 — 칸을 눌러 포커스, 전부 지움(Ctrl+A·Delete 가 `''` 를 내보냄), 40ms 간격으로 친다.
 *
 * @param id 칸(data-testid) 또는 칸을 찾는 함수
 * @param text 칠 값
 * @param clearFirst 먼저 지울지
 */
async function retype(id: string | (() => HTMLInputElement), text: string, clearFirst = true): Promise<void> {
    const el = (): HTMLInputElement => (typeof id === 'function' ? id() : field(id));
    el().focus();
    if (clearFirst) {
        fireEvent.change(el(), { target: { value: '' } });
        await settle(40);
    }
    for (let i = 1; i <= text.length; i++) {
        fireEvent.change(el(), { target: { value: text.slice(0, i) } });
        await settle(40);
    }
}

/** 출시 전 실측의 이동 — 사이드바에 「상품 등록」 링크가 없어 `G7Core.dispatch` 로 navigate(비디바운스 액션 직전 flush 를 거치지 않음, 포커스 유지) */
const dispatchNavigate = (p: string): Promise<unknown> => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p } });

describe('[case:state-closure-same-layout-route-dropped-debounce-display] 같은 레이아웃의 다른 경로로 옮기며 버린 디바운스 대기 입력이 칸 화면에 남지 않는다 (engine-v1.81.2)', () => {
    afterEach(async () => {
        await settle(350);
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
        delete (globalThis as any).__g29Product;
        (window as any).__g7PendingLocalState = null;
        (window as any).__g7ForcedLocalFields = undefined;
        (window as any).__g7SetLocalOverrideKeys = undefined;
        (window as any).__g7LastSetLocalSnapshot = undefined;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 그대로: 정가를 지우고 「32222」 → 0.06초 → G7Core.dispatch navigate(포커스 유지). 종전: 칸 「32222」·상태 빈 폼.
     *
     * @scenario case=cleared_retype_focus_kept_dispatch_navigate
     * @effects destination_field_shows_destination_value, destination_state_empty
     */
    it('정가를 지우고 친 뒤 0.06초 안에 등록 화면으로 옮기면(포커스 유지) 칸이 빈다', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount();
        expect(field().value).toBe('10000');

        await retype('list_price', '32222');
        await settle(60);
        await dispatchNavigate(CREATE);
        await waitFor(() => expect(window.location.pathname).toBe(CREATE));
        await settle(500);

        expect(form(app).id ?? null).toBeNull();
        expect(form(app).list_price ?? null).toBeNull();
        expect(field().value).toBe('');
    });

    /**
     * 수정 화면 원본 값이 비어 있으면 새 화면 값과 같아 칸이 받는 `value` 가 바뀌지 않는다 — 포커스를 풀어도 남았다.
     *
     * @scenario case=same_value_before_and_after_blurred
     * @effects destination_field_shows_destination_value
     */
    it('수정 화면 원본이 빈 칸이면 포커스를 풀고 옮겨도 칸이 빈다(값이 안 바뀌는 경우)', async () => {
        (globalThis as any).__g29Product = { list_price: null };
        const { app } = await mount();
        expect(field().value).toBe('');

        await retype('list_price', '32222', false);
        await retype('memo', '메모');
        field('memo').blur();
        await settle(60);
        await dispatchNavigate(CREATE);
        await waitFor(() => expect(window.location.pathname).toBe(CREATE));
        await settle(500);

        expect(form(app).list_price ?? null).toBeNull();
        expect(field().value).toBe('');
        // 같은 구조의 Textarea 도 같다
        expect(field('memo').value).toBe('');
    });

    /**
     * 레이아웃 로드가 디바운스(300ms)보다 늦으면 대기 쓰기는 타이머 쪽에서 버려진다 — 그 뒤의 첫 새 화면 렌더에서 칸이 빈다.
     *
     * @scenario case=timer_drops_before_destination_render
     * @effects destination_field_shows_destination_value
     */
    it('새 화면 렌더가 디바운스보다 늦어도(타이머가 먼저 버림) 칸이 빈다', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount({ loadDelay: 450 });

        await retype('list_price', '32222');
        await settle(60);
        void dispatchNavigate(CREATE);
        await settle(1200);

        expect(window.location.pathname).toBe(CREATE);
        expect(form(app).list_price ?? null).toBeNull();
        expect(field().value).toBe('');
    });

    /**
     * 뒤로·앞으로: 등록 화면에서 친 칸이 대기 중일 때 뒤로 가면 수정 화면 원본, 다시 앞으로 가면 빈 칸.
     *
     * @scenario case=back_forward_after_typing
     * @effects back_shows_server_value, forward_shows_destination_value
     */
    it('등록 화면에서 치고 곧바로 뒤로 → 앞으로 가도 각 화면 값이 보인다', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app, go } = await mount();
        await retype('list_price', '32222');
        await settle(60);
        await dispatchNavigate(CREATE);
        await waitFor(() => expect(window.location.pathname).toBe(CREATE));
        await settle(500);
        expect(field().value).toBe('');

        await retype('list_price', '45555');
        await settle(60);
        // popstate 와 같은 전환(라우터가 뒤로·앞으로에서 부르는 화면 전환 — flush 없음)
        await go(EDIT);
        await settle(500);
        expect(field().value).toBe('10000');
        expect(String(form(app).list_price)).toBe('10000');

        await go(CREATE);
        await settle(500);
        expect(field().value).toBe('');
        expect(form(app).list_price ?? null).toBeNull();
    });

    /**
     * 새 화면에서 다시 친 값은 칸·상태에 그대로 반영된다(다시 마운트한 칸의 자동바인딩이 산다).
     *
     * @scenario case=typing_after_remount
     * @effects remounted_field_writes_state
     */
    it('옮긴 뒤 새 화면 칸에 친 값은 칸·상태에 반영된다', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount();
        await retype('list_price', '32222');
        await settle(60);
        await dispatchNavigate(CREATE);
        await waitFor(() => expect(window.location.pathname).toBe(CREATE));
        await settle(500);

        await retype('list_price', '7700');
        await settle(400);
        expect(field().value).toBe('7700');
        expect(String(form(app).list_price)).toBe('7700');
        expect(form(app).id ?? null).toBeNull();
    });

    /**
     * 대기 중 옮겼고(타이머가 대기 쓰기를 버림) 새 화면 렌더가 늦게 오기 전에 새 화면 칸에 다시 치면, 늦게 온 렌더가
     * 그 칸을 다시 마운트하지 않는다 — 지금 칸 글자는 새 화면에서 친 사용자 입력이다(포커스·조합이 끊기면 안 된다).
     * 근거: `autoOnChange` 가 새 입력 때 버림 기록(`autoBindingDroppedPendingRef`)을 지운다.
     *
     * @scenario case=retype_on_destination_before_late_render
     * @effects retyped_field_not_remounted
     */
    it('대기 중 옮긴 뒤 새 화면 렌더가 오기 전에 그 칸에 다시 치면 칸이 다시 마운트되지 않는다(포커스·친 글자 유지)', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount({ loadDelay: 450 });

        await retype('list_price', '32222');
        await settle(60);
        void dispatchNavigate(CREATE);
        // 디바운스 타이머(마지막 글자 + 300ms)는 이동 뒤 약 0.2초에 새 경로를 보고 대기 쓰기를 버린다. 레이아웃은 0.45초에 온다
        await settle(300);
        expect(window.location.pathname).toBe(CREATE);
        const before = field();

        // 새 경로에서 같은 칸에 다시 친다 — 치는 도중(약 0.45초)에 새 화면 렌더가 온다
        await retype('list_price', '7700');
        await settle(500);

        expect(field() === before, '칸 요소 유지(다시 마운트 없음)').toBe(true);
        expect(document.activeElement === before, '포커스 유지').toBe(true);
        expect(field().value).toBe('7700');
        expect(String(form(app).list_price)).toBe('7700');
        expect(form(app).id ?? null).toBeNull();
    });

    /**
     * 객체 값 칸(상품명 MultilingualInput, 값 `{ko}`) — 상품명을 고친 직후 0.06초 안에 등록 화면으로 옮기면 상품명 칸이 빈다.
     * 등록 화면 상품명은 값이 없어(`''`) 칸이 받는 `value` 의 내용이 `{ko:'상품'}` → `''` 로 바뀌므로 다국어 칸 자신의 내용 비교
     * 동기화로도 빈다 — 다시 마운트 장치를 꺼도 green 인 회귀 잠금이다. 장치의 fail-first 는 아래 「복사해서 등록」 사례가 맡는다.
     *
     * @scenario case=multilingual_edit_then_create
     * @effects destination_field_shows_destination_value
     */
    it('다국어 상품명을 고친 직후 0.06초 안에 등록 화면으로 옮기면 상품명 칸이 빈다', async () => {
        const { app } = await mount({ multilingual: true });
        expect(nameKo().value).toBe('상품');

        await retype(nameKo, '상품수정');
        await settle(60);
        await dispatchNavigate(CREATE);
        await waitFor(() => expect(window.location.pathname).toBe(CREATE));
        await settle(500);

        expect(form(app).name ?? null).toBeNull();
        expect(nameKo().value).toBe('');
    });

    /**
     * 객체 값 칸 — 「복사해서 등록」(등록 화면 + copy_id)은 새 화면 상품명이 고치기 전 값과 내용이 같은 **객체**다. 칸이 받는
     * `value` 의 내용이 안 바뀌어 다국어 칸이 지역 표시값(고친 글자)을 그대로 둔다. 버린 대기 값과 새 화면 값을 `String()` 으로
     * 견주면 둘 다 `[object Object]` 라 다시 마운트하지 못한다 — 내용(JSON)으로 견줘야 한다.
     *
     * @scenario case=multilingual_edit_then_copy_create
     * @effects destination_field_shows_destination_value
     */
    it('다국어 상품명을 고친 직후 0.06초 안에 「복사해서 등록」 으로 옮기면 상품명 칸이 복사 원본 값을 보인다', async () => {
        const { app } = await mount({ multilingual: true });
        expect(nameKo().value).toBe('상품');

        await retype(nameKo, '상품수정');
        await settle(60);
        await dispatchNavigate(`${CREATE}?copy_id=5`);
        await waitFor(() => expect(window.location.pathname).toBe(CREATE));
        await settle(500);

        expect(form(app).id ?? null).toBeNull();
        expect(form(app).name?.ko).toBe('상품');
        expect(nameKo().value).toBe('상품');
    });

    // 칸의 change 동작이 다른 경로로 navigate — 이 동작은 디바운스 타이머가 **쓰기를 마친 뒤** 실행된다. 대기 값은 확정 렌더에서야
    // 지워지므로 새 경로의 첫 렌더가 「쓰기는 끝났지만 확정 전」 대기 값을 본다. 이 값은 버려진 쓰기가 아니라 옛 경로에서 이미
    // 실행된 쓰기라 다시 마운트 기록 대상이 아니다 — 기록하면 첫 새 경로 렌더의 표시 값이 쓴 값과 다를 때(같은 동작이 칸을 비움 등)
    // 입력 중인 칸이 다시 마운트돼 포커스·IME 조합이 끊긴다. 근거: 렌더 때 다른 경로 대기 값 버림은
    // `autoBindingDebounceTimerRef.current !== null`(타이머 미실행 = 쓰기가 실제로 버려짐)일 때만 다시 마운트를 기록한다.
    // 이동만 하는 동작은 첫 새 경로 렌더가 방금 쓴 값을 보여(버린 값 = 표시 값) 방어 조건 없이도 다시 마운트가 없는 회귀 잠금이고,
    // 칸을 비우고 이동하는 동작이 방어 조건의 fail-first 를 맡는다
    /**
     * @scenario case=change_action_navigates_after_write
     * @effects written_field_not_remounted
     */
    /**
     * @scenario case=change_action_clears_and_navigates_after_write
     * @effects written_field_not_remounted, destination_state_empty
     */
    it.each([
        { label: 'navigate_only', actions: [{ type: 'change', handler: 'navigate', params: { path: CREATE } }] },
        {
            label: 'clear_then_navigate',
            actions: [{
                type: 'change',
                handler: 'sequence',
                actions: [
                    { handler: 'setState', params: { target: 'local', 'form.list_price': '' } },
                    { handler: 'navigate', params: { path: CREATE } },
                ],
            }],
        },
    ])('디바운스 쓰기가 끝난 뒤 그 칸의 change 동작($label)이 등록 화면으로 옮겨도 칸이 다시 마운트되지 않는다(포커스 유지)', async (c) => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount({ changeActions: c.actions });
        const before = field();

        await retype('list_price', '32222', false);
        // 마지막 글자 + 300ms 에 타이머가 옛 경로에서 쓰기를 마치고, 이어서 change 동작이 navigate 한다
        await waitFor(() => expect(window.location.pathname).toBe(CREATE), { timeout: 1000 });
        await settle(500);

        expect(field() === before, '칸 요소 유지(다시 마운트 없음)').toBe(true);
        expect(document.activeElement === before, '포커스 유지').toBe(true);
        expect(form(app).id ?? null).toBeNull();
        expect(form(app).list_price ?? null).toBeNull();
        // 다시 마운트 없이도 칸이 받는 value 가 바뀌어(쓴 값 → 빈 값, 빈 값은 이 칸이 내보낸 적 없음) 칸이 새 화면 값을 따른다
        expect(field().value).toBe('');
    });

    /**
     * 이동 단추(액션 클릭) — 비디바운스 액션 직전 flush 가 대기 쓰기를 옛 경로에서 실행한 뒤 navigate 한다. flush 로 실행된 쓰기도
     * 타이머 표식이 null 이라 다시 마운트 기록 대상이 아니다. 칸은 새 화면 값(빈 칸)을 보이고 상태에 앞 상품이 없다.
     *
     * @scenario case=flush_click_navigate
     * @effects destination_field_shows_destination_value, destination_state_empty
     */
    it('정가를 친 뒤 0.06초 안에 이동 단추로 등록 화면으로 옮기면(flush) 칸이 빈다', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount();

        await retype('list_price', '32222');
        await settle(60);
        fireEvent.click(document.querySelector('button') as HTMLButtonElement);
        await waitFor(() => expect(window.location.pathname).toBe(CREATE));
        await settle(500);

        expect(form(app).id ?? null).toBeNull();
        expect(form(app).list_price ?? null).toBeNull();
        expect(field().value).toBe('');
    });

    /**
     * 늦은 새 화면 렌더(레이아웃 0.45초) 전에 타이머가 대기 쓰기를 버린 뒤, 사용자가 같은 칸에서 한글 조합을 시작했다. 조합 중에는 칸이
     * onChange 를 부르지 않아 버린 값 기록이 지워지지 않았고, 늦게 온 렌더가 조합 중인 칸을 다시 마운트해 조합·포커스가 끊겼다.
     * 근거: 엔진이 같은 이름 칸의 compositionstart 를 들어 기록을 지우고, 판정 때 조합 중인 같은 이름 칸은 다시 마운트하지 않는다 (engine-v1.81.2).
     *
     * @scenario case=ime_composition_before_late_render
     * @effects retyped_field_not_remounted
     */
    it('대기 중 옮긴 뒤 늦은 새 화면 렌더 전에 같은 칸에서 한글 조합을 시작하면 칸이 다시 마운트되지 않는다(조합·포커스 유지)', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        await mount({ loadDelay: 450 });

        await retype('list_price', '32222');
        await settle(60);
        void dispatchNavigate(CREATE);
        // 타이머(마지막 글자 + 300ms)가 새 경로를 보고 대기 쓰기를 버린 뒤 — 레이아웃은 0.45초에 온다
        await settle(300);
        expect(window.location.pathname).toBe(CREATE);
        const before = field();
        fireEvent.compositionStart(before, { data: '' });
        await settle(500);

        expect(field() === before, '칸 요소 유지(다시 마운트 없음)').toBe(true);
        expect(document.activeElement === before, '포커스 유지').toBe(true);
        fireEvent.compositionEnd(before, { data: '' });
    });

    /**
     * 레이아웃이 칸 props 에 `key` 를 주면 펼친 props 의 key 가 다시 마운트 key 를 덮어 장치가 무력했다(R-B10 과 같은 조작에서 칸 「32222」 잔존).
     * 근거: 차례가 0 보다 크면 레이아웃 key 와 다시 마운트 key 를 합친다 (engine-v1.81.2).
     *
     * @scenario case=layout_key_prop
     * @effects destination_field_shows_destination_value
     */
    it('정가 칸에 레이아웃 key 가 있어도 지우고 친 뒤 0.06초 안에 등록 화면으로 옮기면 칸이 빈다', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount({ listPriceKey: 'lp' });

        await retype('list_price', '32222');
        await settle(60);
        await dispatchNavigate(CREATE);
        await waitFor(() => expect(window.location.pathname).toBe(CREATE));
        await settle(500);

        expect(form(app).list_price ?? null).toBeNull();
        expect(field().value).toBe('');
    });

    /**
     * `navigate` 의 `replace: true` 는 공개 계약상 「URL 만 변경」이다(경로 전환 없음 — 같은 화면). path 에 다른 경로를 주면 경로 가드가
     * pathname 만 보고 같은 화면에서 친 입력을 버리고 칸을 다시 마운트해 입력 전 값(10000)으로 되돌렸다.
     * 근거: `G7Core.updateQueryParams` 가 주소만 바꾼 경로를 같은 경로 전환 세대 안의 별칭으로 기록하고 경로 가드가 그것을 같은 화면으로 본다 (engine-v1.81.2).
     *
     * @scenario case=replace_navigate_path_change
     * @effects same_screen_keeps_typing
     */
    it('정가를 친 뒤 0.06초 안에 navigate replace 로 주소의 경로만 바꾸면 칸 요소·친 값이 그대로이고 상태에 쓰인다', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount();
        const before = field();

        await retype('list_price', '32222');
        await settle(60);
        await (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: '/admin/ecommerce/products/P-5/edit-renamed', replace: true } });
        await settle(600);

        expect(window.location.pathname).toBe('/admin/ecommerce/products/P-5/edit-renamed');
        expect(field() === before, '칸 요소 유지(다시 마운트 없음)').toBe(true);
        expect(field().value).toBe('32222');
        expect(String(form(app).list_price)).toBe('32222');
    });

    /**
     * `replaceUrl` 핸들러도 「URL 만 변경」이다(경로 전환·다시 받기 없음). 경로가 다른 주소로 바꾸면 같은 이유로 입력이 버려졌다.
     *
     * @scenario case=replace_url_path_change
     * @effects same_screen_keeps_typing
     */
    it('정가를 친 뒤 0.06초 안에 replaceUrl 로 주소의 경로만 바꾸면 칸 요소·친 값이 그대로이고 상태에 쓰인다', async () => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        const { app } = await mount();
        const before = field();

        await retype('list_price', '32222');
        await settle(60);
        await (window as any).G7Core.dispatch({ handler: 'replaceUrl', params: { path: '/admin/ecommerce/products/P-5/edit-selected' } });
        await settle(600);

        expect(window.location.pathname).toBe('/admin/ecommerce/products/P-5/edit-selected');
        expect(field() === before, '칸 요소 유지(다시 마운트 없음)').toBe(true);
        expect(field().value).toBe('32222');
        expect(String(form(app).list_price)).toBe('32222');
    });

    // 마커 파서는 주석 블록 하나에서 조합 하나만 읽으므로 대조군마다 블록을 둔다
    /**
     * @scenario case=control_query_only
     * @effects query_only_keeps_element_and_typing
     */
    /**
     * @scenario case=control_no_debounce
     * @effects control_keeps_element
     */
    /**
     * @scenario case=control_settled_debounce
     * @effects control_keeps_element
     */
    /**
     * @scenario case=control_dropped_equals_destination
     * @effects control_keeps_element
     */
    it.each([
        // 같은 경로에서 쿼리만 바뀌는 이동(탭·검색) — 대기 값을 버리지 않으므로 칸 요소·치던 글자 그대로
        { label: 'query_only', debounce: true, wait: 60, to: `${EDIT}?tab=sales`, expectValue: '32222' },
        // 디바운스 없는 폼 — 렌더러가 매 글자 칸을 다시 그려 버릴 대기 값이 없다
        { label: 'no_debounce', debounce: false, wait: 60, to: CREATE, expectValue: '' },
        // 디바운스가 끝난 뒤의 이동 — 대기 값이 이미 상태로 확정돼 버릴 것이 없다
        { label: 'settled_debounce', debounce: true, wait: 400, to: CREATE, expectValue: '' },
        // 버린 대기 값이 새 화면 표시 값과 같다(칸을 비우기만 하고 옮김) — 다시 마운트할 이유가 없다
        { label: 'dropped_equals_destination', debounce: true, wait: 60, to: CREATE, expectValue: '', typed: '' },
    ])('대조군 $label: 다시 마운트 없이 칸 표시 「$expectValue」', async (c) => {
        (globalThis as any).__g29Product = { list_price: 10000 };
        await mount({ debounce: c.debounce });
        const before = field();
        await retype('list_price', (c as { typed?: string }).typed ?? '32222');
        await settle(c.wait);
        await dispatchNavigate(c.to);
        await settle(500);

        expect(field().value).toBe(c.expectValue);
        expect(field() === before, '칸 요소 유지(다시 마운트 없음)').toBe(true);
    });
});
