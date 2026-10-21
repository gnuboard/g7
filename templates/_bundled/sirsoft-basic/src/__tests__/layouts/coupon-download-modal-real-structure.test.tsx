// e2e:allow 실제 엔진 구조(TemplateApp 전역 상태·renderTemplate 의 modals 섹션 렌더러·영구 마운트 창) 렌더 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(상품 상세 「+N」·주문서 「쿠폰 다운로드」 창에서 받기 → 닫기 → 다시 열기)이 덮는다.
/**
 * @file coupon-download-modal-real-structure.test.tsx
 * @description 쿠폰 받기 창 — 상품 상세에서도 받기 요청에 쿠폰 번호가 실리고 누른 카드만 「다운로드 중」이며, 닫았다 다시 열면 여는 쪽이 새로 준 목록이 보인다 (7.1.1)
 *
 * 상품 상세 「+N」 이 창에 넣는 목록(`products/{id}/downloadable-coupons`)의 항목에는 `id` 가 없고 `coupon_id` 만 있다. 창은
 * `coupon.id` 로 받기 주소를 만들어 `POST …/user/coupons//download`(404)가 나갔고, 「다운로드 중」 판정 `downloadingCouponId === coupon.id`
 * 가 undefined === undefined 라 카드 전부가 함께 잠겼다. 주문서 목록(`user/coupons/downloadable`)은 서비스가 `coupon_id = id` 를 더해
 * 두 필드가 다 있어 정상이었다. 두 목록에 공통인 `coupon_id` 를 읽는다.
 *
 * modals 섹션 창 안의 `_local` 은 「마지막 전체 렌더 때의 페이지 `_local` 스냅샷」 위에 「창 루트 자신의 지역 상태」를 deep 병합한
 * 값이다(창 쪽이 이긴다). 창이 받기·더보기 결과를 `_local.downloadableCoupons` 에 쓰면, 다시 열 때 여는 쪽이 페이지 `_local` 에 넣은
 * 새 목록이 창 쪽 값에 가려 닿지 않았다. 창 상태를 창 고유 전역 이름 공간 `_global.couponDownloadModal` 로 옮기고 여는 쪽이 null 을
 * 먼저 쓴다(`modal-usage.md` 편집 창 표준, 배송지 창 재작업과 같은 방식). 엔진 무변경.
 *
 * @scenario-manifest tests/scenarios/coupon-download-modal-real-structure.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import React from 'react';
import { fireEvent, waitFor } from '@testing-library/react';

import infoSummary from '../../../layouts/partials/shop/detail/_info_summary.json';
import checkoutDiscount from '../../../layouts/partials/shop/_checkout_discount.json';
import couponDownloadModal from '../../../layouts/partials/shop/_modal_coupon_download.json';
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

/** 상품 상세 목록 항목 — `UserCouponService::getProductDownloadableCoupons` 가 만드는 모양(id·discount_type·valid_to 없음) */
const productCoupon = (couponId: number, name: string, extra: Record<string, unknown> = {}) => ({
    coupon_id: couponId,
    localized_name: name,
    benefit_formatted: `${couponId}% 할인`,
    multi_currency_benefit_formatted: { KRW: `${couponId}% 할인` },
    target_type: 'product_amount',
    target_type_short_label: '상품',
    valid_period_formatted: '발급일로부터 14일',
    min_order_amount: 0,
    min_order_amount_formatted: '0원',
    multi_currency_min_order_amount: null,
    total_quantity: null,
    remaining_quantity: null,
    is_downloaded: false,
    ...extra,
});

/** 주문서 목록 항목 — `UserCouponService::getDownloadableCoupons` 의 쿠폰 모델 직렬화(id 와 coupon_id 가 함께 있다) */
const checkoutCoupon = (id: number) => ({
    id,
    coupon_id: id,
    name: `쿠폰 ${id}`,
    localized_name: `쿠폰 ${id}`,
    discount_type: 'rate',
    discount_value: 10,
    benefit_formatted: '10% 할인',
    valid_to: null,
    valid_period_formatted: '-',
    min_order_amount: 0,
    is_downloaded: false,
});

