// e2e:allow 이커머스 전수 실측 후속 B — 전용 Chrome(CDP)으로 KG 가상계좌 주문 상세를 재실측(이력 B5 실측 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * KG이니시스 가상계좌 입금 안내 — 입금 금액과 자동 취소 경고 (B5)
 *
 * @description
 * 마이페이지·비회원 주문 상세의 「가상계좌 입금 안내」가 은행·계좌번호·예금주·입금 기한만 보여, 나이스·KCP 가 함께
 * 보이는 「입금 금액」과 「입금 기한 내에 입금하지 않으시면 주문이 자동 취소됩니다」가 빠져 있었다(전수 실측 S2).
 * 입금 금액은 입금할 금액의 기준 필드인 결제예정금액(`total_due_amount_formatted` — 마일리지 차감 뒤)이다. 입금 대기
 * 중에는 주문금액(`total_amount_formatted`)과 같은 값이고(실측 B5), 아래 시험의 서로 다른 두 값은 어느 필드를 읽는지 가리기
 * 위한 값이다. 비회원 응답에는 결제예정금액이 없고 비회원은 마일리지를 쓰지 못하므로 주문금액으로 대신한다. 이 안내는 입금 대기 주문에만 붙으므로 경고도 늘 함께 붙는다.
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installVbankInfoInjector } from '../vbankInfoInjector';

const FLAG = '__kginicisVbankInfoInjectorInstalled';

/**
 * 주문 상세 화면 상태와 결제 정보 카드를 심는다.
 *
 * @param path 주소
 * @param order 주문 데이터(결제 제외)
 * @param dict 번역 사전(없으면 G7Core.t 없음 → 한국어 대체 문구)
 * @returns void
 */
function setup(path: string, order: Record<string, unknown>, dict?: Record<string, string>): void {
    history.pushState(null, '', path);
    (window as any).G7Core = {
        ...(dict ? { t: (key: string) => (key in dict ? dict[key] : key) } : {}),
        getState: () => ({
            currentDataContext: {
                order: {
                    data: {
                        order_number: '20261007-0538523637',
                        ...order,
                        payment: {
                            pg_provider: 'kginicis',
                            payment_status: 'waiting_deposit',
                            payment_method: 'vbank',
                            vbank_name: '국민은행',
                            vbank_number: '12345678901234',
                            vbank_holder: '(주)테스트',
                            vbank_due_at_formatted: '2026-10-10 23:59',
                        },
                    },
                },
            },
        }),
    };
    document.body.innerHTML = '<section id="order_payment_info_panel"><div class="space-y-2"></div></section>';
}

/**
 * 주입기를 돌리고 입금 안내 영역의 글자를 돌려준다.
 *
 * @returns 입금 안내 영역 글자(없으면 null)
 */
async function injectedText(): Promise<string | null> {
    installVbankInfoInjector();
    await vi.advanceTimersByTimeAsync(3000);

    return document.getElementById('kginicis-mp-vbank-info')?.textContent ?? null;
}

describe('KG 가상계좌 입금 안내 — 입금 금액·자동 취소 경고', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        vi.useFakeTimers();
        globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({}) } as Response);
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
     * @scenario case=member_with_mileage
     * @effects kginicis_vbank_shows_due_amount, kginicis_vbank_shows_auto_cancel_notice
     */
    it('회원 주문은 결제예정금액(마일리지 차감 뒤)을 입금 금액으로 보이고 자동 취소 경고를 붙인다', async () => {
        setup('/mypage/orders/20261007-0538523637', { total_amount_formatted: '15,000원', total_due_amount_formatted: '12,000원' });

        const text = await injectedText();

        expect(text).toContain('입금 금액');
        expect(text).toContain('12,000원');
        expect(text).not.toContain('15,000원');
        expect(text).toContain('입금 기한 내에 입금하지 않으시면 주문이 자동 취소됩니다.');
    });

    /**
     * @scenario case=guest_without_due
     * @effects kginicis_vbank_shows_due_amount
     */
    it('비회원 주문은 결제예정금액이 없어 주문금액을 입금 금액으로 보인다', async () => {
        setup('/shop/guest/orders/20261007-0538523637', { total_amount_formatted: '9,500원' });

        const text = await injectedText();

        expect(text).toContain('9,500원');
        expect(text).toContain('입금 기한 내에 입금하지 않으시면 주문이 자동 취소됩니다.');
    });

    /**
     * @scenario case=english_translation
     * @effects kginicis_vbank_amount_notice_translated
     */
    it('번역이 있으면 보는 언어 문구로 적는다', async () => {
        setup('/mypage/orders/20261007-0538523637', { total_due_amount_formatted: '₩12,000' }, {
            'sirsoft-pay_kginicis.user.vbank_amount': 'Deposit Amount',
            'sirsoft-pay_kginicis.user.vbank_notice': 'Your order will be automatically cancelled if payment is not received by the deadline.',
        });

        const text = await injectedText();

        expect(text).toContain('Deposit Amount');
        expect(text).toContain('₩12,000');
        expect(text).toContain('Your order will be automatically cancelled');
    });
});
