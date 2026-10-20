// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 의 modals 섹션 렌더러·영구 마운트 창·basic Modal 의 Esc) 렌더 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(주문서 「배송지 관리」 수정 폼 → 닫기·Esc → 다시 열기 → 새 배송지)이 덮는다.
/**
 * @file checkout-address-modal-real-structure.test.tsx
 * @description 주문서 「배송지 관리」 창 — 수정 폼을 닫기·Esc 로 나간 뒤 다시 열면 목록이고, 새 배송지는 빈 등록 폼·POST 다 (7.1.1)
 *
 * modals 섹션 창은 페이지 루트와 따로 영구 마운트된 렌더러로 그려지고, 그 안의 `_local` 은 「마지막 전체 렌더 때의 페이지 `_local`
 * 스냅샷」 위에 「창 루트 자신의 지역 상태」를 deep 병합한 값이다(창 쪽이 이긴다). 창이 `_local.addressModalMode`·
 * `_local.editingAddress` 를 쓰고 페이지 「배송지 관리」 단추가 `_local.addressModalMode="list"` 를 쓰면, 페이지가 쓴 값은 창 쪽 값에
 * 가려 닿지 않는다 — 수정 폼을 닫기·Esc 로 나간 뒤 다시 열면 수정 폼 그대로이고, 「뒤로」 → 「새 배송지」 의 2키 객체는 스냅샷의 앞
 * 배송지(15키, id 포함)와 합쳐져 저장이 앞 배송지에 PUT 됐다(실측 PUT 185). 하네스의 `createLayoutTest` 는 창을 페이지 루트
 * 아래 노드로 그려 이 두 겹을 만들지 않으므로, 이 시험은 운영과 같은 TemplateApp + renderTemplate 로 창을 modals 섹션에 그린다.
 *
 * 고침: 창 상태를 창 고유 전역 이름 공간 `_global.checkoutAddressModal` 로 옮긴다(`modal-usage.md` 편집 창 표준). 여는 쪽은 null 을 먼저
 * 쓰고 객체를 쓴다. 엔진 무변경.
 *
 * @scenario-manifest tests/scenarios/checkout-address-modal-real-structure.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import { fireEvent, waitFor } from '@testing-library/react';

/** 창 닫힘·창 이름 공간 비움 대기 한도 — 닫기는 렌더 두 번(동작 → 닫기 → 마운트 해제 정리)을 거쳐, 병렬 시험 부하에서 기본 1초를 넘긴 전례가 있다 */
const CLOSE_WAIT = { timeout: 5000 };

// 시험 한도 — 기본 5초는 위 대기 한도와 같아, 대기가 끝나기 전에 시험이 먼저 끊겨 어느 대기가 실패했는지 가려졌다. 부하(동시 Vitest)에서
// 레이아웃 전수 정적 검사는 무부하 0.1초 남짓이 CPU 포화 부하에서 16초까지 늘었다 — 한도를 대기보다 넉넉히 둔다.
vi.setConfig({ testTimeout: 30000 });

import checkoutShipping from '../../../layouts/partials/shop/_checkout_shipping.json';
import addressManageModal from '../../../layouts/partials/shop/_modal_address_manage.json';
import profileView from '../../../layouts/partials/mypage/profile/_view.json';
import withdrawModal from '../../../layouts/partials/mypage/profile/_modal_withdraw.json';
import claimDetailReason from '../../../layouts/partials/mypage/claims/_detail_reason.json';
import claimImageViewer from '../../../layouts/partials/mypage/claims/_modal_image_viewer.json';
import mypageAddressList from '../../../layouts/partials/mypage/addresses/_list.json';
import mypageAddressModal from '../../../layouts/partials/mypage/addresses/_modal_address.json';
import confirmOverwriteModal from '../../../layouts/partials/mypage/addresses/_modal_confirm_overwrite.json';
import cartItemPartial from '../../../layouts/partials/shop/_cart_item.json';
import cartOptionChangeModal from '../../../layouts/partials/shop/_modal_cart_option_change.json';
import { initCartOptionSelectionHandler, findMatchingOptionHandler } from '../../handlers/cartOptionChange';
import orderShipping from '../../../layouts/partials/mypage/orders/_shipping.json';
import changeAddressModal from '../../../layouts/partials/mypage/orders/_modal_change_address.json';
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

const ADDRESSES = [
    { id: 185, name: '별장', recipient_name: '홍길동', recipient_phone: '010-1111-2222', country_code: 'KR', zipcode: '06236', address: '서울 강남구 테헤란로 1', address_detail: '1층', is_default: false, abilities: { can_update: true, can_delete: true } },
    { id: 186, name: '회사', recipient_name: '김영희', recipient_phone: '010-3333-4444', country_code: 'KR', zipcode: '04524', address: '서울 중구 세종대로 2', address_detail: '2층', is_default: true, abilities: { can_update: true, can_delete: false } },
];

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

/** 페이지의 실물 「배송지 관리」 단추(창을 여는 쪽) */
const openButton = findNode(checkoutShipping, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('addressManageModal'))!;

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('aria-') || k.startsWith('data-') || ['id', 'type', 'disabled', 'onClick', 'role', 'htmlFor'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

/**
 * basic Modal 대역 — 실물(components/composite/Modal.tsx)과 같은 세 가지를 지킨다: 닫히면 아무것도 그리지 않는다(창 루트 렌더러는
 * 영구 마운트), Esc keydown 에 onClose(event) 를 부른다, 닫기 단추(aria-label)가 onClose(event) 를 부른다. 실물을 그대로 쓰지 않는 것은
 * 템플릿 패키지의 React 와 코어 엔진의 React 가 시험 환경에서 서로 다른 사본으로 풀리기 때문이다.
 *
 * @param props 창 속성
 * @returns 창
 */
function ModalStandIn({ isOpen, onClose, title, children, closeOnBackdropClick, closeOnOverlayClick }: any) {
    // 코어 엔진이 그리는 트리이므로 엔진 쪽 React(initializeG7CoreGlobals 가 window.React 로 노출)의 훅을 쓴다
    const CoreReact = (window as any).React ?? React;
    const dialogRef = CoreReact.useRef(null);
    CoreReact.useEffect(() => {
        if (!isOpen) return undefined;
        const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose?.(event); };
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [isOpen, onClose]);
    // 실물과 같이 Esc 처리기 등록 뒤(같은 커밋의 다음 효과) 창 안 첫 요소로 포커스를 옮긴다 — 시험은 이 포커스를 「Esc 를 받을 준비」 신호로 본다
    CoreReact.useEffect(() => {
        if (!isOpen) return;
        (dialogRef.current as HTMLElement | null)?.querySelector<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')?.focus();
    }, [isOpen]);
    if (!isOpen) return null;
    // 바깥(배경) 클릭 — 실물과 같이 기본은 닫고, closeOnBackdropClick(별칭 closeOnOverlayClick)=false 면 닫지 않는다. 창 안 클릭은 배경으로 올라가지 않는다
    const backdropCloses = (closeOnOverlayClick !== undefined ? closeOnOverlayClick : closeOnBackdropClick) !== false;

    return React.createElement('div', { 'data-testid': 'modal-backdrop', onClick: backdropCloses ? (e: any) => onClose?.(e) : undefined },
        React.createElement('section', { ref: dialogRef, role: 'dialog', 'data-title': title, onClick: (e: any) => e.stopPropagation() },
            React.createElement('button', { type: 'button', 'aria-label': 'close', onClick: (e: any) => onClose?.(e) }, 'x'),
            children));
}

/** 부품 — 창은 basic Modal 대역, 나머지는 DOM 대역 */
function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Modal: c(ModalStandIn),
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        P: c(passthrough('p')),
        H3: c(passthrough('h3')),
        Ul: c(passthrough('ul')),
        Li: c(passthrough('li')),
        Img: c(({ src, alt }: any) => React.createElement('img', { src, alt })),
        Label: c(passthrough('label')),
        Button: c(passthrough('button')),
        Form: c(({ children, className }: any) => React.createElement('form', { className, onSubmit: (e: any) => e.preventDefault() }, children)),
        Icon: c(() => null, { autoBinding: false }),
        Input: c(({ name, value, onChange, onInput, type, readOnly, 'aria-label': aria }: any) =>
            React.createElement('input', { name, value: value ?? '', onChange: onChange ?? (() => {}), onInput, type, readOnly, 'aria-label': aria })),
        Select: c(({ name, value, onChange, options, 'aria-label': aria }: any) =>
            React.createElement('select', { name, value: value ?? '', onChange: onChange ?? (() => {}), 'aria-label': aria },
                (options ?? []).map((o: any) => React.createElement('option', { key: o.value, value: o.value }, o.label)))),
        Checkbox: c(({ id, name, checked, onChange }: any) => React.createElement('input', { type: 'checkbox', id, name, checked: !!checked, onChange: onChange ?? (() => {}) }), { bindingType: 'checked' }),
    };
}

interface Mounted {
    app: any;
    fetchCalls: Array<{ url: string; method: string; body: any }>;
    stop: () => void;
}

/**
 * 운영과 같은 구조로 주문서 일부(「배송지 관리」 단추)와 창(modals 섹션)을 띄운다.
 *
 * @returns 띄운 앱과 요청 기록
 */