/** 상품 상세 init 이 페이지 `_local` 에 세우는 값(7.1.0 판 `shop/show` init — 창 상태를 옮긴 뒤에도 페이지에 남아 있어도 무해해야 한다) */
const SHOW_LOCAL = { downloadableCoupons: null, downloadableCouponsLoading: false, downloadingMore: false, downloadingCouponId: null, selectedCouponForDownload: null };

const PRODUCT_COUPONS = [productCoupon(41, '가입 쿠폰'), productCoupon(42, '주말 쿠폰'), productCoupon(43, '인기상품 쿠폰'), productCoupon(44, '카테고리 쿠폰'), productCoupon(45, '배송 쿠폰')];
const CHECKOUT_PAGE_1 = { data: [47, 48, 49, 50, 51, 52, 53, 54].map(checkoutCoupon), current_page: 1, last_page: 2, per_page: 8, total: 11 };
const CHECKOUT_PAGE_2 = { data: [55, 56, 57].map(checkoutCoupon), current_page: 2, last_page: 2, per_page: 8, total: 11 };

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

/** 상품 상세의 실물 「+N」 단추(창을 여는 쪽) */
const productOpener = findNode(infoSummary, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('coupon_download_modal'))!;
/** 주문서의 실물 「쿠폰 다운로드」 단추(창을 여는 쪽) */
const checkoutOpener = findNode(checkoutDiscount, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('coupon_download_modal'))!;

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('aria-') || k.startsWith('data-') || ['id', 'type', 'disabled', 'onClick', 'onScroll', 'role'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

/**
 * basic Modal 대역 — 닫히면 그리지 않고(창 루트 렌더러는 영구 마운트), 닫기 단추가 onClose 를 부른다. 실물을 쓰지 않는 이유는
 * 템플릿 패키지와 코어 엔진의 React 사본이 시험 환경에서 갈리기 때문이다(checkout-address-modal-real-structure 와 같음).
 *
 * @param props 창 속성
 * @returns 창
 */
function ModalStandIn({ isOpen, onClose, title, children }: any) {
    if (!isOpen) return null;

    return React.createElement('section', { role: 'dialog', 'data-title': title },
        React.createElement('button', { type: 'button', 'aria-label': 'close', onClick: (e: any) => onClose?.(e) }, 'x'),
        children);
}

/** 부품 — 창은 Modal 대역, 나머지는 DOM 대역 */
function registryEntries(): Record<string, { component: any; metadata: any }> {
    const c = (component: any, metadata: any = {}) => ({ component, metadata });

    return {
        Modal: c(ModalStandIn),
        Div: c(passthrough('div')),
        Span: c(passthrough('span')),
        P: c(passthrough('p')),
        Button: c(passthrough('button')),
        Icon: c(() => null, { autoBinding: false }),
    };
}

interface FetchCall { url: string; method: string; body: any }

interface Mounted {
    app: any;
    fetchCalls: FetchCall[];
    /** 받기 요청(POST …/download)을 붙잡아 두었다가 이 함수로 응답을 보낸다 */
    releaseDownload: () => void;
    stop: () => void;
}

interface MountOptions {
    page: Node[];
    local?: Record<string, unknown>;
    data?: Record<string, unknown>;
}

/**
 * 운영과 같은 구조로 여는 단추(페이지)와 쿠폰 받기 창(modals 섹션)을 띄운다.
 *
 * @param opts 페이지 노드·페이지 지역 상태·데이터 소스
 * @returns 띄운 앱과 요청 기록
 */
async function mount(opts: MountOptions): Promise<Mounted> {
    document.body.innerHTML = '<div id="app"></div>';
    const fetchCalls: FetchCall[] = [];
    const originalFetch = globalThis.fetch;
    const held: Array<() => void> = [];
    const json = (body: unknown) => ({ ok: true, status: 200, json: async () => body, text: async () => JSON.stringify(body), headers: new Headers({ 'Content-Type': 'application/json' }) } as Response);
    globalThis.fetch = (async (url: string, init?: RequestInit) => {
        const method = String(init?.method ?? 'GET').toUpperCase();
        const u = String(url);
        fetchCalls.push({ url: u, method, body: init?.body ? JSON.parse(String(init.body)) : null });
        if (u.includes('/user/coupons/downloadable')) {
            return json({ success: true, data: /[?&]page=2/.test(u) ? CHECKOUT_PAGE_2 : CHECKOUT_PAGE_1 });
        }
        if (method === 'POST' && /\/download$/.test(u)) {
            return new Promise<Response>((resolve) => held.push(() => resolve(json({ success: true, data: {} }))));
        }

        return json({ success: true, data: {} });
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
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));

    app.setGlobalState({ currentUser: { uuid: 'u-1' }, defaultCurrency: 'KRW', preferredCurrency: 'KRW', _local: opts.local ?? {} }, { render: false });
    const g = app.getGlobalState();
    await engine.renderTemplate({
        containerId: 'app',
        layoutJson: {
            version: '1.0.0',
            layout_name: 'test/coupon-download-modal',
            components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: opts.page }],
            modals: [couponDownloadModal],
        } as any,
        dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates), ...(opts.data ?? {}) },
    } as any);
    await waitFor(() => expect(document.querySelector('[data-testid="page"]')).not.toBeNull());

    return {
        app,
        fetchCalls,
        releaseDownload: () => { while (held.length) held.shift()!(); },
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
/** 창 안 카드 단추(카드마다 하나) — 닫기 단추 제외 */
const cardButtons = (): HTMLButtonElement[] => Array.from(dialog()?.querySelectorAll('button:not([aria-label="close"])') ?? []) as HTMLButtonElement[];
const labelOf = (b: HTMLElement): 'downloading' | 'done' | 'download' | 'other' => {
    const t = b.textContent ?? '';
    if (/coupon_download\.downloading|다운로드 중/.test(t)) return 'downloading';
    if (/coupon_download\.already_downloaded|다운로드 완료/.test(t)) return 'done';
    if (/coupon_download\.download_btn|다운로드/.test(t)) return 'download';

    return 'other';
};
const labels = (): string[] => cardButtons().map(labelOf);
const downloadCalls = (m: Mounted): FetchCall[] => m.fetchCalls.filter((c) => c.method === 'POST' && /\/user\/coupons\/.*\/download$/.test(c.url));

/** 페이지의 여는 단추를 눌러 창을 연다 */
async function openFromPage(re: RegExp): Promise<void> {
    const page = document.querySelector('[data-testid="page"]')!;
    const btn = Array.from(page.querySelectorAll('button')).find((b) => re.test(b.textContent ?? '')) as HTMLElement;
    fireEvent.click(btn);
    await waitFor(() => expect(dialog()).not.toBeNull());
}

async function closeWindow(): Promise<void> {
    fireEvent.click(dialog()!.querySelector('button[aria-label="close"]') as HTMLElement);
    await waitFor(() => expect(dialog()).toBeNull());
}

describe('상품 상세 「+N」 쿠폰 받기 창 (7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    // @scenario source=product_detail
    // @effects download_request_carries_coupon_id, only_pressed_card_shows_progress, no_checkout_put_outside_checkout
    it('카드의 「다운로드」 는 그 쿠폰 번호로 받기를 요청하고, 응답 전에는 누른 카드만 「다운로드 중」, 응답 뒤에는 그 카드만 「다운로드 완료」 다', async () => {
        m = await mount({ page: [productOpener], local: { ...SHOW_LOCAL }, data: { productDownloadableCoupons: { data: { data: PRODUCT_COUPONS } } } });
        await openFromPage(/\+2/);
        await waitFor(() => expect(cardButtons().length).toBe(5));
        expect(labels()).toEqual(['download', 'download', 'download', 'download', 'download']);

        fireEvent.click(cardButtons()[1]);
        await waitFor(() => expect(downloadCalls(m!).length).toBe(1));
        expect(downloadCalls(m)[0].url).toMatch(/\/api\/modules\/sirsoft-ecommerce\/user\/coupons\/42\/download$/);
        await waitFor(() => expect(labels()).toEqual(['download', 'downloading', 'download', 'download', 'download']));

        m.releaseDownload();
        await waitFor(() => expect(labels()).toEqual(['download', 'done', 'download', 'download', 'download']));
        // 상품 상세에는 주문서가 없다 — 주문서 갱신(PUT /checkout)을 보내지 않는다
        await new Promise((r) => setTimeout(r, 50));
        expect(m.fetchCalls.some((c) => c.method === 'PUT' && /\/checkout$/.test(c.url))).toBe(false);
    });

    // @scenario source=product_detail
    // @effects card_shows_benefit_and_validity_from_product_response
    it('카드 윗줄 할인값과 유효기간이 상품 목록 응답의 필드로 보인다', async () => {
        m = await mount({ page: [productOpener], local: { ...SHOW_LOCAL }, data: { productDownloadableCoupons: { data: { data: PRODUCT_COUPONS } } } });
        await openFromPage(/\+2/);
        await waitFor(() => expect(cardButtons().length).toBe(5));
        const text = dialog()!.textContent ?? '';
        expect(text).toContain('42% 할인');
        expect(text).toContain('발급일로부터 14일');
        expect(text).not.toMatch(/coupon_download\.no_expiry|기한 없음/);
    });
});

