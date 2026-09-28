/**
 * KG이니시스 영수증 버튼의 「로딩 중...」은 번역 키로 낸다 (#599)
 *
 * 마이페이지 주문 상세 영수증 버튼을 누르면 조회하는 동안 버튼 글자가 「로딩 중...」으로 바뀌는데, 이 글자만 한국어로 박혀 있어
 * 영어·일본어 화면에서도 한국어로 나왔다(PO 결정 2026-09-28 「모두 번역 키로」). 이제 user.loading 번역 키를 쓴다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';

describe('KG이니시스 — 영수증 버튼 로딩 글자', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        localStorage.setItem('auth_token', 'member-token');
        history.pushState(null, '', '/mypage/orders/ORD-LD-1');
        (window as any).G7Core = {
            t: (key: string) => (key === 'sirsoft-pay_kginicis.user.loading' ? '読み込み中...' : key),
            getState: () => ({ currentDataContext: { order: { data: {
                order_number: 'ORD-LD-1',
                payment: { pg_provider: 'kginicis', payment_status: 'paid', transaction_id: 'T-1' },
            } } } }),
        };
        document.body.innerHTML = '<section id="order_payment_info_panel"><div class="space-y-2"></div></section>';
    });

    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).__kginicisOrderShowInjectorInstalled;
        document.body.innerHTML = '';
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    /**
     * @scenario surface=mypage_receipt_button
     * @effects loading_text_from_translation_key
     */
    it('영수증 버튼을 누르면 조회 중 글자를 번역 키로 보여 준다', async () => {
        const receipt = { success: true, data: { receipt_type: 'inicis_receipt', receipt_url: 'https://iniweb.inicis.com/r', receipt_label: 'L', receipt_view_label: 'V' } };
        vi.spyOn(globalThis, 'fetch').mockImplementation(async () => new Response(JSON.stringify(receipt), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.spyOn(window, 'open').mockImplementation(() => null);

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2500);

        const button = document.querySelector<HTMLButtonElement>('[data-kginicis-receipt-button="mypage-order"]');
        expect(button).not.toBeNull();
        vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}));
        button!.click();

        expect(button!.textContent).toBe('読み込み中...');
    });
});
