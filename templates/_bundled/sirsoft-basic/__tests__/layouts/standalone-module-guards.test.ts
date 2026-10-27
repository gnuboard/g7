/**
 * 모듈별 단독 사용 — 공통 화면의 모듈 결합 가드 (#822)
 *
 * @description
 * sirsoft-basic 은 게시판·쇼핑몰·페이지 모듈과 우편번호 플러그인을 필수 의존이 아니라 선택 연동(`integrations`)으로 둔다.
 * 그래서 어느 모듈이 꺼져 있어도 공통 화면(상속원·홈·검색·가입·로그인·마이페이지 탭·회원 프로필)이 꺼진 모듈의 API 를
 * 부르거나 갈 곳 없는 링크를 그리면 안 된다.
 *
 * 판정은 코어가 사용자 화면에 싣는 `_global.activeModules`·`_global.activePlugins`(식별자 배열, 코어 7.1.4+)로 하고,
 * 가드 식은 **한 모양만** 쓴다 — `{{_global.activeModules?.includes('<id>') ?? true}}`. 키가 없는 옛 코어에서는
 * `undefined ?? true` 라 지금처럼 보인다. 모듈 API 를 부르는 데이터소스에는 404 를 삼키는 오류 처리도 함께 단다
 * (옛 코어에서는 가드가 늘 참이라 꺼진 모듈 API 가 404 토스트를 띄울 수 있다).
 *
 * 실제 번들 JSON(굽기 산출물)을 읽어 식을 글자 그대로 대조한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const ROOT = path.resolve(__dirname, '../..');
const LAYOUT_ROOT = path.join(ROOT, 'layouts');

const BOARD = "_global.activeModules?.includes('sirsoft-board') ?? true";
const SHOP = "_global.activeModules?.includes('sirsoft-ecommerce') ?? true";
const PAGE = "_global.activeModules?.includes('sirsoft-page') ?? true";

/** 모듈 식별자 → 가드 식 본문 */
const GUARD_OF: Record<string, string> = {
    'sirsoft-board': BOARD,
    'sirsoft-ecommerce': SHOP,
    'sirsoft-page': PAGE,
};

const guard = (expr: string) => `{{${expr}}}`;
const negated = (expr: string) => `{{!(${expr})}}`;

/** 레이아웃 JSON 을 읽는다(경로는 layouts/ 기준, 확장자 생략 가능). */
function load(rel: string): Node {
    const file = path.join(LAYOUT_ROOT, rel.endsWith('.json') ? rel : `${rel}.json`);
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/** 트리에서 노드(이름이 있는 객체)를 모두 모은다. */
function nodes(tree: unknown, out: Node[] = []): Node[] {
    if (Array.isArray(tree)) {
        tree.forEach((child) => nodes(child, out));
        return out;
    }
    if (tree && typeof tree === 'object') {
        const obj = tree as Node;
        if (typeof obj.name === 'string' || typeof obj.partial === 'string') out.push(obj);
        Object.values(obj).forEach((value) => nodes(value, out));
    }
    return out;
}

/** id 로 노드를 찾는다. */
function byId(tree: unknown, id: string): Node {
    const found = nodes(tree).find((n) => n.id === id);
    if (!found) throw new Error(`노드 id=${id} 가 없다`);
    return found;
}

/** href 로 노드를 찾는다(여럿일 수 있다). */
function byHref(tree: unknown, href: string): Node[] {
    return nodes(tree).filter((n) => n.props?.href === href);
}

/** 데이터소스를 id 로 찾는다. */
function dataSource(layout: Node, id: string): Node {
    const ds = (layout.data_sources ?? []).find((d: Node) => d.id === id);
    if (!ds) throw new Error(`데이터소스 ${id} 가 없다`);
    return ds;
}

/** 데이터소스 가드 — `if` 가 정확히 그 모듈 가드이고 404 를 삼킨다. */
function expectGuardedSource(layout: Node, id: string, expr: string): void {
    const ds = dataSource(layout, id);
    expect(ds.if, `${id}.if`).toBe(guard(expr));
    expect(ds.errorHandling?.['404']?.handler, `${id}.errorHandling.404`).toBe('suppress');
}

/** 레이아웃 디렉토리의 본 레이아웃(.json, partials 제외)을 모은다. */
function layoutFiles(dir = LAYOUT_ROOT, out: string[] = []): string[] {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (entry.name !== 'partials') layoutFiles(full, out);
        } else if (entry.name.endsWith('.json')) {
            out.push(full);
        }
    }
    return out;
}

