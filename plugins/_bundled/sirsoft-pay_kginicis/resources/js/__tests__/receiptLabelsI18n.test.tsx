/**
 * 영수증 조회 응답의 표시 글자가 요청 언어로 번역된 뒤에도 화면이 동작하는지 검증한다 (#599)
 *
 * 서버가 확인서 항목 이름을 요청 언어로 보내게 되면서, 항목을 한국어 이름('입금 상태' 등)으로 찾으면 영어·일본어
 * 화면에서 일본 편의점 입금 안내가 비게 된다. 항목에 붙은 고정 키(key)로 찾고, 안내·팝업·버튼의 고정 글자는 번역
 * 키로 그린다. 입금 안내와 버튼은 주문 상세 조각(user_order_show)이, 팝업은 openReceipt 핸들러가 그린다(#639 에서
 * 주입 스크립트를 조각·핸들러로 옮김).
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_kginicis/tests/scenarios/receipt-labels-i18n.yaml
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import extension from '../../extensions/user_order_show.json';
import en from '../../lang/en.json';
import { openReceiptHandler } from '../handlers/openReceipt';
import { renderFragment, type LayoutTest } from './layouts/fragmentTestKit';

/** 영어 화면의 영수증 조회 응답(data) — 항목 이름은 영어, 키는 언어와 무관하다 */
const EN_CVS_RECEIPT = {
    receipt_type: 'cbt_confirmation',
    cbt_pay_method: 'CVS',
    receipt_fields: [
        { key: 'pay_method', label: 'Payment method', value: 'Japan convenience store payment' },
        { key: 'amount', label: 'Amount due', value: '10 JPY' },
        { key: 'deposit_status', label: 'Deposit status', value: 'Awaiting deposit' },
        { key: 'cvs_code', label: 'Convenience store code', value: '00007' },
        { key: 'cvs_confirmation_no', label: 'Confirmation number', value: '999999999999999999' },
        { key: 'cvs_receipt_no', label: 'Receipt number', value: '1779433713966' },
        { key: 'cvs_payment_term', label: 'Deposit deadline', value: '2026-05-27 23:59:59' },
    ],
};

/**
 * 엔화 입금 대기 주문 데이터 소스 값을 만든다.
 *
 * @param payment 덮어쓸 결제 값
 * @returns 데이터 소스 값
 */
function jpyWaitingOrder(payment: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        order: {
            data: {
                order_number: '20260928-0000000901',
                payment: {
                    pg_provider: 'kginicis',
                    currency: 'JPY',
                    payment_status: 'waiting_deposit',
                    transaction_id: 'INIJPGCVSINIpayTest0901',
                    vbank_name: 'CVS',
                    vbank_number: '999999999999999999',
                    ...payment,
                },
            },
        },
    };
}

let utils: LayoutTest | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
    delete (window as any).G7Core;
    document.documentElement.lang = '';
    vi.restoreAllMocks();
});

describe('영수증 표시 글자 번역 — 일본 편의점 입금 안내', () => {
    /**
     * @scenario locale=en, surface=cvs_panel
     * @effects cvs_panel_reads_fields_by_key, panel_and_popup_text_translated
     */
    it('영어 항목 이름이어도 키로 값을 찾아 입금 안내를 채우고, 안내 글자는 번역 키로 그린다', async () => {
        utils = await renderFragment((extension as any).components, jpyWaitingOrder(), {
            locale: 'en',
            state: { _local: { _kgReceipt: EN_CVS_RECEIPT } },
        });

        const panel = document.getElementById('kginicis_cvs_info') as HTMLElement;
        expect(panel).not.toBeNull();
        for (const text of [en.user.cvs_title, en.user.cvs_status, en.user.cvs_due_amount, 'Awaiting deposit', '10 JPY', '00007', '1779433713966', '2026-05-27 23:59:59']) {
            expect(panel.textContent).toContain(text);
        }
        expect(panel.textContent).not.toMatch(/[가-힣]/);
    });

    /**
     * @scenario locale=en, surface=cvs_panel_detection
     * @effects cvs_panel_detected_by_pay_method_code
     */
    it('결제수단 코드가 CVS 면 거래번호 형태·은행 이름과 무관하게 편의점 입금 안내를 그린다', async () => {
        utils = await renderFragment(
            (extension as any).components,
            jpyWaitingOrder({ transaction_id: 'INIJPGCARDINIpayTest0902', vbank_name: 'Convenience store' }),
            { locale: 'en', state: { _local: { _kgReceipt: EN_CVS_RECEIPT } } },
        );

        expect(document.getElementById('kginicis_cvs_info')).not.toBeNull();
        expect(document.getElementById('kginicis_vbank_info')).toBeNull();
    });
});

describe('영수증 표시 글자 번역 — 확인서 팝업과 버튼', () => {
    /**
     * 확인서 팝업의 인쇄 버튼과 문서 언어는 화면 언어를 따른다.
     *
     * @scenario locale=en, surface=popup
     * @effects panel_and_popup_text_translated
     */
    it('팝업의 인쇄 버튼 글자와 문서 언어를 화면 언어로 그린다', () => {
        (window as any).G7Core = { t: (key: string) => (key === 'sirsoft-pay_kginicis.user.receipt_print' ? 'Print' : key) };
        document.documentElement.lang = 'en';
        let html = '';
        const popup = { document: { open: vi.fn(), write: (s: string) => { html += s; }, close: vi.fn() } };
        vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window);

        expect(openReceiptHandler({
            params: {
                receipt: {
                    receipt_type: 'cbt_confirmation',
                    receipt_title: 'KG Inicis CBT payment confirmation',
                    receipt_fields: [{ key: 'order_number', label: 'Order number', value: '20260928-0000000901' }],
                },
            },
        })).toBe(true);

        expect(html).toContain('<html lang="en">');
        expect(html).toContain('>Print</button>');
        expect(html).not.toMatch(/[가-힣]/);
    });

    /**
     * 서버가 버튼 이름을 주지 않으면 번역 키의 이름을 쓴다.
     *
     * @scenario locale=en, surface=button_fallback
     * @effects panel_and_popup_text_translated
     */
    it('버튼·행 이름이 없으면 번역 키 이름으로 대신한다', async () => {
        utils = await renderFragment((extension as any).components, jpyWaitingOrder({ payment_status: 'paid' }), {
            locale: 'en',
            state: { _local: { _kgReceipt: { receipt_type: 'cbt_confirmation', receipt_fields: EN_CVS_RECEIPT.receipt_fields } } },
        });

        expect(screen.getByText(en.user.receipt_view).tagName).toBe('BUTTON');
        expect(screen.getByText(en.user.receipt_label)).toBeTruthy();
    });
});
