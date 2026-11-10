// e2e:allow 실 TemplateApp 화면 처리(handleRouteChange) + 실 템플릿 엔진(renderTemplate·updateTemplateData)으로 브라우저 탭 제목을 잰다 — 브라우저 축은 로컬 사이트 Chrome 실측 매트릭스(이력 「Chrome MCP 정밀 점검 매트릭스」)가 덮는다
/**
 * @file TemplateApp.documentTitle.test.ts
 * @description 사람 방문자 브라우저 탭 제목이 검색엔진 제목과 같은 규칙으로 정해지고, 화면 전환·데이터 도착·언어 전환·오류 화면에서
 *   앞 화면 제목이 남지 않는지 (engine-v1.86.0)
 *
 * 종전: 엔진이 document.title 을 쓰지 않아 탭 제목이 늘 셸의 사이트 이름(`config('app.name')`)이었다. 봇만 SEO 제목을 받았다.
 *
 * 하네스: 레이아웃·데이터 응답만 흉내 내고(레이아웃 로더·데이터소스 받기) 화면 처리와 렌더·데이터 갱신은 실물을 쓴다.
 *
 * @scenario-manifest tests/scenarios/browser-tab-title-rule.yaml
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import React from 'react';

vi.mock('../api/ApiClient', () => ({
    getApiClient: () => ({
        post: vi.fn().mockResolvedValue({}),
        get: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue(null),
        setOnUnauthorized: vi.fn(),
    }),
}));

import { TemplateApp } from '../TemplateApp';
import * as engine from '../template-engine';
import { initializeG7CoreGlobals } from '../template-engine/G7CoreGlobals';
import { ComponentRegistry } from '../template-engine/ComponentRegistry';
import { DataSourceManager } from '../template-engine/DataSourceManager';
import { TranslationEngine } from '../template-engine/TranslationEngine';
import { documentTitle } from '../routing/DocumentTitle';

const settle = (ms = 30): Promise<void> => new Promise((r) => setTimeout(r, ms));

/** 서버 제목 계산 값 — 이커머스 상품 틀은 새 기본값(`{product_name}`) */
const SEO_TITLE_PAYLOAD = {
    suffix: '',
    site_name: { ko: '지세븐 상점', en: 'G7 Shop' },
    core_settings: { 'general.site_name': { ko: '지세븐 상점', en: 'G7 Shop' } },
    extensions: {
        'module:sirsoft-ecommerce': {
            variables: {
                _common: { commerce_name: { source: 'setting', key: 'basic_info.shop_name' } },
                product: { product_name: { source: 'data' } },
            },
            templates: { product: { title: '{product_name}', description: '' } },
            settings: { 'basic_info.shop_name': '쇼핑몰' },
        },
    },
};

/** 상품 상세 — 상품은 progressive 로 늦게 온다 */
const PRODUCT_LAYOUT = {
    layout_name: 'shop/show',
    components: [{ id: 'root', type: 'basic', name: 'Div', text: '{{product.data.name ?? \'\'}}' }],
    data_sources: [{ id: 'product', type: 'api', endpoint: '/api/products/1', loading_strategy: 'progressive' }],
    meta: {
        seo: {
            enabled: true,
            page_type: 'product',
            extensions: [{ type: 'module', id: 'sirsoft-ecommerce' }],
            data_sources: ['product'],
            vars: { product_name: "{{$localized(product.data.name) ?? ''}}" },
            og: { title: "{{_seo.product.title ?? $localized(product.data.name) ?? ''}}" },
        },
    },
};

/** 장바구니 — SEO 선언 없음 */
const CART_LAYOUT = { layout_name: 'shop/cart', components: [{ id: 'root', type: 'basic', name: 'Div', text: 'cart' }] };

/** 홈 — og 제목이 있어도 사이트 이름만 */
const HOME_LAYOUT = { layout_name: 'home', components: [{ id: 'root', type: 'basic', name: 'Div', text: 'home' }], meta: { seo: { enabled: true, og: { title: '홈 OG 제목' } } } };

