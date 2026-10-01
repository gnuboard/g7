/**
 * 결제 정보 카드는 템플릿이 약속한 id 로만 찾는다 — NHN KCP (#599)
 *
 * 종전에는 id(order_payment_info_panel)가 없으면 화면 제목의 한국어 「결제 정보」로 카드를 찾았다. 제목 글자는 언어마다
 * 달라 찾는 기준이 될 수 없다(2026-09-28 결정 「id 로만 찾음」) — id 가 없는 화면에는 붙이지 않는다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';
import { resetOrderScreenPollingForTests } from '../orderScreenPolling';

beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
    localStorage.setItem('auth_token', 'member-token');
    vi.spyOn(console, 'info').mockImplementation(() => {});
    (window as any).G7Core = {
        getState: () => ({
            currentDataContext: {
                order: {
                    data: {
                        order_number: 'ORD-1',
                        payment: { pg_provider: 'nhnkcp', payment_method: 'card', transaction_id: 'T-1', paid_at: '2026-09-28 10:00:00' },
                    },
                },
            },
        }),
    };
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => new Response(
        JSON.stringify({ success: true, data: { receipt_url: 'https://receipt.example/1' } }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
    ));
    history.replaceState({}, '', '/mypage/orders/ORD-1');
});

afterEach(() => {
    // 주문 화면 한 번 조회 캐시와 폴러는 모듈 상태다 — 같은 주문 번호를 쓰는 다음 테스트가 앞 응답을 받지 않게 비운다
    resetOrderScreenPollingForTests();
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete (window as any).__kcpMpShowInjectorInstalled;
    delete (window as any).G7Core;
    document.body.innerHTML = '';
});

describe('NHN KCP — 결제 정보 카드 찾기', () => {
    /**
     * @scenario plugin=nhnkcp
     * @effects card_found_by_id_only
     */
    it('id 가 없으면 「결제 정보」 제목이 있어도 붙이지 않는다', async () => {
        document.body.innerHTML = '<div><div><h3>결제 정보</h3></div><div class="space-y-3"></div></div>';

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2500);

        expect(document.getElementById('kcp-mp-receipt-row')).toBeNull();
    });

    /**
     * @scenario plugin=nhnkcp
     * @effects card_found_by_id_only
     */
    it('id 가 있으면 그 카드 안에 붙인다', async () => {
        document.body.innerHTML = '<div id="order_payment_info_panel"><div class="space-y-3"></div></div>';

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2500);

        const row = document.getElementById('kcp-mp-receipt-row');
        expect(row).not.toBeNull();
        expect(document.getElementById('order_payment_info_panel')!.contains(row!)).toBe(true);
    });
});
