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
import purchaseCard from '../../../layouts/partials/shop/_product_purchase_card.json';

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

describe('_product_purchase_card.json - 옵션 라인 max clamp (A25)', () => {
    it('QuantitySelector 의 max 가 상품 max_purchase_qty 로 clamp 된다', () => {
        const selector = findNode(
            purchaseCard,
            (n) => n.name === 'QuantitySelector'
        );

        expect(selector, 'QuantitySelector 노드가 없다').toBeTruthy();

        const max = selector.props?.max ?? '';
        // max>0 시 Math.min(item.stock, max_purchase_qty), max=0 시 item.stock
        expect(max).toContain('max_purchase_qty');
        expect(max).toContain('Math.min(item.stock');
        expect(max).toContain('item.stock');
    });
});

describe('_product_purchase_card.json - 합계 버튼 한도 차단 (A25)', () => {
    const buttons = collectNodes(
        purchaseCard,
        (n) => n.name === 'Button' && typeof n.props?.disabled === 'string'
    );

    it('구매/장바구니 버튼이 2개 이상 존재한다', () => {
        expect(buttons.length).toBeGreaterThanOrEqual(2);
    });

    it('모든 구매 버튼 disabled 가 min 미달 / max 초과 합계 검증을 포함한다', () => {
        buttons.forEach((btn) => {
            const disabled = btn.props.disabled as string;
            expect(disabled).toContain('min_purchase_qty');
            expect(disabled).toContain('max_purchase_qty');
            expect(disabled).toContain('reduce');
        });
    });
});

describe('_product_purchase_card.json - 한도 위반 안내 문구 (A25)', () => {
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
