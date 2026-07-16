import { afterEach, describe, expect, it } from 'vitest';
import { patchAdminPaymentMethodDisplay } from '../adminOrderPaymentDisplayInjector';
import { patchMypagePaymentMethodDisplay } from '../mypageOrderShowInjector';

describe('NHN KCP order payment display injectors', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('마이페이지 주문 상세의 결제 방법 행을 간편결제 표시로 바꾼다', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="flex items-center justify-between">
                <span class="text-gray-500">결제 방법</span>
                <span class="text-gray-900">신용카드</span>
            </div>
        `;

        expect(patchMypagePaymentMethodDisplay(container, '네이버페이 (신용카드)')).toBe(true);
        expect(container.textContent?.replace(/\s+/g, '')).toContain('결제방법네이버페이(신용카드)');
        expect(container.querySelector('[data-nhnkcp-payment-method-patched="true"]')).not.toBeNull();
    });

    it('관리자 주문 상세의 결제수단 행과 상단 배지를 간편결제 표시로 바꾼다', () => {
        const root = document.createElement('div');
        root.innerHTML = `
            <span class="inline-flex rounded-full font-medium">신용카드</span>
            <div>
                <span class="text-xs block">결제수단</span>
                <span class="text-sm font-semibold block">신용카드</span>
                <span class="text-xs text-gray-500">(일시불)</span>
            </div>
        `;

        expect(patchAdminPaymentMethodDisplay(root, {
            _pay_method_label: '카카오페이 (신용카드)',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: '카카오페이',
        })).toBe(true);

        const text = root.textContent?.replace(/\s+/g, '');
        expect(text).toContain('카카오페이결제수단카카오페이(신용카드,일시불)');
    });
});
