/**
 * @file admin-list-context-round-trip.test.ts
 * @description 관리자 이커머스 목록 컨텍스트 왕복 시 URL 목록 상태 보존 회귀 (#75)
 *
 * 결함: 주문/상품/쿠폰/배송정책/리뷰/마일리지 목록에서 상세·폼으로 들어갔다 돌아오면
 *   navigate 가 `mergeQuery` 없이 실행되어 page 와 필터 20여 종이 URL 에서 사라졌다.
 *   필터를 여러 개 건 상태일수록 손실이 크다.
 *
 * 검증 방식 2단:
 *   1. 실 레이아웃 JSON 전수 분석 — 클러스터 내 navigate 가 모두 `mergeQuery: true` 인지
 *   2. 실 액션을 엔진에 태워 실행(`triggerAction`) — 선언이 실제 목적지 URL 로 이어지는지
 *   손으로 쓴 조각이 아니라 실파일을 읽는다. 선언만 검사하면 "JSON 에 리터럴이 적혀 있다"
 *   까지만 고정되고 병합 결과는 미검증으로 남는다.
 *
 * @scenario leg=row_click|detail_return|form_cancel|filter_apply
 * @effects navigation_preserves_list_query
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import fs from 'fs';
import path from 'path';

import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

const LAYOUTS_ROOT = path.resolve(__dirname, '../../../layouts/admin');

/** 이 확장이 소유한 페이지네이션 목록 라우트 */
const LIST_ROUTES = [
    '/admin/ecommerce/orders',
    '/admin/ecommerce/products',
    '/admin/ecommerce/reviews',
    '/admin/ecommerce/promotion-coupons',
    '/admin/ecommerce/shipping-policies',
    '/admin/ecommerce/mileage-transactions',
];

/** 목록 클러스터에 속하는 레이아웃 파일 */
const CLUSTER_FILES = [
    'admin_ecommerce_order_detail.json',
    'admin_ecommerce_order_index.json',
    'admin_ecommerce_order_list.json',
    'admin_ecommerce_product_form.json',
    'admin_ecommerce_product_list.json',
    'admin_ecommerce_product_review_index.json',
    'admin_ecommerce_promotion_coupon_form.json',
    'admin_ecommerce_promotion_coupon_list.json',
    'admin_ecommerce_shipping_policy_form.json',
    'admin_ecommerce_shipping_policy_list.json',
    'partials/admin_ecommerce_mileage_transaction_index/_filters.json',
    'partials/admin_ecommerce_mileage_transaction_index/_transactions_table.json',
    'partials/admin_ecommerce_order_detail/_partial_order_info.json',
    'partials/admin_ecommerce_order_list/_partial_filter_section.json',
    'partials/admin_ecommerce_order_list/_partial_order_datagrid.json',
    // `_partial_preset_section.json` 은 목록에서 뺐다 — 어디서도 참조하지 않는 고아라 S16 이
    // 지웠고, 그 프리셋 이동은 정본인 `_partial_filter_section` 이 갖고 있다(같은 규약의
    // `audit:allow` 주석까지 함께). 없는 파일을 목록에 두면 이 검사가 ENOENT 로 죽어
    // **나머지 파일의 판정까지 사라진다**.
    'partials/admin_ecommerce_product_form/_modal_delete_confirm.json',
    'partials/admin_ecommerce_product_list/_partial_filter_section.json',
    'partials/admin_ecommerce_product_list/_partial_product_datagrid.json',
    'partials/admin_ecommerce_promotion_coupon_list/_partial_coupon_datagrid.json',
    'partials/admin_ecommerce_promotion_coupon_list/_partial_filter_section.json',
    'partials/admin_ecommerce_shipping_policy_list/_modal_copy.json',
    'partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.json',
    'partials/admin_ecommerce_shipping_policy_list/_partial_filter.json',
];