interface MountOptions {
    international?: boolean;
    /** 페이지(창 밖) 노드 — 기본은 주문서 「배송지 관리」 단추 */
    page?: Node[];
    /** modals 섹션 창 — 기본은 주문서 「배송지 관리」 창 */
    modals?: Node[];
    /** 페이지 _local(저장소 B) 초기값 */
    local?: Record<string, unknown>;
    /** 데이터 소스 값 */
    data?: Record<string, unknown>;
    /** 요청마다 응답을 고른다(없으면 200 성공) — 409 처럼 실패 갈래를 태울 때 */
    respond?: (call: { url: string; method: string; body: any }, index: number) => { status: number; json: any } | undefined;
}

async function mount(opts: MountOptions = {}): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    const fetchCalls: Mounted['fetchCalls'] = [];
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (url: string, init?: RequestInit) => {
        const call = { url: String(url), method: String(init?.method ?? 'GET').toUpperCase(), body: init?.body ? JSON.parse(String(init.body)) : null };
        fetchCalls.push(call);
        const picked = opts.respond?.(call, fetchCalls.length - 1);
        if (picked) {
            return { ok: picked.status < 400, status: picked.status, json: async () => picked.json, text: async () => JSON.stringify(picked.json), headers: new Headers({ 'Content-Type': 'application/json' }) } as Response;
        }

        return { ok: true, status: 200, json: async () => ({ success: true, data: {} }), text: async () => '{}', headers: new Headers({ 'Content-Type': 'application/json' }) } as Response;
    }) as any;

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
    // TemplateApp 부팅이 하는 연결 — 전역 갱신은 앱 전역 상태로 간다(render 면 updateTemplateData 로 다시 그린다)
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));

    // 주문서 init-local 의 해당 키(페이지 _local = 저장소 B)
    const shipping = opts.international
        ? { international_shipping_enabled: true, available_countries: [{ code: 'KR', name: '한국', is_active: true }, { code: 'US', name: '미국', is_active: true }] }
        : { international_shipping_enabled: false, available_countries: [] };
    app.setGlobalState({ currentUser: { uuid: 'u-1' }, modules: { 'sirsoft-ecommerce': { shipping } }, _local: opts.local ?? { selectedAddressId: null, selectedAddressObj: null, checkout: {} } }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/checkout-address-modal',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: opts.page ?? [openButton] }],
            modals: opts.modals ?? [addressManageModal],
        } as any,
        // 운영 TemplateApp 이 렌더 문맥에 싣는 전역 갱신기(TemplateApp.ts _globalSetState) — `data-key="_global.*"` 폼 자동바인딩이 이것으로 쓴다
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates), userAddresses: { data: { addresses: { data: ADDRESSES } } }, ...(opts.data ?? {}) },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="page"]')).not.toBeNull());

    return {
        app,
        fetchCalls,
        stop: () => {
            try { engine.destroyTemplate(); } catch { /* 정리 */ }
            globalThis.fetch = originalFetch;
            delete (window as any).__templateApp;
            delete (window as any).G7Core;
            document.body.innerHTML = '';
        },
    };
}

const dialog = (): HTMLElement | null => document.querySelector('[role="dialog"]');
const buttonByText = (scope: ParentNode, re: RegExp): HTMLElement | undefined =>
    Array.from(scope.querySelectorAll('button')).find((b) => re.test(b.textContent ?? '')) as HTMLElement | undefined;
const isFormMode = (): boolean => !!dialog()?.querySelector('input[name="recipient_name"]');

/** 페이지의 「배송지 관리」 를 눌러 창을 연다 */
async function openManage(): Promise<void> {
    fireEvent.click(buttonByText(document.querySelector('[data-testid="page"]')!, /manage_addresses|배송지 관리/)!);
    await waitFor(() => expect(dialog()).not.toBeNull());
}

/** 목록에서 첫 배송지(185)의 「수정」 */
async function editFirst(): Promise<void> {
    fireEvent.click(dialog()!.querySelector('[data-ref="address_modal_edit_button_0"]') as HTMLElement
        ?? buttonByText(dialog()!, /common\.edit|수정/)!);
    await waitFor(() => expect(isFormMode()).toBe(true));
    await waitFor(() => expect((dialog()!.querySelector('input[name="recipient_name"]') as HTMLInputElement).value).toBe('홍길동'));
}

/**
 * 상태에 묶인 잠금(`disabled`)이 풀린 단추를 누른다. 상태(전역·지역)는 렌더보다 먼저 바뀌므로, 상태만 기다리고 누르면 부하에서 아직 잠긴
 * 단추를 눌러(React 는 비활성 단추의 onClick 을 부르지 않는다) 동작이 아예 돌지 않았다. 사람처럼 풀린 단추를 누른다.
 *
 * @param find 단추를 찾는 함수(매번 다시 찾는다 — 다시 그려지면 요소가 바뀔 수 있다)
 */
async function clickWhenEnabled(find: () => Element | null | undefined): Promise<void> {
    await waitFor(() => expect((find() as HTMLButtonElement | null | undefined)?.disabled).toBe(false));
    fireEvent.click(find() as Element);
}

/** 창의 「선택」 — `disabled: !selectedAddressId` 가 풀린 뒤 누른다 */
const clickSelect = (): Promise<void> => clickWhenEnabled(() => buttonByText(dialog()!, /select_address/));

/** 닫기 — Esc(basic Modal 의 keydown)·창의 닫기 단추·바깥(배경) 클릭. 셋 다 엔진 closeModal 로만 이어진다 */
async function closeBy(how: 'escape' | 'x' | 'backdrop'): Promise<void> {
    if (how === 'escape') {
        // 창의 Esc 처리기가 준비된 뒤 누른다. 실물 Modal 과 대역은 처리기를 수동 효과(useEffect)에서 등록하는데, 창이 DOM 에 붙은 순간
        // (앞 waitFor 통과)과 그 효과 실행 사이에 부하에 따라 매크로태스크 틈이 생긴다 — 그 틈에 누른 Esc 는 받을 처리기가 없어 창이 끝내
        // 열린 채였다(엔진 무관, 시험 경합). 처리기 등록 다음 효과가 옮기는 창 안 포커스를 신호로 기다리고, 키는 사람처럼 그 요소로 보낸다.
        const top = (): HTMLElement | undefined => Array.from(document.querySelectorAll<HTMLElement>('[role="dialog"]')).pop();
        await waitFor(() => expect(top()?.contains(document.activeElement)).toBe(true));
        fireEvent.keyDown(document.activeElement as Element, { key: 'Escape' });
    } else if (how === 'backdrop') {
        const backdrops = Array.from(document.querySelectorAll('[data-testid="modal-backdrop"]')) as HTMLElement[];
        fireEvent.click(backdrops[backdrops.length - 1]);
    } else {
        const x = dialog()!.querySelector('button[aria-label]') as HTMLElement;
        fireEvent.click(x);
    }
    await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
}

