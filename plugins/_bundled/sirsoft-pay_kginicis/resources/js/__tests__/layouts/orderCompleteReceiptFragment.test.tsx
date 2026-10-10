/**
 * @file orderCompleteReceiptFragment.test.tsx
 * @description 주문 완료 화면 › KG이니시스 영수증 조각(user_order_complete_receipt) 렌더 검증
 *
 * 예전에는 주입 스크립트(orderCompleteReceiptInjector)가 `/shop/orders/…/complete` 주소 글자를 맞추고 화면의
 * 파란 버튼을 찾아 그 옆에 영수증 버튼을 끼웠다. 쇼핑몰 경로를 바꾸면 조용히 빠졌고, 로그인 토큰을 저장소에서
 * 직접 읽어 Bearer 를 붙였다(#639). 이제 템플릿이 단 결제 금액 요약 자리(order_complete_payment_summary)에
 * 조각이 붙고, 플러그인 API 응답으로 간편결제 이름과 영수증·결제확인서 버튼을 그린다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_kginicis/tests/scenarios/order-complete-receipt-fragment.yaml
 */

import { afterEach, describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import extension from '../../../extensions/user_order_complete_receipt.json';
import { findAll, receiptRequests, renderFragment, type LayoutTest, type RenderFragmentOptions } from './fragmentTestKit';

const ORDER_NUMBER = '20261005-0000000002';

/** 조각이 붙는 자리의 컴포넌트 */
const COMPONENTS = (extension as any).injections[0].components;

/**
 * 주문 완료 화면 데이터 소스 값을 만든다.
 *
 * @param payment 결제 값
 * @returns 데이터 소스 값
 */
function orderData(payment: Record<string, unknown>): Record<string, unknown> {
    return { orderData: { data: { order_number: ORDER_NUMBER, payment } } };
}

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
    utils = await renderFragment(COMPONENTS, data, options);

    return utils;
}

describe('주문 완료 KG이니시스 영수증 조각', () => {
    // @scenario case=anchor
    // @effects attaches_to_template_anchor_only
    it('주문 완료 레이아웃의 결제 금액 요약 자리(id)에만 붙고 화면 주소 글자를 보지 않는다', () => {
        expect((extension as any).target_layout).toBe('shop/order_complete');
        expect((extension as any).injections.map((i: any) => i.target_id)).toEqual(['order_complete_payment_summary']);
        const json = JSON.stringify(extension, (key, value) => (key === 'comment' ? undefined : value));
        expect(json).not.toMatch(/\/shop\/|route\./);
    });

    // @scenario case=paid_receipt
    // @effects receipt_button_from_api, easy_pay_name_row_from_receipt_api
    it('결제가 끝난 주문은 영수증 API 를 한 번 불러 간편결제 이름과 영수증 버튼을 그린다', async () => {
        await mount(orderData({ pg_provider: 'kginicis', transaction_id: 'StdpayCARDINIpayTest0002', payment_status: 'paid' }), {
            state: { _global: { guestOrderToken: '1999999999|guest-token' } },
            receipt: {
                receipt_type: 'inicis_receipt',
                receipt_url: 'https://receipt.example/r',
                receipt_label: '영수증',
                receipt_view_label: '영수증 조회',
                embedded_pg_provider_label: '네이버페이',
            },
        });

        await waitFor(() => expect(screen.getByText('네이버페이')).toBeTruthy());
        expect(screen.getByText('영수증 조회').closest('button')).not.toBeNull();
        const requests = receiptRequests();
        expect(requests).toHaveLength(1);
        expect(requests[0][0]).toBe(`/api/plugins/sirsoft-pay_kginicis/user/orders/${ORDER_NUMBER}/receipt`);
        expect(new Headers(requests[0][1]?.headers as HeadersInit).get('X-Guest-Order-Token')).toBe('1999999999|guest-token');

        const [action] = findAll(extension, (n) => n.handler === 'sirsoft-pay_kginicis.openReceipt');
        expect(action.params).toEqual({ receipt: '{{_local._kgOcReceipt}}' });
    });

    // @scenario case=cbt_confirmation
    // @effects receipt_button_from_api
    it('일본 결제는 결제 전(편의점 입금 대기)이어도 결제확인서 버튼을 그린다', async () => {
        await mount(orderData({ pg_provider: 'kginicis', transaction_id: 'INIJPGCVSTest0002', payment_status: 'waiting_deposit' }), {
            state: {
                _local: {
                    _kgOcReceipt: {
                        receipt_type: 'cbt_confirmation',
                        receipt_label: '편의점 입금정보',
                        receipt_view_label: '입금정보 보기',
                        receipt_fields: [{ key: 'amount', label: '입금 예정 금액', value: '1,000 JPY' }],
                    },
                },
            },
        });

        expect(screen.getByText('편의점 입금정보')).toBeTruthy();
        expect(screen.getByText('입금정보 보기').closest('button')).not.toBeNull();
    });

    // @scenario case=unpaid
    // @effects receipt_hidden_until_paid
    it('결제 전 원화 주문은 영수증 주소가 와도 버튼을 그리지 않는다', async () => {
        await mount(orderData({ pg_provider: 'kginicis', transaction_id: 'StdpayVBNKINIpayTest0002', payment_status: 'waiting_deposit' }), {
            state: { _local: { _kgOcReceipt: { receipt_type: 'inicis_receipt', receipt_url: 'https://receipt.example/r' } } },
        });

        expect(screen.queryByText('영수증 조회')).toBeNull();
    });

    // @scenario case=other_pg
    // @effects hidden_for_other_pg
    it('다른 결제대행사·무통장 주문이면 아무것도 그리지 않고 영수증 API 도 부르지 않는다', async () => {
        await mount(orderData({ pg_provider: 'tosspayments', transaction_id: 'tgen_1', payment_status: 'paid' }), {
            receipt: { receipt_type: 'inicis_receipt', receipt_url: 'https://receipt.example/r' },
        });
        expect(screen.queryByText('영수증 조회')).toBeNull();
        expect(receiptRequests()).toHaveLength(0);
        utils?.cleanup();

        await mount(orderData({ payment_method: 'dbank', payment_status: 'waiting_deposit' }), {
            receipt: { receipt_type: 'inicis_receipt', receipt_url: 'https://receipt.example/r' },
        });
        expect(receiptRequests()).toHaveLength(0);
    });
});
