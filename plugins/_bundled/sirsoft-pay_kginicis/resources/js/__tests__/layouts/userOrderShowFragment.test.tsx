/**
 * @file userOrderShowFragment.test.tsx
 * @description 주문 상세 결제 정보 카드 › KG이니시스 결제 블록 조각(user_order_show) 렌더 검증
 *
 * 예전에는 주입 스크립트 두 개(mypageOrderShowInjector·vbankInfoInjector)가 화면 주소 글자(`/mypage/orders/…`·
 * `/shop/guest/…`)를 보고 DOM 에 영수증 행·입금 안내를 끼웠다. 쇼핑몰 경로를 바꾼 설치본에서는 조용히 빠졌고,
 * fetch·history 를 덮어써 다른 확장과 순서 다툼을 했다(#639). 이제 결제 정보 카드의 공용 슬롯에 조각이 붙고,
 * 마운트 때 플러그인 영수증 API 를 한 번 불러 그 응답으로 간편결제 이름·입금 안내·결제확인서 버튼을 그린다.
 * 같은 슬롯을 회원·비회원 주문 상세와 신청서 상세가 함께 쓰므로 주소를 보지 않는다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_kginicis/tests/scenarios/user-order-payment-fragment.yaml
 */

import { afterEach, describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import extension from '../../../extensions/user_order_show.json';
import { handlerMap } from '../../handlers';
import { findAll, findById, receiptRequests, renderFragment, type LayoutTest, type RenderFragmentOptions } from './fragmentTestKit';

const ORDER_NUMBER = '20261005-0000000001';

/**
 * 이니시스 결제 주문 데이터 소스 값을 만든다.
 *
 * @param payment 결제 값
 * @returns 데이터 소스 값
 */
function kgOrder(payment: Record<string, unknown>): Record<string, unknown> {
    return {
        order: {
            data: {
                order_number: ORDER_NUMBER,
                total_vat_amount: 0,
                total_tax_free_amount: 0,
                payment: { pg_provider: 'kginicis', transaction_id: 'StdpayCARDINIpayTest0001', currency: 'KRW', ...payment },
            },
        },
    };
}

/** 입금 대기 가상계좌 결제 */
const VBANK_WAITING = {
    payment_method: 'vbank',
    payment_method_label: '가상계좌',
    payment_status: 'waiting_deposit',
    vbank_name: '국민은행',
    vbank_number: '56211234567890',
    vbank_holder: '(주)지세븐',
    vbank_due_at: '2026-10-07T14:59:59+00:00',
    vbank_due_at_formatted: '2026년 10월 7일 23:59',
};

/** 편의점 입금 대기 CBT 영수증 응답 — 항목 이름은 번역되고 키는 고정이다 */
const CVS_RECEIPT = {
    receipt_type: 'cbt_confirmation',
    receipt_url: null,
    cbt_pay_method: 'CVS',
    receipt_label: '편의점 입금정보',
    receipt_view_label: '입금정보 보기',
    receipt_fields: [
        { key: 'amount', label: '입금 예정 금액', value: '1,000 JPY' },
        { key: 'deposit_status', label: '입금 상태', value: '입금 대기' },
        { key: 'cvs_code', label: '편의점 코드', value: '00007' },
        { key: 'cvs_confirmation_no', label: '확인번호', value: '999999999999' },
        { key: 'cvs_receipt_no', label: '접수번호', value: '1779433713966' },
        { key: 'cvs_payment_term', label: '입금 마감', value: '2026-10-08 23:59:59' },
    ],
};

let utils: LayoutTest | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
});

/**
 * 조각을 렌더한다.
 *
 * @param data 데이터 소스 값
 * @param options 초기 상태·API 응답
 * @returns 렌더된 테스트 도구
 */
async function mount(data: Record<string, unknown>, options: RenderFragmentOptions = {}): Promise<LayoutTest> {
    utils = await renderFragment((extension as any).components, data, options);

    return utils;
}