describe('주문서 「배송지 관리」 창 — 실제 창 구조(modals 섹션·영구 마운트) (7.1.1)', () => {
    let m: Mounted | null = null;

    beforeEach(() => {
        m = null;
    });

    afterEach(() => {
        m?.stop();
    });

    // @scenario close=escape
    // @effects reopen_shows_list_after_edit_closed
    it('수정 폼에서 Esc 로 닫고 다시 열면 목록이다', async () => {
        m = await mount();
        await openManage();
        expect(isFormMode()).toBe(false);
        await editFirst();
        await closeBy('escape');
        await openManage();
        await waitFor(() => expect(buttonByText(dialog()!, /address_add_new|새 배송지/)).toBeDefined());
        expect(isFormMode()).toBe(false);
    });

    // @scenario close=close_button
    // @effects reopen_shows_list_after_edit_closed, add_after_reopen_is_blank_and_posts
    it('수정 폼에서 닫기 단추로 닫고 다시 열어 「새 배송지」 를 누르면 빈 등록 폼이고 저장은 POST 다', async () => {
        m = await mount();
        await openManage();
        await editFirst();
        await closeBy('x');
        await openManage();
        // 종전에는 여기서 여전히 수정 폼이었다 — 그 경우에도 사람은 「뒤로」 를 눌러 목록으로 간다
        const back = buttonByText(dialog()!, /common\.back|뒤로/);
        if (isFormMode() && back) fireEvent.click(back);
        await waitFor(() => expect(buttonByText(dialog()!, /address_add_new|새 배송지/)).toBeDefined());
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));

        const title = dialog()!.getAttribute('data-title') ?? '';
        expect(title).toMatch(/address_add/);
        expect(title).not.toMatch(/address_edit/);
        expect((dialog()!.querySelector('input[name="recipient_name"]') as HTMLInputElement).value).toBe('');
        expect((dialog()!.querySelector('input[name="name"]') as HTMLInputElement).value).toBe('');

        fireEvent.click(buttonByText(dialog()!, /mypage\.addresses\.add|새 주소/)!);
        await waitFor(() => expect(m!.fetchCalls.some((c) => c.url.includes('/user/addresses') && c.method !== 'GET')).toBe(true));
        const write = m!.fetchCalls.find((c) => c.url.includes('/user/addresses') && c.method !== 'GET')!;
        expect(write.method).toBe('POST');
        expect(write.url).toMatch(/\/user\/addresses$/);
        expect(write.body?.id ?? null).toBeNull();
    });

    // @scenario close=back_button
    // @effects add_after_reopen_is_blank_and_posts
    it('수정 → 「뒤로」 → 「새 배송지」 도 빈 등록 폼이다(창이 열린 채 다시 그려진 뒤에도)', async () => {
        m = await mount();
        await openManage();
        await editFirst();
        // 창이 열린 채 전체 렌더가 한 번 끼는 경우(전역 render 쓰기) — 분석의 「열린 채 누수」 가설 갈래
        m.app.setGlobalState({ __probe: Date.now() });
        fireEvent.click(buttonByText(dialog()!, /common\.back|뒤로/)!);
        await waitFor(() => expect(isFormMode()).toBe(false));
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        expect((dialog()!.querySelector('input[name="recipient_name"]') as HTMLInputElement).value).toBe('');
    });

    // ── 이름 공간 전환이 새로 들어가는 갈래(`_global` 자동바인딩 표시용 대기 값, 전역 렌더) ─────────────────────────

    // @scenario close=back_button
    // @effects typed_value_cleared_on_reopen_form
    it('새 배송지 폼에 입력하고 「뒤로」 → 「새 배송지」 를 누르면 칸이 비어 있다(전역 칸의 표시용 대기 값이 남지 않는다)', async () => {
        m = await mount();
        await openManage();
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        const input = () => dialog()!.querySelector('input[name="recipient_name"]') as HTMLInputElement;
        fireEvent.change(input(), { target: { value: '이몽룡' } });
        await waitFor(() => expect(m!.app.getGlobalState().checkoutAddressModal?.editing?.recipient_name).toBe('이몽룡'));
        await waitFor(() => expect(input().value).toBe('이몽룡'));
        fireEvent.click(buttonByText(dialog()!, /common\.back|뒤로/)!);
        await waitFor(() => expect(isFormMode()).toBe(false));
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        await waitFor(() => expect(input().value).toBe(''));
        expect(m.app.getGlobalState().checkoutAddressModal?.editing?.recipient_name ?? '').toBe('');
    });

    // @scenario close=none
    // @effects rapid_typing_keeps_all_characters
    it('빠른 연속 입력(렌더를 기다리지 않음)에서 글자가 빠지지 않는다', async () => {
        m = await mount();
        await openManage();
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        const input = () => dialog()!.querySelector('input[name="address_detail"]') as HTMLInputElement;
        const text = '101동 1203호 테스트';
        for (let i = 1; i <= text.length; i += 1) fireEvent.change(input(), { target: { value: text.slice(0, i) } });
        await waitFor(() => expect(m!.app.getGlobalState().checkoutAddressModal?.editing?.address_detail).toBe(text));
        await waitFor(() => expect(input().value).toBe(text));
    });

    // @scenario close=back_button
    // @effects country_select_follows_namespace
    it('국가 선택칸 — US 로 바꾸면 해외 칸이 나오고, 「뒤로」 → 「새 배송지」 는 KR 로 다시 시작한다', async () => {
        m = await mount({ international: true });
        await openManage();
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        const select = () => dialog()!.querySelector('select[name="country_code"]') as HTMLSelectElement;
        await waitFor(() => expect(select()).not.toBeNull());
        fireEvent.change(select(), { target: { value: 'US' } });
        await waitFor(() => expect(m!.app.getGlobalState().checkoutAddressModal?.editing?.country_code).toBe('US'));
        await waitFor(() => expect(dialog()!.querySelector('input[name="address_line_1"]')).not.toBeNull());
        expect(select().value).toBe('US');
        fireEvent.click(buttonByText(dialog()!, /common\.back|뒤로/)!);
        await waitFor(() => expect(isFormMode()).toBe(false));
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(m!.app.getGlobalState().checkoutAddressModal?.editing?.country_code).toBe('KR'));
        await waitFor(() => expect(select().value).toBe('KR'));
        expect(dialog()!.querySelector('input[name="address_line_1"]')).toBeNull();
    });

    // @scenario close=none
    // @effects address_search_dot_path_write_shows
    it('주소 검색 결과(이름 공간 점 경로 쓰기)가 칸에 보이고, 먼저 적어 둔 상세 주소는 남는다', async () => {
        m = await mount();
        await openManage();
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        const field = (name: string) => dialog()!.querySelector(`input[name="${name}"]`) as HTMLInputElement;
        fireEvent.change(field('address_detail'), { target: { value: '3층' } });
        await waitFor(() => expect(field('address_detail').value).toBe('3층'));
        // 주소 검색 플러그인 콜백과 같은 쓰기(창의 onAddressSelect 콜백 액션과 같은 키)
        const callback = findNode(addressManageModal, (n) => n.type === 'extension_point')?.callbacks?.onAddressSelect;
        const params = Array.isArray(callback) ? callback[0]?.params : callback?.params;
        expect(Object.keys(params ?? {})).toEqual(expect.arrayContaining(['checkoutAddressModal.editing.zipcode', 'checkoutAddressModal.editing.address']));
        await (window as any).G7Core.dispatch({ handler: 'setState', params: { target: 'global', 'checkoutAddressModal.editing.zipcode': '48058', 'checkoutAddressModal.editing.address': '부산 해운대구 1' } });
        await waitFor(() => expect(field('zipcode').value).toBe('48058'));
        expect(field('address').value).toBe('부산 해운대구 1');
        expect(field('address_detail').value).toBe('3층');
    });
});

// ── 같은 구조 결함군 — 페이지가 바깥에서 세우고 창이 자기 _local 로도 쓰던 다른 창 ─────────────────────────────────────

describe('같은 구조의 다른 창 — 실제 창 구조 (7.1.1 결함군)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    // @scenario close=cancel_button
    // @effects withdraw_confirm_unchecked_on_reopen
    it('회원 탈퇴 창 — «알고 있다» 를 체크하고 취소한 뒤 다시 열면 체크가 풀려 있고 탈퇴 단추가 잠겨 있다', async () => {
        const opener = findNode(profileView, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('withdrawModal'))!;
        m = await mount({ page: [opener], modals: [withdrawModal], local: {}, data: { user: { data: { is_super: false } } } });
        const openWithdraw = async () => {
            fireEvent.click(buttonByText(document.querySelector('[data-testid="page"]')!, /withdraw_button/)!);
            await waitFor(() => expect(dialog()).not.toBeNull());
        };
        const checkbox = () => dialog()!.querySelector('input[type="checkbox"]') as HTMLInputElement;
        const confirm = () => buttonByText(dialog()!, /confirm_button/)! as HTMLButtonElement;
        await openWithdraw();
        expect(checkbox().checked).toBe(false);
        expect(confirm().disabled).toBe(true);
        fireEvent.click(checkbox());
        await waitFor(() => expect(checkbox().checked).toBe(true));
        await waitFor(() => expect(confirm().disabled).toBe(false));
        fireEvent.click(buttonByText(dialog()!, /common\.cancel/)!);
        await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
        await openWithdraw();
        await waitFor(() => expect(checkbox().checked).toBe(false));
        expect(confirm().disabled).toBe(true);
    });

    // @scenario close=close_button
    // @effects image_viewer_shows_reopened_image
    it('클레임 사진 확대 창 — 닫기 단추로 닫은 뒤 다른 사진을 열면 그 사진이 보인다', async () => {
        const opener = findNode(claimDetailReason, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('modal_claim_image_viewer'))!;
        const images = [{ download_url: '/files/a.jpg', original_name: 'a.jpg' }, { download_url: '/files/b.jpg', original_name: 'b.jpg' }];
        m = await mount({ page: [opener], modals: [claimImageViewer], local: {}, data: { claim: { data: { claim: { images } } } } });
        const thumbs = () => Array.from(document.querySelectorAll('[data-testid="page"] button')) as HTMLElement[];
        await waitFor(() => expect(thumbs().length).toBe(2));
        fireEvent.click(thumbs()[0]);
        await waitFor(() => expect(dialog()?.querySelector('img')?.getAttribute('src')).toBe('/files/a.jpg'));
        fireEvent.click(buttonByText(dialog()!, /image_viewer_close/)!);
        await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
        fireEvent.click(thumbs()[1]);
        await waitFor(() => expect(dialog()).not.toBeNull());
        await waitFor(() => expect(dialog()!.querySelector('img')?.getAttribute('src')).toBe('/files/b.jpg'));
    });
});


// ── 칸 사이 쉼 없는 연속 입력(전역 자동바인딩의 낡은 스냅샷 덮어쓰기) ──────────────────────────────────────────────
//
// 엔진의 자동바인딩 쓰기(DynamicRenderer performStateUpdate)는 «마지막 렌더 때의 _global 스냅샷»(parentFormContext.state)에
// 이번 칸 값만 얹은 전역 사본을 _globalSetState 로 넘긴다. 전역 다시 그리기는 미뤄지므로(동적 import + startTransition),
// 다음 칸의 첫 글자가 그 렌더보다 먼저 오면 앞 칸 값이 없는 스냅샷이 앞 칸을 지운다 — 깊이(1단·2단)와 무관하고, 무거운 화면일수록
// 렌더가 늦어 20ms 간격에서도 터진다(주문서 1/4). 렌더 지연을 결정적으로 재현하려고 «글자 사이 대기 0»(같은 매크로태스크)
// 으로도 잰다. 칸마다 change → 전역 점 경로 setState 로 쓰면 ActionDispatcher 가 매번 최신 전역(G7Core.state.get())에 병합한다.

