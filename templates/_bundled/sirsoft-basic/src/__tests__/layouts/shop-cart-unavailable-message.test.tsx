/**
 * @file shop-cart-unavailable-message.test.tsx
 * @description 상품 상세 담기/바로구매 실패 토스트 메시지 바인딩 검증 (U13②/U4/A25 후속)
 *
 * 배경: 판매중지·구매수량 한도 초과 담기 시 백엔드는 422 + errors.message(구체 안내)를
 * 내려보내는데, 상품 상세의 담기/바로구매 onError 토스트가 상단 generic message 만 노출해
 * "구매할 수 없는 상품이 있습니다"만 보이던 결함. error.errors?.message 우선 fallback 으로 정정.
 */

import { describe, it, expect } from 'vitest';
import detailPurchaseCard from '../../../layouts/partials/shop/detail/_purchase_card.json';
import optionPurchaseCard from '../../../layouts/partials/shop/_product_purchase_card.json';

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

/**
 * 레이아웃의 모든 error 토스트(handler=toast, type=error, message 가 error 바인딩) 수집.
 */
function errorToasts(layout: any): any[] {
    return collectNodes(
        layout,
        (n) =>
            n.handler === 'toast' &&
            n.params?.type === 'error' &&
            typeof n.params?.message === 'string' &&
            n.params.message.includes('error')
    );
}

describe('상품 상세 담기/바로구매 실패 토스트 (U13②/U4/A25)', () => {
    const detailToasts = errorToasts(detailPurchaseCard);
    const optionToasts = errorToasts(optionPurchaseCard);

    it('상세 구매 카드에 error 토스트가 존재한다', () => {
        expect(detailToasts.length).toBeGreaterThan(0);
    });

    it('옵션 상품 카드에 error 토스트가 존재한다', () => {
        expect(optionToasts.length).toBeGreaterThan(0);
    });

    it('모든 error 토스트가 error.errors?.message 를 우선 사용한다 (구체 안내)', () => {
        [...detailToasts, ...optionToasts].forEach((toast) => {
            expect(toast.params.message).toContain('error.errors?.message');
            expect(toast.params.message).toContain('error.message');
        });
    });

    it('error 토스트가 generic message 만 단독 참조하지 않는다 (회귀 가드)', () => {
        [...detailToasts, ...optionToasts].forEach((toast) => {
            expect(toast.params.message).not.toBe('{{error.message}}');
        });
    });
});
