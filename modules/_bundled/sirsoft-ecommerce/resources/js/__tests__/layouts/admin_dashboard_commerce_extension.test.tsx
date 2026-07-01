/**
 * 관리자 대시보드 이커머스 layout extension JSON 구조 검증 테스트
 *
 * @description
 * - quick_menu extension: 상품/주문/리뷰/쿠폰/배송 버튼 5개 + 슬롯/모드/priority
 * - commerce extension: data_sources 4개 + ExtensionBadge + 카드(오늘 배지 7종/판매 차트/최신 리뷰/미답변 문의)
 * - 데이터 바인딩 경로 (commerce_overview / commerce_sales_graph / commerce_recent_reviews / commerce_pending_inquiries)
 * - 다국어 키 (sirsoft-ecommerce.admin.dashboard.*)
 * - iteration 변수명 (item_var/index_var)
 */

import { describe, it, expect } from 'vitest';

import quickMenuExt from '../../../extensions/admin_dashboard_quick_menu.json';
import commerceExt from '../../../extensions/admin_dashboard_commerce.json';

function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (Array.isArray(node)) {
        for (const item of node) {
            const found = findById(item, id);
            if (found) return found;
        }
        return null;
    }
    if (typeof node !== 'object') return null;
    if (node.id === id) return node;
    for (const key of ['components', 'children']) {
        if (node[key] && Array.isArray(node[key])) {
            const found = findById(node[key], id);
            if (found) return found;
        }
    }
    return null;
}

function findByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node) return results;
    if (Array.isArray(node)) {
        for (const item of node) results.push(...findByName(item, name));
        return results;
    }
    if (typeof node !== 'object') return results;
    if (node.name === name) results.push(node);
    for (const key of ['components', 'children']) {
        if (node[key] && Array.isArray(node[key])) {
            results.push(...findByName(node[key], name));
        }
    }
    return results;
}

function extractTKeys(node: any): string[] {
    const keys = new Set<string>();
    const walk = (n: any) => {
        if (!n) return;
        if (typeof n === 'string') {
            const m = n.match(/\$t:([a-zA-Z0-9._-]+)/g);
            if (m) m.forEach(k => keys.add(k.slice(3)));
            return;
        }
        if (Array.isArray(n)) {
            n.forEach(walk);
            return;
        }
        if (typeof n === 'object') {
            for (const k of Object.keys(n)) walk(n[k]);
        }
    };
    walk(node);
    return [...keys].sort();
}

describe('admin_dashboard_quick_menu.json - 이커머스 quick_menu 슬롯 주입', () => {
    it('extension_point / mode / priority 가 올바르다 (priority 40 = 게시판 50보다 앞)', () => {
        expect(quickMenuExt.extension_point).toBe('admin_dashboard_quick_menu');
        expect(quickMenuExt.mode).toBe('append');
        expect(quickMenuExt.priority).toBe(40);
    });

    it('상품/주문/리뷰/쿠폰/배송 버튼 5개를 주입한다', () => {
        expect(quickMenuExt.components).toHaveLength(5);
        const ids = quickMenuExt.components.map((c: any) => c.id);
        expect(ids).toEqual([
            'qm_ecommerce_products',
            'qm_ecommerce_orders',
            'qm_ecommerce_reviews',
            'qm_ecommerce_coupons',
            'qm_ecommerce_shipping',
        ]);
    });

    it('각 버튼은 올바른 href 를 가진다', () => {
        expect(findById(quickMenuExt as any, 'qm_ecommerce_products').props.href).toBe('/admin/ecommerce/products');
        expect(findById(quickMenuExt as any, 'qm_ecommerce_orders').props.href).toBe('/admin/ecommerce/orders');
        expect(findById(quickMenuExt as any, 'qm_ecommerce_reviews').props.href).toBe('/admin/ecommerce/reviews');
        expect(findById(quickMenuExt as any, 'qm_ecommerce_coupons').props.href).toBe('/admin/ecommerce/promotion-coupons');
        expect(findById(quickMenuExt as any, 'qm_ecommerce_shipping').props.href).toBe('/admin/ecommerce/shipping-policies');
    });

    it('각 버튼은 Icon + Span 자식을 가진다', () => {
        for (const btnId of ['qm_ecommerce_products', 'qm_ecommerce_orders', 'qm_ecommerce_reviews', 'qm_ecommerce_coupons', 'qm_ecommerce_shipping']) {
            const btn = findById(quickMenuExt as any, btnId);
            expect(findByName(btn, 'Icon')).toHaveLength(1);
            expect(findByName(btn, 'Span')).toHaveLength(1);
        }
    });

    it('다국어 키는 sirsoft-ecommerce 네임스페이스를 사용한다', () => {
        const tKeys = extractTKeys(quickMenuExt);
        expect(tKeys).toContain('sirsoft-ecommerce.admin.dashboard.quick_menu.products');
        expect(tKeys).toContain('sirsoft-ecommerce.admin.dashboard.quick_menu.shipping');
    });
});

