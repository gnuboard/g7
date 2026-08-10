import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    resetAdminPaymentMethodBrandInjectorForTests,
    syncRenderedAdminPaymentMethodBrands,
} from '../adminPaymentMethodBrandInjector';

function desktopItem(testId: string, title: string, description: string): string {
    return `
        <div class="flex-center border rounded-lg p-3 gap-4" data-test-item="${testId}">
            <div data-drag-handle="true"><svg data-drag-icon="true"></svg></div>
            <svg data-original-icon="true"></svg>
            <div class="flex-1 min-w-0">
                <div class="flex-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-gray-100">${title}</span>
                </div>
                <span class="text-label-subtle">${description}</span>
            </div>
            <div class="row-stack">
                <span>PG사</span>
                <select></select>
            </div>
        </div>
    `;
}

function mobileItem(testId: string, title: string): string {
    return `
        <div class="excel-card" data-test-item="${testId}">
            <div class="excel-card-header">
                <div class="flex-center gap-3 flex-1 min-w-0">
                    <div data-drag-handle="true"><svg data-drag-icon="true"></svg></div>
                    <svg data-original-icon="true"></svg>
                    <span class="font-medium text-gray-900 dark:text-gray-100 truncate">${title}</span>
                </div>
            </div>
            <div class="excel-card-body">
                <div class="row-stack"><label>PG사</label><select></select></div>
            </div>
        </div>
    `;
}

describe('adminPaymentMethodBrandInjector', () => {
    beforeEach(() => {
        document.documentElement.lang = 'ko';
        window.history.pushState({}, '', '/admin/ecommerce/settings?tab=order_settings');
        document.body.innerHTML = `
            ${desktopItem('naverpay', '네이버페이 (KG이니시스)', '네이버페이로 결제')}
            ${mobileItem('kakaopay', '카카오페이 (KG이니시스)')}
            ${desktopItem('nhn-naverpay', '네이버페이 (카드)', '네이버페이 신용카드로 결제 (NHN KCP)')}
            ${desktopItem('card', '신용카드', '신용카드로 결제')}
        `;
    });

    afterEach(() => {
        resetAdminPaymentMethodBrandInjectorForTests();
        document.body.innerHTML = '';
    });

    it('KG이니시스 간편결제 행의 기본 아이콘을 브랜드 텍스트 배지로 바꾼다', () => {
        expect(syncRenderedAdminPaymentMethodBrands()).toBe(true);

        const naverPay = document.querySelector<HTMLElement>('[data-test-item="naverpay"]');
        const kakaoPay = document.querySelector<HTMLElement>('[data-test-item="kakaopay"]');
        const nhnNaverPay = document.querySelector<HTMLElement>('[data-test-item="nhn-naverpay"]');
        const card = document.querySelector<HTMLElement>('[data-test-item="card"]');

        expect(naverPay?.dataset.kginicisAdminPaymentMethod).toBe('kginicis_naverpay');
        expect(naverPay?.querySelector('[data-kginicis-admin-payment-brand-mark="true"]')?.textContent).toBe('NPay');
        expect(naverPay?.querySelector('[data-original-icon="true"]')).toBeNull();
        expect(naverPay?.querySelector('[data-drag-icon="true"]')).not.toBeNull();

        expect(kakaoPay?.dataset.kginicisAdminPaymentMethod).toBe('kginicis_kakaopay');
        expect(kakaoPay?.querySelector('[data-kginicis-admin-payment-brand-mark="true"]')?.textContent).toBe('KakaoPay');

        expect(nhnNaverPay?.dataset.kginicisAdminPaymentMethod).toBeUndefined();
        expect(nhnNaverPay?.querySelector('[data-original-icon="true"]')).not.toBeNull();
        expect(card?.querySelector('[data-original-icon="true"]')).not.toBeNull();
    });

    it('주문설정 화면이 아니면 관리자 결제수단 아이콘을 바꾸지 않는다', () => {
        window.history.pushState({}, '', '/admin/ecommerce/settings?tab=shipping');

        expect(syncRenderedAdminPaymentMethodBrands()).toBe(false);
        expect(document.querySelector('[data-kginicis-admin-payment-brand-mark="true"]')).toBeNull();
    });
});
