/**
 * 체크아웃(주문서) 레이아웃 렌더링 테스트
 *
 * createLayoutTest() 유틸리티를 사용한 실제 렌더링 기반 테스트입니다.
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

const TestInput: React.FC<{
    type?: string;
    placeholder?: string;
    value?: string;
    className?: string;
    readOnly?: boolean;
    name?: string;
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
    'data-testid'?: string;
}> = ({ type, placeholder, value, className, readOnly, name, onChange, 'data-testid': testId }) => (
    <input type={type} placeholder={placeholder} value={value} className={className}
        readOnly={readOnly} name={name} onChange={onChange} data-testid={testId} />
);

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h1 className={className}>{children || text}</h1>;

const TestH2: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h2 className={className}>{children || text}</h2>;

const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestLabel: React.FC<{ className?: string; children?: React.ReactNode; text?: string; htmlFor?: string }> =
    ({ className, children, text, htmlFor }) => <label className={className} htmlFor={htmlFor}>{children || text}</label>;

const TestIcon: React.FC<{ name?: string; className?: string }> =
    ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} />;

const TestFragment: React.FC<{ children?: React.ReactNode }> =
    ({ children }) => <>{children}</>;

const TestImg: React.FC<{ src?: string; alt?: string; className?: string }> =
    ({ src, alt, className }) => <img src={src} alt={alt} className={className} />;

const TestSelect: React.FC<{
    value?: string;
    className?: string;
    children?: React.ReactNode;
    options?: any[];
    name?: string;
    disabled?: boolean;
    'data-testid'?: string;
}> = ({ value, className, children, name, disabled, 'data-testid': testId }) => (
    <select value={value} className={className} name={name} disabled={disabled} data-testid={testId}>{children}</select>
);

const TestOption: React.FC<{ value?: string; disabled?: boolean; children?: React.ReactNode }> =
    ({ value, disabled, children }) => <option value={value} disabled={disabled}>{children}</option>;

const TestModal: React.FC<{
    id?: string;
    isOpen?: boolean;
    title?: string;
    size?: string;
    children?: React.ReactNode;
}> = ({ id, isOpen, title, children }) => (
    isOpen ? (
        <div data-testid={`modal-${id}`} role="dialog">
            <h2>{title}</h2>
            {children}
        </div>
    ) : null
);

const TestForm: React.FC<{ dataKey?: string; children?: React.ReactNode }> =
    ({ dataKey, children }) => <form data-testid={`form-${dataKey}`}>{children}</form>;

const TestContainer: React.FC<{ className?: string; children?: React.ReactNode }> =
    ({ className, children }) => <div className={className} data-testid="container">{children}</div>;

const TestGrid: React.FC<{ cols?: number; gap?: number; className?: string; children?: React.ReactNode }> =
    ({ cols, gap, className, children }) => (
        <div className={className} data-cols={cols} data-gap={gap} data-testid="grid">{children}</div>
    );

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        // Basic 컴포넌트
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
        H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
        H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
        H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
        Option: { component: TestOption, metadata: { name: 'Option', type: 'basic' } },

        // Composite 컴포넌트
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
        Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
        Form: { component: TestForm, metadata: { name: 'Form', type: 'composite' } },

        // Layout 컴포넌트
        Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
        Grid: { component: TestGrid, metadata: { name: 'Grid', type: 'layout' } },
    };

    return registry;
}

// ========== 체크아웃 레이아웃 JSON (간소화) ==========

const checkoutLayout = {
    version: '1.0.0',
    layout_name: 'shop/checkout',
    permissions: [],
    meta: {
        title: '주문서 작성',
        description: '주문서 작성 페이지',
    },
    data_sources: [
        {
            id: 'checkoutData',
            type: 'api',
            endpoint: '/api/modules/sirsoft-ecommerce/checkout',
            method: 'GET',
            auto_fetch: true,
            auth_mode: 'optional',
            loading_strategy: 'progressive',
        },
        {
            id: 'paymentMethods',
            type: 'static',
            data: [
                { code: 'card', name: '신용카드', description: '신용/체크카드 결제' },
                { code: 'vbank', name: '무통장입금', description: '주문 후 계좌로 입금' },
            ],
        },
    ],
    state: {
        paymentMethod: 'card',
    },
    init_actions: [
        {
            handler: 'setState',
            params: { target: 'local', paymentMethod: 'card' },
        },
    ],
    components: [
        {
            id: 'main-container',
            type: 'layout',
            name: 'Container',
            props: { className: 'py-8' },
            children: [
                {
                    id: 'page-title',
                    type: 'basic',
                    name: 'H1',
                    props: { className: 'text-2xl font-bold', 'data-testid': 'checkout-title' },
                    text: '$t:sirsoft-ecommerce.shop.checkout.title',
                },
            ],
        },
    ],
    modals: {
        exclusiveCouponConfirm: {
            type: 'composite',
            name: 'Modal',
            props: { title: '$t:sirsoft-ecommerce.shop.checkout.exclusive_coupon_title', size: 'medium' },
            children: [
                {
                    type: 'basic',
                    name: 'P',
                    text: '$t:sirsoft-ecommerce.shop.checkout.exclusive_coupon_message',
                },
            ],
        },
    },
    actions: [
        {
            id: 'submitOrder',
            type: 'submit',
            handler: 'apiCall',
            auth_required: true,
            target: '/api/modules/sirsoft-ecommerce/checkout/submit',
            params: {
                method: 'POST',
                body: {
                    payment_method: '{{_local.paymentMethod}}',
                },
            },
            onSuccess: [
                { handler: 'navigate', params: { url: '{{response.data.payment_url ?? \'/mypage?tab=orders\'}}' } },
            ],
        },
    ],
};

// ========== Mock 체크아웃 데이터 ==========

const mockCheckoutData = {
    temp_order_id: 1,
    items: [
        {
            id: 1,
            product_option_id: 101,
            product: {
                id: 1,
                name: '테스트 상품 A',
                thumbnail: '/img/product-a.jpg',
            },
            option_text: '빨강/L',
            quantity: 2,
            unit_price: 30000,
            unit_price_formatted: '30,000원',
            subtotal: 60000,
            subtotal_formatted: '60,000원',
            product_coupon_discount_amount: 0,
            total_discount: 0,
            final_amount: 60000,
            final_amount_formatted: '60,000원',
        },
        {
            id: 2,
            product_option_id: 102,
            product: {
                id: 2,
                name: '테스트 상품 B',
                thumbnail: '/img/product-b.jpg',
            },
            option_text: '파랑/M',
            quantity: 1,
            unit_price: 50000,
            unit_price_formatted: '50,000원',
            subtotal: 50000,
            subtotal_formatted: '50,000원',
            product_coupon_discount_amount: 5000,
            product_coupon_discount_formatted: '5,000원',
            total_discount: 5000,
            total_discount_formatted: '5,000원',
            final_amount: 45000,
            final_amount_formatted: '45,000원',
        },
    ],
    calculation: {
        summary: {
            subtotal: 110000,
            subtotal_formatted: '110,000원',
            product_coupon_discount: 5000,
            product_coupon_discount_formatted: '5,000원',
            total_discount: 5000,
            total_discount_formatted: '5,000원',
            total_shipping: 3000,
            total_shipping_formatted: '3,000원',
            base_shipping_total: 3000,
            base_shipping_total_formatted: '3,000원',
            extra_shipping_total: 0,
            shipping_discount: 0,
            taxable_amount: 108000,
            tax_free_amount: 0,
            points_earning: 1050,
            points_earning_formatted: '1,050P',
            points_used: 0,
            payment_amount: 108000,
            payment_amount_formatted: '108,000원',
            final_amount: 108000,
            final_amount_formatted: '108,000원',
            multi_currency: {
                KRW: {
                    subtotal_formatted: '110,000원',
                    product_coupon_discount_formatted: '5,000원',
                    total_discount_formatted: '5,000원',
                    total_shipping_formatted: '3,000원',
                    payment_amount_formatted: '108,000원',
                    final_amount_formatted: '108,000원',
                },
            },
        },
    },
    available_coupons: [
        {
            id: 1,
            name: '10% 할인 쿠폰',
            coupon: {
                id: 1,
                name: '신규회원 10% 할인',
                target_type: 'product',
                benefit_display: '10% 할인',
                is_combinable: true,
            },
        },
        {
            id: 2,
            name: '5000원 할인 쿠폰',
            coupon: {
                id: 2,
                name: '5,000원 할인',
                target_type: 'product',
                benefit_display: '5,000원 할인',
                is_combinable: true,
            },
        },
        {
            id: 3,
            name: '주문 할인 쿠폰',
            coupon: {
                id: 3,
                name: '주문금액 3% 할인',
                target_type: 'order',
                benefit_display: '3% 할인',
                is_combinable: false,
            },
        },
    ],
    mileage: {
        available: 5000,
        max_usable: 5000,
    },
    expires_at: '2026-02-02T23:30:00Z',
};

// ========== 메인 레이아웃 테스트 ==========

describe('checkout.json (체크아웃 메인 레이아웃)', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    let registry: ComponentRegistry;

    beforeEach(() => {
        registry = setupTestRegistry();

        testUtils = createLayoutTest(checkoutLayout as any, {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'TestUser' },
            },
            translations: {
                'sirsoft-ecommerce': {
                    shop: {
                        checkout: {
                            title: '주문서 작성',
                            back: '장바구니로 돌아가기',
                            order_items: '주문 상품',
                            payment_amount: '결제 금액',
                            subtotal: '상품 금액',
                            shipping_fee_label: '배송비',
                            free: '무료',
                            total: '총 결제금액',
                            pay_button: '결제하기',
                            payment_agreement: '위 내용을 확인하였으며, 결제에 동의합니다.',
                            points_used: '마일리지 사용',
                            exclusive_coupon_title: '배타적 쿠폰 적용',
                            exclusive_coupon_message: '이 쿠폰은 다른 쿠폰과 함께 사용할 수 없습니다.',
                            product_coupon_1: '상품 쿠폰 1 선택',
                            product_coupon_2: '상품 쿠폰 2 선택',
                        },
                        cart: {
                            coupon_discount: '쿠폰 할인',
                            discount_total: '할인 합계',
                            expected_mileage: '적립 예정 마일리지',
                        },
                        count_unit: '개',
                    },
                },
                common: {
                    cancel: '취소',
                    confirm: '확인',
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
            expect(info.name).toBe('shop/checkout');
            expect(info.version).toBe('1.0.0');
        });

        it('checkoutData 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            expect(dataSources.length).toBeGreaterThan(0);

            const checkoutDs = dataSources.find((ds: any) => ds.id === 'checkoutData');
            expect(checkoutDs).toBeDefined();
            expect(checkoutDs?.type).toBe('api');
            expect(checkoutDs?.endpoint).toContain('checkout');
            expect(checkoutDs?.method).toBe('GET');
        });

        it('paymentMethods 정적 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            const paymentDs = dataSources.find((ds: any) => ds.id === 'paymentMethods');
            expect(paymentDs).toBeDefined();
            expect(paymentDs?.type).toBe('static');
            expect(Array.isArray(paymentDs?.data)).toBe(true);
            expect(paymentDs?.data).toHaveLength(2);
        });

        it('modals에 exclusiveCouponConfirm이 정의되어 있다', () => {
            const layout = checkoutLayout as any;
            expect(layout.modals).toBeDefined();
            expect(layout.modals.exclusiveCouponConfirm).toBeDefined();
            expect(layout.modals.exclusiveCouponConfirm.name).toBe('Modal');
        });

        it('submitOrder 액션이 정의되어 있다', () => {
            const layout = checkoutLayout as any;
            expect(Array.isArray(layout.actions)).toBe(true);
            const submitAction = layout.actions.find((a: any) => a.id === 'submitOrder');
            expect(submitAction).toBeDefined();
            expect(submitAction?.handler).toBe('apiCall');
            expect(submitAction?.auth_required).toBe(true);
        });
    });

    describe('렌더링 및 상태 테스트', () => {
        it('API 모킹 후 렌더링이 성공한다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            expect(testUtils.getState()._local).toBeDefined();
        });

        it('초기 상태(paymentMethod)가 올바르게 설정된다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            const state = testUtils.getState();
            expect(state._local.paymentMethod).toBe('card');
        });

        it('결제 방법을 변경할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            testUtils.setState('paymentMethod', 'vbank', 'local');

            const state = testUtils.getState();
            expect(state._local.paymentMethod).toBe('vbank');
        });

        it('장바구니 버튼 클릭 시 /cart로 이동한다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            await testUtils.triggerAction({
                type: 'click',
                handler: 'navigate',
                params: { path: '/cart' },
            });

            expect(testUtils.getNavigationHistory()).toContain('/cart');
        });
    });

    describe('모달 테스트', () => {
        it('배타적 쿠폰 확인 모달을 열 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            testUtils.openModal('exclusiveCouponConfirm');
            expect(testUtils.getModalStack()).toContain('exclusiveCouponConfirm');
        });

        it('모달을 닫을 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            testUtils.openModal('exclusiveCouponConfirm');
            testUtils.closeModal('exclusiveCouponConfirm');

            expect(testUtils.getModalStack()).not.toContain('exclusiveCouponConfirm');
        });
    });

    describe('API 에러 처리', () => {
        it('API 에러 시에도 레이아웃이 유지된다', async () => {
            testUtils.mockApiError('checkoutData', 500, '서버 오류');

            await testUtils.render();

            const state = testUtils.getState();
            expect(state._local).toBeDefined();
        });

        it('404 에러 시에도 레이아웃이 유지된다', async () => {
            testUtils.mockApiError('checkoutData', 404, '체크아웃 정보를 찾을 수 없습니다');

            await testUtils.render();

            const state = testUtils.getState();
            expect(state._local).toBeDefined();
        });
    });

    describe('쿠폰 상태 관리', () => {
        it('상품별 쿠폰 상태를 설정할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            // 상품별 쿠폰 선택 상태 설정
            testUtils.setState('itemCoupons', { 101: [1] }, 'local');

            const state = testUtils.getState();
            expect(state._local.itemCoupons).toEqual({ 101: [1] });
        });

        it('주문/배송 쿠폰 상태를 설정할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            testUtils.setState('orderCouponId', 3, 'local');
            testUtils.setState('shippingCouponId', null, 'local');

            const state = testUtils.getState();
            expect(state._local.orderCouponId).toBe(3);
            expect(state._local.shippingCouponId).toBeNull();
        });
    });

    describe('마일리지 상태 관리', () => {
        it('사용 마일리지 상태를 설정할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            testUtils.setState('usePoints', 3000, 'local');

            const state = testUtils.getState();
            expect(state._local.usePoints).toBe(3000);
        });
    });
});