/**
 * 목적지 경로(`path`)가 없는 선택형 navigate/replaceUrl 을 가진 목록 화면.
 *
 * `path` 를 생략한 액션은 "현재 URL 을 유지한 채 쿼리만 바꾼다" 는 뜻이라 목록 화면
 * 자신에게 작용한다. 그런데 `mergeQuery` 가 없으면 엔진이 대체 분기로 가 쿼리를
 * **통째로** 새 값으로 바꿔치므로, 방금 건 검색어·정렬이 항목 선택 한 번에 사라진다.
 * 목적지가 문자열로 안 적혀 있어 경로 기반 검사(`normalizePath`)의 사각지대다.
 */
const SELF_QUERY_FILES = [
    'partials/admin_ecommerce_brand_index/_panel_brand_list.json',
    'partials/admin_ecommerce_category_index/_panel_category_list.json',
    'partials/admin_ecommerce_product_common_info_index/_panel_list.json',
    'partials/admin_ecommerce_product_notice_index/_panel_list.json',
];

type NavAction = {
    handler: string;
    comment?: string;
    params: { path?: string; mergeQuery?: unknown; query?: unknown };
};

/**
 * JSON 트리에서 navigate/replaceUrl 액션을 모두 수집합니다.
 *
 * @param node 순회 시작 노드
 * @returns 액션 목록
 */
function collectNavActions(node: unknown): NavAction[] {
    const out: NavAction[] = [];
    // 링크(`A`) 안의 path 없는 navigate 는 엔진이 그 링크의 href 로 옮긴다(#757 WP-L D12) — 시험도 같은 주소로 푼다
    const walk = (n: unknown, href?: string): void => {
        if (Array.isArray(n)) {
            n.forEach((x) => walk(x, href));
            return;
        }
        if (!n || typeof n !== 'object') return;
        const obj = n as Record<string, unknown>;
        const props = obj.props as Record<string, unknown> | undefined;
        const ownHref = obj.name === 'A' && typeof props?.href === 'string' ? (props.href as string) : href;
        if (
            (obj.handler === 'navigate' || obj.handler === 'replaceUrl') &&
            obj.params &&
            typeof obj.params === 'object'
        ) {
            const params = obj.params as Record<string, unknown>;
            out.push(
                (params.path === undefined && ownHref !== undefined
                    ? { ...obj, params: { ...params, path: ownHref } }
                    : obj) as unknown as NavAction
            );
        }
        Object.values(obj).forEach((x) => walk(x, ownHref));
    };
    walk(node);
    return out;
}

/**
 * 목적지 경로를 비교용 표준형(`{{expr}}` → `*`)으로 바꿉니다.
 *
 * @param raw 원본 경로
 * @returns 표준형 경로 (판정 불가 시 null)
 */
function normalizePath(raw: unknown): string | null {
    if (typeof raw !== 'string' || raw === '') return null;
    const p = raw.replace(/\{\{[^}]*\}\}/g, '*').split('?')[0].replace(/\/+$/, '');
    return p.startsWith('/') ? p : null;
}

/**
 * 경로가 목록 라우트와 같은 클러스터인지 판정합니다.
 *
 * @param dest 표준형 목적지
 * @param list 표준형 목록 라우트
 * @returns 같은 클러스터 여부
 */
function inFamily(dest: string, list: string): boolean {
    return dest === list || dest.startsWith(`${list}/`);
}

/**
 * 레이아웃 파일을 읽어 파싱합니다.
 *
 * @param rel 레이아웃 루트 기준 상대 경로
 * @returns 파싱된 JSON
 */
function readLayout(rel: string): unknown {
    return JSON.parse(fs.readFileSync(path.join(LAYOUTS_ROOT, rel), 'utf8'));
}

describe('#75 관리자 이커머스 목록 컨텍스트 왕복 — mergeQuery 전수', () => {
    /**
     * @scenario leg=entry
     * @effects cluster_navigations_merge_query
     */
    it.each(CLUSTER_FILES)('%s 의 클러스터 내 navigate 는 모두 mergeQuery: true', (rel) => {
        const offenders = collectNavActions(readLayout(rel))
            .filter((a) => {
                if (typeof a.comment === 'string' && a.comment.includes('audit:allow')) return false;
                const dest = normalizePath(a.params.path);
                if (!dest) return false;
                if (!LIST_ROUTES.some((l) => inFamily(dest, l))) return false;
                return a.params.mergeQuery !== true;
            })
            .map((a) => `${a.params.path} (mergeQuery=${JSON.stringify(a.params.mergeQuery)})`);

        expect(offenders, `목록 상태를 떨구는 navigate: \n  ${offenders.join('\n  ')}`).toEqual([]);
    });
});