describe('admin_dashboard_commerce.json - commerce 슬롯 주입', () => {
    it('extension_point / mode / priority 가 올바르다', () => {
        expect(commerceExt.extension_point).toBe('admin_dashboard_commerce');
        expect(commerceExt.mode).toBe('append');
        expect(commerceExt.priority).toBe(100);
    });

    it('data_sources 4개(overview/sales-graph/recent-reviews/pending-inquiries)를 정의한다', () => {
        expect(commerceExt.data_sources).toHaveLength(4);
        const ids = commerceExt.data_sources.map((d: any) => d.id);
        expect(ids).toEqual([
            'commerce_overview',
            'commerce_sales_graph',
            'commerce_recent_reviews',
            'commerce_pending_inquiries',
        ]);
    });

    it('각 data_source 는 모듈 API 엔드포인트를 호출한다', () => {
        const endpoints = commerceExt.data_sources.map((d: any) => d.endpoint);
        expect(endpoints).toEqual([
            '/api/modules/sirsoft-ecommerce/admin/dashboard/overview',
            '/api/modules/sirsoft-ecommerce/admin/dashboard/sales-graph',
            '/api/modules/sirsoft-ecommerce/admin/dashboard/recent-reviews',
            '/api/modules/sirsoft-ecommerce/admin/dashboard/pending-inquiries',
        ]);
    });

    it('각 data_source 는 auth_required=true 와 fallback 을 가진다', () => {
        for (const ds of commerceExt.data_sources) {
            expect(ds.auth_required).toBe(true);
            expect(ds.method).toBe('GET');
            expect(ds.fallback).toBeDefined();
        }
    });

    it('commerce_section_wrapper 안에 ExtensionBadge 를 주입한다', () => {
        const wrapper = findById(commerceExt as any, 'commerce_section_wrapper');
        expect(wrapper).not.toBeNull();
        const badges = findByName(wrapper, 'ExtensionBadge');
        expect(badges).toHaveLength(1);
        expect(badges[0].props.type).toBe('module');
        expect(badges[0].props.identifier).toBe('sirsoft-ecommerce');
    });

    it('카드(오늘 배지/판매 차트/최신 리뷰/미답변 문의) 가 존재한다', () => {
        expect(findById(commerceExt as any, 'commerce_today_summary')).not.toBeNull();
        expect(findById(commerceExt as any, 'sales_graph_card')).not.toBeNull();
        expect(findById(commerceExt as any, 'latest_reviews_card')).not.toBeNull();
        expect(findById(commerceExt as any, 'pending_inquiries_card')).not.toBeNull();
    });

    it('오늘 주문 배지 7종이 commerce_overview 응답을 바인딩한다', () => {
        const statuses = ['pending_payment', 'payment_complete', 'preparing', 'shipping_ready', 'shipping', 'cancellations', 'returns'];
        for (const s of statuses) {
            const badge = findById(commerceExt as any, `today_${s}_badge`);
            expect(badge, `today_${s}_badge 누락`).not.toBeNull();
            expect(badge.text).toContain(`commerce_overview?.data?.${s}`);
        }
    });

    it('오늘 주문 배지는 클릭 시 상태 + 오늘 날짜로 필터된 주문 목록으로 이동한다', () => {
        // 배지는 "오늘" 기준이므로 상태 필터뿐 아니라 ordered_at 날짜 필터(오늘)도 포함해야 한다.
        // 날짜는 하드코딩이 아니라 클릭 시점의 오늘로 동적 평가되어야 한다 (엔진 Date 표현식).
        const statusFilter: Record<string, string> = {
            today_pending_payment_badge: 'order_status[]=pending_payment',
            today_payment_complete_badge: 'order_status[]=payment_complete',
            today_preparing_badge: 'order_status[]=preparing',
            today_shipping_ready_badge: 'order_status[]=shipping_ready',
            today_shipping_badge: 'order_status[]=shipping',
            today_cancellations_badge: 'order_status[]=cancelled',
            today_returns_badge: 'date_type=ordered_at',
        };
        // 하드코딩 날짜(YYYY-MM-DD 리터럴) 금지 — 동적 표현식만 허용
        const HARDCODED_DATE = /start_date=\d{4}-\d{2}-\d{2}/;
        for (const [id, filter] of Object.entries(statusFilter)) {
            const badge = findById(commerceExt as any, id);
            const click = (badge.actions ?? []).find((a: any) => a.type === 'click' && a.handler === 'navigate');
            expect(click, `${id} navigate 액션 누락`).toBeDefined();
            const path: string = click.params.path;
            expect(path).toContain('/admin/ecommerce/orders');
            expect(path).toContain(filter);
            // 오늘 날짜 필터(동적 표현식) 포함
            expect(path).toContain('date_type=ordered_at');
            expect(path).toContain('start_date={{new Date().toISOString().slice(0, 10)}}');
            expect(path).toContain('end_date={{new Date().toISOString().slice(0, 10)}}');
            // 하드코딩 날짜 금지
            expect(path).not.toMatch(HARDCODED_DATE);
            expect(badge.props.className).toContain('cursor-pointer');
        }
    });

    it('총 판매수량/매출 값은 commerce_sales_graph 응답을 바인딩한다', () => {
        expect(findById(commerceExt as any, 'total_quantity_value').text).toContain('commerce_sales_graph?.data?.total_quantity');
        expect(findById(commerceExt as any, 'total_sales_value').text).toContain('commerce_sales_graph?.data?.total_sales');
    });

    it('변화율은 데이터 부족 시 — 폴백을 가진다', () => {
        const qtyChange = findById(commerceExt as any, 'total_quantity_change');
        expect(qtyChange.text).toContain("'—'");
        expect(qtyChange.text).toContain('quantity_change');
        const salesChange = findById(commerceExt as any, 'total_sales_change');
        expect(salesChange.text).toContain("'—'");
        expect(salesChange.text).toContain('sales_change');
    });

    it('BarChart 는 commerce_sales_graph.days 를 변환하여 labels/datasets 을 생성한다', () => {
        const chart = findById(commerceExt as any, 'sales_graph_chart');
        expect(chart.name).toBe('BarChart');
        expect(chart.props.labels).toContain('commerce_sales_graph?.data?.days');
        expect(chart.props.datasets).toContain('sales_quantity');
        expect(chart.props.datasets).toContain('sales_amount');
    });

    it('최신 리뷰 항목(latest_review_item)에 iteration 이 정의된다', () => {
        const list = findById(commerceExt as any, 'latest_reviews_list');
        expect(list.iteration).toBeUndefined();
        const item = findById(commerceExt as any, 'latest_review_item');
        expect(item.iteration).toBeDefined();
        expect(item.iteration.source).toBe('commerce_recent_reviews?.data');
        expect(item.iteration.item_var).toBe('review');
        expect(item.iteration.index_var).toBe('i');
    });

    it('미답변 문의 항목(pending_inquiry_item)에 iteration 이 정의된다', () => {
        const list = findById(commerceExt as any, 'pending_inquiries_list');
        expect(list.iteration).toBeUndefined();
        const item = findById(commerceExt as any, 'pending_inquiry_item');
        expect(item.iteration).toBeDefined();
        expect(item.iteration.source).toBe('commerce_pending_inquiries?.data?.items');
        expect(item.iteration.item_var).toBe('inquiry');
        expect(item.iteration.index_var).toBe('i');
    });

    it('빈 상태 메시지는 length === 0 조건으로 노출된다', () => {
        expect(findById(commerceExt as any, 'latest_reviews_empty').if).toBe('{{(commerce_recent_reviews?.data ?? []).length === 0}}');
        expect(findById(commerceExt as any, 'pending_inquiries_empty').if).toBe('{{(commerce_pending_inquiries?.data?.items ?? []).length === 0}}');
    });

    it('리뷰 "전체 보기" 링크가 실제 라우트를 사용한다', () => {
        expect(findById(commerceExt as any, 'latest_reviews_view_all').props.href).toBe('/admin/ecommerce/reviews');
    });

    it('미답변 문의는 게시판 모듈 Post 로 관리되므로 board_slug 기반 게시판 경로로 연결된다', () => {
        // 상품 문의는 게시판 Post(inquirable). 관리자 화면은 지정 게시판의 글 상세에서 본다.
        const viewAll = findById(commerceExt as any, 'pending_inquiries_view_all');
        expect(viewAll.props.href).toBe('/admin/board/{{commerce_pending_inquiries?.data?.board_slug}}');
        // board_slug 미설정 시 링크 미노출
        expect(viewAll.if).toBe('{{!!commerce_pending_inquiries?.data?.board_slug}}');

        const item = findById(commerceExt as any, 'pending_inquiry_item');
        const click = item.actions.find((a: any) => a.type === 'click' && a.handler === 'navigate');
        expect(click.params.path).toBe('/admin/board/{{commerce_pending_inquiries?.data?.board_slug}}/post/{{inquiry?.inquirable_id}}');
    });

    it('판매 차트는 매출액 데이터셋을 보조 Y축(y1)으로 지정해 수량과 분리 표시한다', () => {
        // 판매수량(0~수십)과 매출액(수백만)을 단일 축에 그리면 수량 막대가 묻힌다.
        const chart = findById(commerceExt as any, 'sales_graph_chart');
        expect(chart.props.datasets).toContain("yAxisID: 'y'");
        expect(chart.props.datasets).toContain("yAxisID: 'y1'");
    });

    it('3 카드(sales_graph_card / latest_reviews_card / pending_inquiries_card) 가 h-full 로 동일 높이를 보장한다', () => {
        for (const id of ['sales_graph_card', 'latest_reviews_card', 'pending_inquiries_card']) {
            const card = findById(commerceExt as any, id);
            expect(card.props.className, `${id} 에 h-full 누락`).toContain('h-full');
        }
    });

    it('카드는 회색톤(bg-slate-100 dark:bg-slate-800) 스타일을 사용한다', () => {
        for (const id of ['sales_graph_card', 'latest_reviews_card', 'pending_inquiries_card']) {
            const card = findById(commerceExt as any, id);
            expect(card.props.className).toContain('bg-slate-100');
            expect(card.props.className).toContain('dark:bg-slate-800');
        }
    });

    it('갱신 시각 캡션은 updated_at_display 가 있을 때만 노출된다', () => {
        const caption = findById(commerceExt as any, 'sales_graph_updated_at_caption');
        expect(caption).not.toBeNull();
        expect(caption.if).toBe('{{!!commerce_sales_graph?.data?.updated_at_display}}');
    });

    it('모든 다국어 키는 sirsoft-ecommerce 네임스페이스 또는 코어 공용 키를 사용한다', () => {
        const tKeys = extractTKeys(commerceExt);
        for (const key of tKeys) {
            const ok =
                key.startsWith('sirsoft-ecommerce.') ||
                key === 'admin.dashboard.stats.today';
            expect(ok, `다국어 키 형식 위반: ${key}`).toBe(true);
        }
    });
});