describe('상속원(_user_base) — 매 화면 부르는 모듈 데이터소스·액션', () => {
    const base = load('_user_base');

    // @scenario surface=user_base
    // @effects shared_module_source_guarded
    it('헤더·서랍 게시판 메뉴(boards)는 게시판이 켜져 있을 때만 부르고 404 를 삼킨다', () => {
        expectGuardedSource(base, 'boards', BOARD);
    });

    // @scenario surface=user_base
    // @effects shared_module_source_guarded
    it('장바구니 수(cart)는 쇼핑몰이 켜져 있을 때만 부르고 404 를 삼킨다', () => {
        expectGuardedSource(base, 'cart', SHOP);
    });

    // @scenario surface=user_base
    // @effects module_action_guarded
    it('로그인 사용자 로드 뒤 통화·배송국가 재해석 액션은 쇼핑몰이 켜져 있을 때만 실행한다', () => {
        const onSuccess = dataSource(base, 'current_user').onSuccess;
        const actions = (Array.isArray(onSuccess) ? onSuccess : [onSuccess]).filter((a: Node) =>
            String(a?.handler ?? '').startsWith('sirsoft-ecommerce.'),
        );
        expect(actions.map((a: Node) => a.handler)).toEqual([
            'sirsoft-ecommerce.initPreferredCurrency',
            'sirsoft-ecommerce.initPreferredShippingCountry',
        ]);
        actions.forEach((a: Node) => expect(a.if, a.handler).toBe(guard(SHOP)));
    });

    // @scenario surface=user_base
    // @effects module_action_guarded
    it('첫 진입 장바구니 키 발급(initCartKey)과 쇼핑몰 소유 init 핸들러는 쇼핑몰이 켜져 있을 때만 실행한다', () => {
        const init = base.init_actions as Node[];
        const cartKey = init.find((a) => a.handler === 'initCartKey');
        expect(cartKey?.if).toBe(guard(SHOP));
        const owned = init.filter((a) => String(a.handler ?? '').startsWith('sirsoft-ecommerce.'));
        expect(owned.length).toBeGreaterThanOrEqual(2);
        owned.forEach((a) => expect(a.if, a.handler).toBe(guard(SHOP)));
    });

    // @scenario surface=user_base_drawer
    // @effects shared_module_link_guarded
    it('모바일 머리글 장바구니·서랍의 쇼핑 항목은 쇼핑몰, 게시판 항목은 게시판, 정보·정책은 페이지 가드를 단다', () => {
        const tree = base.components;
        expect(byId(tree, 'mobile_cart_btn').if).toBe(guard(SHOP));
        expect(byId(tree, 'mobile_drawer_orders_link').if).toBe(guard(SHOP));
        expect(byId(tree, 'mobile_drawer_wishlist_link').if).toBe(guard(SHOP));
        expect(byId(tree, 'mobile_drawer_guest_order_link').if).toBe(guard(SHOP));
        expect(byId(tree, 'mobile_drawer_shop_section').if).toBe(guard(SHOP));
        expect(byId(tree, 'mobile_drawer_popular_link').if).toBe(guard(BOARD));
        expect(byId(tree, 'mobile_drawer_boards_section').if).toBe(guard(BOARD));
        expect(byId(tree, 'mobile_drawer_info_section').if).toBe(guard(PAGE));
        // 검색 공급자(게시판·쇼핑몰·페이지)가 모두 꺼졌을 때만 서랍 검색칸을 숨긴다(D12)
        expect(byId(tree, 'mobile_drawer_search').if).toBe(`{{(${BOARD}) || (${SHOP}) || (${PAGE})}}`);
    });

    // @scenario surface=user_base_drawer
    // @effects shared_module_link_guarded
    it('서랍의 쇼핑·게시판·페이지 링크는 모두 가드가 달린 노드 아래에 있다', () => {
        const tree = base.components;
        const drawer = byId(tree, 'mobile_nav_drawer');
        /** 노드에서 조상 가드 식을 모은다. */
        const guardedHrefs: Array<{ href: string; guards: string[] }> = [];
        const visit = (node: unknown, guards: string[]) => {
            if (Array.isArray(node)) return node.forEach((c) => visit(c, guards));
            if (!node || typeof node !== 'object') return;
            const n = node as Node;
            const next = typeof n.if === 'string' ? [...guards, n.if] : guards;
            if (typeof n.props?.href === 'string') guardedHrefs.push({ href: n.props.href, guards: next });
            Object.values(n).forEach((v) => visit(v, next));
        };
        visit(drawer, []);
        const expectations: Array<[RegExp, string]> = [
            [/^\{\{_global\.shopBase\}\}\//, SHOP],
            [/^\/mypage\/(orders|wishlist)$/, SHOP],
            [/^\/boards?\//, BOARD],
            [/^\/board\//, BOARD],
            [/^\/page\//, PAGE],
        ];
        for (const { href, guards } of guardedHrefs) {
            for (const [pattern, expr] of expectations) {
                if (pattern.test(href)) {
                    expect(guards.some((g) => g.includes(expr)), `${href} 의 조상 가드`).toBe(true);
                }
            }
        }
    });

    // @scenario surface=user_base_header
    // @effects header_footer_module_props
    it('데스크톱 Header·Footer 에 모듈 활성 props 를 넘긴다(검색칸은 셋 중 하나라도 켜져 있으면 표시)', () => {
        const header = nodes(base.components).find((n) => n.name === 'Header')!;
        const footer = nodes(base.components).find((n) => n.name === 'Footer')!;
        expect(header.props.boardEnabled).toBe(guard(BOARD));
        expect(header.props.shopEnabled).toBe(guard(SHOP));
        expect(header.props.searchEnabled).toBe(`{{(${BOARD}) || (${SHOP}) || (${PAGE})}}`);
        // 봇 화면 「쇼핑」 링크용 완성 주소 — 옛 코어 봇 렌더는 섞인 attrs 패턴({shopBase}/products)을 치환하지 못한다
        expect(header.props.shopProductsHref).toBe("{{_global.shopBase ?? '/shop'}}/products");
        // seo-config 대체 prop 은 keep 된 옛 레이아웃에도 늘 있는 값이어야 한다
        expect(header.props.maxVisibleBoards).toBe(5);
        expect(String(footer.props.copyrightText)).toMatch(/^\$t:footer\.copyright/);

        expect(footer.props.boardEnabled).toBe(guard(BOARD));
        expect(footer.props.pageEnabled).toBe(guard(PAGE));
    });
});

describe('seo-config — 봇 화면의 머리글·꼬리말 링크', () => {
    const seo = JSON.parse(fs.readFileSync(path.join(ROOT, 'seo-config.json'), 'utf8'));
    const all = (tree: unknown, out: Node[] = []): Node[] => {
        if (Array.isArray(tree)) tree.forEach((c) => all(c, out));
        else if (tree && typeof tree === 'object') {
            out.push(tree as Node);
            Object.values(tree as Node).forEach((v) => all(v, out));
        }
        return out;
    };
    const header = all(seo.render_modes.header_nav.fields);
    const footer = all(seo.render_modes.footer_nav.fields);

    // @scenario surface=user_base_header
    // @effects header_footer_module_props
    it('머리글 인기글·쇼핑 링크는 모듈 props 로 가리고, 없으면(keep 된 옛 레이아웃) 늘 있는 maxVisibleBoards 로 넘어가 보인다', () => {
        expect(header.find((f) => f.attrs?.href === '/boards/popular')?.if).toBe('{boardEnabled|maxVisibleBoards}');
        const shop = header.find((f) => f.content === '$t:nav.shop')!;
        expect(shop.if).toBe('{shopEnabled|maxVisibleBoards}');
        // 섞인 패턴은 옛 코어 봇 렌더가 중괄호째 내보낸다 — 완성된 주소 prop 하나만 쓴다
        expect(shop.attrs.href).toBe('{shopProductsHref}');
    });

    // @scenario surface=user_base_header
    // @effects header_footer_module_props
    it('꼬리말 커뮤니티·정보·정책 묶음은 모듈 props 로 가리고, 대체는 사이트명(빈 값 가능)이 아닌 copyrightText', () => {
        const groups = footer.filter((f) => f.tag === 'div' && typeof f.if === 'string');
        expect(groups.map((g) => g.if)).toEqual(['{boardEnabled|copyrightText}', '{pageEnabled|copyrightText}', '{pageEnabled|copyrightText}']);
        expect(JSON.stringify(seo)).not.toContain('|siteName}');
        expect(JSON.stringify(seo.render_modes.header_nav)).not.toContain('{shopBase}');
    });
});

describe('홈 — 게시판·쇼핑 섹션', () => {
    const home = load('home');

    // @scenario surface=home
    // @effects shared_module_source_guarded
    it.each(['stats', 'recent_posts', 'popular_boards', 'home_boards'])('게시판 데이터소스 %s 는 게시판 가드 + 404 삼킴', (id) => {
        expectGuardedSource(home, id, BOARD);
    });

    // @scenario surface=home
    // @effects shared_module_link_guarded
    it('통계 카드 줄·게시판 요약 줄은 게시판 가드, 2행(최근 글·인기 게시판·쇼핑 홍보)은 둘 중 하나라도 켜져 있을 때', () => {
        const tree = home.slots.content;
        expect(byId(tree, 'home_stat_cards').if).toBe(guard(BOARD));
        expect(byId(tree, 'home_board_summaries').if).toBe(guard(BOARD));
        expect(byId(tree, 'home_feature_row').if).toBe(`{{(${BOARD}) || (${SHOP})}}`);
    });

    // @scenario surface=home
    // @effects shared_module_link_guarded
    it.each([
        ['home/_recent_posts', BOARD],
        ['home/_popular_boards', BOARD],
        ['home/_community_guide', BOARD],
        ['home/_shop_promo', SHOP],
    ])('조각 %s 의 뿌리는 모듈 가드를 단다', (partial, expr) => {
        const root = load(`partials/${partial}`);
        expect(root.if).toBe(guard(expr));
    });

    // @scenario surface=home
    // @effects shared_module_link_guarded
    it('환영 카드 「자세히」(/page/about)는 페이지 가드를 단다', () => {
        const card = load('partials/home/_welcome_card');
        const links = byHref(card, '/page/about');
        expect(links).toHaveLength(1);
        expect(links[0].if).toBe(guard(PAGE));
    });
});

describe('검색 — 탭·섹션·필터', () => {
    const search = load('search/index');

    // @scenario surface=search
    // @effects search_source_if_condition
    it('검색 결과 데이터소스는 엔진이 읽는 if 로 검색어 유무를 가린다(엔진이 모르는 condition 키 제거)', () => {
        const ds = dataSource(search, 'searchResults');
        expect(ds.if).toBe('{{query?.q}}');
        expect(ds).not.toHaveProperty('condition');
    });

    // @scenario surface=search
    // @effects shared_module_link_guarded
    it.each([
        ['posts', BOARD],
        ['products', SHOP],
        ['pages', PAGE],
    ])('%s 탭은 그 모듈 가드를 단다', (type, expr) => {
        const tabs = load('partials/search/_search_tabs');
        const tab = byHref(tabs, `/search?type=${type}&board_slug=&page=&cursor=`);
        expect(tab).toHaveLength(1);
        expect(tab[0].if).toBe(guard(expr));
    });

    // @scenario surface=search
    // @effects shared_module_link_guarded
    it.each([
        ['search/posts/_section', BOARD],
        ['search/products/_section', SHOP],
        ['search/pages/_section', PAGE],
    ])('전체 탭 섹션 %s 의 뿌리는 모듈 가드를 단다', (partial, expr) => {
        expect(load(`partials/${partial}`).if).toBe(guard(expr));
    });

    // @scenario surface=search
    // @effects shared_module_link_guarded
    it('게시판 필터는 게시판, 상품 정렬은 쇼핑몰 가드를 함께 단다', () => {
        const filters = nodes(load('partials/search/_search_filters'));
        const boardFilter = filters.find((n) => n.props?.name === 'boardFilter')!;
        expect(boardFilter.if).toContain(BOARD);
        const productSort = filters.find((n) => n.props?.name === 'sortBy' && String(n.if).includes("'products'"))!;
        expect(productSort.if).toContain(SHOP);
    });
});

describe('가입·로그인 — 약관 보기·비회원 쇼핑 안내', () => {
    // @scenario surface=register
    // @effects shared_module_source_guarded
    it('가입 약관·방침 본문 데이터소스는 페이지 가드 + 404 삼킴', () => {
        const register = load('auth/register');
        expectGuardedSource(register, 'termsContent', PAGE);
        expectGuardedSource(register, 'privacyContent', PAGE);
    });

    // @scenario surface=register
    // @effects terms_view_hidden_checkbox_kept
    it('약관 「보기」 단추는 페이지 가드, 꺼지면 같은 글을 일반 글자로 보이고 동의 체크박스는 그대로 둔다', () => {
        const form = load('partials/auth/_register_form');
        expect(byId(form, 'register_terms_view_button').if).toBe(guard(PAGE));
        expect(byId(form, 'register_privacy_view_button').if).toBe(guard(PAGE));
        expect(byId(form, 'register_terms_label').if).toBe(negated(PAGE));
        expect(byId(form, 'register_privacy_label').if).toBe(negated(PAGE));
        const checkboxes = nodes(form).filter((n) => n.name === 'Checkbox').map((n) => n.props?.name);
        expect(checkboxes).toEqual(expect.arrayContaining(['agree_terms', 'agree_privacy']));
        nodes(form)
            .filter((n) => n.name === 'Checkbox' && ['agree_terms', 'agree_privacy'].includes(n.props?.name))
            .forEach((n) => expect(n.if).toBeUndefined());
    });

    // @scenario surface=login
    // @effects shared_module_link_guarded
    it('로그인 화면의 비회원 결제·비회원 주문 조회 안내는 쇼핑몰 가드를 함께 단다', () => {
        const login = load('auth/login');
        expect(byId(login, 'login_guest_checkout').if).toContain(SHOP);
        expect(byId(login, 'login_guest_order_lookup').if).toContain(SHOP);
    });
});

describe('마이페이지 탭·회원 프로필·상품 문의 탭', () => {
    /** `{{…}}` 식을 평가한다(테스트 전용 — 번들 식의 의미 확인). */
    function evaluate(expression: string, scope: Record<string, unknown>): any {
        const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
        // eslint-disable-next-line no-new-func
        return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
    }

    const tabs = byId(load('partials/mypage/_tab_navigation'), 'mypageTabNavigation');
    const settings = { 'sirsoft-ecommerce': { inquiry: { board_slug: 'qna' }, mileage: { enabled: true } } };

    // @scenario surface=mypage_tabs
    // @effects mypage_tabs_follow_active_modules
    it('게시판만 켜진 사이트는 주문·클레임·위시리스트·배송지·적립금·문의 탭을 숨긴다', () => {
        const hidden = evaluate(tabs.props.hiddenTabIds, { _global: { modules: settings, activeModules: ['sirsoft-board'] }, _computed: {} });
        expect([...hidden].sort()).toEqual(['addresses', 'claims', 'inquiries', 'mileage', 'orders', 'wishlist']);
    });

    // @scenario surface=mypage_tabs
    // @effects mypage_tabs_follow_active_modules
    it('쇼핑몰만 켜진 사이트는 게시글·스크랩·문의 탭을 숨긴다', () => {
        const hidden = evaluate(tabs.props.hiddenTabIds, { _global: { modules: settings, activeModules: ['sirsoft-ecommerce'] }, _computed: {} });
        expect([...hidden].sort()).toEqual(['board', 'inquiries', 'scrap']);
    });

    // @scenario surface=mypage_tabs
    // @effects legacy_core_shows_all
    it('활성 목록이 없는 옛 코어에서는 지금처럼 기능 설정만 본다', () => {
        expect(evaluate(tabs.props.hiddenTabIds, { _global: { modules: settings }, _computed: {} })).toEqual([]);
        expect(evaluate(tabs.props.hiddenTabIds, { _global: { modules: {} }, _computed: {} }).sort()).toEqual(['inquiries', 'mileage']);
    });

    // @scenario surface=user_profile
    // @effects shared_module_source_guarded
    it('회원 프로필의 게시판 데이터소스와 블록은 게시판 가드를 단다', () => {
        const show = load('users/show');
        expectGuardedSource(show, 'postStats', BOARD);
        expectGuardedSource(show, 'recentPosts', BOARD);
        const tree = show.slots.content;
        ['user_profile_post_stats', 'user_profile_recent_posts', 'user_profile_recent_posts_empty'].forEach((id) =>
            expect(byId(tree, id).if, id).toContain(BOARD),
        );
    });

    // @scenario surface=product_qna
    // @effects product_qna_needs_board
    it('상품 상세 문의 탭은 문의 게시판 설정과 함께 게시판 활성도 본다', () => {
        const qna = load('partials/shop/detail/_tab_qna');
        expect(qna.if).toContain(`&& (${BOARD})`);

        const show = load('shop/show');
        const nav = nodes(show.slots.content).find((n) => n.name === 'TabNavigation' && String(n.props?.hiddenTabIds).includes('qna'))!;
        const hiddenWhenBoardOff = evaluate(nav.props.hiddenTabIds, { _global: { modules: settings, activeModules: ['sirsoft-ecommerce'] } });
        const hiddenWhenBoardOn = evaluate(nav.props.hiddenTabIds, { _global: { modules: settings, activeModules: ['sirsoft-ecommerce', 'sirsoft-board'] } });
        const hiddenLegacy = evaluate(nav.props.hiddenTabIds, { _global: { modules: settings } });
        expect(hiddenWhenBoardOff).toEqual(['qna']);
        expect(hiddenWhenBoardOn).toEqual([]);
        expect(hiddenLegacy).toEqual([]);
        expect(dataSource(show, 'qna').if).toBe(guard(BOARD));
    });
});

describe('모집단 — 라우트가 그 모듈을 요구하지 않는 화면의 모듈 API 데이터소스', () => {
    const routes: Node[] = JSON.parse(fs.readFileSync(path.join(ROOT, 'routes.json'), 'utf8')).routes;

    /** 레이아웃 이름 → 그 레이아웃을 쓰는 모든 라우트가 공통으로 요구하는 모듈 */
    function requiredModules(layoutName: string): Set<string> {
        const using = routes.filter((r) => r.layout === layoutName);
        if (using.length === 0) return new Set();
        const sets = using.map((r) => new Set<string>(r.requires?.modules ?? []));
        return new Set([...sets[0]].filter((m) => sets.every((s) => s.has(m))));
    }

    // @scenario surface=all_layouts
    // @effects shared_module_source_guarded
    it('라우트 requires 로 보장되지 않는 모듈 API 데이터소스는 전부 그 모듈 가드 + 404 삼킴을 단다', () => {
        const files = layoutFiles();
        expect(files.length).toBeGreaterThanOrEqual(40);
        const violations: string[] = [];
        let checked = 0;
        for (const file of files) {
            const layout = JSON.parse(fs.readFileSync(file, 'utf8'));
            const name = path.relative(LAYOUT_ROOT, file).replace(/\\/g, '/').replace(/\.json$/, '');
            const required = requiredModules(name);
            for (const ds of layout.data_sources ?? []) {
                const match = String(ds.endpoint ?? '').match(/^\/api\/modules\/(sirsoft-(?:board|ecommerce|page))\//);
                if (!match || required.has(match[1])) continue;
                checked++;
                const expr = GUARD_OF[match[1]];
                if (!String(ds.if ?? '').includes(expr)) violations.push(`${name}:${ds.id} if 에 ${match[1]} 가드 없음`);
                if (ds.errorHandling?.['404']?.handler !== 'suppress') violations.push(`${name}:${ds.id} 404 삼킴 없음`);
            }
        }
        // 모집단이 비면 검사가 공허하게 통과한다 — 상속원·홈·가입·회원 프로필이 걸려야 한다
        expect(checked).toBeGreaterThanOrEqual(10);
        expect(violations).toEqual([]);
    });
});
