/**
 * @file cartItemUnavailableBadge.test.tsx
 * @description 장바구니 품목의 「판매중지」 표시는 서버가 내려주는 구매 가능 여부(available)를 따른다.
 *
 * 상품 판매 상태만 보면, 상품은 판매 중이지만 담은 옵션이 판매 중지된 품목·전시 중지된 상품이 표시 없이
 * 정상 상품처럼 보였다(합계에서는 빠지는데 화면은 그대로). 서버는 그런 품목에 available=false 를 준다.
 */
import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { evaluateStringCondition } from '@core/template-engine/helpers/ConditionEvaluator';
import cartItemJson from '../../layouts/partials/shop/_cart_item.json';

const engine = new DataBindingEngine();

/**
 * 레이아웃 트리에서 조건에 맞는 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드
 * @param pred 판정 함수
 * @returns 찾은 노드 또는 undefined
 */
function findNode(node: any, pred: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return undefined;
    if (pred(node)) return node;
    for (const v of Object.values(node)) {
        const found = Array.isArray(v) ? v.map((c) => findNode(c, pred)).find(Boolean) : findNode(v, pred);
        if (found) return found;
    }
    return undefined;
}

describe('장바구니 품목 판매중지 표시', () => {
    const badge = findNode(cartItemJson, (n) => n?.text === '$t:shop.cart.not_on_sale');
    const show = (item: Record<string, unknown>) => evaluateStringCondition(String(badge.if), { item }, engine);

    /**
     * @scenario item_state=option_suspended
     * @effects cart_marks_inactive_option_unavailable
     */
    it('상품은 판매 중이지만 담은 옵션이 판매 중지(available=false)면 판매중지를 표시한다', () => {
        expect(badge, '판매중지 표시 노드를 찾지 못했다').toBeDefined();
        expect(show({ product: { sales_status: 'on_sale' }, available: false, unavailable_reason: 'suspended' })).toBe(true);
    });

    /**
     * @scenario item_state=product_not_on_sale
     * @effects product_status_badge_kept
     */
    it('상품 판매 상태가 판매 중이 아니면 종전처럼 표시한다', () => {
        expect(show({ product: { sales_status: 'suspended' }, available: false })).toBe(true);
    });

    /**
     * @scenario item_state=purchasable
     * @effects purchasable_item_has_no_badge
     */
    it('구매 가능한 품목에는 표시하지 않는다', () => {
        expect(show({ product: { sales_status: 'on_sale' }, available: true })).toBe(false);
    });
});
