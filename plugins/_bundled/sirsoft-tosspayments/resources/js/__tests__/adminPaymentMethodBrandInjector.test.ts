/**
 * 관리자 주문설정 브랜드 마크 주입 회귀.
 *
 * 배경: #475 "확장 결제수단 1급화" 가 nicepayments·nhnkcp·kginicis 에 관리자 브랜드
 * injector 를 도입할 때 토스는 전환 대상에서 빠졌다(그 커밋에 tosspayments 파일 0건).
 * 관리자 레이아웃은 아이콘 열을 `Icon name={{$method._cached_icon}}` 하나로만 그리므로,
 * injector 가 없는 플러그인의 결제수단은 브랜드 배지 없이 회색 기본 아이콘으로 남는다.
 */
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

describe('adminPaymentMethodBrandInjector (tosspayments)', () => {
    beforeEach(() => {
        document.documentElement.lang = 'ko';
        window.history.pushState({}, '', '/admin/ecommerce/settings?tab=order_settings');
        document.body.innerHTML = `
            ${desktopItem('toss-naverpay', '네이버페이 (토스페이먼츠)', '네이버페이 간편결제 — 토스페이먼츠를 통해 처리')}
            ${mobileItem('toss-kakaopay', '카카오페이 (토스페이먼츠)')}
            ${desktopItem('nice-naverpay', '네이버페이 (나이스페이먼츠)', '네이버페이로 결제 (나이스페이먼츠)')}
            ${desktopItem('toss-card', '신용카드 (토스페이먼츠)', '신용·체크카드로 결제 — 토스페이먼츠를 통해 처리')}
        `;
    });

    afterEach(() => {
        resetAdminPaymentMethodBrandInjectorForTests();
        document.body.innerHTML = '';
    });

    it('토스 간편결제 행의 기본 아이콘을 브랜드 배지로 바꾼다', () => {
        expect(syncRenderedAdminPaymentMethodBrands()).toBe(true);

        const naverpay = document.querySelector<HTMLElement>('[data-test-item="toss-naverpay"]');
        expect(naverpay?.dataset.tossAdminPaymentMethod).toBe('toss_naverpay');
        expect(naverpay?.querySelector('[data-toss-admin-payment-brand-mark="true"]')?.textContent).toBe('N');
        // 원래 아이콘은 치환되고, 드래그 핸들 아이콘은 남아야 한다
        expect(naverpay?.querySelector('[data-original-icon="true"]')).toBeNull();
        expect(naverpay?.querySelector('[data-drag-icon="true"]')).not.toBeNull();
    });

    it('모바일 카드 뷰에서도 동일하게 주입한다', () => {
        expect(syncRenderedAdminPaymentMethodBrands()).toBe(true);

        const kakaopay = document.querySelector<HTMLElement>('[data-test-item="toss-kakaopay"]');
        expect(kakaopay?.dataset.tossAdminPaymentMethod).toBe('toss_kakaopay');
        expect(kakaopay?.querySelector('[data-toss-admin-payment-brand-mark="true"]')?.textContent).toBe('K');
    });

    it('다른 PG 의 같은 브랜드 행은 건드리지 않는다 (플러그인 간 침범 금지)', () => {
        syncRenderedAdminPaymentMethodBrands();

        const nice = document.querySelector<HTMLElement>('[data-test-item="nice-naverpay"]');
        expect(nice?.dataset.tossAdminPaymentMethod).toBeUndefined();
        expect(nice?.querySelector('[data-original-icon="true"]')).not.toBeNull();
    });

    it('브랜드가 아닌 수단(카드)은 기본 아이콘을 유지한다', () => {
        syncRenderedAdminPaymentMethodBrands();

        const card = document.querySelector<HTMLElement>('[data-test-item="toss-card"]');
        expect(card?.dataset.tossAdminPaymentMethod).toBeUndefined();
        expect(card?.querySelector('[data-original-icon="true"]')).not.toBeNull();
    });

    it('주문설정 화면이 아니면 아무것도 바꾸지 않는다', () => {
        window.history.pushState({}, '', '/admin/ecommerce/settings?tab=shipping');

        expect(syncRenderedAdminPaymentMethodBrands()).toBe(false);
        expect(document.querySelector('[data-toss-admin-payment-brand-mark="true"]')).toBeNull();
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
        expect(document.querySelectorAll('[data-toss-admin-payment-brand-mark="true"]').length).toBeGreaterThan(0);

        // 다른 탭으로 이동 (replaceState) — 화면이 갈아끼워지며 배지가 사라진 상태를 재현
        window.history.replaceState({}, '', '/admin/ecommerce/settings?tab=shipping');
        document.body.innerHTML = '';
        await new Promise((r) => setTimeout(r, 300));

        // 주문설정으로 복귀 (replaceState) + 행 재렌더
        document.body.innerHTML = desktopItem('toss-naverpay', '네이버페이 (토스페이먼츠)', '네이버페이 간편결제 — 토스페이먼츠를 통해 처리');
        window.history.replaceState({}, '', '/admin/ecommerce/settings?tab=order_settings');
        await new Promise((r) => setTimeout(r, 600));

        expect(
            document.querySelectorAll('[data-toss-admin-payment-brand-mark="true"]').length,
            'replaceState 복귀 후 배지가 재주입되지 않았다',
        ).toBeGreaterThan(0);
    });
});
