import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    patchAdminApplePayNotice,
    resetAdminApplePayNoticeInjectorForTests,
} from '../adminApplePayNoticeInjector';

describe('adminApplePayNoticeInjector', () => {
    beforeEach(() => {
        document.documentElement.lang = 'ko';
        window.history.pushState({}, '', '/admin/ecommerce/settings?tab=order_settings');
        document.body.innerHTML = `
            <section>
                <div>
                    <span>애플페이</span>
                </div>
            </section>
        `;
    });

    afterEach(() => {
        resetAdminApplePayNoticeInjectorForTests();
        document.body.innerHTML = '';
    });

    it('관리자 주문설정의 애플페이 항목에 iOS 모바일 결제 안내를 추가한다', () => {
        expect(patchAdminApplePayNotice()).toBe(true);

        expect(document.body.textContent).toContain('애플페이는 IOS 기기에 모바일결제만 가능합니다.');
        expect(document.querySelectorAll('[data-nhnkcp-admin-apple-pay-notice="true"]')).toHaveLength(1);
    });

    it('주문설정 화면이 아니면 안내를 추가하지 않는다', () => {
        window.history.pushState({}, '', '/admin/ecommerce/settings?tab=shipping');

        expect(patchAdminApplePayNotice()).toBe(false);
        expect(document.body.textContent).not.toContain('애플페이는 IOS 기기에 모바일결제만 가능합니다.');
    });
});