describe('#75 목적지가 자기 자신인 액션도 목록 상태를 유지한다', () => {
    /**
     * @scenario leg=in_place
     * @effects in_place_navigation_preserves_query
     */
    it.each(SELF_QUERY_FILES)('%s 의 path 없는 navigate/replaceUrl 은 mergeQuery: true', (rel) => {
        const offenders = collectNavActions(readLayout(rel))
            .filter((a) => {
                if (typeof a.comment === 'string' && a.comment.includes('audit:allow')) return false;
                if (normalizePath(a.params.path) !== null) return false;
                return a.params.mergeQuery !== true;
            })
            .map((a) => `${a.handler} query=${JSON.stringify(a.params.query)}`);

        expect(
            offenders,
            `현재 URL 의 검색어·정렬을 통째로 덮어쓰는 액션: \n  ${offenders.join('\n  ')}`
        ).toEqual([]);
    });

    /**
     * @scenario leg=in_place
     * @effects in_place_navigation_preserves_query
     */
    it('브랜드를 선택해도 걸어둔 검색어와 정렬이 URL 에 남는다', async () => {
        window.history.replaceState({}, '', '/admin/ecommerce/brands?search=나이키&sort=name_desc');

        const action = collectNavActions(
            readLayout('partials/admin_ecommerce_brand_index/_panel_brand_list.json')
        ).find((a) => a.handler === 'replaceUrl' && normalizePath(a.params.path) === null);
        expect(action, '브랜드 선택 replaceUrl 을 찾지 못했습니다.').toBeTruthy();

        await runAction(action as NavAction);
        const q = new URLSearchParams(window.location.search);

        expect(q.get('search')).toBe('나이키');
        expect(q.get('sort')).toBe('name_desc');
        expect(q.get('mode')).toBe('view');
    });
});

describe('#75 주문자명 검색은 회원 지정 필터를 함께 비운다', () => {
    /**
     * `orderer_uuid`(회원 지정)와 `search_field=orderer_name`(이름 문자열)은 서로 배타적이다.
     * `mergeQuery: true` 가 붙은 뒤로는 이전 화면의 `orderer_uuid` 가 살아남으므로,
     * 이름 검색을 걸면 두 조건이 AND 로 겹쳐 결과가 비어 보인다. 이름 검색을 실행하는
     * 모든 leg 이 `orderer_uuid` 를 명시적으로 비워야 한다.
     */
    const ORDERER_SEARCH_LEGS: Array<[string, string]> = [
        ['목록 데이터그리드', 'partials/admin_ecommerce_order_list/_partial_order_datagrid.json'],
        ['주문 상세 주문자 카드', 'partials/admin_ecommerce_order_detail/_partial_order_info.json'],
    ];

    /**
     * @scenario leg=in_place
     * @effects orderer_search_clears_member_filter
     */
    it.each(ORDERER_SEARCH_LEGS)('%s 의 주문자명 검색이 orderer_uuid 를 비운다', (_label, rel) => {
        const actions = collectNavActions(readLayout(rel)).filter(
            (a) =>
                a.params.query !== null &&
                typeof a.params.query === 'object' &&
                (a.params.query as Record<string, unknown>).search_field === 'orderer_name'
        );

        expect(actions.length, '주문자명 검색 navigate 를 찾지 못했습니다.').toBeGreaterThan(0);
        actions.forEach((a) => {
            const query = a.params.query as Record<string, unknown>;
            expect(Object.keys(query)).toContain('orderer_uuid');
            expect(query.orderer_uuid).toBe('');
        });
    });
});