describe('쿠폰 받기 창 — 닫았다 다시 열면 여는 쪽이 준 새 목록 (7.1.1)', () => {
    let m: Mounted | null = null;

    afterEach(() => {
        m?.stop();
        m = null;
    });

    // @scenario source=product_detail
    // @effects reopen_shows_fresh_list
    it('상품 상세 — 창에서 하나 받고 닫은 뒤 목록이 바뀌어(재조회) 다시 열면 바뀐 목록이다', async () => {
        m = await mount({ page: [productOpener], local: { ...SHOW_LOCAL }, data: { productDownloadableCoupons: { data: { data: PRODUCT_COUPONS } } } });
        await openFromPage(/\+2/);
        await waitFor(() => expect(cardButtons().length).toBe(5));
        fireEvent.click(cardButtons()[0]);
        await waitFor(() => expect(downloadCalls(m!).length).toBe(1));
        m.releaseDownload();
        await waitFor(() => expect(labels()[0]).toBe('done'));
        await closeWindow();

        // 페이지 데이터 소스가 다시 조회된 결과 — 41 은 받았고, 새 쿠폰 46 이 생겼다
        const fresh = [...PRODUCT_COUPONS.map((c) => (c.coupon_id === 41 ? { ...c, is_downloaded: true } : c)), productCoupon(46, '새 쿠폰')];
        engine.updateTemplateData({ productDownloadableCoupons: { data: { data: fresh } } });
        await waitFor(() => expect(document.querySelector('[data-testid="page"]')?.textContent).toMatch(/\+3/));

        await openFromPage(/\+3/);
        await waitFor(() => expect(cardButtons().length).toBe(6));
        expect(labels()).toEqual(['done', 'download', 'download', 'download', 'download', 'download']);
    });

    // @scenario source=checkout
    // @effects reopen_shows_fresh_list, download_request_carries_coupon_id, only_pressed_card_shows_progress, infinite_scroll_loads_next_page
    it('주문서 — 더보기로 11장이 된 뒤 하나 받고 닫았다 다시 열면 다시 조회한 첫 페이지 8장이다', async () => {
        m = await mount({
            page: [checkoutOpener],
            local: { checkout: { item_coupons: {}, order_coupon_issue_id: null, use_points: 0, zipcode: '06236' } },
            data: { checkoutData: { data: { available_coupons: [] } } },
        });
        await openFromPage(/coupon_download_btn|쿠폰 다운로드/);
        await waitFor(() => expect(cardButtons().length).toBe(8));

        // 더보기(무한 스크롤) — jsdom 은 스크롤 치수가 0 이라 「하단 근접」 조건이 바로 참이다
        const grid = dialog()!.querySelector('.overflow-y-auto') as HTMLElement;
        fireEvent.scroll(grid);
        await waitFor(() => expect(cardButtons().length).toBe(11), { timeout: 2000 });
        expect(m.fetchCalls.some((c) => /downloadable\?per_page=8&page=2/.test(c.url))).toBe(true);

        fireEvent.click(cardButtons()[0]);
        await waitFor(() => expect(downloadCalls(m!).length).toBe(1));
        expect(downloadCalls(m)[0].url).toMatch(/\/user\/coupons\/47\/download$/);
        await waitFor(() => expect(labels().filter((l) => l === 'downloading').length).toBe(1));
        m.releaseDownload();
        await waitFor(() => expect(labels().filter((l) => l === 'done').length).toBe(1));
        // 주문서에서는 받은 쿠폰이 쿠폰 선택칸에 들어오도록 주문서를 다시 계산한다(종전 동작 유지)
        await waitFor(() => expect(m!.fetchCalls.some((c) => c.method === 'PUT' && /\/checkout$/.test(c.url))).toBe(true));
        await closeWindow();

        const before = m.fetchCalls.length;
        await openFromPage(/coupon_download_btn|쿠폰 다운로드/);
        expect(m.fetchCalls.slice(before).some((c) => /downloadable\?page=1&per_page=8/.test(c.url))).toBe(true);
        await waitFor(() => expect(cardButtons().length).toBe(8));
        expect(labels()).toEqual(Array(8).fill('download'));
    });
});

