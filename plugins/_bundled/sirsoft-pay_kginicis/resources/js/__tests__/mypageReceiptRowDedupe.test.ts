/**
 * 주문 상세 결제 정보 카드의 KG이니시스 영수증 줄이 하나만 보이는지 검증한다 (이커머스 전수 실측 S4 — S4-D16)
 *
 * 결함: 결제를 마친 KRW 주문 상세(회원·비회원)에서 확장 조각(user_order_show)이 「영수증 [영수증 조회]」 줄을 그리고,
 *   주입 스크립트도 같은 카드에 「영수증 [영수증 조회]」 줄을 하나 더 붙였다. 주입 스크립트 줄은 언어 헤더 없는 조회
 *   응답의 글자를 써서 한국어·일본어 화면에도 「Receipt View Receipt」 로 보였다.
 * 이제 영수증 줄은 조각 하나만 그린다(#639 에서 주입 스크립트를 조각으로 옮김). 조각의 영수증 줄은 고정 id
 *   (kginicis-mp-receipt-row)를 달아 화면 점검이 줄 수를 셀 수 있게 하고, 글자는 화면 언어 사전의 번역 키로 그린다.
 */

import { afterEach, describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import userOrderShow from '../../extensions/user_order_show.json';
import { findAll, renderFragment, type LayoutTest } from './layouts/fragmentTestKit';

const ROW_ID = 'kginicis-mp-receipt-row';

/** 결제를 마친 KRW 가상계좌 주문 — 조각의 영수증 블록 조건(kginicis·JPY 아님·거래번호·결제일시)을 채운다 */
const PAID_KRW_ORDER = {
    order: {
        data: {
            id: 390,
            order_number: '20261007-0337110194',
            total_vat_amount: 0,
            total_tax_free_amount: 0,
            payment: {
                pg_provider: 'kginicis',
                payment_status: 'paid',
                payment_method: 'vbank',
                currency: 'KRW',
                transaction_id: 'StdpayVBNKINIpayTest20261007123849384732',
                paid_at: '2026-10-07 15:24:42',
            },
        },
    },
};

let utils: LayoutTest | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
});

describe('주문 상세 KG이니시스 영수증 줄 — 하나만', () => {
    /**
     * @scenario case=fragment_contract
     * @effects fragment_receipt_row_carries_stable_id
     */
    it('확장 조각에는 고정 id(kginicis-mp-receipt-row)를 단 영수증 줄이 하나뿐이고 글자는 번역 키로 그린다', () => {
        const rows = findAll(userOrderShow, (node) => node.props?.id === ROW_ID);
        expect(rows.length).toBe(1);
        expect(JSON.stringify(rows[0])).toContain('$t:sirsoft-pay_kginicis.user.receipt_label');
        expect(JSON.stringify(rows[0])).toContain('$t:sirsoft-pay_kginicis.user.receipt_view');
    });

    /**
     * @scenario case=order_show_paid_krw
     * @effects single_receipt_row_rendered
     */
    it('결제를 마친 KRW 주문 상세에는 영수증 줄이 하나만 그려지고, 응답의 영어 글자가 아니라 화면 언어로 보인다', async () => {
        utils = await renderFragment((userOrderShow as any).components, PAID_KRW_ORDER, {
            state: {
                _local: {
                    _kgReceipt: {
                        receipt_type: 'inicis_receipt',
                        receipt_url: 'https://iniweb.inicis.com/receipt/iniReceipt.jsp?tid=x',
                        receipt_label: 'Receipt',
                        receipt_view_label: 'View Receipt',
                    },
                },
            },
        });

        expect(document.querySelectorAll(`#${ROW_ID}`).length).toBe(1);
        expect(screen.getAllByText('영수증 조회')).toHaveLength(1);
        expect(document.body.textContent).not.toContain('View Receipt');
    });
});