describe('#75 주문 목록 ↔ 상세 왕복 leg 고정', () => {
    /**
     * @scenario leg=entry
     * @effects entry_carries_list_query
     */
    it('주문 행 클릭이 필터/페이지를 상세로 나른다', () => {
        const actions = collectNavActions(
            readLayout('partials/admin_ecommerce_order_list/_partial_order_datagrid.json')
        ).filter((a) => String(a.params.path ?? '').startsWith('/admin/ecommerce/orders/{{'));

        expect(actions.length).toBeGreaterThan(0);
        actions.forEach((a) => expect(a.params.mergeQuery).toBe(true));
    });

    /**
     * @scenario leg=back
     * @effects list_return_preserves_query
     */
    it("주문 상세 '목록' 버튼이 필터/페이지를 복원한다", () => {
        const a = collectNavActions(readLayout('admin_ecommerce_order_detail.json')).find(
            (x) => x.params.path === '/admin/ecommerce/orders'
        );
        expect(a, "주문 상세의 '목록' navigate 를 찾지 못했습니다.").toBeTruthy();
        expect(a?.params.mergeQuery).toBe(true);

        // 새 탭·가운데 클릭으로 열어도 같은 목록 조건이어야 한다 — 링크 자체가 현재 쿼리를 href 에 합친다(#757 WP-L W8)
        let link: Record<string, any> | undefined;
        const find = (n: unknown): void => {
            if (link || !n || typeof n !== 'object') return;
            if (Array.isArray(n)) return n.forEach(find);
            const obj = n as Record<string, any>;
            if (obj.id === 'list_button') link = obj;
            else Object.values(obj).forEach(find);
        };
        find(readLayout('admin_ecommerce_order_detail.json'));
        expect(link?.name).toBe('A');
        expect(link?.props?.href).toBe('/admin/ecommerce/orders');
        expect(link?.props?.mergeQuery).toBe(true);
    });
});

// ---------------------------------------------------------------------------
// 실행 검증 — 실 액션을 엔진에 태워 목적지 URL 을 확인한다
// ---------------------------------------------------------------------------

/**
 * 실 JSON 액션을 현재 URL 상태에서 실행하고 목적지를 돌려줍니다.
 *
 * @param action 실 레이아웃 JSON 에서 뽑은 navigate 액션
 * @returns navigate 목적지 경로
 */
async function runNav(action: NavAction): Promise<string> {
    return (await runAction(action)) as string;
}

/**
 * 실 JSON 액션을 현재 URL 상태에서 실행합니다.
 *
 * `navigate` 는 목적지가 navigation history 에 쌓이지만 `replaceUrl` 은 주소만 바꾸므로
 * 히스토리가 비어 있다. 결과 확인은 호출 측이 목적으로 나눠서 한다 —
 * navigate 는 반환값, replaceUrl 은 `window.location`.
 *
 * @param action 실 레이아웃 JSON 에서 뽑은 navigate/replaceUrl 액션
 * @returns navigate 목적지 경로 (replaceUrl 이면 undefined)
 */
async function runAction(action: NavAction): Promise<string | undefined> {
    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'nav_action_probe',
            data_sources: [],
            components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' } }],
        } as never,
        { componentRegistry: createMockComponentRegistryWithBasics() as never, locale: 'ko' }
    );
    await utils.render();
    await utils.triggerAction({ type: 'click', ...action } as never);
    const dest = utils.getNavigationHistory()[0];
    utils.cleanup();
    return dest;
}

/**
 * 목적지 URL 의 쿼리스트링을 파싱합니다.
 *
 * @param dest navigate 목적지
 * @returns URLSearchParams
 */
function queryOf(dest: string): URLSearchParams {
    return new URLSearchParams(dest.includes('?') ? dest.slice(dest.indexOf('?')) : '');
}

