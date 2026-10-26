/**
 * @file zeroRatedTaxBadges.test.tsx
 * @description 사용자 화면의 면세·영세 표시 (#612)
 *
 * 사용자 화면은 과세는 표시하지 않고 면세·영세만 보인다.
 * - 주문상세 품목(회원·비회원 공용 `_items`): 품목 `tax_status` 가 면세·영세일 때만 배지.
 * - 상품 상세 가격 옆: 모든 옵션의 실효 과세 종류(옵션 지정 ?? 상품 기본값)가 같은 면세·영세일 때만.
 * - 상품 상세 「선택한 상품」: 고른 옵션의 실효 과세 종류가 면세·영세면.
 * - 주문서·장바구니 세금 상세: 영세 금액이 있으면 「영세 금액」 줄.
 *
 * 레이아웃 JSON 의 실제 `if` 식을 꺼내 템플릿 엔진으로 렌더해 평가한다(식을 테스트에 옮겨 적지 않는다).
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createLayoutTest, screen } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';
import itemsJson from '../../../layouts/partials/mypage/orders/_items.json';
import priceMobileJson from '../../../layouts/partials/shop/detail/_price_mobile.json';
import infoSummaryJson from '../../../layouts/partials/shop/detail/_info_summary.json';
import purchaseCardJson from '../../../layouts/partials/shop/detail/_purchase_card.json';
import checkoutSummaryJson from '../../../layouts/partials/shop/_checkout_summary.json';
import cartSummaryJson from '../../../layouts/partials/shop/_cart_summary.json';

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
    <div className={className} data-testid={testId}>{children}</div>
);
const TestSpan: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
    <span className={className} data-testid={testId}>{children || text}</span>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 객체 트리에서 술어를 만족하는 첫 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @returns 찾은 노드 (없으면 null)
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
    if (node == null || typeof node !== 'object') return null;
    if (predicate(node)) return node;
    for (const value of Object.values(node)) {
        const list = Array.isArray(value) ? value : [value];
        for (const item of list) {
            const hit = item && typeof item === 'object' ? findNode(item, predicate) : null;
            if (hit) return hit;
        }
    }

    return null;
}

/**
 * 레이아웃에서 문자열을 품은 `if` 식을 꺼낸다(없으면 테스트가 레이아웃 변경을 알린다).
 *
 * @param layout 레이아웃 JSON
 * @param needle `if` 식에 들어 있어야 할 글자
 * @returns `if` 식
 */
function ifContaining(layout: any, needle: string): string {
    const node = findNode(layout, (n) => typeof n.if === 'string' && n.if.includes(needle));
    if (!node) throw new Error(`if 식을 찾지 못함: ${needle}`);

    return node.if as string;
}

/**
 * 주어진 `if` 식으로 표식 Span 하나를 그리는 탐침 레이아웃.
 *
 * @param condition `if` 식
 * @param iterate 반복 원본 식 (없으면 반복 없음)
 * @returns 레이아웃
 */
function probe(condition: string, iterate?: { source: string; item_var: string }): any {
    const span = { type: 'basic', name: 'Span', if: condition, props: { 'data-testid': 'probe' }, text: 'shown' };

    return {
        version: '1.0.0',
        layout_name: 'test/zero-rated-probe',
        data_sources: [{ id: 'src', type: 'api', endpoint: '/api/test/src', method: 'GET', auto_fetch: true }],
        components: [
            iterate
                ? { type: 'basic', name: 'Div', iteration: iterate, children: [span] }
                : { type: 'basic', name: 'Div', children: [span] },
        ],
    };
}

/**
 * 탐침을 렌더해 표식이 보이는 개수를 센다.
 *
 * @param layout 탐침 레이아웃
 * @param data 데이터 소스 응답
 * @param initLocal 초기 _local
 * @returns 보인 표식 개수
 */
async function shownCount(layout: any, data: Record<string, unknown>, initLocal: Record<string, unknown> = {}): Promise<number> {
    const t = createLayoutTest(layout, { initialState: { _local: initLocal } });
    t.mockApi('src', { response: data });
    await t.render();
    const count = screen.queryAllByTestId('probe').length;
    t.cleanup();

    return count;
}

