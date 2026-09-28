/**
 * 마이페이지 주문·신청서 상세 주입 스크립트의 경로 매칭 (NHN KCP)
 *
 * 결제 정보 카드를 쓰는 화면은 넷이다 — 회원 주문 상세(/mypage/orders/{N}), 비회원 주문
 * 상세(/shop/guest/orders/{N}), 회원 신청서 상세(/mypage/claims/{N}), 비회원 신청서
 * 상세(/shop/guest/claims/{N}). 주입 스크립트가 보지 않는 주소에서는 영수증 버튼·가상계좌
 * 안내가 그 화면에서만 조용히 빠진다(오류·로그 없음). 신청서 상세는 주소가 클레임 번호라
 * 주문번호를 화면 상태에서 가져오고, 비회원은 비회원 주문 조회 토큰을 헤더에 싣는다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';

const FLAG = '__kcpMpShowInjectorInstalled';
const ROW_ID = 'kcp-mp-receipt-row';
const RECEIPT_URL = '/api/plugins/sirsoft-pay_nhnkcp/user/orders/ORD-1/receipt';

function seedOrderState(orderNumber = 'ORD-1'): void {
    (window as any).G7Core = {
        getState: () => ({
            currentDataContext: {
                order: {
                    data: {
                        order_number: orderNumber,
                        payment: {
                            pg_provider: 'nhnkcp',
                            payment_method: 'card',
                            transaction_id: 'T-1',
                            paid_at: '2026-09-06 10:00:00',
                        },
                    },
                },
            },
        }),
    };
}

function mountPaymentPanel(): void {
    document.body.innerHTML = '<div id="order_payment_info_panel"><div class="space-y-3"></div></div>';
}

/** 주소를 세우고 스크립트를 설치한 뒤 설치 지연(1500ms) + 폴링(400ms) 을 흘려보낸다. */
async function installAt(path: string) {
    history.replaceState({}, '', path);
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        // 영수증 조회 응답은 공통 형식({ success, message, data })이다.
        new Response(JSON.stringify({ success: true, data: { receipt_url: 'https://receipt.example/1' } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }),
    );

    installMypageOrderShowInjector();
    await vi.advanceTimersByTimeAsync(2500);

    return fetchSpy;
}

beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
    sessionStorage.clear();
    mountPaymentPanel();
    seedOrderState();
    vi.spyOn(console, 'info').mockImplementation(() => {});
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete (window as any)[FLAG];
    delete (window as any).G7Core;
    document.body.innerHTML = '';
});

describe('NHN KCP mypage order show injector — 경로 매칭', () => {
    it('비회원 신청서 상세(/shop/guest/claims/{N})에서 화면 상태의 주문번호로 영수증을 조회하고 비회원 토큰을 싣는다', async () => {
        sessionStorage.setItem('g7_guest_order_token', 'guest-token');

        const fetchSpy = await installAt('/shop/guest/claims/CL-2026-0001');

        expect(fetchSpy).toHaveBeenCalledWith(
            RECEIPT_URL,
            expect.objectContaining({
                headers: expect.objectContaining({ 'X-Guest-Order-Token': 'guest-token' }),
            }),
        );
        expect(document.getElementById(ROW_ID)).not.toBeNull();
    });

    it('회원 신청서 상세(/mypage/claims/{N})에서 화면 상태의 주문번호로 영수증을 조회하고 회원 토큰을 싣는다', async () => {
        localStorage.setItem('auth_token', 'member-token');

        const fetchSpy = await installAt('/mypage/claims/CL-2026-0001');

        expect(fetchSpy).toHaveBeenCalledWith(
            RECEIPT_URL,
            expect.objectContaining({
                headers: expect.objectContaining({ Authorization: 'Bearer member-token' }),
            }),
        );
        expect(document.getElementById(ROW_ID)).not.toBeNull();
    });

    it('비회원 주문 상세(/shop/guest/orders/{N})는 주소의 주문번호를 쓴다', async () => {
        sessionStorage.setItem('g7_guest_order_token', 'guest-token');

        const fetchSpy = await installAt('/shop/guest/orders/ORD-1');

        expect(fetchSpy).toHaveBeenCalledWith(RECEIPT_URL, expect.anything());
    });

    it('신청서 상세가 아닌 신청 내역 목록(/mypage/claims)에서는 요청을 보내지 않는다', async () => {
        localStorage.setItem('auth_token', 'member-token');

        const fetchSpy = await installAt('/mypage/claims');

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(document.getElementById(ROW_ID)).toBeNull();
    });

    it('신청서 상세에서 주소의 클레임 번호를 주문번호로 오용하지 않는다 — 화면 상태의 주문번호만 요청에 쓴다', async () => {
        localStorage.setItem('auth_token', 'member-token');

        const fetchSpy = await installAt('/mypage/claims/CL-2026-0001');

        const urls = fetchSpy.mock.calls.map(([url]) => String(url));
        expect(urls.some(u => u.includes('CL-2026-0001'))).toBe(false);
        expect(urls).toContain(RECEIPT_URL);
    });
});