/**
 * 칸들을 차례로 친다 — 칸 사이 쉼 없이, 글자 사이 gap ms.
 *
 * @param fields [칸 이름, 칠 문자열]
 * @param gap 글자 사이 대기(ms) — 0 이면 렌더가 끼지 않는다
 */
async function typeFields(fields: Array<[string, string]>, gap: number): Promise<void> {
    for (const [name, text] of fields) {
        for (let i = 1; i <= text.length; i += 1) {
            const el = dialog()!.querySelector(`input[name="${name}"]`) as HTMLInputElement;
            fireEvent.change(el, { target: { value: text.slice(0, i) } });
            if (gap) await new Promise((r) => setTimeout(r, gap));
        }
    }
    await new Promise((r) => setTimeout(r, 200));
}

const FOUR: Array<[string, string]> = [['name', '별장2'], ['recipient_name', '이몽룡'], ['recipient_phone', '010-5555-6666'], ['address_detail', '101동 1203호']];

describe('칸 사이 쉼 없는 연속 입력 — 앞 칸 값이 남는다 (7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    for (const gap of [0, 20]) {
        // @scenario close=none
        // @effects rapid_field_switch_keeps_all_fields
        it(`주문서 새 배송지 — 4칸을 쉼 없이(글자 간격 ${gap}ms) 치면 상태와 POST 본문에 4칸이 모두 남는다`, async () => {
            m = await mount();
            await openManage();
            fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
            await waitFor(() => expect(isFormMode()).toBe(true));
            await typeFields(FOUR, gap);
            const editing = m.app.getGlobalState().checkoutAddressModal?.editing ?? {};
            expect(FOUR.map(([k]) => editing[k])).toEqual(FOUR.map(([, v]) => v));
            fireEvent.click(buttonByText(dialog()!, /mypage\.addresses\.add|새 주소/)!);
            await waitFor(() => expect(m!.fetchCalls.some((c) => c.url.includes('/user/addresses') && c.method === 'POST')).toBe(true));
            const body = m!.fetchCalls.find((c) => c.url.includes('/user/addresses') && c.method === 'POST')!.body;
            expect(FOUR.map(([k]) => body?.[k])).toEqual(FOUR.map(([, v]) => v));
        });

        // @scenario close=none
        // @effects rapid_field_switch_keeps_all_fields
        it(`주문서 수정(별장) — 배송지명을 바꾸고 쉼 없이(글자 간격 ${gap}ms) 다음 칸을 치면 PUT 본문에 바뀐 배송지명이 실린다`, async () => {
            m = await mount();
            await openManage();
            await editFirst();
            // 수정 폼은 앞 값이 채워져 있다 — 끝에 덧붙여 친다
            await typeFields([['name', '별장 2층'], ['recipient_name', '홍길동A'], ['address_detail', '1층 B']], gap);
            fireEvent.click(buttonByText(dialog()!, /common\.save|저장/)!);
            await waitFor(() => expect(m!.fetchCalls.some((c) => c.url.includes('/user/addresses/185') && c.method === 'PUT')).toBe(true));
            const body = m!.fetchCalls.find((c) => c.url.includes('/user/addresses/185') && c.method === 'PUT')!.body;
            expect([body?.name, body?.recipient_name, body?.address_detail]).toEqual(['별장 2층', '홍길동A', '1층 B']);
        });
    }

    // @scenario close=none
    // @effects rapid_field_switch_keeps_all_fields
    it('마이페이지 배송지 창(1단 전역 키) — 렌더가 끼기 전에 칸을 옮겨도 4칸이 모두 남는다', async () => {
        const opener = findNode(mypageAddressList, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('addressModal') && JSON.stringify(n).includes('mypage.addresses.add'))!;
        m = await mount({ page: [opener], modals: [mypageAddressModal], local: {} });
        fireEvent.click(buttonByText(document.querySelector('[data-testid="page"]')!, /mypage\.addresses\.add/)!);
        await waitFor(() => expect(Boolean(dialog()?.querySelector('input[name="recipient_name"]'))).toBe(true));
        // 배송지명 칸은 name 속성 없이 change 로 쓰는 칸이라(종전부터) 나머지 3칸으로 잰다
        const three: Array<[string, string]> = [['recipient_name', '이몽룡'], ['recipient_phone', '010-5555-6666'], ['address_detail', '101동 1203호']];
        await typeFields(three, 0);
        const editing = m.app.getGlobalState().editingAddress ?? {};
        expect(three.map(([k]) => editing[k])).toEqual(three.map(([, v]) => v));
    });

    // @scenario close=none
    // @effects rapid_field_switch_keeps_all_fields
    it('주문 상세 배송지 변경 창(직접 입력) — 렌더가 끼기 전에 칸을 옮겨도 3칸이 모두 남는다', async () => {
        const opener = findNode(orderShipping, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('changeAddressModal'))!;
        const order = { data: { id: 7, order_status: 'pending_payment', shipping_address: { recipient_name: '홍길동', recipient_phone: '010-1111-2222', zipcode: '06236', address: '서울 강남구 테헤란로 1', address_detail: '1층' } } };
        m = await mount({ page: [opener], modals: [changeAddressModal], local: {}, data: { order } });
        fireEvent.click(buttonByText(document.querySelector('[data-testid="page"]')!, /change_shipping_address/)!);
        await waitFor(() => expect(dialog()).not.toBeNull());
        const manual = buttonByText(dialog()!, /manual_input/);
        if (manual) fireEvent.click(manual);
        await waitFor(() => expect(dialog()!.querySelector('input[name="recipient_name"]')).not.toBeNull());
        await waitFor(() => expect((dialog()!.querySelector('input[name="recipient_name"]') as HTMLInputElement).value).toBe('홍길동'));
        await typeFields([['recipient_name', '홍길동B'], ['recipient_phone', '010-1111-2223'], ['address_detail', '1층 C']], 0);
        const shipping = m.app.getGlobalState().editingShippingAddress ?? {};
        expect([shipping.recipient_name, shipping.recipient_phone, shipping.address_detail]).toEqual(['홍길동B', '010-1111-2223', '1층 C']);
    });
});


// ── — 창 「선택」 이 주문서 상태(우편번호·국가·배송지)에 닿는다 ─────────────────────────────────────────────────────
//
// 종전 「선택」 은 `<set-state target="$parent._local" checkout.zipcode=… checkout.country_code=…>` 였다. 엔진의 `$parent._local`
// 쓰기(ActionDispatcher handleParentScopeSetState, 경로 없는 갈래)는 페이지 렌더러의 지역 상태(저장소 A)에만 payload 를 그대로
// 넘기고(DynamicRenderer.deepMergeState 는 점 이름을 경로로 풀지 않아 `"checkout.zipcode"` 라는 키가 생긴다) 저장소 B 는 일부러
// 쓰지 않는다(사례 29). 같은 액션의 PUT 본문만 스택 상태 덕에 새 값이고, 그 뒤 페이지 동작(쿠폰·적립금)의 PUT 은 옛 우편번호였다
// (71517 → 37233 선택 뒤 다음 PUT 71517).

/** 페이지 쪽 「다른 동작」 — 쿠폰·적립금 변경처럼 페이지 문맥에서 `_local.checkout` 을 실어 PUT 한다 */
const pageRecalc = {
    type: 'basic', name: 'Button', props: { type: 'button', 'data-testid': 'page-recalc' }, text: 'recalc',
    actions: [{ type: 'click', handler: 'apiCall', target: '/api/modules/sirsoft-ecommerce/checkout', params: { method: 'PUT', body: '{{_local.checkout}}', auth_mode: 'optional' } }],
};

describe('주문서 「배송지 관리」 창 「선택」 — 주문서 상태에 닿는다 (7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    // @scenario close=none
    // @effects select_reaches_page_checkout_state
    it('「선택」 뒤 페이지 동작의 PUT /checkout 본문이 고른 배송지의 우편번호·국가이고, 앞서 쓴 적립금은 남는다', async () => {
        m = await mount({
            page: [checkoutShipping, pageRecalc],
            local: { selectedAddressId: null, selectedAddressObj: null, shipping: { recipient_name: '앞사람', zipcode: '71517', address: '옛 주소' }, checkout: { zipcode: '71517', country_code: 'KR', use_points: 100, order_coupon_issue_id: null } },
        });
        await openManage();
        // 186(회사, 04524) 카드를 고른다 — 카드의 정보 영역 단추
        const cards = Array.from(dialog()!.querySelectorAll('button[aria-pressed]')) as HTMLElement[];
        fireEvent.click(cards[1]);
        await waitFor(() => expect(m!.app.getGlobalState().checkoutAddressModal?.selectedAddressId).toBe(186));
        await clickSelect();
        await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
        await waitFor(() => expect(m!.fetchCalls.filter((c) => c.url.endsWith('/checkout') && c.method === 'PUT').length).toBeGreaterThanOrEqual(1));
        const before = m.fetchCalls.length;

        // 주문 쿠폰·적립금 변경 같은 페이지 동작
        fireEvent.click(document.querySelector('[data-testid="page-recalc"]') as HTMLElement);
        await waitFor(() => expect(m!.fetchCalls.slice(before).some((c) => c.url.endsWith('/checkout') && c.method === 'PUT')).toBe(true));
        const body = m.fetchCalls.slice(before).find((c) => c.url.endsWith('/checkout') && c.method === 'PUT')!.body;
        expect(body?.zipcode).toBe('04524');
        expect(body?.country_code).toBe('KR');
        expect(body?.use_points).toBe(100);

        const local = (window as any).G7Core.state.getLocal();
        expect(local?.checkout?.zipcode).toBe('04524');
        expect(local?.shipping?.recipient_name).toBe('김영희');
        expect(local?.shipping?.address).toBe('서울 중구 세종대로 2');
        expect(local?.selectedAddressId).toBe(186);
    });
});

