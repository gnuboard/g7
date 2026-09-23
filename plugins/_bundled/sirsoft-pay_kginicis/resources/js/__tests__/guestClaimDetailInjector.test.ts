/**
 * 비회원 클레임 신청서 상세(/shop/guest/claims/{N})에도 영수증 행을 주입한다 (#599 validate P7)
 *
 * 비회원 신청서 상세는 회원 상세와 같은 결제 정보 카드를 쓴다. 주소 매칭이 회원 경로만 보면
 * 카드는 뜨는데 이니시스 영수증·결제수단 표시만 빠진 채 열리고, 오류도 경고도 남지 않는다
 * (NHN KCP·나이스페이먼츠 주입기는 이미 두 경로를 함께 매칭한다).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';

const FLAG = '__kginicisOrderShowInjectorInstalled';

describe('mypageOrderShowInjector — 비회원 클레임 신청서 상세', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
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
     * 비회원 신청서 상세 주소에서도 화면이 읽어 둔 주문의 주문번호로 영수증을 조회해 행을 붙인다.
     *
     * @scenario case=guest_claim_detail
     * @effects guest_claim_detail_shows_kginicis_receipt
     */
    it('/shop/guest/claims/{번호} 에서 영수증 행을 주입한다', async () => {
        history.pushState(null, '', '/shop/guest/claims/CLM-20260923-0001');
        (window as any).G7Core = {
            getState: () => ({
                currentDataContext: {
                    order: {
                        data: {
                            id: 812,
                            order_number: '20260923-0000000812',
                            payment: { pg_provider: 'kginicis', payment_status: 'paid', transaction_id: 'TX-812' },
                        },
                    },
                },
            }),
        };
        document.body.innerHTML = `
            <section id="order_payment_info_panel">
                <div class="space-y-2">
                    <div class="flex items-center justify-between"><span>결제 방법</span><span>무통장입금</span></div>
                </div>
            </section>
        `;
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://iniweb.inicis.com/receipt',
                receipt_label: '영수증',
                receipt_view_label: '영수증 조회',
                payment_method_display_label: '신용카드',
            }),
        } as Response);
        globalThis.fetch = fetchMock;

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2500);

        expect(document.getElementById('kginicis-mp-receipt-row')).not.toBeNull();
        expect(String(fetchMock.mock.calls[0]?.[0])).toContain('/orders/20260923-0000000812/receipt');
    });
});
