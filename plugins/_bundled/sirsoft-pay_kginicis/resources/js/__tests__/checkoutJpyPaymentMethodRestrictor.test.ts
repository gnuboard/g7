import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    hideJapanPaymentMethodsForNonJpy,
    installCheckoutJpyPaymentMethodRestrictor,
    resetCheckoutJpyPaymentMethodRestrictorForTests,
    restrictPaymentSettingsForJpy,
} from '../checkoutJpyPaymentMethodRestrictor';

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function paymentSettingsBody() {
    return {
        success: true,
        data: {
            order_settings: {
                payment_methods: [
                    { id: 'card', is_active: true },
                    { id: 'vbank', is_active: true },
                    { id: 'bank', is_active: true },
                    { id: 'phone', is_active: true },
                    { id: 'kginicis_kakaopay', is_active: true },
                    { id: 'kginicis_japan_paypay', is_active: true },
                    { id: 'kginicis_japan_cvs', is_active: true },
                ],
            },
        },
    };
}

function installJpyGlobalState(): void {
    windowRecord()['__templateApp'] = {
        globalState: {
            modules: {
                'sirsoft-ecommerce': {
                    language_currency: {
                        default_currency: 'JPY',
                    },
                },
            },
        },
    };
}

function installKrwGlobalState(): void {
    windowRecord()['__templateApp'] = {
        globalState: {
            modules: {
                'sirsoft-ecommerce': {
                    language_currency: {
                        default_currency: 'KRW',
                    },
                },
            },
        },
    };
}

describe('checkoutJpyPaymentMethodRestrictor', () => {
    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        installJpyGlobalState();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        resetCheckoutJpyPaymentMethodRestrictorForTests();
        delete windowRecord()['__templateApp'];
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('JPY 제한 시 허용된 CBT 결제수단만 활성 상태로 남긴다', () => {
        const restricted = restrictPaymentSettingsForJpy(paymentSettingsBody());
        const methods = restricted.data?.order_settings?.payment_methods ?? [];
        const activeIds = methods.filter((method) => method.is_active).map((method) => method.id);

        expect(activeIds).toEqual(['card', 'kginicis_japan_paypay', 'kginicis_japan_cvs']);
        expect(methods.find((method) => method.id === 'kginicis_kakaopay')).toMatchObject({
            is_active: false,
            _kginicis_restricted_for_jpy: true,
        });
    });

    it('KRW 등 비-JPY 주문에서 일본 전용 결제수단(PayPay/일본 편의점)을 숨긴다', () => {
        const restricted = hideJapanPaymentMethodsForNonJpy(paymentSettingsBody());
        const methods = restricted.data?.order_settings?.payment_methods ?? [];
        const activeIds = methods.filter((method) => method.is_active).map((method) => method.id);

        expect(activeIds).toEqual(['card', 'vbank', 'bank', 'phone', 'kginicis_kakaopay']);
        expect(methods.find((method) => method.id === 'kginicis_japan_paypay')).toMatchObject({
            is_active: false,
            _kginicis_restricted_for_non_jpy: true,
        });
        expect(methods.find((method) => method.id === 'kginicis_japan_cvs')).toMatchObject({
            is_active: false,
            _kginicis_restricted_for_non_jpy: true,
        });
    });

    it('KRW 주문 시 체크아웃 결제 설정 응답에서 일본 결제수단만 비활성화한다', async () => {
        resetCheckoutJpyPaymentMethodRestrictorForTests();
        installKrwGlobalState();

        const fetchSpy = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url.includes('/payments/client-config/kginicis')) {
                return new Response(JSON.stringify({
                    data: { japan_restrict_jpy_payment_methods: true },
                }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }

            if (url.includes('/settings/payment')) {
                return new Response(JSON.stringify(paymentSettingsBody()), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                });
            }

            return new Response('{}', { status: 404 });
        });

        window.fetch = fetchSpy as unknown as typeof fetch;
        installCheckoutJpyPaymentMethodRestrictor();

        const response = await window.fetch('/api/modules/sirsoft-ecommerce/settings/payment');
        const body = await response.json();
        const activeIds = body.data.order_settings.payment_methods
            .filter((method: { is_active?: boolean }) => method.is_active)
            .map((method: { id?: string }) => method.id);

        expect(activeIds).toEqual(['card', 'vbank', 'bank', 'phone', 'kginicis_kakaopay']);
    });

    it('KRW 주문 시 어드민 toggle(japan_restrict_jpy_payment_methods)이 false 여도 일본 결제수단은 숨긴다', async () => {
        resetCheckoutJpyPaymentMethodRestrictorForTests();
        installKrwGlobalState();

        const fetchSpy = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url.includes('/payments/client-config/kginicis')) {
                return new Response(JSON.stringify({
                    data: { japan_restrict_jpy_payment_methods: false },
                }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }

            if (url.includes('/settings/payment')) {
                return new Response(JSON.stringify(paymentSettingsBody()), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                });
            }

            return new Response('{}', { status: 404 });
        });

        window.fetch = fetchSpy as unknown as typeof fetch;
        installCheckoutJpyPaymentMethodRestrictor();

        const response = await window.fetch('/api/modules/sirsoft-ecommerce/settings/payment');
        const body = await response.json();
        const activeIds = body.data.order_settings.payment_methods
            .filter((method: { is_active?: boolean }) => method.is_active)
            .map((method: { id?: string }) => method.id);

        expect(activeIds).toEqual(['card', 'vbank', 'bank', 'phone', 'kginicis_kakaopay']);
    });

    it('체크아웃 결제 설정 응답을 렌더링 전에 보정한다', async () => {
        const fetchSpy = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url.includes('/payments/client-config/kginicis')) {
                return new Response(JSON.stringify({
                    data: { japan_restrict_jpy_payment_methods: true },
                }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }

            if (url.includes('/settings/payment')) {
                return new Response(JSON.stringify(paymentSettingsBody()), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                });
            }

            return new Response('{}', { status: 404 });
        });

        window.fetch = fetchSpy as unknown as typeof fetch;
        installCheckoutJpyPaymentMethodRestrictor();

        const response = await window.fetch('/api/modules/sirsoft-ecommerce/settings/payment');
        const body = await response.json();
        const activeIds = body.data.order_settings.payment_methods
            .filter((method: { is_active?: boolean }) => method.is_active)
            .map((method: { id?: string }) => method.id);

        expect(activeIds).toEqual(['card', 'kginicis_japan_paypay', 'kginicis_japan_cvs']);
    });
});
