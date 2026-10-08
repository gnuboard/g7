// e2e:allow 이커머스 전수 실측 후속 B — 가상계좌 입금 안내 금액은 단위 단언으로 고정하고 주문 상세 화면은 전용 Chrome 으로 재실측(이력 B5). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * KCP 가상계좌 입금 안내 — 입금 금액 = 결제예정금액 (B5 같은 결함군)
 *
 * @description
 * 마이페이지 주문 상세의 KCP 가상계좌 블록(레이아웃 확장 `user_order_show`)에는 「입금 금액」 행이 없었다(실측 S2).
 * 두 경로(확장·확장이 없는 템플릿의 주입기) 모두 입금할 금액의 기준 필드인 결제예정금액(`total_due_amount_formatted`)을
 * 읽고, 그 값이 없는 비회원 응답에서는 주문금액(`total_amount_formatted`)으로 대신한다. 입금 대기 중에는 두 값이 같다
 * (둘 다 마일리지 차감 뒤 — 실측 B5 마일리지 주문 5,000/5,000). 아래 시험의 서로 다른 두 값은 어느 필드를 읽는지 가리기 위한 값이다.
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';
import userOrderShow from '../../extensions/user_order_show.json';

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

describe('KCP 가상계좌 블록(레이아웃 확장) — 입금 금액 행', () => {
    /**
     * @scenario path=extension
     * @effects kcp_vbank_shows_due_amount
     */
    it('입금 금액 행이 있고 결제예정금액을 먼저 읽는다', () => {
        const block = collect(userOrderShow, (v) => (v.props as any)?.id === 'kcp-mp-vbank-row')[0];
        const text = JSON.stringify(block);

        expect(text).toContain('sirsoft-pay_nhnkcp.user.vbank_amount');
        expect(text).toContain('order.data.total_due_amount_formatted ?? order.data.total_amount_formatted');
    });
});

describe('KCP 주문 상세 주입기(확장 없는 템플릿) — 입금 금액', () => {
    const FLAG = '__kcpMpShowInjectorInstalled';

    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '<div id="order_payment_info_panel"><div class="space-y-3"></div></div>';
        (window as any).G7Core = {
            getState: () => ({
                currentDataContext: {
                    order: { data: { order_number: 'ORD-V', total_amount_formatted: '15,000원', total_due_amount_formatted: '12,000원', payment: { pg_provider: 'nhnkcp', payment_method: 'vbank', vbank_name: '기업은행', vbank_number: 'T0309', vbank_holder: 'NHN KCP' } } },
                },
            }),
        };
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ success: true, data: { available: false } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        history.replaceState({}, '', '/mypage/orders/ORD-V');
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        delete (window as any)[FLAG];
        delete (window as any).G7Core;
        document.body.innerHTML = '';
    });

    /**
     * @scenario path=injector
     * @effects kcp_vbank_shows_due_amount
     */
    it('결제예정금액(마일리지 차감 뒤)을 입금 금액으로 적는다', async () => {
        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(5000);

        const text = document.getElementById('kcp-mp-vbank-row')?.textContent ?? '';
        expect(text).toContain('12,000원');
        expect(text).not.toContain('15,000원');
    });
});
