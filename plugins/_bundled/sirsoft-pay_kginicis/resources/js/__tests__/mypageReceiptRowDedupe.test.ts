/**
 * 주문 상세 결제 정보 카드의 KG이니시스 영수증 줄이 하나만 보이는지 검증한다 (이커머스 전수 실측 S4 — S4-D16)
 *
 * 결함: 결제를 마친 KRW 주문 상세(회원·비회원)에서 확장 조각(user_order_show)이 「영수증 [영수증 조회]」 줄을 그리고,
 *   주입기(mypageOrderShowInjector)도 같은 카드에 「영수증 [영수증 조회]」 줄을 하나 더 붙였다. 주입기 줄은 언어 헤더 없는
 *   조회 응답의 글자를 써서 한국어·일본어 화면에도 「Receipt View Receipt」 로 보였다.
 * 조각이 그리는 주문 상세에서는 주입기가 줄을 붙이지 않는다.
 * 후속(S4 마감 검증): 조각은 레이아웃 이름이 아니라 결제 정보 카드의 공용 슬롯을 겨냥해, 같은 카드를 쓰는 신청서 상세에도
 *   영수증 줄을 그린다. 주입기가 「조각이 없는 신청서 상세」 로 보고 줄을 붙이면 조각보다 먼저 돈 경우 두 줄이 된다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';
import userOrderShow from '../../extensions/user_order_show.json';

const FLAG = '__kginicisOrderShowInjectorInstalled';
const ROW_ID = 'kginicis-mp-receipt-row';

function setPaymentPanel(): void {
    document.body.innerHTML = `
        <section id="order_payment_info_panel">
            <div class="space-y-2">
                <div id="order_payment_method_row" class="flex items-center justify-between">
                    <span>결제 방법</span>
                    <span>가상계좌</span>
                </div>
            </div>
        </section>
    `;
}

function setOrderState(data: Record<string, unknown>): void {
    (window as any).G7Core = {
        t: (key: string) => key,
        getState: () => ({ currentDataContext: { order: { data } } }),
    };
}

/** 결제를 마친 KRW 가상계좌 주문 — 확장 조각의 영수증 블록 조건(kginicis·JPY 아님·거래번호·결제일시)을 채운다 */
const PAID_KRW_ORDER = {
    id: 390,
    order_number: '20261007-0337110194',
    payment: {
        pg_provider: 'kginicis',
        payment_status: 'paid',
        payment_method: 'vbank',
        currency: 'KRW',
        transaction_id: 'StdpayVBNKINIpayTest20261007123849384732',
        paid_at: '2026-10-07 15:24:42',
    },
};

/** 트리에서 조건에 맞는 노드를 모은다 */
function collect(node: unknown, match: (value: Record<string, unknown>) => boolean, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, match, out));

        return out;
    }
    if (node === null || typeof node !== 'object') {
        return out;
    }
    const value = node as Record<string, unknown>;
    if (match(value)) {
        out.push(value);
    }
    Object.values(value).forEach((child) => collect(child, match, out));

    return out;
}

describe('주문 상세 KG이니시스 영수증 줄 — 하나만', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        localStorage.clear();
        sessionStorage.clear();
        vi.useFakeTimers();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.innerHTML = '';
        delete (window as Record<string, unknown>)[FLAG];
        delete (window as any).G7Core;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    /**
     * @scenario case=fragment_contract
     * @effects fragment_receipt_row_carries_injector_id
     */
    it('확장 조각의 영수증 줄은 주입기가 찾는 id(kginicis-mp-receipt-row)를 단다', () => {
        const rows = collect(userOrderShow, (value) => (value.props as Record<string, unknown> | undefined)?.id === ROW_ID);
        expect(rows.length).toBe(1);
        expect(JSON.stringify(rows[0])).toContain('$t:sirsoft-pay_kginicis.user.receipt_view');
    });

    /**
     * @scenario case=order_show_paid_krw
     * @effects injector_skips_row_when_fragment_draws_it
     */
    it('결제를 마친 KRW 주문 상세에서는 조각이 아직 그려지지 않았어도 주입기가 영수증 줄을 붙이지 않는다', async () => {
        history.pushState(null, '', '/shop/guest/orders/20261007-0337110194');
        setOrderState(PAID_KRW_ORDER);
        setPaymentPanel();
        globalThis.fetch = vi.fn(async () => new Response(JSON.stringify({
            success: true,
            data: { receipt_url: 'https://iniweb.inicis.com/receipt/iniReceipt.jsp?tid=x', receipt_label: 'Receipt', receipt_view_label: 'View Receipt' },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } })) as unknown as typeof fetch;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(16000);

        expect(document.querySelectorAll('[data-kginicis-receipt-row]').length).toBe(0);
        expect(document.getElementById(ROW_ID)).toBeNull();
    });

    /**
     * @scenario case=claim_show_paid_krw
     * @effects injector_skips_row_on_claim_show_too
     */
    it('신청서 상세도 조각이 같은 카드에 영수증 줄을 그리므로 주입기는 붙이지 않는다', async () => {
        history.pushState(null, '', '/mypage/claims/RF20261007-0000000001');
        setOrderState(PAID_KRW_ORDER);
        setPaymentPanel();
        globalThis.fetch = vi.fn(async () => new Response(JSON.stringify({
            success: true,
            data: { receipt_url: 'https://iniweb.inicis.com/receipt/iniReceipt.jsp?tid=x', receipt_label: '영수증', receipt_view_label: '영수증 조회' },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } })) as unknown as typeof fetch;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(16000);

        expect(document.querySelectorAll('[data-kginicis-receipt-row]').length).toBe(0);
    });
});
