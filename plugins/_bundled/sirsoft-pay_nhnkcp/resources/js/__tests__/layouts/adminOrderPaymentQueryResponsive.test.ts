/**
 * @file adminOrderPaymentQueryResponsive.test.ts
 * @description 관리자 주문 상세 결제 조회 조각 — 카드 머리 설명이 640px 미만에서 숨고 640px 이상에서 보이는지
 *
 * 화면 폭 분기는 엔진 responsive 로 쓴다. 확장 유틸리티는 화면에 실릴 때 층 없이 실려 의미 클래스 칸
 * (`@layer g7-extension`)의 `pay-nhnkcp-hidden` 을 이긴다. 그래서 640px 이상 갈래에 `inline` 이 있어야 설명이 보인다.
 * 이 갈래에서 `inline` 이 빠지면 넓은 화면에서도 설명이 숨는다.
 *
 * @scenario-manifest tests/scenarios/admin-order-payment-query-responsive.yaml
 */

import { describe, it, expect } from 'vitest';
import fragment from '../../../extensions/admin_order_payment_query.json';

/** 확장 유틸리티 접두 */
const P = 'paynhnkcp';
/** 의미 클래스 접두 */
const NS = 'pay-nhnkcp-';

type LayoutNode = {
    name?: string;
    text?: string;
    props?: { className?: string };
    responsive?: Record<string, { props?: { className?: string } }>;
};

/**
 * 레이아웃 트리의 모든 노드를 펼친다.
 *
 * @param node 시작 노드(배열·객체 아무거나)
 * @returns 노드 목록
 */
function flatten(node: unknown): LayoutNode[] {
    const out: LayoutNode[] = [];
    const stack: unknown[] = [node];
    while (stack.length) {
        const cur = stack.pop();
        if (Array.isArray(cur)) {
            stack.push(...cur);
            continue;
        }
        if (!cur || typeof cur !== 'object') continue;
        out.push(cur as LayoutNode);
        stack.push(...Object.values(cur as Record<string, unknown>));
    }
    return out;
}

const tokens = (c: unknown): string[] => (typeof c === 'string' ? c.split(/\s+/) : []);

/** 카드 머리 설명 — 의미 클래스 hidden 을 단 `*_description` 글 */
const descriptions = flatten(fragment).filter(
    (n) =>
        typeof n.name === 'string' &&
        typeof n.text === 'string' &&
        n.text.endsWith('_description') &&
        tokens(n.props?.className).includes(`${NS}hidden`),
);

describe('NHN KCP 관리자 결제 조회 조각 — 카드 머리 설명의 폭 분기', () => {
    /**
     * @scenario plugin=sirsoft-pay_nhnkcp, width=narrow
     *
     * @effects card_description_hidden_below_640
     */
    it('640px 미만(기본 갈래)에서는 카드 머리 설명을 숨긴다', () => {
        expect(descriptions.map((n) => n.text).sort()).toEqual([
            '$t:sirsoft-pay_nhnkcp.admin.escrow_delivery_description',
            '$t:sirsoft-pay_nhnkcp.admin.order_info_description',
        ]);
        for (const n of descriptions) {
            expect({ text: n.text, hidden: tokens(n.props?.className).includes(`${P}:hidden`) }).toEqual({ text: n.text, hidden: true });
        }
    });

    /**
     * @scenario plugin=sirsoft-pay_nhnkcp, width=wide
     *
     * @effects card_description_inline_from_640
     */
    it('640px 이상 갈래는 확장 유틸리티 inline 으로 설명을 보인다', () => {
        expect(descriptions.length).toBeGreaterThan(0);
        for (const n of descriptions) {
            const wide = tokens(n.responsive?.['640-']?.props?.className);
            expect({ text: n.text, inline: wide.includes(`${P}:inline`) }).toEqual({ text: n.text, inline: true });
        }
    });
});