describe('사용자 화면 면세·영세 표시 (#612)', () => {
    beforeEach(() => {
        const registry = ComponentRegistry.getInstance();
        (registry as any).registry = {
            Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
            Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
            Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
        };
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    // @scenario surface=order_items
    // @effects order_item_badge_only_for_tax_free_or_zero_rated
    it('주문상세 품목 배지는 면세·영세만 보이고 과세는 보이지 않는다', async () => {
        const cond = ifContaining(itemsJson, "item.tax_status === 'zero_rated'");
        const layout = probe(cond, { source: '{{src.data.options ?? []}}', item_var: 'item' });
        const options = [{ tax_status: 'taxable' }, { tax_status: 'tax_free' }, { tax_status: 'zero_rated' }];

        expect(await shownCount(layout, { data: { options } })).toBe(2);
    });

    // @scenario surface=product_detail
    // @effects product_price_badge_when_all_options_share_tax_free_or_zero_rated
    it('상품 가격 옆 배지는 모든 옵션의 실효 과세 종류가 같은 면세·영세일 때만 보인다', async () => {
        for (const layoutJson of [priceMobileJson, infoSummaryJson]) {
            const cond = ifContaining(layoutJson, "['tax_free', 'zero_rated'].includes(").replace(/product\.data/g, 'src.data');
            const layout = probe(cond);

            // 상품 영세, 옵션 둘 다 따름 → 보임
            expect(await shownCount(layout, { data: { tax_status: 'zero_rated', options: [{ tax_status: null }, { tax_status: null }] } })).toBe(1);
            // 상품 과세지만 옵션 하나뿐이고 영세로 지정 → 보임
            expect(await shownCount(layout, { data: { tax_status: 'taxable', options: [{ tax_status: 'zero_rated' }] } })).toBe(1);
            // 옵션마다 다름 → 가격 옆에는 안 보임(고른 항목에 보인다)
            expect(await shownCount(layout, { data: { tax_status: 'taxable', options: [{ tax_status: null }, { tax_status: 'zero_rated' }] } })).toBe(0);
            // 전부 과세 → 안 보임
            expect(await shownCount(layout, { data: { tax_status: 'taxable', options: [{ tax_status: null }] } })).toBe(0);
        }
    });

    // @scenario surface=product_detail
    // @effects selected_option_badge_follows_effective_tax_status
    it('선택한 상품 줄은 옵션마다 과세 종류가 다를 때 고른 옵션의 실효 과세 종류(옵션 ?? 상품)가 면세·영세면 배지를 보인다', async () => {
        const cond = ifContaining(purchaseCardJson, 'item.optionId').replace(/product\.data/g, 'src.data');
        const layout = probe(cond, { source: '{{_local.selectedOptionItems ?? []}}', item_var: 'item' });
        const data = { data: { tax_status: 'taxable', options: [{ id: 1, tax_status: null }, { id: 2, tax_status: 'zero_rated' }, { id: 3, tax_status: 'tax_free' }] } };

        expect(await shownCount(layout, data, { selectedOptionItems: [{ optionId: 1 }, { optionId: 2 }, { optionId: 3 }] })).toBe(2);

        // 모든 옵션이 같은 영세면 가격 옆에 이미 보이므로 선택 줄에는 다시 붙이지 않는다
        const uniform = { data: { tax_status: 'zero_rated', options: [{ id: 1, tax_status: null }, { id: 2, tax_status: null }] } };
        expect(await shownCount(layout, uniform, { selectedOptionItems: [{ optionId: 1 }, { optionId: 2 }] })).toBe(0);
    });

    // @scenario surface=checkout_cart_summary
    // @effects summary_zero_rated_line_when_present
    it('주문서·장바구니 세금 상세의 영세 금액 줄은 영세 금액이 있을 때만 보인다', async () => {
        for (const [layoutJson, root] of [[checkoutSummaryJson, 'checkoutData'], [cartSummaryJson, 'cartItems']] as const) {
            const cond = ifContaining(layoutJson, 'summary?.zero_rated_amount').replace(new RegExp(root, 'g'), 'src');
            const layout = probe(cond);

            expect(await shownCount(layout, { data: { calculation: { summary: { zero_rated_amount: 13000 } } } })).toBe(1);
            expect(await shownCount(layout, { data: { calculation: { summary: { zero_rated_amount: 0 } } } })).toBe(0);
        }
    });
});