// ── 정적 잠금 — 창은 지역 상태를 쓰지 않고, 쿠폰 번호는 두 목록 공통 필드로 읽는다 ─────────────────────────────────────

describe('쿠폰 받기 창 정적 잠금 (7.1.1)', () => {
    const text = JSON.stringify(couponDownloadModal);

    /**
     * 트리의 setState 액션 params 를 모은다
     *
     * @param x 탐색 대상
     * @param out 모은 목록
     * @returns 모은 목록
     */
    const setStates = (x: any, out: any[] = []): any[] => {
        if (Array.isArray(x)) { x.forEach((y) => setStates(y, out)); return out; }
        if (!x || typeof x !== 'object') return out;
        if (x.handler === 'setState' && x.params) out.push(x.params);
        Object.values(x).forEach((v) => setStates(v, out));

        return out;
    };

    // @scenario source=static
    // @effects reopen_shows_fresh_list
    it('창 안의 상태 쓰기는 모두 창 고유 전역 이름 공간(couponDownloadModal)이다', () => {
        const writes = setStates(couponDownloadModal);
        expect(writes.length).toBeGreaterThan(0);
        for (const w of writes) {
            expect(w.target).toBe('global');
            expect(Object.keys(w).filter((k) => k !== 'target').every((k) => k.startsWith('couponDownloadModal.'))).toBe(true);
        }
        expect(text).not.toMatch(/_local\.(downloadableCoupons|downloadingMore|downloadingCouponId)/);
    });

    // @scenario source=static
    // @effects download_request_carries_coupon_id
    it('받기 주소·진행 판정·받음 표시는 coupon_id 로 읽고 coupon.id 를 읽지 않는다', () => {
        expect(text).toContain('/user/coupons/{{coupon.coupon_id}}/download');
        expect(text).not.toMatch(/coupon\.id\b/);
    });

    // @scenario source=static
    // @effects reopen_shows_fresh_list
    it('여는 쪽 두 곳이 이름 공간을 null 로 비운 뒤 객체를 쓰고 창을 연다', () => {
        for (const opener of [productOpener, checkoutOpener]) {
            const writes = setStates(opener).filter((w) => w.target === 'global' && 'couponDownloadModal' in w);
            expect(writes[0]?.couponDownloadModal).toBeNull();
            expect(typeof writes[1]?.couponDownloadModal).toBe('object');
            expect(writes[1]?.couponDownloadModal).not.toBeNull();
        }
    });
});
