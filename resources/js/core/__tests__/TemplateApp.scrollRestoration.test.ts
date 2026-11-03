// e2e:allow 실 TemplateApp·Router 로 뒤로 가기 스크롤 복원·더보기 스냅샷을 잰다 — 문서 높이는 jsdom 이 계산하지 않아 데이터 길이로 흉내 내고, 브라우저 축은 개발 서버 390×844 실측(홈 2,742px → 상품 → 뒤로)이 덮는다
/**
 * @file TemplateApp.scrollRestoration.test.ts
 * @description 뒤로/앞으로 가기로 돌아온 화면이 데이터를 다시 받느라 늦게 자라도 떠날 때 위치로 되돌리고, 더보기로 늘어난 목록은
 *   스냅샷으로 되살린 뒤 다시 받은 첫 묶음을 반영하는지 (engine-v1.85.0)
 *
 * 종전: 엔진에 스크롤 기억·복원이 없어 브라우저 기본 복원(popstate 직후 1회)에 맡겼다 — 그때 홈이 아직 짧아 맨 위에 머물렀다
 * (개발 서버 390×844: 홈 2,742px → 상품 → 뒤로 = 0, 홈 높이 약 1초에 걸쳐 3,416 → 5,574px).
 *
 * @scenario-manifest tests/scenarios/client-router-scroll-restoration.yaml
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../template-engine', async () => ({
    ...(await vi.importActual<any>('../template-engine')),
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn(async () => {
        document.getElementById('app')!.innerHTML = '<main id="main">screen</main>';
    }),
    destroyTemplate: vi.fn(),
    releaseTemplateRoot: vi.fn(),
    updateTemplateData: vi.fn(),
}));

/** 레이아웃 — 홈은 progressive 상품 목록, 상세는 데이터 없음 */
const layouts: Record<string, any> = {};

