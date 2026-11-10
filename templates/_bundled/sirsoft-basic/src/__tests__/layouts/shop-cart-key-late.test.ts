/**
 * @file shop-cart-key-late.test.ts
 * @description 장바구니 키가 없을 때 담기와 바로 구매의 요청 헤더 계약 (#869 λ 4부 16 관찰 3)
 *
 * 담기와 바로 구매가 `X-Cart-Key: {{_global.cartKey}}` 를 직접 실어, 키가 없으면(발급 실패) 헤더 값이 글자 그대로 `null` 로 나갔다
 * (동작 headers 는 빈 값을 거르지 않는다). 바탕 레이아웃 globalHeaders 가 같은 경로에 키를 싣고 빈 값은 싣지 않으며, 요청 시점의 최신 전역
 * 상태를 읽어 sequence 앞의 `ensureCartKey` 가 막 실은 키도 탄다. 그래서 동작에 직접 싣지 않는다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/browser-storage-handlers.yaml
 */

import { describe, it, expect } from 'vitest';
import purchaseCard from '../../../layouts/partials/shop/detail/_purchase_card.json';
import userBase from '../../../layouts/_user_base.json';

/**
 * JSON 트리에서 조건을 만족하는 모든 노드를 모은다.
 *
 * @param obj 트리
 * @param predicate 조건
 * @param acc 모은 노드
 * @returns 모은 노드
 */
function collectNodes(obj: any, predicate: (node: any) => boolean, acc: any[] = []): any[] {
    if (!obj || typeof obj !== 'object') {
        return acc;
    }
    if (predicate(obj)) {
        acc.push(obj);
    }
    for (const value of Object.values(obj)) {
        if (value && typeof value === 'object') {
            collectNodes(value, predicate, acc);
        }
    }

    return acc;
}

describe('장바구니 키가 없을 때의 요청 헤더 (#869 λ 4부 16)', () => {
    // @scenario storage=cart_key
    // @effects no_literal_null_cart_key_header
    it('관찰 3 — 담기와 바로 구매는 X-Cart-Key 를 직접 싣지 않고, 바탕 레이아웃 globalHeaders 가 같은 경로에 싣는다', () => {
        const calls = collectNodes(purchaseCard, (n) => n.handler === 'apiCall'
            && typeof n.target === 'string'
            && /\/api\/modules\/sirsoft-ecommerce\/(cart|checkout)$/.test(n.target));

        expect(calls.length).toBeGreaterThanOrEqual(2);
        calls.forEach((call) => {
            expect(call.params?.headers?.['X-Cart-Key']).toBeUndefined();
        });
        const rule = (userBase as any).globalHeaders.find((r: any) => r.pattern === '/api/modules/sirsoft-ecommerce/*');
        expect(rule?.headers?.['X-Cart-Key']).toBe('{{_global.cartKey}}');
    });
});