// ── 정적 잠금 — modals 섹션 창과 그 창 밖(페이지)이 같은 _local 키를 함께 쓰지 않는다 ───────────────────────────────────

const ROOT = resolve(__dirname, '../../..');

/**
 * 두 겹 구조에 아직 남은 자리(무해 2) — 고치지 않았다(출시 전 보류 판단).
 * 새 자리가 생기면 이 목록이 아니라 창 고유 전역 이름 공간으로 옮긴다. 쿠폰 받기 창(보류 4)은 `_global.couponDownloadModal` 로 옮겼다
 * (7.1.1, coupon-download-modal-real-structure.test.tsx). 미사용이던 중복 불가 쿠폰 확인 창(exclusiveCouponConfirm)은 7.1.1 에서 지웠다.
 */
const DEFERRED = new Set([
    // 무해 — 문의 삭제 창이 지운 글의 펼침 표시를 걷는 쓰기라, 페이지에 닿지 않아도 그 글이 목록에서 사라진다(해당 없음)
    'layouts/mypage/inquiries.json#inquiry_delete_modal#openedInquiryId',
    'layouts/shop/show.json#inquiry_delete_modal#qnaOpenId',
]);

/**
 * basic 레이아웃마다 modals 섹션 창이 지역 상태(target local·지역 data-key)로 쓰는 최상위 키 중, 같은 레이아웃의 창 밖에서도
 * 지역 상태로 쓰는 키를 모은다(partial 참조를 펼친다).
 *
 * @returns `레이아웃#창#키` 목록
 */
function sharedLocalKeys(): string[] {
    const layoutsDir = join(ROOT, 'layouts');
    const files: string[] = [];
    (function walk(d: string): void {
        for (const e of readdirSync(d)) {
            const p = join(d, e);
            if (statSync(p).isDirectory()) walk(p); else if (p.endsWith('.json')) files.push(p);
        }
    })(layoutsDir);
    const load = (rel: string): any => { const p = join(layoutsDir, rel); return existsSync(p) ? JSON.parse(readFileSync(p, 'utf-8')) : null; };
    const expand = (x: any, seen: Set<string>): any => {
        if (Array.isArray(x)) return x.map((y) => expand(y, seen));
        if (!x || typeof x !== 'object') return x;
        if (typeof x.partial === 'string' && !seen.has(x.partial)) {
            const j = load(x.partial);
            if (j) return expand(j, new Set([...seen, x.partial]));
        }
        return Object.fromEntries(Object.entries(x).map(([k, v]) => [k, expand(v, seen)]));
    };
    const localWrites = (tree: any): Set<string> => {
        const out = new Set<string>();
        (function w(x: any): void {
            if (Array.isArray(x)) return x.forEach(w);
            if (!x || typeof x !== 'object') return;
            if (x.handler === 'setState' && x.params && (x.params.target ?? 'local') === 'local') {
                Object.keys(x.params).filter((k) => !['target', 'merge'].includes(k) && !k.startsWith('__')).forEach((k) => out.add(k.split('.')[0]));
            }
            if (typeof x.dataKey === 'string' && !x.dataKey.startsWith('_global.') && !x.dataKey.startsWith('_isolated')) out.add(x.dataKey.replace(/^_local\./, '').split('.')[0]);
            Object.values(x).forEach(w);
        })(tree);
        return out;
    };
    const found: string[] = [];
    for (const p of files) {
        const j = JSON.parse(readFileSync(p, 'utf-8'));
        if (!Array.isArray(j.modals) || !j.modals.length || j.meta?.is_partial) continue;
        const rel = relative(ROOT, p).split('\\').join('/');
        const pageW = localWrites(expand({ ...j, modals: undefined }, new Set()));
        for (const m of j.modals.map((x: any) => expand(x, new Set()))) {
            for (const k of localWrites(m)) if (pageW.has(k)) found.push(`${rel}#${m.id ?? '?'}#${k}`);
        }
    }
    return [...new Set(found)].sort();
}

describe('modals 섹션 창과 창 밖이 같은 지역 상태 키를 함께 쓰지 않는다 (7.1.1 결함군 정적 잠금)', () => {
    const shared = sharedLocalKeys();

    // @scenario close=static
    // @effects modal_and_page_do_not_share_local_keys
    it('basic 레이아웃 전부에서 보류 목록 밖의 자리가 없다', () => {
        const outside = shared.filter((k) => !DEFERRED.has(k));
        expect(outside, outside.join('\n')).toEqual([]);
    });

    // @scenario close=static
    // @effects modal_and_page_do_not_share_local_keys
    it('판정이 살아 있다 — 보류 자리는 여전히 잡히고, 고친 창(주문서 배송지·탈퇴·사진)은 잡히지 않는다', () => {
        expect(shared).toEqual(expect.arrayContaining([...DEFERRED]));
        expect(shared.some((k) => /addressManageModal|withdrawModal|modal_claim_image_viewer|coupon_download_modal|exclusiveCouponConfirm/.test(k))).toBe(false);
    });
});

describe('전역 data-key 자동바인딩 폼이 없다 (7.1.1 정적 잠금)', () => {
    // @scenario close=static
    // @effects rapid_field_switch_keeps_all_fields
    it('basic 레이아웃 전부에서 data-key="_global.*" 폼이 없다 — 전역 칸은 value + change 점 경로 쓰기로 둔다', () => {
        const found: string[] = [];
        (function walk(d: string): void {
            for (const e of readdirSync(d)) {
                const p = join(d, e);
                if (statSync(p).isDirectory()) { walk(p); continue; }
                if (!p.endsWith('.json')) continue;
                (function w(x: any): void {
                    if (Array.isArray(x)) return x.forEach(w);
                    if (!x || typeof x !== 'object') return;
                    if (typeof x.dataKey === 'string' && x.dataKey.startsWith('_global.')) found.push(`${relative(ROOT, p).split(sep).join('/')} ${x.dataKey}`);
                    Object.values(x).forEach(w);
                })(JSON.parse(readFileSync(p, 'utf-8')));
            }
        })(join(ROOT, 'layouts'));
        expect(found).toEqual([]);
    });
});


// ── 창을 닫으면 창 이름 공간을 비운다 ─────────────────────────────────────────────────────────────────────
//
// 주문서 「배송지 관리」 창의 `_global.checkoutAddressModal`(배송지 이름·받는 분·연락처·주소)과 클레임 사진 창의
// `_global.claimImageViewer.url`(한시 서명 URL)이 창을 닫은 뒤에도 전역에 남았다 — 다음 열기가 덮어쓸 때까지 DevTools 로 보인다
// (security.md 「_global 민감 정보 금지」). 같은 모양인 마이페이지 배송지 창(`_global.editingAddress`)·주문 상세 배송지 변경 창
// (`_global.editingShippingAddress`)도 같다. 닫기 단추·Esc·바깥 클릭은 엔진이 주입한 closeModal 로만 이어져 레이아웃 동작을 타지
// 않으므로, 창이 열려 있는 동안 내내 붙어 있는 노드의 unmount 수명주기에서 비운다(창 본문은 창이 닫히면 내려간다). 엔진 무변경.

const closeState = (m: Mounted, key: string) => m.app.getGlobalState()[key];

