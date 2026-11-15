/**
 * @file shop-buy-now-direct-checkout.test.tsx
 * @description 상품 상세 "바로 구매" 장바구니 미경유 검증 (직접 항목 체크아웃)
 *
 * 배경: "바로 구매"가 먼저 POST /cart 로 장바구니에 담은 뒤 체크아웃해, 장바구니 기존
 * 동일 상품 수량과 합산되어 한도 초과 차단되거나 장바구니가 오염되던 결함.
 * direct_items 로 장바구니를 경유하지 않고 바로 체크아웃하도록 변경.
 *
 * @scenario-manifest tests/scenarios/shop-buy-now-direct-checkout.yaml
 */

import { describe, it, expect } from 'vitest';
// 담기·바로구매·주문하기는 화면의 이름 붙은 액션이고 옵션 영역은 조각이다 — 엔진이 조립하듯 그 자리에 펼쳐서 본다
import { inlineNamedActions } from '../../../__tests__/helpers/inlineNamedActions';
import purchaseCardRaw from '../../../layouts/partials/shop/detail/_purchase_card.json';
const purchaseCard = inlineNamedActions(purchaseCardRaw);

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

describe('_purchase_card.json - 바로 구매 장바구니 미경유', () => {
    // "바로 구매" 버튼 노드 (text = $t:shop.product.buy_now 자식 Span 보유) 의 액션 서브트리만 검사
    const buyNowButton = collectNodes(
        purchaseCard,
        (n) =>
            n.name === 'Button' &&
            Array.isArray(n.actions) &&
            JSON.stringify(n.children ?? []).includes('shop.product.buy_now')
    )[0];

    const buyNowApiCalls = buyNowButton
        ? collectNodes(buyNowButton.actions, (n) => n.handler === 'apiCall')
        : [];

    // @scenario case=c01
    // @effects buy_now_skips_cart
    it('바로 구매 버튼이 존재한다', () => {
        expect(buyNowButton, '바로 구매 버튼을 찾지 못함').toBeTruthy();
    });

    // @scenario case=c02
    // @effects buy_now_skips_cart
    it('바로 구매 흐름이 체크아웃을 호출한다', () => {
        const hasCheckout = buyNowApiCalls.some(
            (n) => typeof n.target === 'string' && n.target.includes('/checkout')
        );
        expect(hasCheckout).toBe(true);
    });

    // @scenario case=c03
    // @effects buy_now_skips_cart
    it('바로 구매 흐름이 장바구니 담기(POST /cart)를 거치지 않는다 (회귀 가드)', () => {
        const cartPosts = buyNowApiCalls.filter(
            (n) =>
                typeof n.target === 'string' &&
                /\/cart($|[^a-z])/.test(n.target) &&
                n.params?.method === 'POST'
        );
        expect(cartPosts.length).toBe(0);
    });

    // @scenario case=c04
    // @effects buy_now_skips_cart
    it('바로 구매 체크아웃 body 가 direct_items 를 전달한다', () => {
        const usesDirectItems = buyNowApiCalls.some((n) => {
            const body = n.params?.body;
            return (
                typeof n.target === 'string' &&
                n.target.includes('/checkout') &&
                typeof body === 'string' &&
                body.includes('direct_items')
            );
        });
        expect(usesDirectItems).toBe(true);
    });

    // @scenario case=c05
    // @effects buy_now_skips_cart
    it('바로 구매 direct_items 가 옵션을 product_option_id(옵션 ID)로 전달한다 (값 기반 option_values 미사용)', () => {
        const checkoutBody = buyNowApiCalls
            .map((n) => n.params?.body)
            .find((body) => typeof body === 'string' && body.includes('direct_items')) as
            | string
            | undefined;
        expect(checkoutBody, '체크아웃 direct_items body 를 찾지 못함').toBeTruthy();
        // 옵션 식별은 옵션 ID 기반이어야 하며, 로케일 값 조합(option_values) 을 실어보내지 않는다
        expect(checkoutBody).toContain('product_option_id: item.optionId');
        expect(checkoutBody).not.toContain('option_values');
    });
});
