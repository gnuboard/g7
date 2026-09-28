/**
 * 영수증 조회 응답의 표시 글자가 요청 언어로 번역된 뒤에도 화면 스크립트가 동작하는지 검증한다 (#599)
 *
 * 서버가 확인서 항목 이름을 요청 언어로 보내게 되면서, 항목을 한국어 이름('입금 상태' 등)으로 찾던 일본 편의점
 * 입금 안내 패널은 영어·일본어 화면에서 값을 못 찾고 비게 된다. 항목에 붙은 고정 키(key)로 찾고, 패널·팝업의
 * 고정 글자도 번역 키로 그린다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installVbankInfoInjector } from '../vbankInfoInjector';
import { openKginicisReceipt, receiptButtonLabel } from '../receiptPopup';

const FLAG = '__kginicisVbankInfoInjectorInstalled';

const EN: Record<string, string> = {
    'sirsoft-pay_kginicis.user.cvs_title': 'Japan convenience store deposit details',
    'sirsoft-pay_kginicis.user.cvs_status': 'Status',
    'sirsoft-pay_kginicis.user.cvs_due_amount': 'Amount due',
    'sirsoft-pay_kginicis.user.cvs_code': 'Convenience store code',
    'sirsoft-pay_kginicis.user.cvs_confirmation_no': 'Confirmation number',
    'sirsoft-pay_kginicis.user.cvs_receipt_no': 'Receipt number',
    'sirsoft-pay_kginicis.user.cvs_payment_term': 'Deposit deadline',
    'sirsoft-pay_kginicis.user.receipt_view': 'View Receipt',
    'sirsoft-pay_kginicis.user.receipt_print': 'Print',
};

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

function mountOrder(payment: Record<string, unknown>): void {
    (window as any).G7Core = {
        t: (key: string) => EN[key] ?? key,
        getState: () => ({
            currentDataContext: {
                order: { data: { id: 901, order_number: '20260928-0000000901', payment } },
            },
        }),
    };
    document.body.innerHTML = `
        <section id="order_payment_info_panel">
            <div class="space-y-2"></div>
        </section>
    `;
}

describe('영수증 표시 글자 번역 — 일본 편의점 입금 안내 패널', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        vi.useFakeTimers();
        history.pushState(null, '', '/mypage/orders/20260928-0000000901');
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
     * 영어 응답에서도 항목 값을 고정 키로 찾아 패널을 채우고, 패널 글자는 번역 키로 그린다.
     *
     * @scenario locale=en, surface=cvs_panel
     * @effects cvs_panel_reads_fields_by_key, panel_and_popup_text_translated
     */
    it('영어 항목 이름이어도 키로 값을 찾아 입금 안내를 채운다', async () => {
        mountOrder({
            pg_provider: 'kginicis',
            payment_status: 'waiting_deposit',
            payment_method: 'card',
            transaction_id: 'INIJPGCVS_CBTTEST00120260928000000000001',
            currency: 'JPY',
            vbank_name: 'CVS',
            vbank_number: '999999999999999999',
        });
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, data: EN_CVS_RECEIPT }),
        } as Response);

        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(3000);

        const section = document.getElementById('kginicis-mp-vbank-info');
        expect(section).not.toBeNull();
        const text = section!.textContent ?? '';
        expect(text).toContain('Japan convenience store deposit details');
        expect(text).toContain('Awaiting deposit');
        expect(text).toContain('10 JPY');
        expect(text).toContain('00007');
        expect(text).toContain('1779433713966');
        expect(text).toContain('2026-05-27 23:59:59');
        expect(text).toContain('Amount due');
        expect(text).not.toMatch(/[가-힣]/);
    });

    /**
     * 편의점 결제 여부는 번역되는 결제수단 이름이 아니라 응답의 결제수단 코드(cbt_pay_method)로 가린다.
     *
     * @scenario locale=en, surface=cvs_panel_detection
     * @effects cvs_panel_detected_by_pay_method_code
     */
    it('결제수단 코드가 CVS 면 거래번호 형태와 무관하게 편의점 입금 안내를 그린다', async () => {
        mountOrder({
            pg_provider: 'kginicis',
            payment_status: 'waiting_deposit',
            payment_method: 'card',
            transaction_id: 'INIJPG_OTHER_FORMAT_0001',
            vbank_number: '999999999999999999',
        });
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, data: EN_CVS_RECEIPT }),
        } as Response);

        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(3000);

        const section = document.getElementById('kginicis-mp-vbank-info');
        expect(section?.textContent ?? '').toContain('Japan convenience store deposit details');
    });
});

describe('영수증 표시 글자 번역 — 확인서 팝업과 버튼', () => {
    afterEach(() => {
        delete (window as any).G7Core;
        document.documentElement.lang = '';
        vi.restoreAllMocks();
    });

    /**
     * 확인서 팝업의 인쇄 버튼과 문서 언어는 화면 언어를 따른다.
     *
     * @scenario locale=en, surface=popup
     * @effects panel_and_popup_text_translated
     */
    it('팝업의 인쇄 버튼 글자와 문서 언어를 화면 언어로 그린다', () => {
        (window as any).G7Core = { t: (key: string) => EN[key] ?? key };
        document.documentElement.lang = 'en';
        let html = '';
        const popup = { document: { open: vi.fn(), write: (s: string) => { html += s; }, close: vi.fn() } };
        vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window);

        openKginicisReceipt({
            receipt_type: 'cbt_confirmation',
            receipt_title: 'KG Inicis CBT payment confirmation',
            receipt_fields: [{ key: 'order_number', label: 'Order number', value: '20260928-0000000901' }],
        });

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
    it('버튼 이름이 없으면 번역 키 이름으로 대신한다', () => {
        (window as any).G7Core = { t: (key: string) => EN[key] ?? key };

        expect(receiptButtonLabel({ receipt_url: 'https://example.test/receipt' })).toBe('View Receipt');
    });
});