describe('창을 닫으면 창 이름 공간이 비워진다 — 주문서 배송지 관리 (7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    for (const how of ['x', 'escape', 'backdrop'] as const) {
        // @scenario close=close_button
        // @scenario close=escape
        // @scenario close=backdrop
        // @effects modal_namespace_cleared_on_close
        it(`수정 폼(배송지 15칸)을 연 채 ${how} 로 닫으면 _global.checkoutAddressModal 이 null 이고, 다시 열면 목록·페이지 현재 선택으로 시작한다`, async () => {
            m = await mount({ local: { selectedAddressId: 186, selectedAddressObj: ADDRESSES[1], checkout: {} } });
            await openManage();
            await editFirst();
            expect(closeState(m, 'checkoutAddressModal')?.editing?.recipient_phone).toBe('010-1111-2222');
            await closeBy(how);
            await waitFor(() => expect(closeState(m!, 'checkoutAddressModal')).toBeNull(), CLOSE_WAIT);
            await openManage();
            expect(isFormMode()).toBe(false);
            expect(closeState(m, 'checkoutAddressModal')?.selectedAddressId).toBe(186);
            expect(closeState(m, 'checkoutAddressModal')?.editing).toBeNull();
        });
    }

    // @scenario close=cancel_button
    // @effects modal_namespace_cleared_on_close
    it('목록에서 카드를 고른 뒤 「취소」 로 닫으면 고른 배송지 사본도 함께 비워진다', async () => {
        m = await mount();
        await openManage();
        const cards = Array.from(dialog()!.querySelectorAll('button[aria-pressed]')) as HTMLElement[];
        fireEvent.click(cards[0]);
        await waitFor(() => expect(closeState(m!, 'checkoutAddressModal')?.selectedAddressObj?.recipient_name).toBe('홍길동'));
        fireEvent.click(buttonByText(dialog()!, /common\.cancel/)!);
        await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
        await waitFor(() => expect(closeState(m!, 'checkoutAddressModal')).toBeNull(), CLOSE_WAIT);
    });

    // @scenario close=select_button
    // @effects modal_namespace_cleared_on_close, select_reaches_page_checkout_state
    it('「선택」 으로 닫으면 이름 공간이 비워지고, 고른 배송지(이벤트로 넘긴 값)는 주문서 상태에 그대로 닿는다', async () => {
        m = await mount({
            page: [checkoutShipping],
            local: { selectedAddressId: null, selectedAddressObj: null, shipping: { recipient_name: '앞사람', zipcode: '71517' }, checkout: { zipcode: '71517', country_code: 'KR' } },
        });
        await openManage();
        const cards = Array.from(dialog()!.querySelectorAll('button[aria-pressed]')) as HTMLElement[];
        fireEvent.click(cards[1]);
        await waitFor(() => expect(closeState(m!, 'checkoutAddressModal')?.selectedAddressId).toBe(186));
        await clickSelect();
        await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
        await waitFor(() => expect(closeState(m!, 'checkoutAddressModal')).toBeNull(), CLOSE_WAIT);
        await waitFor(() => expect((window as any).G7Core.state.getLocal()?.selectedAddressId).toBe(186));
        const local = (window as any).G7Core.state.getLocal();
        expect(local?.shipping?.recipient_name).toBe('김영희');
        expect(local?.checkout?.zipcode).toBe('04524');
    });

    // @scenario close=none
    // @effects form_pii_cleared_on_save_success
    it('저장 성공은 창을 닫지 않고 목록으로 돌아가며, 쓰던 배송지(editing)는 비우고 창 상태는 남긴다', async () => {
        m = await mount();
        await openManage();
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        await typeFields(FOUR, 0);
        fireEvent.click(buttonByText(dialog()!, /mypage\.addresses\.add|새 주소/)!);
        await waitFor(() => expect(m!.fetchCalls.some((c) => c.url.includes('/user/addresses') && c.method === 'POST')).toBe(true));
        await waitFor(() => expect(isFormMode()).toBe(false));
        expect(dialog()).not.toBeNull();
        expect(closeState(m, 'checkoutAddressModal')?.mode).toBe('list');
        expect(closeState(m, 'checkoutAddressModal')?.editing).toBeNull();
    });

    // @scenario close=none
    // @effects namespace_kept_while_open
    it('열려 있는 동안에는 비워지지 않는다 — 목록 ↔ 폼 전환·전체 다시 그리기를 거쳐도 입력한 값이 남는다', async () => {
        m = await mount();
        await openManage();
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        await typeFields([['recipient_name', '이몽룡']], 0);
        // 열린 채 전체 렌더
        m.app.setGlobalState({ __probe: Date.now() });
        await new Promise((r) => setTimeout(r, 50));
        expect(closeState(m, 'checkoutAddressModal')?.editing?.recipient_name).toBe('이몽룡');
        // 폼 → 목록 → 폼(두 얼굴이 번갈아 내려가고 올라온다)
        fireEvent.click(buttonByText(dialog()!, /common\.back|뒤로/)!);
        await waitFor(() => expect(isFormMode()).toBe(false));
        await new Promise((r) => setTimeout(r, 50));
        expect(closeState(m, 'checkoutAddressModal')).not.toBeNull();
        expect(closeState(m, 'checkoutAddressModal')?.mode).toBe('list');
        fireEvent.click(buttonByText(dialog()!, /address_add_new|새 배송지/)!);
        await waitFor(() => expect(isFormMode()).toBe(true));
        await typeFields(FOUR, 0);
        const editing = closeState(m, 'checkoutAddressModal')?.editing ?? {};
        expect(FOUR.map(([k]) => editing[k])).toEqual(FOUR.map(([, v]) => v));
    });
});

describe('창을 닫으면 창 이름 공간이 비워진다 — 클레임 사진 확대 (7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    for (const how of ['x', 'escape', 'backdrop', 'close_button'] as const) {
        // @scenario close=close_button
        // @scenario close=escape
        // @scenario close=backdrop
        // @effects modal_namespace_cleared_on_close
        it(`사진(한시 서명 URL)을 연 뒤 ${how} 로 닫으면 _global.claimImageViewer 가 null 이고, 다시 열면 고른 사진이다`, async () => {
            const opener = findNode(claimDetailReason, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('modal_claim_image_viewer'))!;
            const images = [{ download_url: '/files/a.jpg?expires=1&signature=s1', original_name: 'a.jpg' }, { download_url: '/files/b.jpg?expires=1&signature=s2', original_name: 'b.jpg' }];
            m = await mount({ page: [opener], modals: [claimImageViewer], local: {}, data: { claim: { data: { claim: { images } } } } });
            const thumbs = () => Array.from(document.querySelectorAll('[data-testid="page"] button')) as HTMLElement[];
            await waitFor(() => expect(thumbs().length).toBe(2));
            fireEvent.click(thumbs()[0]);
            await waitFor(() => expect(dialog()?.querySelector('img')?.getAttribute('src')).toBe(images[0].download_url));
            if (how === 'close_button') {
                fireEvent.click(buttonByText(dialog()!, /image_viewer_close/)!);
                await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
            } else {
                await closeBy(how);
            }
            await waitFor(() => expect(closeState(m!, 'claimImageViewer')).toBeNull(), CLOSE_WAIT);
            fireEvent.click(thumbs()[1]);
            await waitFor(() => expect(dialog()?.querySelector('img')?.getAttribute('src')).toBe(images[1].download_url));
        });
    }
});

