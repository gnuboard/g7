/**
 * @file shop-single-option-purchase.test.tsx
 * @description 상품 상세 구매 카드 — 옵션이 하나뿐인 상품도 바로 구매·장바구니 담기가 된다
 *
 * 배경: 관리자에서 옵션을 하나만 만든 상품은 `has_options=1`·`option_groups=[{기본:[기본]}]`·옵션 행 1개가 된다.
 * 화면은 옵션 칸 대신 수량 선택기를 세우고(`options.length <= 1`) 수량을 `noOptionQuantity` 로만 세는데,
 * 두 버튼의 잠금은 `option_groups` 가 있으면 옵션 줄(`selectedOptionItems`) 합계로 최소 구매수량을 판정해
 * 합계 0 으로 영영 잠겼다. 잠금 판정을 화면 분기와 같은 기준(옵션 2개 이상)으로 맞춘다 — 옵션 하나뿐인
 * 상품의 최소·최대는 수량 선택기의 min/max 가 막는다.
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

/** 관리자에서 옵션 하나로 만든 상품 (개발 DB 상품 50 과 같은 모양) */
const SINGLE_OPTION_PRODUCT = {
    id: 50,
    sales_status: 'on_sale',
    has_options: true,
    option_groups: [{ name: '기본', values: ['기본'] }],
    options: [{ id: 237, stock_quantity: 9 }],
    min_purchase_qty: 1,
    max_purchase_qty: 0,
    stock_quantity: 9,
    // 주문 권한(7.1.0 shop#3) — 상품 응답은 이 키를 늘 싣고 [바로 구매]는 `can_order !== true` 면 잠긴다.
    // 이 파일은 옵션 축만 재므로 권한은 있는 상태로 둔다(권한 게이트 계약은 이커머스 모듈 테스트가 잰다).
    abilities: { can_order: true },
};

/** 옵션이 둘인 상품 */
const MULTI_OPTION_PRODUCT = {
    ...SINGLE_OPTION_PRODUCT,
    option_groups: [{ name: '색상', values: ['빨강', '파랑'] }],
    options: [{ id: 1, stock_quantity: 5 }, { id: 2, stock_quantity: 5 }],
};

describe('_purchase_card.json - 옵션이 하나뿐인 상품의 구매 버튼', () => {
    const buttons = ['buy-now', 'add-to-cart'].map((testId) => ({
        testId,
        node: collectNodes(purchaseCard, (n) => n.name === 'Button' && n.props?.['data-testid'] === testId)[0],
    }));

    /**
     * @scenario purchase_case=single_option
     * @effects single_option_product_purchasable_without_selection
     */
    it.each(buttons)('$testId: 옵션 하나뿐인 상품은 옵션을 고르지 않아도 잠기지 않는다', ({ node }) => {
        expect(node, '버튼 노드가 없다').toBeTruthy();
        expect(evaluateDisabled(node.props.disabled, SINGLE_OPTION_PRODUCT, { noOptionQuantity: 1 })).toBe(false);
    });

    /**
     * @scenario purchase_case=multi_option_unselected
     * @scenario purchase_case=multi_option_selected
     * @effects multi_option_product_locked_until_selection
     */
    it.each(buttons)('$testId: 옵션이 둘 이상이면 옵션을 고르기 전까지 잠긴다 (회귀 가드)', ({ node }) => {
        expect(evaluateDisabled(node.props.disabled, MULTI_OPTION_PRODUCT, {})).toBe(true);
        expect(
            evaluateDisabled(node.props.disabled, MULTI_OPTION_PRODUCT, { selectedOptionItems: [{ optionId: 1, quantity: 1 }] })
        ).toBe(false);
    });

    /**
     * @scenario purchase_case=single_option
     * @effects quantity_selector_guards_single_option_limits
     */
    it('옵션 하나뿐인 상품은 수량 선택기가 서고, 그 min/max 가 구매 한도를 막는다', () => {
        const selector = collectNodes(
            purchaseCard,
            (n) => n.name === 'QuantitySelector' && String(n.props?.value ?? '').includes('noOptionQuantity')
        )[0];
        expect(selector, '옵션 없는 상품용 수량 선택기가 없다').toBeTruthy();
        expect(String(selector.props.min)).toContain('min_purchase_qty');
        expect(String(selector.props.max)).toContain('max_purchase_qty');
    });
});
