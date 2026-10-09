// e2e:allow 이커머스 전수 실측 후속 B — 전용 Chrome(CDP)으로 KG 가상계좌 주문 상세를 재실측(이력 B5 실측 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * KG이니시스 가상계좌 입금 안내 — 입금 금액과 자동 취소 경고 (B5)
 *
 * @description
 * 마이페이지·비회원 주문 상세의 「가상계좌 입금 안내」가 은행·계좌번호·예금주·입금 기한만 보여, 나이스·KCP 가 함께
 * 보이는 「입금 금액」과 「입금 기한 내에 입금하지 않으시면 주문이 자동 취소됩니다」가 빠져 있었다(전수 실측 S2).
 * 입금 금액은 입금할 금액의 기준 필드인 결제예정금액(`total_due_amount_formatted` — 마일리지 차감 뒤)이다. 입금 대기
 * 중에는 주문금액(`total_amount_formatted`)과 같은 값이고(실측 B5), 아래 시험의 서로 다른 두 값은 어느 필드를 읽는지 가리기
 * 위한 값이다. 비회원 응답에는 결제예정금액이 없고 비회원은 마일리지를 쓰지 못하므로 주문금액으로 대신한다. 이 안내는 입금 대기
 * 주문에만 붙으므로 경고도 늘 함께 붙는다.
 *
 * 입금 안내는 주문 상세 결제 정보 카드의 조각(user_order_show)이 그린다(#639 에서 주입 스크립트를 조각으로 옮김).
 * 설치되는 조각 JSON 을 엔진에 태워 렌더 결과를 단언한다.
 */

import { afterEach, describe, expect, it } from 'vitest';
import extension from '../../extensions/user_order_show.json';
import { renderFragment, type LayoutTest } from './layouts/fragmentTestKit';

let utils: LayoutTest | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
});

/**
 * 입금 대기 가상계좌 주문으로 조각을 그리고 입금 안내 영역의 글자를 돌려준다.
 *
 * @param amounts 주문 금액 필드(결제예정금액·주문금액)
 * @param locale 화면 언어
 * @returns 입금 안내 영역 글자(없으면 null)
 */
async function vbankPanelText(amounts: Record<string, unknown>, locale: 'ko' | 'en' = 'ko'): Promise<string | null> {
    utils = await renderFragment(
        (extension as any).components,
        {
            order: {
                data: {
                    order_number: '20261007-0538523637',
                    total_vat_amount: 0,
                    total_tax_free_amount: 0,
                    ...amounts,
                    payment: {
                        pg_provider: 'kginicis',
                        transaction_id: 'StdpayVBNKINIpayTest0001',
                        currency: 'KRW',
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
        { locale, state: { _local: { _kgReceipt: { receipt_type: 'inicis_receipt' } } } },
    );

    return document.getElementById('kginicis_vbank_info')?.textContent ?? null;
}

describe('KG 가상계좌 입금 안내 — 입금 금액·자동 취소 경고', () => {
    /**
     * @scenario case=member_with_mileage
     * @effects kginicis_vbank_shows_due_amount, kginicis_vbank_shows_auto_cancel_notice
     */
    it('회원 주문은 결제예정금액(마일리지 차감 뒤)을 입금 금액으로 보이고 자동 취소 경고를 붙인다', async () => {
        const text = await vbankPanelText({ total_amount_formatted: '15,000원', total_due_amount_formatted: '12,000원' });

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
        const text = await vbankPanelText({ total_amount_formatted: '9,500원' });

        expect(text).toContain('입금 금액');
        expect(text).toContain('9,500원');
        expect(text).toContain('입금 기한 내에 입금하지 않으시면 주문이 자동 취소됩니다.');
    });

    /**
     * @scenario case=english_translation
     * @effects kginicis_vbank_amount_notice_translated
     */
    it('보는 언어 사전의 문구로 적는다', async () => {
        const text = await vbankPanelText({ total_due_amount_formatted: '₩12,000' }, 'en');

        expect(text).toContain('Deposit Amount');
        expect(text).toContain('₩12,000');
        expect(text).toContain('Your order will be automatically cancelled if payment is not received by the deadline.');
        expect(text).not.toContain('입금 금액');
    });
});
