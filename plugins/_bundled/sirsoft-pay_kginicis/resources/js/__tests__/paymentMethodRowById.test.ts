/**
 * 결제 방법 행은 화면이 약속한 id 로만 찾는다 — KG이니시스 (#599)
 *
 * 간편결제 이름(예: 카카오페이)을 결제 방법 행에 덧붙일 때 종전에는 행 이름 글자(「결제 방법」「결제수단」「Payment Method」)로
 * 행을 찾아, 그 밖의 언어 화면(일본어 등, 관리자는 한국어 외 전부)에서는 조용히 빠졌다. 사용자 화면은 템플릿이 단
 * id(order_payment_method_row), 관리자 주문 상세는 이커머스가 단 id(payment_method_block_{결제 순번})로만 찾는다
 * (PO 결정 2026-09-28 「id 로만 찾음」). 주문 완료 화면도 같은 id 약속을 따른다(기본 템플릿에는 그 행이 없다).
 */

import { afterEach, describe, expect, it } from 'vitest';

import { patchAdminPaymentMethodDisplay } from '../adminOrderPaymentDisplayInjector';
import { patchMypagePaymentMethodDisplay } from '../mypageOrderShowInjector';
import { patchPaymentMethodDisplay as patchOrderCompletePaymentMethodDisplay } from '../orderCompleteReceiptInjector';

const EASY = { _pay_method_label: '카카오페이 (신용카드)', _base_pay_method_label: null, _embedded_pg_provider_label: '카카오페이' };

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KG이니시스 — 결제 방법 행 찾기', () => {
    /**
     * @scenario screen=mypage_order,row=with_id
     * @effects row_found_by_id_only
     */
    it('주문 상세: id 가 있으면 행 이름이 일본어여도 간편결제 이름을 붙인다', () => {
        const container = document.createElement('div');
        container.innerHTML = '<div id="order_payment_method_row"><span>お支払い方法</span><span>クレジットカード</span></div>';

        expect(patchMypagePaymentMethodDisplay(container, 'カカオペイ (クレジットカード)')).toBe(true);
        expect(container.querySelector('#order_payment_method_row span:last-child')!.textContent).toBe('カカオペイ (クレジットカード)');
    });

    /**
     * @scenario screen=mypage_order,row=without_id
     * @effects row_found_by_id_only
     */
    it('주문 상세: id 가 없으면 「결제 방법」 글자가 있어도 건드리지 않는다', () => {
        const container = document.createElement('div');
        container.innerHTML = '<div><span>결제 방법</span><span>신용카드</span></div>';

        expect(patchMypagePaymentMethodDisplay(container, '카카오페이 (신용카드)')).toBe(false);
        expect(container.textContent).toBe('결제 방법신용카드');
    });

    /**
     * @scenario screen=admin_order,row=with_id
     * @effects row_found_by_id_only
     */
    it('관리자 주문 상세: id 가 있으면 칸 이름이 영어여도 간편결제 표시로 바꾼다', () => {
        const root = document.createElement('div');
        root.innerHTML = '<div id="payment_method_block_0"><span>Payment Method</span><span>Credit Card</span></div>';

        expect(patchAdminPaymentMethodDisplay(root, EASY)).toBe(true);
        expect(root.querySelector('#payment_method_block_0')!.children[1].textContent).toBe('카카오페이 (신용카드)');
    });

    /**
     * @scenario screen=admin_order,row=without_id
     * @effects row_found_by_id_only
     */
    it('관리자 주문 상세: id 가 없으면 「결제수단」 글자가 있어도 건드리지 않는다', () => {
        const root = document.createElement('div');
        root.innerHTML = '<div><span>결제수단</span><span>신용카드</span></div>';

        expect(patchAdminPaymentMethodDisplay(root, EASY)).toBe(false);
        expect(root.textContent).toBe('결제수단신용카드');
    });

    /**
     * @scenario screen=order_complete,row=with_id
     * @effects row_found_by_id_only
     */
    it('주문 완료: 같은 id 의 행이 있으면 행 이름이 일본어여도 간편결제 이름을 붙인다', () => {
        document.body.innerHTML = '<div id="order_payment_method_row"><span>お支払い方法</span><span>クレジットカード</span></div>';

        expect(patchOrderCompletePaymentMethodDisplay('カカオペイ (クレジットカード)')).toBe(true);
        expect(document.querySelector('#order_payment_method_row span:last-child')!.textContent).toBe('カカオペイ (クレジットカード)');
    });

    /**
     * @scenario screen=order_complete,row=without_id
     * @effects row_found_by_id_only
     */
    it('주문 완료: id 가 없으면 「결제 방법」 글자가 있어도 건드리지 않는다', () => {
        document.body.innerHTML = '<div><span>결제 방법</span><span>신용카드</span></div>';

        expect(patchOrderCompletePaymentMethodDisplay('카카오페이 (신용카드)')).toBe(false);
        expect(document.body.textContent).toBe('결제 방법신용카드');
    });
});
