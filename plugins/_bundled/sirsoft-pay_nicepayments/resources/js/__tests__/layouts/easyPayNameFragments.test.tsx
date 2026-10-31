/**
 * @file easyPayNameFragments.test.tsx
 * @description 간편결제 서비스명(카카오페이 등)은 레이아웃 조각이 번역 키로 그린다 (#639)
 *
 * 이커머스 주문 화면은 간편결제 주문도 기본 결제수단(신용카드)만 안다. 종전에는 주입 스크립트가 주소 정규식으로
 * 화면을 알아보고 history.pushState 를 덮어써 이동을 엿본 뒤, 관리자 주문 상세의 결제수단 칸
 * (payment_method_block_{n})과 마이페이지의 결제 방법 행(order_payment_method_row) 글자를 직접 고쳐 썼다.
 * 이제 조각이 플러그인 API 가 주는 원 수단 코드(embedded_pg_provider)를 번역 키로 풀어 자기 행에 보인다.
 * 마이페이지 조각이 종전에 보던 `payment_meta.PayMethod` 는 주문 응답에 없는 값이라 그 행은 한 번도 뜨지 않았다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/easy-pay-name-fragment.yaml
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createLayoutTest, screen } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import adminExtension from '../../../extensions/admin_order_payment_query.json';
import mypageExtension from '../../../extensions/user_mypage_order_receipt.json';
import {
    PLUGIN_TRANSLATIONS,
    callsTo,
    clearTemplateConfig,
    findNodeById,
    resetFragmentRegistry,
    setupFragmentRegistry,
    stubTemplateConfig,
} from './fragmentRenderHelpers';

let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
    stubTemplateConfig();
});

afterEach(() => {
    utils?.cleanup();
    utils = null;
    resetFragmentRegistry();
    clearTemplateConfig();
});

/**
 * 조각 노드를 주어진 데이터로 그립니다.
 *
 * @param components 그릴 노드
 * @param initialData 데이터 소스 값
 * @param receipt 영수증 조회 API 응답 (마이페이지 조각용)
 */
async function renderNodes(components: unknown[], initialData: Record<string, unknown>, receipt?: Record<string, unknown>): Promise<void> {
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'easy_pay_name_fragment_test', components } as any,
        { translations: PLUGIN_TRANSLATIONS, locale: 'ko', componentRegistry: setupFragmentRegistry(), initialData },
    );
    if (receipt) {
        utils.mockApi('sirsoft_pay_nicepayments', { response: receipt });
    }
    await utils.render();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await utils.rerender();
}

const NICEPAY_PAYMENT = { pg_provider: 'nicepayments', transaction_id: 'T-1', paid_at: '2026-10-05 10:00:00', payment_method: 'card' };

describe('관리자 주문 상세 — 결제 조회 패널의 간편결제 서비스명', () => {
    const panel = findNodeById(adminExtension, 'nicepay_verify_panel');
    const ORDER = { data: { order_number: 'ORD-A-1', payments: [NICEPAY_PAYMENT] } };

    // @scenario screen=admin_order, provider=easy_pay
    // @effects easy_pay_name_translated
    it('거래 조회가 원 수단 코드를 주면 번역된 서비스명을 보인다', async () => {
        await renderNodes([panel], { order: ORDER, nicepay_status: { data: { _embedded_pg_provider: 'payco' } } });

        expect(document.getElementById('nicepay_easy_pay_row')).not.toBeNull();
        expect(screen.getByText('간편결제 서비스')).toBeTruthy();
        expect(screen.getByText('PAYCO')).toBeTruthy();
    });

    // @scenario screen=admin_order, provider=none
    // @effects easy_pay_row_hidden_without_provider
    it('간편결제가 아닌 결제(원 수단 코드 없음)에는 행을 그리지 않는다', async () => {
        await renderNodes([panel], { order: ORDER, nicepay_status: { data: { Status: '0' } } });

        expect(document.getElementById('nicepay_verify_panel')).not.toBeNull();
        expect(document.getElementById('nicepay_easy_pay_row')).toBeNull();
    });
});

describe('마이페이지 주문 상세 — 결제 정보 카드 슬롯의 간편결제 서비스명', () => {
    const ORDER = { data: { order_number: 'ORD-M-1', payment: NICEPAY_PAYMENT } };

    // @scenario screen=mypage_order, provider=easy_pay
    // @effects easy_pay_name_translated
    it('영수증 조회 API 가 준 원 수단 코드를 번역된 서비스명으로 보인다', async () => {
        await renderNodes((mypageExtension as any).components, { order: ORDER }, {
            payment_receipt_url: 'https://receipt.example.test/p',
            receipt_url: 'https://receipt.example.test/s',
            embedded_pg_provider: 'naverpay',
        });

        expect(callsTo(globalThis.fetch, '/user/orders/ORD-M-1/receipt')).toHaveLength(1);
        expect(document.getElementById('nicepay_mp_easy_pay_row')).not.toBeNull();
        expect(screen.getByText('네이버페이')).toBeTruthy();
    });

    // @scenario screen=mypage_order, provider=none
    // @effects easy_pay_row_hidden_without_provider
    it('간편결제가 아니면(원 수단 코드 없음) 행을 그리지 않는다', async () => {
        await renderNodes((mypageExtension as any).components, { order: ORDER }, {
            payment_receipt_url: 'https://receipt.example.test/p',
            receipt_url: 'https://receipt.example.test/s',
            embedded_pg_provider: null,
        });

        expect(screen.getByText('매출전표 보기')).toBeTruthy();
        expect(document.getElementById('nicepay_mp_easy_pay_row')).toBeNull();
    });
});