/** 관리자 셸 제목 계산 값(engine-v1.88.0) — 사이트 이름 + 언어별 관리자 접미사 */
const ADMIN_TITLE_PAYLOAD = {
    suffix: { ko: ' - {site_name} 관리자', en: ' - {site_name} Admin' },
    site_name: { ko: '지세븐 상점', en: 'G7 Shop' },
};

/** 관리자 회원 상세 — 레이아웃 화면 제목(meta.title)을 선언하고, 검색엔진 선언(og)은 관리자 제목에 쓰이지 않는다 */
const ADMIN_DETAIL_LAYOUT = {
    layout_name: 'admin_user_detail',
    components: [{ id: 'root', type: 'basic', name: 'Div', text: 'detail' }],
    meta: { title: '$t:admin.users.detail.page_title', seo: { enabled: true, og: { title: 'OG 제목' } } },
};

/** 관리자 화면 — 레이아웃 화면 제목 없음(라우트 제목으로) */
const ADMIN_PLAIN_LAYOUT = { layout_name: 'admin_plain', components: [{ id: 'root', type: 'basic', name: 'Div', text: 'plain' }] };

const ROUTES: Record<string, any> = {
    product: { path: '/shop/products/1', layout: 'shop/show', params: { product_code: '1' }, query: {}, meta: { title: '$t:user.shop.show_title' } },
    cart: { path: '/shop/cart', layout: 'shop/cart', params: {}, query: {}, meta: { title: '$t:user.shop.cart_title' } },
    home: { path: '/', layout: 'home', params: {}, query: {}, meta: { title: '$t:user.home.page_title' } },
    adminDetail: { path: '/admin/users/1', layout: 'admin_user_detail', params: { id: '1' }, query: {}, meta: { title: '$t:admin.route.users' } },
    adminPlain: { path: '/admin/plain', layout: 'admin_plain', params: {}, query: {}, meta: { title: '$t:admin.route.plain' } },
};

let app: any;
let layoutDelay: Record<string, number> = {};
let productDelay = 0;
let productName: Record<string, string> = { ko: '후드 티', en: 'Hoodie' };

async function boot(templateType: 'user' | 'admin' = 'user', seoTitle: unknown = templateType === 'admin' ? ADMIN_TITLE_PAYLOAD : SEO_TITLE_PAYLOAD): Promise<void> {
    document.body.innerHTML = '<div id="app"></div>';
    document.title = '셸 제목';
    (window as any).G7Config = { seoTitle, appConfig: { siteLocale: 'ko' } };
    app = new TemplateApp({ templateId: 'sirsoft-basic', templateType, locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
    const translations = TranslationEngine.getInstance();
    for (const [locale, dict] of Object.entries({
        ko: { 'user.shop.show_title': '상품 상세', 'user.shop.cart_title': '장바구니', 'user.home.page_title': '홈', 'user.errors.not_found_title': '페이지를 찾을 수 없음', 'admin.users.detail.page_title': '회원 상세', 'admin.route.users': '회원 관리', 'admin.route.plain': '라우트 제목' },
        en: { 'user.shop.show_title': 'Product', 'user.shop.cart_title': 'Cart', 'user.home.page_title': 'Home', 'user.errors.not_found_title': 'Page not found', 'admin.users.detail.page_title': 'User details', 'admin.route.users': 'Users', 'admin.route.plain': 'Route title' },
    })) {
        for (const [key, value] of Object.entries(dict)) translations.setTranslationValue('sirsoft-basic', locale, key, value);
    }
    (ComponentRegistry.getInstance() as any).registry = {
        Div: { component: ({ children, text }: any) => React.createElement('div', null, children ?? text), metadata: {} },
    };
    const layouts: Record<string, any> = { 'shop/show': PRODUCT_LAYOUT, 'shop/cart': CART_LAYOUT, home: HOME_LAYOUT, admin_user_detail: ADMIN_DETAIL_LAYOUT, admin_plain: ADMIN_PLAIN_LAYOUT };
    app.layoutLoader = {
        loadLayout: vi.fn(async (_t: string, name: string) => {
            if (layoutDelay[name]) await settle(layoutDelay[name]);
            return JSON.parse(JSON.stringify(layouts[name]));
        }),
        clear: vi.fn(),
        setCacheVersion: vi.fn(),
    };
    app.router = {
        match: (path: string) => (path === '/404' ? { route: { path: '/404', meta: { title: '$t:user.errors.not_found_title' } }, params: {} } : null),
        navigateToCurrentPath: vi.fn(async () => app.handleRouteChange(ROUTES.product)),
        on: vi.fn(),
    };
    vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults').mockImplementation(async (sources: any[]) => {
        if (productDelay) await settle(productDelay);
        const locale = app.getLocale();
        return sources.map((s: any) => ({ id: s.id, state: 'success', data: { data: { name: productName[locale] ?? productName.ko } } })) as any;
    });
    app.configureDocumentTitle?.();
}

