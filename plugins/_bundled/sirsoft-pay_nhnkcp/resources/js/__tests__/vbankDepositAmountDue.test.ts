// e2e:allow 이커머스 전수 실측 후속 B — 가상계좌 입금 안내 금액은 단위 단언으로 고정하고 주문 상세 화면은 전용 Chrome 으로 재실측(이력 B5). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * KCP 가상계좌 입금 안내 — 입금 금액 = 결제예정금액 (B5 같은 결함군)
 *
 * @description
 * 마이페이지 주문 상세의 KCP 가상계좌 블록(레이아웃 확장 `user_order_show`)에는 「입금 금액」 행이 없었다(실측 S2).
 * 입금할 금액의 기준 필드인 결제예정금액(`total_due_amount_formatted`)을 읽고, 그 값이 없는 비회원 응답에서는
 * 주문금액(`total_amount_formatted`)으로 대신한다. 입금 대기 중에는 두 값이 같다(둘 다 마일리지 차감 뒤 — 실측 B5
 * 마일리지 주문 5,000/5,000). 아래 시험의 서로 다른 두 값은 어느 필드를 읽는지 가리기 위한 값이다.
 * 입금 안내는 결제 정보 카드의 조각이 그린다(#639 에서 주입 스크립트를 조각으로 옮김).
 */

import { afterEach, describe, expect, it } from 'vitest';
import userOrderShow from '../../extensions/user_order_show.json';
import { cleanupRender, kcpVbankOrder, renderUserOrderShow, type LayoutTest } from './layouts/userOrderShowKit';

/**
 * 조건에 맞는 노드를 트리에서 모두 모은다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @param out 결과 누적
 * @returns 모인 노드
 */
function collect(node: unknown, match: (value: Record<string, unknown>) => boolean, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, match, out));

        return out;
    }
    if (node === null || typeof node !== 'object') return out;
    const value = node as Record<string, unknown>;
    if (match(value)) out.push(value);
    Object.values(value).forEach((child) => collect(child, match, out));

    return out;
}

let utils: LayoutTest | null = null;

afterEach(() => {
    cleanupRender(utils);
    utils = null;
});

describe('KCP 가상계좌 블록(레이아웃 확장) — 입금 금액 행', () => {
    /**
     * @scenario case=fragment_contract
     * @effects kcp_vbank_shows_due_amount
     */
    it('입금 금액 행이 있고 결제예정금액을 먼저 읽는다', () => {
        const block = collect(userOrderShow, (v) => (v.props as any)?.id === 'kcp-mp-vbank-row')[0];
        const text = JSON.stringify(block);

        expect(text).toContain('sirsoft-pay_nhnkcp.user.vbank_amount');
        expect(text).toContain('order.data.total_due_amount_formatted ?? order.data.total_amount_formatted');
    });

    /**
     * @scenario case=member_with_mileage
     * @effects kcp_vbank_shows_due_amount
     */
    it('회원 주문은 결제예정금액(마일리지 차감 뒤)을 입금 금액으로 적는다', async () => {
        utils = await renderUserOrderShow(kcpVbankOrder({}, { total_due_amount_formatted: '12,000원' }));

        const text = document.getElementById('kcp-mp-vbank-row')?.textContent ?? '';
        expect(text).toContain('입금 금액');
        expect(text).toContain('12,000원');
        expect(text).not.toContain('15,000원');
    });

    /**
     * @scenario case=guest_without_due
     * @effects kcp_vbank_shows_due_amount
     */
    it('비회원 주문은 결제예정금액이 없어 주문금액을 입금 금액으로 적는다', async () => {
        utils = await renderUserOrderShow(kcpVbankOrder());

        const text = document.getElementById('kcp-mp-vbank-row')?.textContent ?? '';
        expect(text).toContain('입금 금액');
        expect(text).toContain('15,000원');
    });
});
