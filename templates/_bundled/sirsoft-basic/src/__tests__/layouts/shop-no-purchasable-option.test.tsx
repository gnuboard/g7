/**
 * @file shop-no-purchasable-option.test.tsx
 * @description 상품 상세 구매 카드 — 구매할 수 있는 옵션이 없는 상품의 버튼 잠금·안내 검증
 *
 * 배경: 옵션 행이 하나도 없는 상품(공개 응답 `options` 가 빈 배열)에서 「바로 구매」가 활성으로
 * 보였고, 누르면 체크아웃이 서버 오류(500)로 끝났다. 서버는 이제 이런 상품의 담기·바로 구매를
 * 422 로 거절하고, 화면은 두 버튼을 잠근 뒤 이유를 안내한다.
 *
 * 판정 기준은 `options` 가 **배열이면서 비어 있을 때**다. 응답에 `options` 키가 아예 없을 때까지
 * 잠그면, 관계를 싣지 않는 다른 응답 경로에서 정상 상품의 구매까지 막히기 때문이다.
 */

import { describe, it, expect } from 'vitest';
import purchaseCard from '../../../layouts/partials/shop/detail/_purchase_card.json';

/**
 * JSON 트리에서 조건을 만족하는 모든 노드를 수집한다.
 *
 * @param obj 탐색할 노드
 * @param predicate 수집 조건
 * @param acc 누적 배열
 * @returns 조건을 만족한 노드 목록
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
 * 버튼 disabled 표현식을 주어진 문맥으로 평가한다 (엔진과 같은 `{{ }}` 한 덩어리 표현식).
 *
 * @param expression `{{...}}` 로 감싼 표현식
 * @param product 상품 응답 (`product.data` 에 들어갈 값)
 * @param local 로컬 상태
 * @returns 평가 결과
 */
function evaluateDisabled(expression: string, product: any, local: any = {}): boolean {
    const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    const fn = new Function('product', '_local', `return (${body});`);
    return Boolean(fn({ data: product }, local));
}

const NO_OPTION_GUARD = '(Array.isArray(product.data?.options) && product.data.options.length === 0)';

/**
 * 주문 권한(7.1.0 shop#3) — 상품 응답은 `abilities.can_order` 를 늘 싣고 [바로 구매]는 `can_order !== true` 면 잠긴다.
 * 이 파일은 옵션 없음 가드만 재므로 권한은 있는 상태로 둔다. 빼면 「잠긴다」 단언이 권한 게이트 때문에 공허하게 통과한다.
 */
const CAN_ORDER = { abilities: { can_order: true } };

describe('_purchase_card.json - 구매할 수 있는 옵션이 없는 상품', () => {
    const buyNow = collectNodes(purchaseCard, (n) => n.name === 'Button' && n.props?.['data-testid'] === 'buy-now')[0];
    const addToCart = collectNodes(purchaseCard, (n) => n.name === 'Button' && n.props?.['data-testid'] === 'add-to-cart')[0];
    const notice = collectNodes(purchaseCard, (n) => n.props?.['data-testid'] === 'no-purchasable-option')[0];

    /**
     * @effects purchase_buttons_disabled_without_purchasable_option
     */
    it('바로 구매·장바구니 버튼의 disabled 가 옵션 없음 가드를 포함한다', () => {
        expect(buyNow, 'buy-now 버튼이 없다').toBeTruthy();
        expect(addToCart, 'add-to-cart 버튼이 없다').toBeTruthy();
        expect(buyNow.props.disabled).toContain(NO_OPTION_GUARD);
        expect(addToCart.props.disabled).toContain(NO_OPTION_GUARD);
    });

    /**
     * @scenario options_state=empty_array
     * @effects purchase_buttons_disabled_without_purchasable_option
     */
    it('옵션이 빈 배열이면 두 버튼이 잠긴다', () => {
        const product = { options: [], option_groups: [], is_shippable_to_selected_country: true, ...CAN_ORDER };
        expect(evaluateDisabled(buyNow.props.disabled, product)).toBe(true);
        expect(evaluateDisabled(addToCart.props.disabled, product)).toBe(true);
    });

    /**
     * @scenario options_state=present
     * @effects purchase_buttons_enabled_with_purchasable_option
     */
    it('판매 중인 옵션이 하나라도 있으면 옵션 없음 가드로 잠기지 않는다 (회귀 가드)', () => {
        const product = { options: [{ id: 1 }], option_groups: [], is_shippable_to_selected_country: true, ...CAN_ORDER };
        expect(evaluateDisabled(buyNow.props.disabled, product)).toBe(false);
        expect(evaluateDisabled(addToCart.props.disabled, product)).toBe(false);
    });

    /**
     * @scenario options_state=missing_key
     * @effects missing_options_key_does_not_disable
     */
    it('응답에 options 키가 없으면 옵션 없음으로 판정하지 않는다', () => {
        const product = { option_groups: [], is_shippable_to_selected_country: true, ...CAN_ORDER };
        expect(evaluateDisabled(buyNow.props.disabled, product)).toBe(false);
        expect(evaluateDisabled(addToCart.props.disabled, product)).toBe(false);
    });

    /**
     * @scenario options_state=empty_array
     * @effects no_purchasable_option_notice_shown
     */
    it('잠긴 이유 안내가 같은 조건으로 표시된다', () => {
        expect(notice, '옵션 없음 안내 노드가 없다').toBeTruthy();
        expect(notice.if).toBe(`{{${NO_OPTION_GUARD}}}`);
        const text = collectNodes(notice, (n) => typeof n.text === 'string').map((n) => n.text).join(' ');
        expect(text).toContain('$t:shop.product.no_purchasable_option');
    });

    /**
     * @effects server_rejection_message_toasted
     */
    it('서버 거절 안내를 토스트로 보여 준다 (바로 구매·장바구니 실패 분기)', () => {
        [buyNow, addToCart].forEach((button) => {
            const toasts = collectNodes(button.actions, (n) => n.handler === 'toast' && n.params?.type === 'error');
            expect(toasts.length, '실패 토스트가 없다').toBeGreaterThan(0);
            expect(toasts.some((t) => String(t.params?.message ?? '').includes('error.message'))).toBe(true);
        });
    });
});
