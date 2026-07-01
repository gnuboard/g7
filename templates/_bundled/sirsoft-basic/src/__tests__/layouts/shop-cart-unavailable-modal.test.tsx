/**
 * @file shop-cart-unavailable-modal.test.tsx
 * @description 장바구니 구매불가 모달 reason별 사유 표시 검증 (U13②/U4/A25 후속)
 *
 * 배경: 모달이 stock/status reason 만 사유 줄을 그려, A25 로 도입한 max_qty/min_qty 와
 * 구매대상제한(restricted) 항목은 상품명만 보이고 "왜 구매 불가인지"가 누락되던 결함.
 * 모든 reason 에 대응 표시 분기를 추가.
 */

import { describe, it, expect } from 'vitest';
import modal from '../../../layouts/partials/shop/_modal_cart_unavailable.json';

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

describe('_modal_cart_unavailable.json - reason별 사유 표시 (A25)', () => {
    // if 조건이 unavailableItem.reason 을 분기하는 노드들
    const reasonNodes = collectNodes(
        modal,
        (n) => typeof n.if === 'string' && n.if.includes('unavailableItem.reason')
    );

    const ifConditions = reasonNodes.map((n) => n.if as string).join(' || ');

    it.each([['stock'], ['status'], ['max_qty'], ['min_qty'], ['restricted']])(
        "reason '%s' 에 대한 표시 분기가 존재한다",
        (reason) => {
            expect(ifConditions).toContain(`'${reason}'`);
        }
    );

    it('max_qty/min_qty 표시는 limit/requested 를 치환한다', () => {
        const maxNode = reasonNodes.find((n) => (n.if as string).includes("'max_qty'"));
        const minNode = reasonNodes.find((n) => (n.if as string).includes("'min_qty'"));

        expect(maxNode?.text).toContain('limit=');
        expect(maxNode?.text).toContain('requested=');
        expect(minNode?.text).toContain('limit=');
    });
});