describe('TemplateApp 브라우저 탭 제목 — 검색엔진 제목과 같은 규칙 (engine-v1.86.0)', () => {
    beforeEach(() => {
        layoutDelay = {};
        productDelay = 0;
        productName = { ko: '후드 티', en: 'Hoodie' };
        localStorage.removeItem('g7_locale');
        window.history.replaceState({}, '', '/');
    });

    afterEach(() => {
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        documentTitle.reset();
        vi.restoreAllMocks();
        delete (window as any).__templateApp;
        delete (window as any).G7Config;
        delete (window as any).G7Core;
        document.body.innerHTML = '';
    });

    /**
     * @scenario case=navigate_shows_route_title
     * @effects route_title_shown_at_navigation_start
     */
    it('이동을 시작하면 레이아웃·데이터를 받기 전에 라우트 제목 + 접미사를 쓴다 (SEO 선언 없는 화면은 그대로 둔다)', async () => {
        await boot();
        layoutDelay['shop/cart'] = 200;
        const run = app.handleRouteChange(ROUTES.cart);
        await settle(10);
        expect(document.title).toBe('장바구니 - 지세븐 상점');
        await run;
        expect(document.title).toBe('장바구니 - 지세븐 상점');
    });

    /**
     * @scenario case=data_arrival_replaces_title
     * @effects loading_route_title_replaced_by_data_title
     */
    it('데이터를 받는 동안은 라우트 제목, 받으면 상품 제목(틀 {product_name}) + 접미사로 바꾼다', async () => {
        await boot();
        productDelay = 150;
        const run = app.handleRouteChange(ROUTES.product);
        await settle(60);
        expect(document.title).toBe('상품 상세 - 지세븐 상점');
        await run;
        await vi.waitFor(() => expect(document.title).toBe('후드 티 - 지세븐 상점'), { timeout: 2000 });
    });

    /**
     * @scenario case=late_previous_layout_data_ignored
     * @effects late_previous_layout_data_does_not_overwrite_title
     */
    it('다른 화면으로 옮기는 중 앞 화면 레이아웃에 늦게 온 데이터가 새 화면 제목을 덮지 않는다', async () => {
        await boot();
        await app.handleRouteChange(ROUTES.product);
        await vi.waitFor(() => expect(document.title).toBe('후드 티 - 지세븐 상점'), { timeout: 2000 });

        layoutDelay['shop/cart'] = 200;
        const run = app.handleRouteChange(ROUTES.cart);
        await settle(10);
        expect(document.title).toBe('장바구니 - 지세븐 상점');

        // 앞 화면(상품 상세 레이아웃이 아직 렌더러에 있음)에 늦게 도착한 데이터 갱신
        engine.updateTemplateData({ product: { data: { name: '늦은 앞 화면 상품' } } });
        await settle(20);
        expect(document.title).toBe('장바구니 - 지세븐 상점');

        await run;
        engine.updateTemplateData({ product: { data: { name: '늦은 앞 화면 상품' } } });
        await settle(20);
        expect(document.title).toBe('장바구니 - 지세븐 상점');
    });

    /**
     * @scenario case=locale_switch_recomputes
     * @effects locale_switch_recomputes_title
     */
    it('언어를 바꾸면 새 언어의 상품명·사이트 이름으로 다시 계산한다', async () => {
        await boot();
        window.history.replaceState({}, '', ROUTES.product.path);
        await app.handleRouteChange(ROUTES.product);
        await vi.waitFor(() => expect(document.title).toBe('후드 티 - 지세븐 상점'), { timeout: 2000 });

        await app.changeLocale('en', { persist: false });
        await vi.waitFor(() => expect(document.title).toBe('Hoodie - G7 Shop'), { timeout: 3000 });
    });

    /**
     * @scenario case=home_site_name_only
     * @effects home_title_is_site_name_only
     */
    it('사이트 첫 화면은 og 제목·라우트 제목이 있어도 사이트 이름만 쓴다', async () => {
        await boot();
        await app.handleRouteChange(ROUTES.home);
        await settle();
        expect(document.title).toBe('지세븐 상점');
    });

    /**
     * @scenario case=not_found_title
     * @effects not_found_uses_template_404_route_title
     */
    it('경로 없음(404)은 템플릿 404 화면 라우트 제목 + 접미사로 바꾼다 — 앞 화면 제목이 남지 않는다', async () => {
        await boot();
        await app.handleRouteChange(ROUTES.cart);
        expect(document.title).toBe('장바구니 - 지세븐 상점');
        app.errorPageHandler = null;
        await app.handleRouteNotFound('/no-such-page');
        expect(document.title).toBe('페이지를 찾을 수 없음 - 지세븐 상점');
    });

    /**
     * @scenario case=editor_mode_suppressed
     * @effects layout_editor_tab_title_untouched
     */
    it('레이아웃 편집기 경로·편집기 미리보기 화면에서는 탭 제목을 바꾸지 않는다', async () => {
        await boot();
        window.history.replaceState({}, '', '/admin/layout-editor/sirsoft-basic');
        document.title = '레이아웃 편집기';
        documentTitle.beginRoute(ROUTES.cart);
        expect(document.title).toBe('레이아웃 편집기');

        window.history.replaceState({}, '', '/preview/abc');
        documentTitle.beginRoute({ path: '/preview/abc', layout: '__preview__', meta: { title: '미리보기' } });
        expect(document.title).toBe('레이아웃 편집기');
    });

    /**
     * @scenario case=admin_screen_title
     * @effects admin_title_uses_layout_title_with_admin_suffix
     */
    it('관리자 화면은 「{레이아웃 화면 제목} - {사이트 이름} 관리자」 — 검색엔진 선언(og)은 쓰지 않는다 (engine-v1.88.0)', async () => {
        await boot('admin');
        layoutDelay.admin_user_detail = 200;
        const run = app.handleRouteChange(ROUTES.adminDetail);
        await settle(10);
        // 레이아웃을 받기 전에는 라우트 제목
        expect(document.title).toBe('회원 관리 - 지세븐 상점 관리자');
        await run;
        expect(document.title).toBe('회원 상세 - 지세븐 상점 관리자');
    });

    /**
     * @scenario case=admin_screen_title
     * @effects admin_title_falls_back_to_route_title
     */
    it('관리자 레이아웃에 화면 제목이 없으면 라우트 제목, 화면을 옮기면 앞 화면 제목이 남지 않는다', async () => {
        await boot('admin');
        await app.handleRouteChange(ROUTES.adminDetail);
        expect(document.title).toBe('회원 상세 - 지세븐 상점 관리자');
        await app.handleRouteChange(ROUTES.adminPlain);
        expect(document.title).toBe('라우트 제목 - 지세븐 상점 관리자');
    });

    /**
     * @scenario case=admin_screen_title
     * @effects admin_title_suffix_follows_locale
     */
    it('관리자 접미사는 화면 언어를 따른다', async () => {
        await boot('admin');
        app.config.locale = 'en';
        await app.handleRouteChange(ROUTES.adminPlain);
        expect(document.title).toBe('Route title - G7 Shop Admin');
    });

    /**
     * @scenario case=admin_template_untouched
     * @effects admin_template_keeps_shell_title
     */
    it('관리자 셸이 제목 계산 값을 넘기지 않으면(옛 서버) 종전대로 셸 제목을 둔다', async () => {
        await boot('admin', null);
        await app.handleRouteChange(ROUTES.adminDetail);
        expect(document.title).toBe('셸 제목');
    });
});
