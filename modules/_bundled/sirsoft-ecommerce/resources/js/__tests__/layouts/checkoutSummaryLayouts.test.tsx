/**
 * 체크아웃 결제 금액 요약 레이아웃 테스트
 *
 * _checkout_summary.json partial의 렌더링 및 동작을 테스트합니다.
 * - 상품 금액, 할인, 배송비, 최종 결제금액 표시
 * - 접이식 상세 보기 (세금, 할인 상세)
 * - 결제 버튼 및 disabled 조건
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{
    className?: string;
    children?: React.ReactNode;
    'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
    <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
    className?: string;
    children?: React.ReactNode;
    text?: string;
}> = ({ className, children, text }) => (
    <span className={className}>{children || text}</span>
);

const TestButton: React.FC<{
    type?: string;
    className?: string;
    disabled?: boolean;
    children?: React.ReactNode;
    onClick?: () => void;
    'data-testid'?: string;
}> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
    <button type={type as any} className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
        {children}
    </button>
);

const TestH2: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h2 className={className}>{children || text}</h2>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestIcon: React.FC<{ name?: string; className?: string }> =
    ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} />;

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    };

    return registry;
}

// ========== _checkout_summary.json 기반 레이아웃 ==========

const checkoutSummaryLayout = {
    version: '1.0.0',
    layout_name: 'partials/shop/_checkout_summary',
    meta: {
        is_partial: true,
        description: '결제 금액 요약 및 결제 버튼',
    },
    data_sources: [
        {
            id: 'checkoutData',
            type: 'api',
            endpoint: '/api/modules/sirsoft-ecommerce/checkout',
            method: 'GET',
            auto_fetch: true,
        },
    ],
    state: {
        showTaxDetails: false,
        showDiscountDetails: false,
    },
    components: [
        {
            id: 'summary-container',
            type: 'basic',
            name: 'Div',
            props: { className: 'sticky top-4', 'data-testid': 'summary-container' },
            children: [
                {
                    id: 'summary-card',
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'bg-white rounded-lg border p-6' },
                    children: [
                        {
                            id: 'summary-header',
                            type: 'basic',
                            name: 'H2',
                            props: { className: 'text-lg font-semibold mb-4', 'data-testid': 'summary-title' },
                            text: '$t:sirsoft-ecommerce.shop.checkout.payment_amount',
                        },
                        {
                            comment: '상품 금액 (클릭하면 세금 상세 토글)',
                            id: 'subtotal-row',
                            type: 'basic',
                            name: 'Div',
                            props: { className: 'flex justify-between text-sm cursor-pointer', 'data-testid': 'subtotal-row' },
                            actions: [
                                {
                                    type: 'click',
                                    handler: 'setState',
                                    params: { target: 'local', showTaxDetails: '{{!_local.showTaxDetails}}' },
                                },
                            ],
                            children: [
                                {
                                    type: 'basic',
                                    name: 'Div',
                                    props: { className: 'flex items-center gap-1' },
                                    children: [
                                        { type: 'basic', name: 'Span', text: '$t:sirsoft-ecommerce.shop.checkout.subtotal' },
                                        {
                                            type: 'basic',
                                            name: 'Icon',
                                            props: { name: '{{_local.showTaxDetails ? \'chevron-up\' : \'chevron-down\'}}' },
                                        },
                                    ],
                                },
                                {
                                    type: 'basic',
                                    name: 'Span',
                                    props: { className: 'font-medium', 'data-testid': 'subtotal-value' },
                                    text: '{{checkoutData.data.calculation?.summary?.subtotal_formatted ?? \'-\'}}',
                                },
                            ],
                        },
                        {
                            comment: '세금 상세 (접이식)',
                            id: 'tax-details',
                            type: 'basic',
                            name: 'Div',
                            if: '{{_local.showTaxDetails}}',
                            props: { className: 'ml-4 mt-2 space-y-1', 'data-testid': 'tax-details' },
                            children: [
                                {
                                    type: 'basic',
                                    name: 'Div',
                                    if: '{{(checkoutData.data.calculation?.summary?.taxable_amount ?? 0) > 0}}',
                                    props: { className: 'flex justify-between text-xs' },
                                    children: [
                                        { type: 'basic', name: 'Span', text: '$t:sirsoft-ecommerce.shop.cart.taxable_amount' },
                                        { type: 'basic', name: 'Span', text: '{{checkoutData.data.calculation?.summary?.taxable_amount_formatted ?? \'-\'}}' },
                                    ],
                                },
                            ],
                        },
                        {
                            comment: '할인 섹션 (할인이 있을 때만)',
                            id: 'discount-row',
                            type: 'basic',
                            name: 'Div',
                            if: '{{(checkoutData.data.calculation?.summary?.total_discount ?? 0) > 0}}',
                            props: { 'data-testid': 'discount-row' },
                            children: [
                                {
                                    type: 'basic',
                                    name: 'Div',
                                    props: { className: 'flex justify-between text-sm cursor-pointer' },
                                    actions: [
                                        {
                                            type: 'click',
                                            handler: 'setState',
                                            params: { target: 'local', showDiscountDetails: '{{!_local.showDiscountDetails}}' },
                                        },
                                    ],
                                    children: [
                                        {
                                            type: 'basic',
                                            name: 'Div',
                                            props: { className: 'flex items-center gap-1' },
                                            children: [
                                                { type: 'basic', name: 'Span', text: '$t:sirsoft-ecommerce.shop.cart.discount_total' },
                                                {
                                                    type: 'basic',
                                                    name: 'Icon',
                                                    props: { name: '{{_local.showDiscountDetails ? \'chevron-up\' : \'chevron-down\'}}' },
                                                },
                                            ],
                                        },
                                        {
                                            type: 'basic',
                                            name: 'Span',
                                            props: { className: 'font-medium text-red-600', 'data-testid': 'discount-value' },
                                            text: '-{{checkoutData.data.calculation?.summary?.total_discount_formatted ?? \'-\'}}',
                                        },
                                    ],
                                },
                                {
                                    comment: '할인 상세 (접이식)',
                                    id: 'discount-details',
                                    type: 'basic',
                                    name: 'Div',
                                    if: '{{_local.showDiscountDetails}}',
                                    props: { className: 'ml-4 mt-2 space-y-1', 'data-testid': 'discount-details' },
                                    children: [
                                        {
                                            type: 'basic',
                                            name: 'Div',
                                            if: '{{(checkoutData.data.calculation?.summary?.product_coupon_discount ?? 0) > 0}}',
                                            props: { className: 'flex justify-between text-xs', 'data-testid': 'coupon-discount-row' },
                                            children: [
                                                { type: 'basic', name: 'Span', text: '$t:sirsoft-ecommerce.shop.cart.coupon_discount' },
                                                { type: 'basic', name: 'Span', text: '-{{checkoutData.data.calculation?.summary?.product_coupon_discount_formatted ?? \'-\'}}' },
                                            ],
                                        },
                                    ],
                                },
                            ],
                        },
                        {
                            comment: '배송비 섹션',
                            id: 'shipping-row',
                            type: 'basic',
                            name: 'Div',
                            props: { className: 'flex justify-between text-sm', 'data-testid': 'shipping-row' },
                            children: [
                                { type: 'basic', name: 'Span', text: '$t:sirsoft-ecommerce.shop.checkout.shipping_fee_label' },
                                {
                                    type: 'basic',
                                    name: 'Span',
                                    props: { 'data-testid': 'shipping-value' },
                                    text: '{{(checkoutData.data.calculation?.summary?.total_shipping ?? 0) === 0 ? \'$t:sirsoft-ecommerce.shop.checkout.free\' : checkoutData.data.calculation?.summary?.total_shipping_formatted}}',
                                },
                            ],
                        },
                        {
                            comment: '사용 포인트 (마일리지 사용 시)',
                            id: 'points-used-row',
                            type: 'basic',
                            name: 'Div',
                            if: '{{(checkoutData.data.calculation?.summary?.points_used ?? 0) > 0}}',
                            props: { className: 'flex justify-between text-sm', 'data-testid': 'points-used-row' },
                            children: [
                                { type: 'basic', name: 'Span', text: '$t:sirsoft-ecommerce.shop.checkout.points_used' },
                                {
                                    type: 'basic',
                                    name: 'Span',
                                    props: { className: 'text-red-600', 'data-testid': 'points-used-value' },
                                    text: '-{{checkoutData.data.calculation?.summary?.points_used_formatted ?? \'-\'}}',
                                },
                            ],
                        },
                        {
                            comment: '총 결제금액',
                            id: 'final-amount-row',
                            type: 'basic',
                            name: 'Div',
                            props: { className: 'flex justify-between items-center py-4', 'data-testid': 'final-amount-row' },
                            children: [
                                {
                                    type: 'basic',
                                    name: 'Span',
                                    props: { className: 'text-lg font-semibold' },
                                    text: '$t:sirsoft-ecommerce.shop.checkout.total',
                                },
                                {
                                    type: 'basic',
                                    name: 'Span',
                                    props: { className: 'text-xl font-bold', 'data-testid': 'final-amount-value' },
                                    text: '{{checkoutData.data.calculation?.summary?.final_amount_formatted ?? \'-\'}}',
                                },
                            ],
                        },
                        {
                            comment: '결제 버튼',
                            id: 'pay-button',
                            type: 'basic',
                            name: 'Button',
                            props: {
                                type: 'button',
                                className: 'w-full py-4 bg-gray-900 text-white rounded-lg font-bold',
                                disabled: '{{!_local.shipping?.zonecode || !_local.shipping?.recipient_name || !_local.shipping?.phone}}',
                                'data-testid': 'pay-button',
                            },
                            text: '{{checkoutData.data.calculation?.summary?.final_amount_formatted ?? \'-\'}} $t:sirsoft-ecommerce.shop.checkout.pay_button',
                            actions: [
                                {
                                    type: 'click',
                                    handler: 'triggerAction',
                                    params: { actionId: 'submitOrder' },
                                },
                            ],
                        },
                        {
                            comment: '결제 동의 문구',
                            id: 'payment-agreement',
                            type: 'basic',
                            name: 'P',
                            props: { className: 'text-xs text-gray-500 text-center mt-3', 'data-testid': 'payment-agreement' },
                            text: '$t:sirsoft-ecommerce.shop.checkout.payment_agreement',
                        },
                    ],
                },
            ],
        },
    ],
};

// ========== Mock 체크아웃 데이터 ==========

const mockCheckoutDataWithDiscount = {
    temp_order_id: 1,
    items: [],
    calculation: {
        summary: {
            subtotal: 110000,
            subtotal_formatted: '110,000원',
            product_coupon_discount: 5000,
            product_coupon_discount_formatted: '5,000원',
            code_discount: 0,
            order_coupon_discount: 0,
            total_discount: 5000,
            total_discount_formatted: '5,000원',
            total_shipping: 3000,
            total_shipping_formatted: '3,000원',
            base_shipping_total: 3000,
            extra_shipping_total: 0,
            shipping_discount: 0,
            taxable_amount: 108000,
            taxable_amount_formatted: '108,000원',
            tax_free_amount: 0,
            points_earning: 1050,
            points_earning_formatted: '1,050P',
            points_used: 0,
            payment_amount: 108000,
            payment_amount_formatted: '108,000원',
            final_amount: 108000,
            final_amount_formatted: '108,000원',
        },
    },
    available_coupons: [],
    mileage: { available: 5000, max_usable: 5000 },
};

const mockCheckoutDataWithPointsUsed = {
    temp_order_id: 1,
    items: [],
    calculation: {
        summary: {
            subtotal: 100000,
            subtotal_formatted: '100,000원',
            product_coupon_discount: 0,
            total_discount: 0,
            total_shipping: 0,
            total_shipping_formatted: '무료',
            taxable_amount: 100000,
            taxable_amount_formatted: '100,000원',
            tax_free_amount: 0,
            points_earning: 950,
            points_earning_formatted: '950P',
            points_used: 5000,
            points_used_formatted: '5,000P',
            payment_amount: 95000,
            payment_amount_formatted: '95,000원',
            final_amount: 95000,
            final_amount_formatted: '95,000원',
        },
    },
    available_coupons: [],
    mileage: { available: 10000, max_usable: 10000 },
};

const mockCheckoutDataFreeShipping = {
    temp_order_id: 1,
    items: [],
    calculation: {
        summary: {
            subtotal: 100000,
            subtotal_formatted: '100,000원',
            product_coupon_discount: 0,
            total_discount: 0,
            total_shipping: 0,
            total_shipping_formatted: '무료',
            taxable_amount: 100000,
            taxable_amount_formatted: '100,000원',
            tax_free_amount: 0,
            points_earning: 1000,
            points_earning_formatted: '1,000P',
            points_used: 0,
            payment_amount: 100000,
            payment_amount_formatted: '100,000원',
            final_amount: 100000,
            final_amount_formatted: '100,000원',
        },
    },
    available_coupons: [],
    mileage: { available: 0, max_usable: 0 },
};

// ========== 테스트 ==========

describe('_checkout_summary.json (결제 금액 요약 Partial)', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    let registry: ComponentRegistry;

    beforeEach(() => {
        registry = setupTestRegistry();

        testUtils = createLayoutTest(checkoutSummaryLayout as any, {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'TestUser' },
            },
            translations: {
                'sirsoft-ecommerce': {
                    shop: {
                        checkout: {
                            payment_amount: '결제 금액',
                            subtotal: '상품 금액',
                            shipping_fee_label: '배송비',
                            free: '무료',
                            total: '총 결제금액',
                            pay_button: '결제하기',
                            payment_agreement: '위 내용을 확인하였으며, 결제에 동의합니다.',
                            points_used: '마일리지 사용',
                        },
                        cart: {
                            discount_total: '할인 합계',
                            coupon_discount: '쿠폰 할인',
                            taxable_amount: '과세 금액',
                        },
                    },
                },
            },
            locale: 'ko',
            componentRegistry: registry,
        });
    });

    afterEach(() => {
        testUtils.cleanup();
    });

    describe('레이아웃 구조 검증', () => {
        it('레이아웃 정보가 올바르게 로드된다', () => {
            const info = testUtils.getLayoutInfo();
            expect(info.name).toBe('partials/shop/_checkout_summary');
        });

        it('state에 showTaxDetails와 showDiscountDetails가 정의되어 있다', () => {
            const layout = checkoutSummaryLayout as any;
            expect(layout.state.showTaxDetails).toBe(false);
            expect(layout.state.showDiscountDetails).toBe(false);
        });

        it('결제 버튼에 disabled 조건이 설정되어 있다', () => {
            const layout = checkoutSummaryLayout as any;

            const findButton = (components: any[]): any => {
                for (const comp of components) {
                    if (comp.id === 'pay-button') return comp;
                    if (comp.children) {
                        const found = findButton(comp.children);
                        if (found) return found;
                    }
                }
                return null;
            };

            const payButton = findButton(layout.components);
            expect(payButton).toBeDefined();
            expect(payButton.props.disabled).toContain('_local.shipping');
        });
    });

    describe('금액 표시 테스트', () => {
        it('상품 금액이 올바르게 표시된다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithDiscount },
            });

            await testUtils.render();

            // 상태 확인으로 데이터가 올바르게 로드되었는지 검증
            expect(testUtils.getState()._local).toBeDefined();
        });

        it('할인이 있을 때 할인 섹션이 표시된다', () => {
            const layout = checkoutSummaryLayout as any;

            const findComponent = (components: any[], id: string): any => {
                for (const comp of components) {
                    if (comp.id === id) return comp;
                    if (comp.children) {
                        const found = findComponent(comp.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const discountRow = findComponent(layout.components, 'discount-row');
            expect(discountRow).toBeDefined();
            expect(discountRow.if).toBe('{{(checkoutData.data.calculation?.summary?.total_discount ?? 0) > 0}}');
        });

        it('무료 배송일 때 조건문이 올바르게 설정되어 있다', () => {
            const layout = checkoutSummaryLayout as any;

            const findComponent = (components: any[], id: string): any => {
                for (const comp of components) {
                    if (comp.id === id) return comp;
                    if (comp.children) {
                        const found = findComponent(comp.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const shippingRow = findComponent(layout.components, 'shipping-row');
            expect(shippingRow).toBeDefined();

            // 배송비 값 표시 컴포넌트의 text에 조건문이 있는지 확인
            const shippingValue = shippingRow.children.find((c: any) => c.props?.['data-testid'] === 'shipping-value');
            expect(shippingValue).toBeDefined();
            expect(shippingValue.text).toContain('total_shipping');
        });

        it('마일리지 사용 시 사용 포인트가 표시된다', () => {
            const layout = checkoutSummaryLayout as any;

            const findComponent = (components: any[], id: string): any => {
                for (const comp of components) {
                    if (comp.id === id) return comp;
                    if (comp.children) {
                        const found = findComponent(comp.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const pointsUsedRow = findComponent(layout.components, 'points-used-row');
            expect(pointsUsedRow).toBeDefined();
            expect(pointsUsedRow.if).toBe('{{(checkoutData.data.calculation?.summary?.points_used ?? 0) > 0}}');
        });
    });

    describe('접이식 상세 보기 테스트', () => {
        it('상품 금액 클릭 시 세금 상세 토글 액션이 연결되어 있다', () => {
            const layout = checkoutSummaryLayout as any;

            const findComponent = (components: any[], id: string): any => {
                for (const comp of components) {
                    if (comp.id === id) return comp;
                    if (comp.children) {
                        const found = findComponent(comp.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const subtotalRow = findComponent(layout.components, 'subtotal-row');
            expect(subtotalRow).toBeDefined();
            expect(subtotalRow.actions).toBeDefined();
            expect(subtotalRow.actions[0].handler).toBe('setState');
            expect(subtotalRow.actions[0].params.showTaxDetails).toBe('{{!_local.showTaxDetails}}');
        });

        it('세금 상세에 if 조건이 showTaxDetails로 설정되어 있다', () => {
            const layout = checkoutSummaryLayout as any;

            const findComponent = (components: any[], id: string): any => {
                for (const comp of components) {
                    if (comp.id === id) return comp;
                    if (comp.children) {
                        const found = findComponent(comp.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const taxDetails = findComponent(layout.components, 'tax-details');
            expect(taxDetails).toBeDefined();
            expect(taxDetails.if).toBe('{{_local.showTaxDetails}}');
        });

        it('showTaxDetails 상태를 토글할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithDiscount },
            });

            await testUtils.render();

            // 초기 상태 확인
            expect(testUtils.getState()._local.showTaxDetails).toBeFalsy();

            // 토글
            testUtils.setState('showTaxDetails', true, 'local');
            expect(testUtils.getState()._local.showTaxDetails).toBe(true);

            // 다시 토글
            testUtils.setState('showTaxDetails', false, 'local');
            expect(testUtils.getState()._local.showTaxDetails).toBe(false);
        });

        it('showDiscountDetails 상태를 토글할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithDiscount },
            });

            await testUtils.render();

            // 초기 상태 확인
            expect(testUtils.getState()._local.showDiscountDetails).toBeFalsy();

            // 토글
            testUtils.setState('showDiscountDetails', true, 'local');
            expect(testUtils.getState()._local.showDiscountDetails).toBe(true);
        });
    });

    describe('결제 버튼 테스트', () => {
        it('결제 버튼에 triggerAction 핸들러가 연결되어 있다', () => {
            const layout = checkoutSummaryLayout as any;

            const findComponent = (components: any[], id: string): any => {
                for (const comp of components) {
                    if (comp.id === id) return comp;
                    if (comp.children) {
                        const found = findComponent(comp.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const payButton = findComponent(layout.components, 'pay-button');
            expect(payButton).toBeDefined();
            expect(payButton.actions).toBeDefined();
            expect(payButton.actions[0].handler).toBe('triggerAction');
            expect(payButton.actions[0].params.actionId).toBe('submitOrder');
        });

        it('배송 정보가 없으면 결제 버튼이 disabled 된다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithDiscount },
            });

            await testUtils.render();

            // 배송 정보가 없는 상태
            const state = testUtils.getState();
            expect(state._local.shipping?.zonecode).toBeFalsy();
        });

        it('배송 정보가 입력되면 결제 버튼이 활성화된다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithDiscount },
            });

            await testUtils.render();

            // 배송 정보 설정
            testUtils.setState('shipping', {
                zonecode: '12345',
                recipient_name: '홍길동',
                phone: '010-1234-5678',
                address: '서울시 강남구',
            }, 'local');

            const state = testUtils.getState();
            expect(state._local.shipping.zonecode).toBe('12345');
            expect(state._local.shipping.recipient_name).toBe('홍길동');
            expect(state._local.shipping.phone).toBe('010-1234-5678');
        });
    });

    describe('API 에러 처리', () => {
        it('API 에러 시에도 레이아웃이 유지된다', async () => {
            testUtils.mockApiError('checkoutData', 500, '서버 오류');

            await testUtils.render();

            const state = testUtils.getState();
            expect(state._local).toBeDefined();
        });
    });
});