describe('창을 닫으면 창 이름 공간이 비워진다 — 같은 모양의 배송지 창 (7.1.1 같은 결함군)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    const mypageAdd = () => findNode(mypageAddressList, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('addressModal') && JSON.stringify(n).includes('mypage.addresses.add'))!;
    const openMypage = async () => {
        fireEvent.click(buttonByText(document.querySelector('[data-testid="page"]')!, /mypage\.addresses\.add/)!);
        await waitFor(() => expect(Boolean(dialog()?.querySelector('input[name="recipient_name"]'))).toBe(true));
    };

    for (const how of ['x', 'escape', 'backdrop', 'cancel'] as const) {
        // @scenario close=close_button
        // @scenario close=escape
        // @scenario close=backdrop
        // @scenario close=cancel_button
        // @effects modal_namespace_cleared_on_close
        it(`마이페이지 배송지 창 — 입력한 뒤 ${how} 로 닫으면 _global.editingAddress 가 null 이다`, async () => {
            m = await mount({ page: [mypageAdd()], modals: [mypageAddressModal], local: {} });
            await openMypage();
            await typeFields([['recipient_name', '이몽룡'], ['recipient_phone', '010-5555-6666']], 0);
            expect(closeState(m, 'editingAddress')?.recipient_phone).toBe('010-5555-6666');
            if (how === 'cancel') {
                fireEvent.click(buttonByText(dialog()!, /common\.cancel/)!);
                await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
            } else {
                await closeBy(how);
            }
            await waitFor(() => expect(closeState(m!, 'editingAddress')).toBeNull(), CLOSE_WAIT);
        });
    }

    // @scenario close=save_success
    // @effects modal_namespace_cleared_on_close
    it('마이페이지 배송지 창 — 저장 성공으로 닫히면 _global.editingAddress 가 null 이고 저장 본문에는 입력이 다 실렸다', async () => {
        m = await mount({ page: [mypageAdd()], modals: [mypageAddressModal], local: {} });
        await openMypage();
        const three: Array<[string, string]> = [['recipient_name', '이몽룡'], ['recipient_phone', '010-5555-6666'], ['address_detail', '101동 1203호']];
        await typeFields(three, 0);
        fireEvent.click(buttonByText(dialog()!, /mypage\.addresses\.add/)!);
        await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
        await waitFor(() => expect(closeState(m!, 'editingAddress')).toBeNull(), CLOSE_WAIT);
        const body = m.fetchCalls.find((c) => c.url.endsWith('/user/addresses') && c.method === 'POST')!.body;
        expect(three.map(([k]) => body?.[k])).toEqual(three.map(([, v]) => v));
    });

    // @scenario close=save_success
    // @effects overwrite_chain_reads_namespace_before_clear
    it('마이페이지 배송지 창 — 409(같은 이름) → 덮어쓰기 확인 → 확인: 다시 보내는 본문에 입력이 실리고, 두 창이 닫힌 뒤 비워진다', async () => {
        let posts = 0;
        m = await mount({
            page: [mypageAdd()],
            modals: [mypageAddressModal, confirmOverwriteModal],
            local: {},
            respond: (call) => (call.method === 'POST' && (posts += 1) === 1 ? { status: 409, json: { success: false, message: 'duplicate', errors: { name: ['dup'] } } } : undefined),
        });
        await openMypage();
        await typeFields([['recipient_name', '이몽룡'], ['recipient_phone', '010-5555-6666']], 0);
        fireEvent.click(buttonByText(dialog()!, /mypage\.addresses\.add/)!);
        await waitFor(() => expect(document.querySelectorAll('[role="dialog"]').length).toBe(2));
        // 덮어쓰기 확인 창이 떠 있는 동안 아래 편집 창은 열려 있다 — 이름 공간이 남아 있어야 한다
        expect(closeState(m, 'editingAddress')?.recipient_name).toBe('이몽룡');
        const confirmDialog = Array.from(document.querySelectorAll('[role="dialog"]')).pop() as HTMLElement;
        fireEvent.click(buttonByText(confirmDialog, /overwrite_confirm/)!);
        await waitFor(() => expect(m!.fetchCalls.filter((c) => c.url.endsWith('/user/addresses') && c.method === 'POST').length).toBe(2));
        const resend = m.fetchCalls.filter((c) => c.url.endsWith('/user/addresses') && c.method === 'POST')[1].body;
        expect(resend?.force_overwrite).toBe(true);
        expect([resend?.recipient_name, resend?.recipient_phone]).toEqual(['이몽룡', '010-5555-6666']);
        await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
        await waitFor(() => expect(closeState(m!, 'editingAddress')).toBeNull(), CLOSE_WAIT);
    });

    const orderOpener = () => findNode(orderShipping, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('changeAddressModal'))!;
    const order = { data: { id: 7, order_status: 'pending_payment', shipping_address: { recipient_name: '홍길동', recipient_phone: '010-1111-2222', zipcode: '06236', address: '서울 강남구 테헤란로 1', address_detail: '1층' } } };

    for (const how of ['x', 'escape', 'cancel'] as const) {
        // @scenario close=close_button
        // @scenario close=escape
        // @scenario close=cancel_button
        // @effects modal_namespace_cleared_on_close
        it(`주문 상세 배송지 변경 창 — 현재 배송지로 연 뒤 ${how} 로 닫으면 _global.editingShippingAddress 가 null 이다`, async () => {
            m = await mount({ page: [orderOpener()], modals: [changeAddressModal], local: {}, data: { order } });
            fireEvent.click(buttonByText(document.querySelector('[data-testid="page"]')!, /change_shipping_address/)!);
            await waitFor(() => expect(dialog()).not.toBeNull());
            expect(closeState(m, 'editingShippingAddress')?.recipient_phone).toBe('010-1111-2222');
            if (how === 'cancel') {
                fireEvent.click(buttonByText(dialog()!, /common\.cancel/)!);
                await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
            } else {
                await closeBy(how);
            }
            await waitFor(() => expect(closeState(m!, 'editingShippingAddress')).toBeNull(), CLOSE_WAIT);
        });
    }

    // @scenario close=backdrop
    // @effects namespace_kept_while_open
    it('주문 상세 배송지 변경 창 — 바깥 클릭으로는 닫히지 않는 창이라(closeOnBackdropClick=false) 입력이 남는다', async () => {
        m = await mount({ page: [orderOpener()], modals: [changeAddressModal], local: {}, data: { order } });
        fireEvent.click(buttonByText(document.querySelector('[data-testid="page"]')!, /change_shipping_address/)!);
        await waitFor(() => expect(dialog()).not.toBeNull());
        const manual = buttonByText(dialog()!, /manual_input/);
        if (manual) fireEvent.click(manual);
        await waitFor(() => expect(dialog()!.querySelector('input[name="address_detail"]')).not.toBeNull());
        await typeFields([['address_detail', '1층 C']], 0);
        fireEvent.click(document.querySelector('[data-testid="modal-backdrop"]') as HTMLElement);
        await new Promise((r) => setTimeout(r, 50));
        expect(dialog()).not.toBeNull();
        expect(closeState(m, 'editingShippingAddress')?.address_detail).toBe('1층 C');
    });
});

// ── 정적 잠금 — 닫을 때 비우는 동작(unmount)이 열려 있는 동안 내내 붙어 있는 노드에 있다 (7.1.1) ─────────────────
//
// unmount 정리를 `data-if`·반복 안 노드에 걸면 창이 열린 채 그 노드가 내려갈 때(주문서 창의 목록 ↔ 폼 전환 등) 입력 중인 배송지를
// 지운다. 그래서 정리 노드부터 창(Modal) 루트까지 조건·반복이 없어야 한다.

/**
 * 창 안에서 `lifecycle.onUnmount` 가 key 를 전역에서 비우는 노드들을 찾고, 각 노드의 창 루트까지 조상 경로에 조건·반복이 있는지 돌려준다.
 *
 * @param modal 창(Modal) 노드
 * @param key 비우는 전역 키
 * @returns 찾은 노드마다 조건·반복 조상 여부
 */
function unmountClears(modal: Node, key: string): Array<{ guarded: boolean }> {
    const out: Array<{ guarded: boolean }> = [];
    const walk = (x: any, guarded: boolean) => {
        if (Array.isArray(x)) {
            x.forEach((y) => walk(y, guarded));

            return;
        }
        if (!x || typeof x !== 'object') return;
        const g = guarded || (x !== modal && (x.if !== undefined || x.iteration !== undefined));
        const clears = (x.lifecycle?.onUnmount ?? []).some((a: any) => a?.handler === 'setState' && a.params?.target === 'global' && Object.prototype.hasOwnProperty.call(a.params, key) && (a.params[key] === null || a.params[key] === ''));
        if (clears) out.push({ guarded: g });
        (x.children ?? []).forEach((c: any) => walk(c, g));
    };
    walk(modal, false);

    return out;
}

describe('닫을 때 비우는 동작이 열려 있는 동안 내내 붙어 있는 노드에 있다 (7.1.1 정적 잠금)', () => {
    const cases: Array<[string, Node, string]> = [
        ['shop/_modal_address_manage', addressManageModal as Node, 'checkoutAddressModal'],
        ['mypage/claims/_modal_image_viewer', claimImageViewer as Node, 'claimImageViewer'],
        ['mypage/addresses/_modal_address', mypageAddressModal as Node, 'editingAddress'],
        ['mypage/orders/_modal_change_address', changeAddressModal as Node, 'editingShippingAddress'],
    ];

    for (const [name, json, key] of cases) {
        // @scenario close=static
        // @effects unmount_clear_node_persistent_while_open
        it(`${name} — _global.${key} 를 비우는 unmount 가 조건·반복 밖 창 본문 노드에 하나 이상 있다`, () => {
            const modal = findNode(json, (n) => n.name === 'Modal')!;
            expect(modal).not.toBeNull();
            const found = unmountClears(modal, key);
            expect(found.length).toBeGreaterThan(0);
            expect(found.every((f) => !f.guarded)).toBe(true);
        });
    }

    // @scenario close=static
    // @effects unmount_clear_node_persistent_while_open
    it('판정이 살아 있다 — 조건(data-if) 안 노드에 건 정리는 잡히고, 정리가 없는 창은 0 이다', () => {
        const clear = { onUnmount: [{ handler: 'setState', params: { target: 'global', ns: null } }] };
        const guardedModal = { type: 'composite', name: 'Modal', children: [{ type: 'basic', name: 'Div', if: '{{_global.ns?.mode === "form"}}', children: [{ type: 'basic', name: 'Div', lifecycle: clear }] }] };
        expect(unmountClears(guardedModal, 'ns')).toEqual([{ guarded: true }]);
        expect(unmountClears({ type: 'composite', name: 'Modal', children: [{ type: 'basic', name: 'Div' }] }, 'ns')).toEqual([]);
    });
});


// ── 장바구니 「옵션 변경」 창: 앞 품목의 추가옵션 키가 다음 품목 적용 본문에 섞이지 않는다 ──────────────────────
//
// 품목 「옵션 변경」 단추는 페이지 `_local.additionalSelections`·`additionalCustomTexts` 를 그 품목의 추가옵션으로 채운 뒤 창을 연다.
// 기본(deep) 병합은 비어 있지 않은 객체를 써도 이번 객체에 없는 앞 키를 남기므로(엔진 계약 — 빈 객체 `{}` 만 교체), 추가옵션 있는
// 품목 A 의 창을 연 뒤 B 의 창에서 「적용」 하면 본문에 A 의 추가옵션 키가 실려 서버가 422(additional_option_invalid)로 거절했다
// (출시 전 실측). 창 안에서 추가옵션을 비우는 동작도 그 키를 뺀 객체를 써서 같은 이유로 키가 남았다. 여는 쪽은 null 로 먼저 비우고,
// 창 안 비우기는 그 키를 null 로 쓴다(본문은 null 선택을 거른다). 엔진 무변경.

const cartItemOptionButton = findNode(cartItemPartial, (n) => n.name === 'Button' && n.props?.['data-testid'] === 'cart-change-option')!;

