/**
 * @file orderCompleteReceiptFragment.test.tsx
 * @description 주문 완료 화면 › 나이스페이먼츠 영수증·매출전표·간편결제 이름은 레이아웃 조각이 그린다 (#639)
 *
 * 종전에는 주입 스크립트(orderCompleteReceiptInjector)가 주소 정규식(/shop/orders/{N}/complete)으로 화면을 알아보고
 * history.pushState 를 덮어써 화면 이동을 엿본 뒤, 파란 버튼 옆에 「영수증 조회」 버튼을 직접 그렸다. 조각은 결제사 주소를
 * 글자 그대로 적고 회원 토큰으로만 조회해 비회원에게는 현금영수증 조회가 실패했다. 이제 조각 하나가 영수증 조회 API 를
 * 회원(선택 인증)·비회원(조회 토큰 헤더) 모두 같은 방식으로 불러 링크를 그린다.
 *
 * 설치되는 조각 원본(JSON)의 주입 노드를 그대로 엔진에 태운다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/order-complete-receipt-fragment.yaml
 */

import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createLayoutTest, screen } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import extension from '../../../extensions/user_order_complete_receipt.json';
import {
    PLUGIN_TRANSLATIONS,
    callsTo,
    clearTemplateConfig,
    findNodeById,
    headersOf,
    resetFragmentRegistry,
    setupFragmentRegistry,
    stubTemplateConfig,
} from './fragmentRenderHelpers';

const MARKUP_PATH = path.resolve(__dirname, '../../../extensions/user_order_complete_receipt.g7.html');
const RECEIPT_API = '/api/plugins/sirsoft-pay_nicepayments/user/orders/ORD-OC-1/receipt';
const RECEIPT = {
    receipt_url: 'https://receipt.example.test/slip?TID=T-1',
    payment_receipt_url: 'https://receipt.example.test/payment?TID=T-1',
    cash_receipt_url: null,
    embedded_pg_provider: 'kakaopay',
};

const block = findNodeById(extension, 'nicepay_oc_receipt_block');

let utils: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 주문 응답과 영수증 조회 응답으로 조각을 그립니다.
 *
 * @param payment 주문 응답의 결제 정보
 * @param options 비회원 조회 토큰·영수증 응답
 * @return 테스트 도구
 */
async function renderFragment(
    payment: Record<string, unknown>,
    options: { guestToken?: string; receipt?: Record<string, unknown> } = {},
): Promise<ReturnType<typeof createLayoutTest>> {
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'order_complete_receipt_fragment_test', components: [block] } as any,
        {
            translations: PLUGIN_TRANSLATIONS,
            locale: 'ko',
            componentRegistry: setupFragmentRegistry(),
            initialState: { _global: options.guestToken ? { guestOrderToken: options.guestToken } : {} },
            initialData: { orderData: { data: { order_number: 'ORD-OC-1', payment } } },
        },
    );
    // 영수증 조회 주소의 셋째 마디(플러그인 식별자)가 목 등록 키다
    utils.mockApi('sirsoft_pay_nicepayments', { response: options.receipt ?? RECEIPT });
    await utils.render();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await utils.rerender();

    return utils;
}

beforeEach(() => {
    stubTemplateConfig();
});

afterEach(() => {
    utils?.cleanup();
    utils = null;
    resetFragmentRegistry();
    clearTemplateConfig();
});

const PAID_NICEPAY = { pg_provider: 'nicepayments', transaction_id: 'T-1', paid_at: '2026-10-05 10:00:00', payment_method: 'card' };

describe('주문 완료 화면 — 나이스페이먼츠 영수증 조각', () => {
    // @scenario viewer=member, payment=nicepay_paid
    // @effects receipt_links_from_api, easy_pay_name_translated
    it('회원: 영수증 조회 API 를 불러 결제 영수증·매출전표 링크와 간편결제 이름을 그린다', async () => {
        await renderFragment(PAID_NICEPAY);

        expect(callsTo(globalThis.fetch, RECEIPT_API)).toHaveLength(1);

        const slip = screen.getByText('매출전표 보기').closest('a');
        expect(slip?.getAttribute('href')).toBe(RECEIPT.receipt_url);
        expect(slip?.getAttribute('target')).toBe('_blank');
        expect(slip?.getAttribute('rel')).toBe('noopener noreferrer');
        expect(screen.getByText('영수증 보기').closest('a')?.getAttribute('href')).toBe(RECEIPT.payment_receipt_url);
        expect(screen.getByText('카카오페이')).toBeTruthy();
        // 현금영수증 주소가 없으면 그 링크는 그리지 않는다
        expect(screen.queryByText('현금영수증 조회')).toBeNull();
    });

    // @scenario viewer=guest, payment=nicepay_paid
    // @effects guest_token_header_sent, receipt_links_from_api
    it('비회원: 공개 상태 계약(_global.guestOrderToken)의 조회 토큰을 헤더로 실어 같은 API 를 부른다', async () => {
        await renderFragment(PAID_NICEPAY, {
            guestToken: '1999999999|guest-token',
            receipt: { ...RECEIPT, cash_receipt_url: 'https://receipt.example.test/cash?TID=R-1' },
        });

        const [[, init]] = callsTo(globalThis.fetch, RECEIPT_API);
        expect(headersOf(init)['X-Guest-Order-Token']).toBe('1999999999|guest-token');
        expect(screen.getByText('현금영수증 조회').closest('a')?.getAttribute('href')).toBe('https://receipt.example.test/cash?TID=R-1');
    });

    // @scenario viewer=member, payment=vbank_waiting
    // @effects hidden_until_paid
    it('입금 전 가상계좌 결제에는 블록도 조회도 없다', async () => {
        await renderFragment({ pg_provider: 'nicepayments', transaction_id: 'T-2', paid_at: null, payment_method: 'vbank' });

        expect(screen.queryByText('영수증')).toBeNull();
        expect(callsTo(globalThis.fetch, '/receipt')).toHaveLength(0);
    });

    // @scenario viewer=member, payment=other_pg
    // @effects hidden_for_other_pg
    it('다른 결제사 주문에는 블록도 조회도 없다', async () => {
        await renderFragment({ pg_provider: 'tosspayments', transaction_id: 'T-3', paid_at: '2026-10-05 10:00:00' });

        expect(screen.queryByText('영수증')).toBeNull();
        expect(callsTo(globalThis.fetch, 'sirsoft-pay_nicepayments')).toHaveLength(0);
    });

    // @scenario viewer=member, payment=nicepay_paid
    // @effects no_external_url_literal_in_fragment
    it('조각 원본과 저작본 어디에도 결제사 주소를 글자 그대로 적지 않는다', () => {
        expect(JSON.stringify(extension)).not.toContain('npg.nicepay.co.kr');
        expect(readFileSync(MARKUP_PATH, 'utf8')).not.toContain('npg.nicepay.co.kr');
    });
});
