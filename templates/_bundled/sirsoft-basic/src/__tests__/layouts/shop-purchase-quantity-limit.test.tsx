/**
 * @file shop-purchase-quantity-limit.test.tsx
 * @description 옵션 상품 구매수량 한도 UI 바인딩 검증 (A25 / MP05)
 *
 * 검증 항목:
 * 1. 옵션 라인 QuantitySelector max 가 상품 max_purchase_qty 로 clamp 된다
 *    (max>0 시 Math.min(item.stock, max_purchase_qty), max=0 시 item.stock).
 * 2. 구매/장바구니 버튼 disabled 가 선택 합계 min 미달 / max 초과 시 비활성화된다.
 * 3. 한도 위반 안내 Span(min/max notice)이 존재한다.
 */

import { describe, it, expect } from 'vitest';
import purchaseCard from '../../../layouts/partials/shop/detail/_purchase_card.json';

/**
 * JSON 트리에서 조건을 만족하는 첫 노드를 재귀 탐색한다.
 */
function findNode(obj: any, predicate: (node: any) => boolean): any | null {
    if (!obj || typeof obj !== 'object') {
        return null;
    }
    if (predicate(obj)) {
        return obj;
    }
    for (const value of Object.values(obj)) {
        if (Array.isArray(value)) {
            for (const item of value) {
                const found = findNode(item, predicate);
                if (found) {
                    return found;
                }
            }
        } else if (value && typeof value === 'object') {
            const found = findNode(value, predicate);
            if (found) {
                return found;
            }
        }
    }
    return null;
}

/**
 * JSON 트리에서 조건을 만족하는 모든 노드를 수집한다.
 */
function collectNodes(obj: any, predicate: (node: any) => boolean, acc: any[] = []): any[] {
    if (!obj || typeof obj !== 'object') {
        return acc;
    }
    if (predicate(obj)) {
        acc.push(obj);
    }
    for (const value of Object.values(obj)) {
        if (Array.isArray(value)) {
            value.forEach((item) => collectNodes(item, predicate, acc));
        } else if (value && typeof value === 'object') {
            collectNodes(value, predicate, acc);
        }
    }
    return acc;
}

describe('_purchase_card.json - 옵션 라인 max clamp (A25)', () => {
    /**
     * @effects option_line_quantity_clamped_to_max_purchase
     */
    it('QuantitySelector 의 max 가 상품 max_purchase_qty 로 clamp 된다', () => {
        // 옵션 라인 셀렉터는 `item.*` 을 바인딩하는 쪽이다 (다른 하나는 옵션 없는 상품용).
        const selector = collectNodes(
            purchaseCard,
            (n) => n.name === 'QuantitySelector' && String(n.props?.value ?? '').includes('item.quantity')
        )[0];

        expect(selector, '옵션 라인 QuantitySelector 노드가 없다').toBeTruthy();

        const max = selector.props?.max ?? '';
        // max>0 시 Math.min(item.stock, max_purchase_qty), max=0 시 item.stock
        expect(max).toContain('max_purchase_qty');
        expect(max).toContain('Math.min(item.stock');
        expect(max).toContain('item.stock');
    });
});

describe('_purchase_card.json - 합계 버튼 한도 차단 (A25)', () => {
    const buttons = collectNodes(
        purchaseCard,
        (n) => n.name === 'Button' && typeof n.props?.disabled === 'string'
    );

    /**
     * @effects purchase_total_checked_against_purchase_limits
     */
    it('구매/장바구니 버튼이 2개 이상 존재한다', () => {
        expect(buttons.length).toBeGreaterThanOrEqual(2);
    });

    /**
     * @scenario purchase_case=multi_option_selected
     * @effects purchase_total_checked_against_purchase_limits
     */
    it('모든 구매 버튼 disabled 가 min 미달 / max 초과 합계 검증을 포함한다', () => {
        buttons.forEach((btn) => {
            const disabled = btn.props.disabled as string;
            expect(disabled).toContain('min_purchase_qty');
            expect(disabled).toContain('max_purchase_qty');
            // 라인별 max 만으로는 여러 옵션 라인을 합쳐 한도를 넘길 수 있다 — 합계(reduce)로 봐야 한다.
            expect(disabled).toContain('reduce');
            expect(disabled).toContain('selectedOptionItems');
        });
    });

    /**
     * @scenario purchase_case=single_option
     * @effects single_option_product_purchasable_without_selection
     */
    it('옵션이 없거나 하나뿐인 상품은 합계 조건에 걸리지 않는다 (옵션 2개 이상 게이트 — 수량 선택기 분기와 같은 기준)', () => {
        buttons.forEach((btn) => {
            const disabled = btn.props.disabled as string;
            expect(disabled).toContain('(product.data?.options?.length ?? 0) > 1');
            // option_groups 로 가르면 옵션 하나짜리 상품(option_groups 1개·옵션 행 1개)이 합계 0 으로 영영 잠긴다
            expect(disabled).not.toContain('option_groups');
        });
    });
});

describe('_purchase_card.json - 한도 위반 안내 문구 (A25)', () => {
    /**
     * @effects purchase_limit_notices_present
     */
    it('min/max 구매수량 안내 Span 이 존재한다', () => {
        const minNotice = findNode(
            purchaseCard,
            (n) => typeof n.text === 'string' && n.text.includes('min_purchase_qty_notice')
        );
        const maxNotice = findNode(
            purchaseCard,
            (n) => typeof n.text === 'string' && n.text.includes('max_purchase_qty_notice')
        );

        expect(minNotice, 'min_purchase_qty_notice 안내가 없다').toBeTruthy();
        expect(maxNotice, 'max_purchase_qty_notice 안내가 없다').toBeTruthy();
    });
});
