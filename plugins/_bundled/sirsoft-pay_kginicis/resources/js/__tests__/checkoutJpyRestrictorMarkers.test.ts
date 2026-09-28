/**
 * KG이니시스 엔화 결제수단 제한 — 화면 보정은 화면이 단 id·결제수단 표식으로만 찾는다 (#599)
 *
 * 응답을 거르는 앞단 제한과 별개로, 이미 그려진 주문서 결제수단 버튼을 숨기는 화면 보정이 있다. 종전에는 결제수단 칸을
 * 제목 글자(「결제 수단」「결제 방법」「Payment Method」)로, 버튼을 이름 글자(플러그인 안에 한국어·영어·일본어 이름표)로
 * 찾아, 번역이 다르면 보정이 빠졌다(PO 결정 2026-09-28 「id·표식으로만 찾음」). 이제 칸은 템플릿이 단
 * id(checkout_payment_method_section), 버튼은 data-payment-method(결제수단 id)로만 찾는다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { patchRenderedPaymentButtons, resetCheckoutJpyPaymentMethodRestrictorForTests } from '../checkoutJpyPaymentMethodRestrictor';

function setCurrency(currency: string): void {
    (window as any).__templateApp = {
        globalState: { modules: { 'sirsoft-ecommerce': { language_currency: { default_currency: currency } } } },
    };
}

function button(method: string, title: string): string {
    return `<button type="button" data-payment-method="${method}"><p>${title}</p></button>`;
}

describe('KG이니시스 엔화 제한 — 화면 보정 대상 찾기', () => {
    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        vi.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        resetCheckoutJpyPaymentMethodRestrictorForTests();
        delete (window as any).__templateApp;
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    /**
     * @scenario currency=jpy,section=with_id
     * @effects buttons_found_by_marker_only
     */
    it('엔화: 칸 id 가 있으면 제목·이름이 일본어여도 허용 밖 결제수단 버튼을 숨긴다', () => {
        setCurrency('JPY');
        document.body.innerHTML = `<div id="checkout_payment_method_section"><h2>お支払い方法</h2>
            ${button('card', 'クレジットカード払い')}${button('vbank', '仮想口座で支払う')}
            <button type="button"><p>가상계좌</p></button></div>`;

        expect(patchRenderedPaymentButtons()).toBe(true);
        const [card, vbank, unmarked] = Array.from(document.querySelectorAll<HTMLButtonElement>('button'));
        expect(card.style.display).toBe('');
        expect(vbank.style.display).toBe('none');
        expect(vbank.disabled).toBe(true);
        // 표식이 없는 버튼은 이름 글자(「가상계좌」)로 추측하지 않는다.
        expect(unmarked.style.display).toBe('');
    });

    /**
     * @scenario currency=krw,section=with_id
     * @effects buttons_found_by_marker_only
     */
    it('엔화 외: 칸 id 가 있으면 이름이 어떤 글자여도 일본 전용 결제수단 버튼을 숨긴다', () => {
        setCurrency('KRW');
        document.body.innerHTML = `<div id="checkout_payment_method_section"><h2>결제 수단</h2>
            ${button('card', '신용카드')}${button('kginicis_japan_paypay', 'PayPay で支払う')}</div>`;

        expect(patchRenderedPaymentButtons()).toBe(true);
        const [card, paypay] = Array.from(document.querySelectorAll<HTMLButtonElement>('button'));
        expect(card.style.display).toBe('');
        expect(paypay.style.display).toBe('none');
    });

    /**
     * @scenario currency=jpy,section=without_id
     * @effects buttons_found_by_marker_only
     */
    it('칸 id 가 없으면 「결제 수단」 제목과 한국어 이름이 있어도 건드리지 않는다', () => {
        setCurrency('JPY');
        document.body.innerHTML = `<div class="rounded-lg"><h2>결제 수단</h2>
            <button type="button"><p>신용카드</p></button><button type="button"><p>가상계좌</p></button></div>`;

        expect(patchRenderedPaymentButtons()).toBe(false);
        expect(Array.from(document.querySelectorAll<HTMLButtonElement>('button')).every((b) => b.style.display === '')).toBe(true);
    });

    /**
     * @scenario currency=krw,section=without_id
     * @effects buttons_found_by_marker_only
     */
    it('엔화 외: 칸 id 가 없으면 한국어 제목·일본 결제수단 이름이 있어도 건드리지 않는다', () => {
        setCurrency('KRW');
        document.body.innerHTML = `<div class="rounded-lg"><h2>결제 수단</h2>
            <button type="button"><p>PayPay (일본 KG이니시스)</p></button></div>`;

        expect(patchRenderedPaymentButtons()).toBe(false);
        expect(document.querySelector<HTMLButtonElement>('button')!.style.display).toBe('');
    });
});
