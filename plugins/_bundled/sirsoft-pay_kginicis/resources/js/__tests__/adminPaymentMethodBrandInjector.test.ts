import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    installAdminPaymentMethodBrandInjector,
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

    /**
     * 탭 전환 후 복귀 시 배지 재주입 (브라우저 실측으로 발견한 회귀).
     *
     * 환경설정 화면의 탭 전환은 pushState 가 아니라 **replaceState** 로 URL 만 바꾼다
     * (탭 왕복 1회에 push 0 / replace 2 실측). pushState/popstate 만 후킹하던 기존 구현은
     * 다른 탭에 갔다 돌아왔을 때 onRouteChange 가 발화하지 않아, 행은 다시 렌더되는데
     * 배지만 사라진 채로 남았다 — 사용자에게는 아이콘이 회색으로 되돌아간 것으로 보인다.
     */
    it('replaceState 로 탭을 전환했다 돌아와도 배지를 재주입한다', async () => {
        installAdminPaymentMethodBrandInjector();
        expect(document.querySelectorAll('[data-kginicis-admin-payment-brand-mark="true"]').length).toBeGreaterThan(0);

        // 다른 탭으로 이동 (replaceState) — 화면이 갈아끼워지며 배지가 사라진 상태를 재현
        window.history.replaceState({}, '', '/admin/ecommerce/settings?tab=shipping');
        document.body.innerHTML = '';
        await new Promise((r) => setTimeout(r, 300));

        // 주문설정으로 복귀 (replaceState) + 행 재렌더
        document.body.innerHTML = desktopItem('naverpay', '네이버페이 (KG이니시스)', '네이버페이로 결제');
        window.history.replaceState({}, '', '/admin/ecommerce/settings?tab=order_settings');
        await new Promise((r) => setTimeout(r, 600));

        expect(
            document.querySelectorAll('[data-kginicis-admin-payment-brand-mark="true"]').length,
            'replaceState 복귀 후 배지가 재주입되지 않았다',
        ).toBeGreaterThan(0);
    });
});