describe('주문 상세 KG이니시스 결제 블록 조각', () => {
    // @scenario case=easy_pay
    // @effects receipt_fetched_once_on_mount, easy_pay_name_row_from_receipt_api
    it('마운트 때 영수증 API 를 불러 간편결제 이름을 따로 한 줄로 보이고 영수증 링크를 그린다', async () => {
        await mount(
            kgOrder({ payment_status: 'paid', paid_at: '2026-10-05 10:00:00', payment_method_label: '신용카드' }),
            { receipt: { receipt_type: 'inicis_receipt', receipt_url: 'https://receipt.example/r', embedded_pg_provider_label: '카카오페이' } },
        );

        await waitFor(() => expect(screen.getByText('카카오페이')).toBeTruthy());
        expect((screen.getByText('카카오페이').parentElement as HTMLElement).textContent).toContain('간편결제');
        expect(screen.getByText('영수증 조회').getAttribute('href')).toBe('https://receipt.example/r');
        expect(receiptRequests()).toHaveLength(1);
    });

    // @scenario case=guest
    // @effects guest_token_header_sent, order_number_from_loaded_order
    it('비회원은 상태 계약의 조회 토큰을 헤더로 싣고, 주문번호는 주소가 아니라 불러온 주문에서 읽는다', async () => {
        await mount(
            kgOrder({ payment_status: 'paid', paid_at: '2026-10-05 10:00:00' }),
            { state: { _global: { guestOrderToken: '1999999999|guest-token' } }, receipt: { receipt_type: 'inicis_receipt', receipt_url: 'https://receipt.example/r' } },
        );

        await waitFor(() => expect(receiptRequests()).toHaveLength(1));
        const [url, init] = receiptRequests()[0];
        expect(url).toBe(`/api/plugins/sirsoft-pay_kginicis/user/orders/${ORDER_NUMBER}/receipt`);
        expect(new Headers(init?.headers as HeadersInit).get('X-Guest-Order-Token')).toBe('1999999999|guest-token');
    });

    // @scenario case=other_pg
    // @effects hidden_for_other_pg
    it('다른 결제대행사 주문이면 블록을 그리지 않고 영수증 API 도 부르지 않는다', async () => {
        await mount({
            order: { data: { order_number: ORDER_NUMBER, payment: { pg_provider: 'nicepayments', transaction_id: 'T1', payment_status: 'paid', paid_at: 'x' } } },
        }, { receipt: { receipt_type: 'inicis_receipt', receipt_url: 'https://receipt.example/r' } });

        expect(document.getElementById('kginicis_order_payment_block')).toBeNull();
        expect(screen.queryByText('영수증 조회')).toBeNull();
        expect(receiptRequests()).toHaveLength(0);
    });

    // @scenario case=vbank_waiting
    // @effects vbank_info_uses_shop_formatted_due, bank_code_admin_only
    it('입금 대기 가상계좌는 입금 안내를 그리고 입금 기한은 쇼핑몰이 서식을 입힌 값을 그대로 쓴다 — 은행코드는 관리자만', async () => {
        await mount(kgOrder(VBANK_WAITING), {
            // 은행코드는 서버가 주문 조회 권한자에게만 싣는다(7.1.0) — 회원 응답은 null 이고 그러면 줄을 그리지 않는다
            state: { _local: { _kgReceipt: { receipt_type: 'inicis_receipt', receipt_url: 'https://receipt.example/r', vbank_bank_code: null } } },
        });

        const panel = document.getElementById('kginicis_vbank_info') as HTMLElement;
        expect(panel).not.toBeNull();
        expect(panel.textContent).toContain('가상계좌 입금 안내');
        expect(panel.textContent).toContain('국민은행');
        expect(panel.textContent).toContain('56211234567890');
        expect(panel.textContent).toContain('(주)지세븐');
        expect(panel.textContent).toContain('2026년 10월 7일 23:59');
        expect(panel.textContent).not.toContain('2026-10-07T14:59:59');
        expect(panel.textContent).not.toContain('은행코드');
        // 결제 전이라 영수증 링크는 없다
        expect(screen.queryByText('영수증 조회')).toBeNull();
    });

    // @scenario case=vbank_admin
    // @effects bank_code_admin_only
    it('관리자가 보면 영수증 API 가 계산한 은행코드를 함께 보인다', async () => {
        await mount(kgOrder(VBANK_WAITING), {
            state: {
                _local: { _kgReceipt: { receipt_type: 'inicis_receipt', vbank_bank_code: '04' } },
                _global: { currentUser: { uuid: 'admin-1', is_admin: true } },
            },
        });

        const panel = document.getElementById('kginicis_vbank_info') as HTMLElement;
        expect(panel.textContent).toContain('은행코드');
        expect(panel.textContent).toContain('04');
    });

    // @scenario case=cvs_waiting
    // @effects cvs_panel_reads_fields_by_key
    it('일본 편의점 입금 대기면 확인서 항목을 고정 키로 찾아 편의점 입금 안내를 그리고 가상계좌 안내는 그리지 않는다', async () => {
        await mount(
            kgOrder({ ...VBANK_WAITING, currency: 'JPY', vbank_name: 'CVS', transaction_id: 'INIJPGCVSTest0001' }),
            { state: { _local: { _kgReceipt: CVS_RECEIPT } } },
        );

        const panel = document.getElementById('kginicis_cvs_info') as HTMLElement;
        expect(panel).not.toBeNull();
        expect(document.getElementById('kginicis_vbank_info')).toBeNull();
        for (const value of ['입금 대기', '1,000 JPY', '00007', '999999999999', '1779433713966', '2026-10-08 23:59:59']) {
            expect(panel.textContent).toContain(value);
        }
    });

    // @scenario case=cbt_confirmation
    // @effects cbt_confirmation_opens_via_handler
    it('일본 결제는 결제확인서 버튼을 그리고, 버튼은 받아 둔 응답을 플러그인 핸들러(openReceipt)로 넘긴다', async () => {
        await mount(
            kgOrder({ currency: 'JPY', payment_status: 'paid', paid_at: '2026-10-05 10:00:00', transaction_id: 'INIJPGCARDTest0001' }),
            { state: { _local: { _kgReceipt: { ...CVS_RECEIPT, receipt_label: 'CBT 결제확인서', receipt_view_label: '결제확인서 보기' } } } },
        );

        expect(screen.getByText('CBT 결제확인서')).toBeTruthy();
        expect(screen.getByText('결제확인서 보기').tagName).toBe('BUTTON');
        // 원화 결제 영수증 링크는 엔화 결제에 그리지 않는다
        expect(screen.queryByText('영수증 조회')).toBeNull();

        const row = findById(extension, 'kginicis_cbt_confirmation_row');
        const [action] = findAll(row, (n) => typeof n.handler === 'string' && n.handler.endsWith('.openReceipt'));
        expect(action.handler).toBe('sirsoft-pay_kginicis.openReceipt');
        expect(action.params).toEqual({ receipt: '{{_local._kgReceipt}}' });
        expect(handlerMap).toHaveProperty('openReceipt');
    });

    // @scenario case=no_script_injection
    // @effects no_route_or_dom_scanning
    it('조각은 확장 슬롯에 붙고 화면 주소·제목 글자에 기대지 않는다', () => {
        expect((extension as any).extension_point).toBe('mypage_order_payment_provider_slot');
        expect((extension as any).target_layout).toBeUndefined();
        // 개발자 주석(comment)을 뺀 실제 동작 값만 본다
        const json = JSON.stringify(extension, (key, value) => (key === 'comment' ? undefined : value));
        expect(json).not.toMatch(/\/shop\/|\/mypage\/|route\./);
        expect(json).not.toContain('iniweb.inicis.com');
    });
});