vi.mock('../template-engine/LayoutLoader', async () => ({
    ...(await vi.importActual<any>('../template-engine/LayoutLoader')),
    LayoutLoader: vi.fn(function (this: any) {
        this.loadLayout = vi.fn(async (_templateId: string, layout: string) => layouts[layout] ?? { layout_name: layout, components: [] });
        this.setCacheVersion = vi.fn();
        this.clear = vi.fn();
    }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
    transitionManager: { setPending: vi.fn(), getIsPending: vi.fn(() => false), subscribe: vi.fn(() => vi.fn()), clearSubscribers: vi.fn() },
}));

vi.mock('../template-engine/ComponentRegistry', () => {
    const mockInstance: any = { loadComponents: vi.fn().mockResolvedValue(undefined), getComponent: vi.fn().mockReturnValue(() => null), hasComponent: vi.fn().mockReturnValue(true), getInstance: vi.fn(), getMetadata: vi.fn(() => null), getMissingExtensionComponents: vi.fn(() => []) };
    mockInstance.getInstance.mockReturnValue(mockInstance);
    return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';
import { DataSourceManager } from '../template-engine/DataSourceManager';
import { initializeG7CoreGlobals } from '../template-engine/G7CoreGlobals';
import { resetScrollMemoryForTesting } from '../routing/ScrollMemory';
import { resetDataSourceSnapshotsForTesting } from '../template-engine/dataSourceSnapshot';

const tick = (ms = 20) => new Promise((r) => setTimeout(r, ms));

/** 흉내 낸 문서 — 홈 높이는 받은 상품 수로 정한다(받기 전 900px) */
const page = { y: 0, viewport: 844 };
let app: any;

const products = (from: number, to: number, rename: Record<number, string> = {}) =>
    Array.from({ length: to - from + 1 }, (_, i) => ({ id: from + i, name: rename[from + i] ?? `상품 ${from + i}` }));

const homeHeight = (): number => {
    const list = app?.getDataSource?.('products')?.data;
    return Array.isArray(list) && list.length > 0 ? 600 + list.length * 500 : 900;
};

function userScrollTo(y: number): void {
    page.y = y;
    window.dispatchEvent(new Event('scroll'));
}

/** 뒤로/앞으로 가기 — 주소를 그 항목으로 바꾸고 popstate (Navigation API 없음 → 주소가 항목 키) */
function traverseTo(path: string): void {
    window.history.pushState({}, '', path);
    window.dispatchEvent(new PopStateEvent('popstate'));
}

/** 상품 목록 응답 — 첫 묶음, 지연 */
let firstPage = products(1, 10);
let fetchDelayMs = 300;

async function boot(templateConfig: Record<string, any> = {}): Promise<void> {
    const routes = [
        { path: '/', layout: 'home' },
        { path: '/shop/products/:id', layout: 'shop/show' },
    ];
    vi.mocked(globalThis.fetch).mockImplementation(async (url: any) => ({
        ok: true, status: 200, statusText: 'OK', headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => (String(url).includes('routes')
            ? { success: true, data: { routes } }
            : String(url).includes('/config')
                ? { success: true, data: templateConfig }
                : { success: true, data: {} }),
        text: async () => '{}',
    }) as any);
    vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults').mockImplementation(async (sources: any[]) => {
        await tick(fetchDelayMs);
        return sources.map((s: any) => ({ id: s.id, state: 'success', data: { data: firstPage.map((p) => ({ ...p })), meta: { current_page: 1 } } })) as any;
    });
    document.body.innerHTML = '<div id="app"></div>';
    app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    await app.init();
    initializeG7CoreGlobals({
        getState: () => ({ actionDispatcher: null, translationEngine: null, translationContext: { templateId: 't', locale: 'ko' }, bindingEngine: null, templateMetadata: null }) as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await vi.waitFor(() => expect(app.getDataSource('products')?.data?.length).toBe(10), { timeout: 3000 });
}

/** 홈 → 상품 상세 → 뒤로. 이전 화면이 남은 채 생긴 스크롤 이벤트도 흉내 낸다 */
async function toProductAndBack(): Promise<void> {
    app.getRouter().navigate('/shop/products/P0000DTB');
    await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));
    await tick(50);
    page.y = 0;
    traverseTo('/');
    userScrollTo(0);
}

describe('TemplateApp 뒤로 가기 스크롤 복원 (engine-v1.85.0)', () => {
    beforeEach(() => {
        resetScrollMemoryForTesting();
        resetDataSourceSnapshotsForTesting();
        window.history.replaceState({}, '', '/');
        page.y = 0;
        firstPage = products(1, 10);
        fetchDelayMs = 300;
        layouts.home = {
            layout_name: 'home',
            components: [],
            data_sources: [{ id: 'products', type: 'api', endpoint: '/api/products', loading_strategy: 'progressive' }],
        };
        layouts['shop/show'] = { layout_name: 'shop/show', components: [] };
        Object.defineProperty(document.documentElement, 'scrollHeight', {
            configurable: true,
            get: () => (window.location.pathname.startsWith('/shop/products') ? 1500 : homeHeight()),
        });
        Object.defineProperty(window, 'innerHeight', { configurable: true, get: () => page.viewport });
        Object.defineProperty(window, 'scrollY', { configurable: true, get: () => page.y });
        window.scrollTo = vi.fn((arg: ScrollToOptions | number) => {
            const top = typeof arg === 'number' ? arg : (arg.top ?? 0);
            page.y = Math.max(0, Math.min(top, document.documentElement.scrollHeight - page.viewport));
            window.dispatchEvent(new Event('scroll'));
        }) as unknown as typeof window.scrollTo;
        window.requestAnimationFrame = ((cb: FrameRequestCallback) => { cb(0); return 0; }) as typeof window.requestAnimationFrame;
    });

    afterEach(() => {
        if (app) {
            window.removeEventListener('popstate', (app.getRouter() as any).handlePopState);
        }
        resetScrollMemoryForTesting();
        resetDataSourceSnapshotsForTesting();
        vi.restoreAllMocks();
        delete (window as any).G7Core;
        delete (window as any).__templateApp;
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=engine_default
    // @effects scroll_position_restored_after_late_content
    it('홈을 2,742px 까지 내린 뒤 상품에 갔다 돌아오면, 상품 목록을 다시 받아 홈이 자란 뒤 2,742px 로 되돌린다', async () => {
        await boot();
        userScrollTo(2742);

        await toProductAndBack();

        // 다시 받기 전에는 홈이 짧다(900px) — 기억한 위치에 닿지 못한다
        expect(homeHeight()).toBeLessThan(2742);
        await vi.waitFor(() => expect(page.y).toBe(2742), { timeout: 3000 });
    });

    // @scenario entry=popstate, content_timing=already_tall, config_layer=engine_default
    // @effects restore_waits_for_data_before_settling
    it('[case:components-misc-back-scroll-restore-skeleton-settle] 돌아온 직후 뼈대 화면이 이미 목표만큼 길어도, 데이터가 와서 위쪽이 바뀐 뒤 다시 맞춘다(일찍 끝내지 않는다)', async () => {
        await boot();
        userScrollTo(2500);
        // 뼈대(받기 전) 높이가 3,416 — 목표 2,500 을 이미 담는다. 데이터는 900ms 뒤에 오고, 오면 위쪽이 늘어 브라우저가 위치를
        // 끌어내린다(스크롤 앵커링 흉내: 높이가 늘면 같은 만큼 y 를 내린다)
        fetchDelayMs = 900;
        let lastHeight = 0;
        Object.defineProperty(document.documentElement, 'scrollHeight', {
            configurable: true,
            get: () => {
                const h = window.location.pathname.startsWith('/shop/products') ? 1500 : (app.getDataSource('products')?.data?.length ? 5574 : 3416);
                if (lastHeight && h > lastHeight && !window.location.pathname.startsWith('/shop/products')) {
                    page.y += h - lastHeight;
                }
                lastHeight = h;
                return h;
            },
        });

        await toProductAndBack();
        // 데이터가 오기 전에 일찍 끝냈다면, 데이터가 온 뒤 앵커링이 끌어내린 위치(2,500 + 2,158)에 남는다.
        // 데이터가 온 직후 브라우저가 배치를 다시 계산하는 순간을 흉내 내 높이를 읽는다
        await vi.waitFor(() => expect(app.getDataSource('products')?.data?.length).toBe(10), { timeout: 3000 });
        void document.documentElement.scrollHeight;
        await tick(1200);

        expect(page.y).toBe(2500);
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects snapshot_restores_more_loaded_list
    it('더보기로 20개까지 늘린 목록은 돌아오면 스냅샷으로 20개를 먼저 그리고, 다시 받은 첫 묶음의 바뀐 항목만 갈아끼운다', async () => {
        await boot();
        (window as any).G7Core.dataSource.updateData('products', 'data', products(11, 20), 'append');
        expect(app.getDataSource('products').data).toHaveLength(20);
        userScrollTo(8000);
        firstPage = products(1, 10, { 3: '상품 3 (가격 변경)' });

        await toProductAndBack();

        // 받기 전(첫 렌더)부터 20개 — 높이가 확보돼 8,000px 에 닿는다
        await vi.waitFor(() => expect(page.y).toBe(8000), { timeout: 1000 });
        // 받은 뒤: 길이 20 유지, 3번만 새 값
        await vi.waitFor(() => expect(app.getDataSource('products').data[2].name).toBe('상품 3 (가격 변경)'), { timeout: 3000 });
        expect(app.getDataSource('products').data).toHaveLength(20);
        expect(app.getDataSource('products').data[15].name).toBe('상품 16');
        expect(page.y).toBe(8000);
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects snapshot_ttl_counts_from_first_capture
    it('되살린 스냅샷으로 오가도 유효 시간은 처음 기억한 때부터 잰다 — 2쪽 이후가 유효 시간보다 오래 다시 받지 않은 채 남지 않는다', async () => {
        const realNow = Date.now.bind(Date);
        let offsetMs = 0;
        vi.spyOn(Date, 'now').mockImplementation(() => realNow() + offsetMs);
        await boot();
        (window as any).G7Core.dataSource.updateData('products', 'data', products(11, 20), 'append');
        userScrollTo(3000);

        // 처음 기억(t0) → 500초 뒤 돌아옴(스냅샷 20개 사용)
        app.getRouter().navigate('/shop/products/P0000DTB');
        await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));
        await tick(50);
        offsetMs = 500_000;
        traverseTo('/');
        await vi.waitFor(() => expect(app.getDataSource('products').data).toHaveLength(20), { timeout: 3000 });
        await tick(400);

        // 다시 상품으로 갔다가 200초 뒤(처음 기억에서 700초 — 유효 시간 600초 초과) 돌아온다
        await toProductAndBack();
        await tick(50);
        offsetMs = 700_000;
        app.getRouter().navigate('/shop/products/P0000DTC');
        await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTC'));
        await tick(50);
        traverseTo('/');
        await tick(fetchDelayMs + 300);

        // 스냅샷이 만료돼 첫 묶음만 그린다
        expect(app.getDataSource('products').data).toHaveLength(10);
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects snapshot_state_restored_after_init
    it('snapshot.state 로 적은 진행 상태(다음 쪽 번호)를 다른 화면이 초기화해도 돌아오면 되돌린다', async () => {
        layouts.home.meta = { scroll_restoration: { snapshot: { state: ['_global.infiniteScroll'] } } };
        await boot();
        (window as any).G7Core.dataSource.updateData('products', 'data', products(11, 20), 'append');
        app.globalState.infiniteScroll = { currentPage: 2, hasMore: true };
        userScrollTo(8000);

        app.getRouter().navigate('/shop/products/P0000DTB');
        await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));
        // 다른 화면이 같은 키를 1로 초기화
        app.globalState.infiniteScroll = { currentPage: 1, hasMore: true };
        await tick(50);
        page.y = 0;
        traverseTo('/');

        await vi.waitFor(() => expect(app.getDataSource('products').data).toHaveLength(20), { timeout: 3000 });
        expect(app.globalState.infiniteScroll).toEqual({ currentPage: 2, hasMore: true });
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects snapshot_discarded_on_kept_fallback
    it('다시 받기가 대체 값(kept: fallback)이면 스냅샷을 버리고 그 값으로 그린다 — 앞 계정 목록을 되살리지 않는다', async () => {
        await boot();
        (window as any).G7Core.dataSource.updateData('products', 'data', products(11, 20), 'append');
        userScrollTo(8000);
        vi.mocked(DataSourceManager.prototype.fetchDataSourcesWithResults).mockImplementation(async (sources: any[]) => {
            await tick(fetchDelayMs);
            return sources.map((s: any) => ({ id: s.id, state: 'success', data: { data: [] }, kept: 'fallback' })) as any;
        });

        await toProductAndBack();

        await vi.waitFor(() => expect(app.getDataSource('products').data).toHaveLength(0), { timeout: 3000 });
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects snapshot_discarded_on_context_change
    it('다른 계정으로 바뀐 뒤 돌아오면 스냅샷을 쓰지 않고 첫 묶음만 그린다', async () => {
        await boot();
        (window as any).G7Core.dataSource.updateData('products', 'data', products(11, 20), 'append');
        userScrollTo(8000);
        const { AuthManager } = await import('../auth/AuthManager');
        const spy = vi.spyOn(AuthManager.getInstance(), 'getUser');
        spy.mockReturnValue(null);
        app.getRouter().navigate('/shop/products/P0000DTB');
        await vi.waitFor(() => expect(window.location.pathname).toBe('/shop/products/P0000DTB'));
        spy.mockReturnValue({ id: 999 } as any);
        await tick(50);
        traverseTo('/');

        await tick(100);
        await vi.waitFor(() => expect(app.getDataSource('products')?.data).toHaveLength(10), { timeout: 3000 });
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects wait_data_restores_after_all_data
    it('wait:"data" 화면은 데이터를 다 받은 뒤에 한 번 되돌린다', async () => {
        layouts.home.meta = { scroll_restoration: { wait: 'data' } };
        await boot();
        userScrollTo(3000);

        await toProductAndBack();
        await tick(100);
        expect(page.y).toBe(0);

        await vi.waitFor(() => expect(page.y).toBe(3000), { timeout: 3000 });
    });

    // @scenario entry=popstate, content_timing=already_tall, config_layer=template_config
    // @effects config_layers_merge_narrower_wins
    it('템플릿 기본값(template.json)이 맨 위여도, 화면 meta 가 restore 면 그 화면은 되돌린다', async () => {
        layouts.home.meta = { scroll_restoration: { mode: 'restore' } };
        layouts['shop/show'].meta = {};
        await boot({ scroll_restoration: { mode: 'top' } });
        userScrollTo(2500);

        await toProductAndBack();

        await vi.waitFor(() => expect(page.y).toBe(2500), { timeout: 3000 });
    });

    // @scenario entry=popstate, content_timing=already_tall, config_layer=template_config
    // @effects template_default_applies_without_meta
    it('화면 meta 가 없으면 템플릿 기본값(template.json mode:"top")을 따른다', async () => {
        await boot({ scroll_restoration: { mode: 'top' } });
        userScrollTo(2500);

        await toProductAndBack();
        await tick(600);

        expect(page.y).toBe(0);
    });
});
