/**
 * 관리자 결제수단 설정의 토스페이먼츠 브랜드 표시는 화면이 단 결제수단 id 표식으로만 찾는다 (#599)
 *
 * 종전에는 결제수단 항목을 이름 글자(한국어·영어 이름표)와 클래스 모양으로 찾아, 다른 언어 관리자 화면에서는 브랜드 표시가
 * 빠졌다(PO 결정 2026-09-28 「id 표식으로만 찾음」). 이제 이커머스 관리자 화면이 항목마다 단 data-payment-method
 * (결제수단 id)로만 찾는다.
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { resetAdminPaymentMethodBrandInjectorForTests, syncRenderedAdminPaymentMethodBrands } from '../adminPaymentMethodBrandInjector';

function item(method: string, title: string): string {
    return `<div class="excel-card" data-payment-method="${method}"><svg data-original-icon="true"></svg><span class="font-medium">${title}</span></div>`;
}

describe('토스페이먼츠 — 관리자 결제수단 항목 찾기', () => {
    beforeEach(() => {
        window.history.pushState({}, '', '/admin/ecommerce/settings?tab=order_settings');
    });

    afterEach(() => {
        resetAdminPaymentMethodBrandInjectorForTests();
        delete (window as any).G7Core;
        document.body.innerHTML = '';
    });

    /**
     * @scenario target=brand_mark,item=with_marker
     * @effects item_found_by_marker_only
     */
    it('브랜드 표시: 표식이 있으면 이름이 일본어여도 브랜드 배지를 붙인다', () => {
        document.body.innerHTML = item('toss_kakaopay', 'カカオペイ');

        expect(syncRenderedAdminPaymentMethodBrands()).toBe(true);
        expect(document.querySelector('[data-toss-admin-payment-brand-mark="true"]')).not.toBeNull();
    });

    /**
     * @scenario target=brand_mark,item=without_marker
     * @effects item_found_by_marker_only
     */
    it('브랜드 표시: 표식이 없으면 한국어 이름이 같아도 건드리지 않는다', () => {
        document.body.innerHTML = '<div class="excel-card"><svg data-original-icon="true"></svg><span class="font-medium">카카오페이 (토스페이먼츠)</span></div>';

        expect(syncRenderedAdminPaymentMethodBrands()).toBe(false);
        expect(document.querySelector('[data-toss-admin-payment-brand-mark="true"]')).toBeNull();
    });
});
