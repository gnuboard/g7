/**
 * 이커머스 모듈 필터 named_actions 검증 테스트
 *
 * @description
 * - 쿠폰관리, 배송정책, 주문관리 필터의 named_actions + actionRef 적용 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 쿠폰관리
import couponList from '../../../layouts/admin/admin_ecommerce_promotion_coupon_list.json';
import couponFilter from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_partial_filter_section.json';

// 배송정책
import shippingPolicyList from '../../../layouts/admin/admin_ecommerce_shipping_policy_list.json';
import shippingPolicyFilter from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_filter.json';

// 주문관리
import orderList from '../../../layouts/admin/admin_ecommerce_order_list.json';
import orderFilter from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.json';

/** 재귀적으로 JSON 트리에서 특정 조건의 노드를 찾는 유틸리티 */
function findNodes(node: any, predicate: (n: any) => boolean, results: any[] = []): any[] {
    if (!node) return results;
    if (predicate(node)) results.push(node);
    if (node.children) {
        for (const child of node.children) {
            findNodes(child, predicate, results);
        }
    }
    return results;
}

/** JSON 전체에서 actionRef를 가진 액션을 찾는 유틸리티 */
function findActionRefs(obj: any, refName: string, results: any[] = []): any[] {
    if (!obj) return results;
    if (typeof obj === 'object') {
        if (obj.actionRef === refName) {
            results.push(obj);
        }
        for (const key of Object.keys(obj)) {
            findActionRefs(obj[key], refName, results);
        }
    }
    return results;
}

// ============================================
// 쿠폰관리
// ============================================

describe('쿠폰관리 named_actions 검증', () => {
    it('메인 레이아웃에 named_actions.searchCoupons가 정의되어 있어야 함', () => {
        const namedActions = (couponList as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchCoupons).toBeDefined();
    });

    it('searchCoupons가 올바른 navigate 핸들러를 가져야 함', () => {
        const searchCoupons = (couponList as any).named_actions.searchCoupons;
        expect(searchCoupons.handler).toBe('navigate');
        expect(searchCoupons.params.path).toBe('/admin/ecommerce/promotion-coupons');
        expect(searchCoupons.params.replace).toBe(true);
        expect(searchCoupons.params.query.page).toBe(1);
        expect(searchCoupons.params.query.search_keyword).toBeDefined();
    });

    it('파샬 필터에서 Enter keypress가 actionRef로 searchCoupons를 참조해야 함', () => {
        const refs = findActionRefs(couponFilter, 'searchCoupons');
        const enterRef = refs.find((r: any) => r.type === 'keypress' && r.key === 'Enter');
        expect(enterRef).toBeDefined();
    });

    it('파샬 필터에서 검색 버튼 click이 actionRef로 searchCoupons를 참조해야 함', () => {
        const refs = findActionRefs(couponFilter, 'searchCoupons');
        const clickRef = refs.find((r: any) => r.type === 'click');
        expect(clickRef).toBeDefined();
    });
});

// ============================================
// 배송정책
// ============================================

describe('배송정책 named_actions 검증', () => {
    it('메인 레이아웃에 named_actions.searchShippingPolicies가 정의되어 있어야 함', () => {
        const namedActions = (shippingPolicyList as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchShippingPolicies).toBeDefined();
    });

    it('searchShippingPolicies가 올바른 navigate 핸들러를 가져야 함', () => {
        const action = (shippingPolicyList as any).named_actions.searchShippingPolicies;
        expect(action.handler).toBe('navigate');
        expect(action.params.path).toBe('/admin/ecommerce/shipping-policies');
        expect(action.params.replace).toBe(true);
        expect(action.params.query.page).toBe(1);
    });

    it('파샬 필터의 검색 Input에 Enter keypress actionRef가 있어야 함', () => {
        const refs = findActionRefs(shippingPolicyFilter, 'searchShippingPolicies');
        const enterRef = refs.find((r: any) => r.type === 'keypress' && r.key === 'Enter');
        expect(enterRef).toBeDefined();
    });

    it('파샬 필터의 검색 버튼 click이 actionRef를 참조해야 함', () => {
        const refs = findActionRefs(shippingPolicyFilter, 'searchShippingPolicies');
        const clickRef = refs.find((r: any) => r.type === 'click');
        expect(clickRef).toBeDefined();
    });
});

// ============================================
// 주문관리
// ============================================

describe('주문관리 named_actions 검증', () => {
    it('메인 레이아웃에 named_actions.searchOrders가 정의되어 있어야 함', () => {
        const namedActions = (orderList as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchOrders).toBeDefined();
    });

    it('searchOrders가 올바른 navigate 핸들러를 가져야 함', () => {
        const action = (orderList as any).named_actions.searchOrders;
        expect(action.handler).toBe('navigate');
        expect(action.params.path).toBe('/admin/ecommerce/orders');
        expect(action.params.replace).toBe(true);
        expect(action.params.mergeQuery).toBe(true);
        expect(action.params.query.page).toBe(1);
        expect(action.params.query.search_keyword).toBeDefined();
    });

    it('파샬 필터의 검색 Input에 Enter keypress actionRef가 있어야 함', () => {
        const refs = findActionRefs(orderFilter, 'searchOrders');
        const enterRef = refs.find((r: any) => r.type === 'keypress' && r.key === 'Enter');
        expect(enterRef).toBeDefined();
    });

    it('파샬 필터의 검색 버튼 click이 actionRef를 참조해야 함', () => {
        const refs = findActionRefs(orderFilter, 'searchOrders');
        const clickRef = refs.find((r: any) => r.type === 'click');
        expect(clickRef).toBeDefined();
    });
});
