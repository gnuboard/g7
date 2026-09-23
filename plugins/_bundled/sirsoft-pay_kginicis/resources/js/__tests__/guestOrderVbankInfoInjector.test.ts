/**
 * 비회원 주문 상세(/shop/guest/orders/{주문번호})에도 가상계좌 입금 안내를 주입한다 (#599 validate)
 *
 * 입금 안내 주입기의 주소 매칭이 회원 주문 상세(/mypage/orders/{주문번호})만 봐서, 비회원이 가상계좌로
 * 주문한 뒤 주문 상세를 열면 결제 정보 카드는 뜨는데 입금 계좌·기한 안내만 오류 없이 빠졌다. 같은
 * 플러그인의 영수증 주입기는 이미 두 경로를 함께 매칭한다. 두 경로 모두 주소 조각이 주문번호다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installVbankInfoInjector } from '../vbankInfoInjector';

const FLAG = '__kginicisVbankInfoInjectorInstalled';

describe('vbankInfoInjector — 비회원 주문 상세', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
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
     * 비회원 주문 상세 주소에서도 입금 대기 가상계좌 주문이면 입금 안내 영역을 붙인다.
     *
     * @scenario case=guest_order_detail
     * @effects guest_order_detail_shows_kginicis_vbank_info
     */
    it('/shop/guest/orders/{주문번호} 에서 입금 안내를 주입한다', async () => {
        history.pushState(null, '', '/shop/guest/orders/20260923-0000000812');
        (window as any).G7Core = {
            getState: () => ({
                currentDataContext: {
                    order: {
                        data: {
                            id: 812,
                            order_number: '20260923-0000000812',
                            payment: {
                                pg_provider: 'kginicis',
                                payment_status: 'waiting_deposit',
                                payment_method: 'vbank',
                                vbank_name: '국민은행',
                                vbank_number: '12345678901234',
                                vbank_holder: '(주)테스트',
                                vbank_due_at: '2026-09-30T23:59:59+09:00',
                            },
                        },
                    },
                },
            }),
        };
        document.body.innerHTML = `
            <section id="order_payment_info_panel">
                <div class="space-y-2"></div>
            </section>
        `;
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({}),
        } as Response);

        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(3000);

        expect(document.getElementById('kginicis-mp-vbank-info')).not.toBeNull();
    });
});