/** 품목 하나 — 추가옵션 선택(그룹 id → 값 id)과 직접입력 글자 */
function cartItem(id: number, code: string, additional: Array<{ gid: number; vid: number; text?: string }>): Record<string, unknown> {
    return {
        id, product_id: id * 10, product_option_id: id * 100, quantity: 1,
        product: { product_code: code, name: `상품${code}`, sales_status: 'on_sale', thumbnail_url: null },
        product_option: { option_name: '기본', option_values: {}, option_values_localized: {}, stock_quantity: 9, selling_price_formatted: '1,000원' },
        additional_options: additional.map((a) => ({ additional_option_id: a.gid, value_id: a.vid, custom_text: a.text ?? null })),
    };
}

/** 상품 조회 응답 — 옵션 조합 없음(적용 단추가 열린다), 추가옵션 그룹만 */
function productResponse(groups: Array<{ id: number; values: number[]; custom?: boolean }>): Record<string, unknown> {
    return {
        has_options: false, option_groups: [], options: [],
        additional_options: groups.map((g) => ({ id: g.id, name: `추가${g.id}`, is_required: false, values: g.values.map((v) => ({ id: v, name: `값${v}`, price_adjustment: 0, allow_custom_text: !!g.custom })) })),
    };
}

describe('장바구니 「옵션 변경」 창 — 앞 품목의 추가옵션 키가 남지 않는다 (7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    const items = [
        cartItem(1, 'A', [{ gid: 11, vid: 111, text: '각인A' }, { gid: 12, vid: 121, text: '각인B' }]),
        cartItem(2, 'B', [{ gid: 22, vid: 222 }, { gid: 23, vid: 233 }]),
    ];
    const products: Record<string, unknown> = {
        A: productResponse([{ id: 11, values: [111, 112], custom: true }, { id: 12, values: [121, 122], custom: true }]),
        B: productResponse([{ id: 22, values: [222, 223] }, { id: 23, values: [233, 234] }]),
    };
    const respond = (call: { url: string; method: string }) => {
        const hit = call.method === 'GET' && /\/products\/(A|B)$/.exec(call.url);

        return hit ? { status: 200, json: { success: true, data: products[hit[1]] } } : undefined;
    };
    const mountCart = async () => {
        const page = [{ type: 'basic', name: 'Div', iteration: { source: '{{cartItems}}', item_var: 'item' }, children: [cartItemOptionButton] }];
        const mounted = await mount({ page, modals: [cartOptionChangeModal], local: {}, data: { cartItems: items }, respond });
        engine.getActionDispatcher()!.registerHandler('initCartOptionSelection', initCartOptionSelectionHandler as any);
        engine.getActionDispatcher()!.registerHandler('findMatchingOption', findMatchingOptionHandler as any);

        return mounted;
    };
    const openFor = async (index: number) => {
        const buttons = Array.from(document.querySelectorAll('[data-testid="page"] [data-testid="cart-change-option"]')) as HTMLElement[];
        fireEvent.click(buttons[index]);
        await waitFor(() => expect(dialog()).not.toBeNull());
    };
    const applyBody = async (mm: Mounted) => {
        const before = mm.fetchCalls.length;
        // 「적용」 은 품목 조회·옵션 맞춤 뒤에 잠금이 풀린다 — 「선택」 과 같이 풀린 단추를 누른다
        await clickWhenEnabled(() => dialog()?.querySelector('[data-testid="modal-apply"]'));
        await waitFor(() => expect(mm.fetchCalls.slice(before).some((c) => c.method === 'PUT' && /\/cart\/\d+\/option$/.test(c.url))).toBe(true));

        return mm.fetchCalls.slice(before).find((c) => c.method === 'PUT' && /\/cart\/\d+\/option$/.test(c.url))!;
    };
    const groupIds = (body: any) => (body?.additional_option_selections ?? []).map((s: any) => s.additional_option_id).sort();

    // @scenario close=cancel_button
    // @effects cart_option_reopen_drops_previous_item_additional_keys
    it('추가옵션 있는 품목 A 의 창을 열었다 닫고 B 의 창에서 「적용」 하면 본문은 B 의 추가옵션뿐이다', async () => {
        m = await mountCart();
        await openFor(0);
        await waitFor(() => expect((window as any).G7Core.state.getLocal()?.additionalSelections?.[11]).toBe(111));
        fireEvent.click(buttonByText(dialog()!, /common\.cancel/)!);
        await waitFor(() => expect(dialog()).toBeNull(), CLOSE_WAIT);
        await openFor(1);
        const put = await applyBody(m);
        expect(put.url).toMatch(/\/cart\/2\/option$/);
        expect(groupIds(put.body)).toEqual([22, 23]);
        expect((put.body?.additional_option_selections ?? []).every((s: any) => s.custom_text == null)).toBe(true);
    });

    // @scenario close=none
    // @effects additional_option_clear_drops_key_from_body
    it('창 안에서 추가옵션 하나를 비우면(다른 그룹은 남은 채) 적용 본문에서 그 그룹이 빠진다', async () => {
        m = await mountCart();
        await openFor(1);
        const select = () => dialog()!.querySelector('select[aria-label="추가22"]') as HTMLSelectElement;
        await waitFor(() => expect(select()).not.toBeNull());
        fireEvent.change(select(), { target: { value: '' } });
        await waitFor(() => expect(select().value).toBe(''));
        const put = await applyBody(m);
        expect(groupIds(put.body)).toEqual([23]);
    });

    // @scenario close=none
    // @effects additional_option_clear_drops_key_from_body
    it('창 안에서 직접입력 글자 하나를 지우면(다른 그룹 글자는 남은 채) 적용 본문의 그 그룹 custom_text 가 비어 있다', async () => {
        m = await mountCart();
        await openFor(0);
        const text = () => dialog()!.querySelector('input[type="text"]') as HTMLInputElement;
        await waitFor(() => expect(text()?.value).toBe('각인A'));
        fireEvent.input(text(), { target: { value: '   ' } });
        await waitFor(() => expect(text().value.trim()).toBe(''));
        const put = await applyBody(m);
        expect(groupIds(put.body)).toEqual([11, 12]);
        const byGroup = Object.fromEntries((put.body.additional_option_selections ?? []).map((s: any) => [s.additional_option_id, s.custom_text ?? null]));
        expect(byGroup).toEqual({ 11: null, 12: '각인B' });
    });
});

// ── 정적 잠금 — 객체 상태에서 키를 «뺀» 객체를 같은 키에 기본(deep) 병합으로 다시 쓰지 않는다 (7.1.1 같은 결함군) ────────
//
// `X = Object.fromEntries(Object.entries(X).filter(...))` 를 deep setState 로 쓰면, 걸러 낸 키가 병합에서 살아남는다(엔진 계약 —
// 비어 있지 않은 객체의 빠진 키는 남는다). 비울 키는 null 로 쓴다. 모집단: 번들 확장·템플릿의 layouts/·extensions/ 굽기 JSON 전부.

const REPO = resolve(ROOT, '../../..');

/**
 * 번들 레이아웃 JSON 전부에서 deep setState 가 같은 키에 «키를 뺀 자기 자신» 을 쓰는 자리를 모은다
 *
 * @returns `경로 키` 목록
 */
function keyDroppingWrites(): string[] {
    const found: string[] = [];
    const roots = ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled'].map((r) => join(REPO, r));
    const files: string[] = [];
    const walkDir = (d: string) => {
        if (!existsSync(d)) return;
        for (const e of readdirSync(d)) {
            if (e === 'node_modules' || e === 'dist' || e === '__tests__') continue;
            const p = join(d, e);
            if (statSync(p).isDirectory()) walkDir(p);
            else if (p.endsWith('.json') && /[\/](layouts|extensions)[\/]/.test(p)) files.push(p);
        }
    };
    roots.forEach(walkDir);
    for (const p of files) {
        let json: unknown;
        try { json = JSON.parse(readFileSync(p, 'utf8')); } catch { continue; }
        (function visit(o: any): void {
            if (Array.isArray(o)) { o.forEach(visit); return; }
            if (!o || typeof o !== 'object') return;
            if (o.handler === 'setState' && o.params && (o.params.merge === undefined || o.params.merge === 'deep')) {
                for (const [k, v] of Object.entries(o.params)) {
                    if (typeof v !== 'string') continue;
                    const leaf = k.split('.').pop()!;
                    const re = /Object\.fromEntries\(\s*Object\.entries\(([^)]*)\)\s*\.filter\(/g;
                    let mm: RegExpExecArray | null;
                    while ((mm = re.exec(v)) !== null) {
                        if (new RegExp(`(_local|_global)\??\.[\w.?]*\b${leaf}\b`).test(mm[1])) found.push(`${relative(REPO, p).split(sep).join('/')} ${k}`);
                    }
                }
            }
            Object.values(o).forEach(visit);
        })(json);
    }

    return found;
}

describe('객체 상태에서 키를 뺀 객체를 deep 병합으로 다시 쓰지 않는다 (7.1.1 정적 잠금)', () => {
    // 번들 전체 읽기는 수집 단계에서 한 번 한다(시험 한도 밖) — 시험은 판정만 한다
    const KEY_DROPPING_WRITES = keyDroppingWrites();

    // @scenario close=static
    // @effects additional_option_clear_drops_key_from_body
    it('번들 레이아웃 전부에서 그런 자리가 없다 — 비울 키는 null 로 쓴다', () => {
        expect(KEY_DROPPING_WRITES).toEqual([]);
    }, 60_000);
});
