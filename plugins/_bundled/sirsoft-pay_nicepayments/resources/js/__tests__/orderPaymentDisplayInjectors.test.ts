import { describe, expect, it } from 'vitest';
import { patchAdminPaymentMethodDisplay } from '../adminOrderPaymentDisplayInjector';

describe('NicePayments order payment display injectors', () => {
    it('관리자 주문상세 결제수단 행을 간편결제 표시명으로 교체한다', () => {
        document.body.innerHTML = `
            <section id="section_payment_info">
                <div>
                    <div>결제수단</div>
                    <div>신용카드</div>
                    <div>(일시불)</div>
                </div>
            </section>
        `;

        const root = document.getElementById('section_payment_info');

        const patched = patchAdminPaymentMethodDisplay(root!, {
            _pay_method_label: '카카오페이 (신용카드)',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: '카카오페이',
        });

        expect(patched).toBe(true);
        expect(root?.textContent).toContain('카카오페이 (신용카드, 일시불)');
        expect(root?.textContent).not.toContain('(일시불)');
    });

    it('관리자 주문상세 결제수단 배지를 간편결제 라벨로 교체한다', () => {
        document.body.innerHTML = `
            <section id="section_payment_info">
                <span class="rounded-full font-medium">신용카드</span>
            </section>
        `;

        const root = document.getElementById('section_payment_info');

        const patched = patchAdminPaymentMethodDisplay(root!, {
            _pay_method_label: '네이버페이 (신용카드)',
            _base_pay_method_label: '신용카드',
            _embedded_pg_provider_label: '네이버페이',
        });

        expect(patched).toBe(true);
        expect(root?.querySelector('span')?.textContent).toBe('네이버페이');
    });
});