describe('#75 주문 목록 ↔ 상세 왕복 — 실행 결과 URL', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    /** 필터를 여러 개 건 목록 URL — 이 화면이 손실 폭이 가장 크다 */
    const LIST_URL =
        '/admin/ecommerce/orders?page=2&per_page=20&search_field=orderer_name&search=홍길동&order_status=confirmed';

    /**
     * @scenario leg=entry
     * @effects entry_carries_list_query
     */
    it('주문 행 클릭이 필터 전부를 상세 URL 로 나른다', async () => {
        window.history.replaceState({}, '', LIST_URL);

        const action = collectNavActions(
            readLayout('partials/admin_ecommerce_order_list/_partial_order_datagrid.json')
        ).find((a) => String(a.params.path ?? '').startsWith('/admin/ecommerce/orders/{{'));
        expect(action, '주문 행 클릭 navigate 를 찾지 못했습니다.').toBeTruthy();

        const q = queryOf(await runNav(action as NavAction));

        expect(q.get('page')).toBe('2');
        expect(q.get('per_page')).toBe('20');
        expect(q.get('search_field')).toBe('orderer_name');
        expect(q.get('search')).toBe('홍길동');
        expect(q.get('order_status')).toBe('confirmed');
    });

    /**
     * @scenario leg=back
     * @effects list_return_preserves_query
     */
    it("주문 상세 '목록' 버튼이 필터 전부를 목록으로 복원한다", async () => {
        window.history.replaceState(
            {},
            '',
            '/admin/ecommerce/orders/1024?page=2&per_page=20&search=홍길동&order_status=confirmed'
        );

        const action = collectNavActions(readLayout('admin_ecommerce_order_detail.json')).find(
            (x) => x.params.path === '/admin/ecommerce/orders'
        );

        const q = queryOf(await runNav(action as NavAction));

        expect(q.get('page')).toBe('2');
        expect(q.get('per_page')).toBe('20');
        expect(q.get('search')).toBe('홍길동');
        expect(q.get('order_status')).toBe('confirmed');
    });
});

describe('행 메뉴 링크(href)도 목록 조건을 싣는다 (7.1.1 출시 전 실측)', () => {
    /**
     * 행 메뉴 항목의 href 는 가운데 클릭·Ctrl+클릭·주소 복사가 쓰는 주소다. 보통 클릭(onRowAction 의 navigate)은
     * `mergeQuery: true` 로 목록 조건을 싣는데 항목에 `mergeQuery` 가 없으면 새 탭만 조건 없이 열린다
     * (실측: 상품·쿠폰·배송정책 「수정」, 주문·클레임 「상세보기」). 관리자 템플릿 ActionMenu 는 항목의
     * `mergeQuery` 가 참일 때만 href 에 현재 쿼리를 병합하므로, 이 확장 목록 화면으로 가는 항목에 켠다.
     * 다른 화면으로 가는 항목(「회원 보기」 `/admin/users/…`)은 목록 조건을 실으면 안 되므로 모집단에서 뺀다.
     */
    function rowActionItems(node: any, file: string, acc: Array<[string, any]> = []): Array<[string, any]> {
        if (Array.isArray(node)) {
            node.forEach((child) => rowActionItems(child, file, acc));

            return acc;
        }
        if (node === null || typeof node !== 'object') return acc;
        if (Array.isArray(node.props?.rowActions)) {
            node.props.rowActions.forEach((item: any) => acc.push([file, item]));
        }
        Object.entries(node).forEach(([k, v]) => {
            if (k !== 'props' && v && typeof v === 'object') rowActionItems(v, file, acc);
        });

        return acc;
    }

    function jsonFiles(dir: string): string[] {
        return fs.readdirSync(dir).flatMap((entry) => {
            const full = path.join(dir, entry);
            if (fs.statSync(full).isDirectory()) return jsonFiles(full);

            return full.endsWith('.json') ? [full] : [];
        });
    }

    /**
     * @scenario leg=entry
     * @effects row_menu_href_carries_list_query
     */
    it('이 확장 화면으로 가는 행 메뉴 링크는 mergeQuery 가 켜져 있다', () => {
        const items = jsonFiles(LAYOUTS_ROOT).flatMap((full) =>
            rowActionItems(JSON.parse(fs.readFileSync(full, 'utf-8')), path.relative(LAYOUTS_ROOT, full).split(path.sep).join('/')),
        );
        const targets = items.filter(([, item]) => typeof item?.href === 'string' && item.href.startsWith('/admin/ecommerce/'));
        expect(targets.length).toBeGreaterThanOrEqual(5);

        const missing = targets.filter(([, item]) => item.mergeQuery !== true).map(([file, item]) => `${file}: ${item.id} ${item.href}`);
        expect(missing).toEqual([]);
    });
});
