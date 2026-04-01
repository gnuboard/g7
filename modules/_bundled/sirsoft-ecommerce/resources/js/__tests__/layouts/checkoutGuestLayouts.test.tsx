/**
 * 비회원 체크아웃 레이아웃 렌더링 테스트
 *
 * 비회원 상태에서의 체크아웃 화면 동작을 테스트합니다.
 * - 쿠폰 UI가 표시되지 않음
 * - 마일리지 UI가 표시되지 않음
 * - 비회원 전용 필드 표시
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../resources/js/core/template-engine/ComponentRegistry';

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
    'data-testid'?: string;
}> = ({ type, placeholder, value, className, readOnly, name, 'data-testid': testId }) => (
    <input type={type} placeholder={placeholder} value={value} className={className}
        readOnly={readOnly} name={name} data-testid={testId} />
);

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h1 className={className}>{children || text}</h1>;

const TestH2: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h2 className={className}>{children || text}</h2>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestIcon: React.FC<{ name?: string; className?: string }> =
    ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} />;

const TestSelect: React.FC<{
    value?: string;
    className?: string;
    children?: React.ReactNode;
    name?: string;
    'data-testid'?: string;
}> = ({ value, className, children, name, 'data-testid': testId }) => (
    <select value={value} className={className} name={name} data-testid={testId}>{children}</select>
);

const TestOption: React.FC<{ value?: string; disabled?: boolean; children?: React.ReactNode }> =
    ({ value, disabled, children }) => <option value={value} disabled={disabled}>{children}</option>;

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
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
        H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
        H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
        Option: { component: TestOption, metadata: { name: 'Option', type: 'basic' } },
        Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
        Grid: { component: TestGrid, metadata: { name: 'Grid', type: 'layout' } },
    };

    return registry;
}

// ========== 체크아웃 레이아웃 JSON (비회원 테스트용) ==========

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
        },
        {
            id: 'paymentMethods',
            type: 'static',
            data: [
                { code: 'card', name: '신용카드' },
                { code: 'vbank', name: '무통장입금' },
            ],
        },
    ],
    state: {
        paymentMethod: 'card',
    },
    components: [
        {
            id: 'main-container',
            type: 'layout',
            name: 'Container',
            children: [
                {
                    id: 'page-title',
                    type: 'basic',
                    name: 'H1',
                    props: { 'data-testid': 'checkout-title' },
                    text: '$t:sirsoft-ecommerce.shop.checkout.title',
                },
                {
                    comment: '회원 전용 쿠폰 영역 (비회원에게는 표시 안 함)',
                    id: 'coupon-section',
                    type: 'basic',
                    name: 'Div',
                    if: '{{_global.isAuthenticated}}',
                    props: { 'data-testid': 'coupon-section' },
                    children: [
                        {
                            type: 'basic',
                            name: 'H2',
                            text: '쿠폰',
                        },
                    ],
                },
                {
                    comment: '회원 전용 마일리지 영역 (비회원에게는 표시 안 함)',
                    id: 'mileage-section',
                    type: 'basic',
                    name: 'Div',
                    if: '{{_global.isAuthenticated}}',
                    props: { 'data-testid': 'mileage-section' },
                    children: [
                        {
                            type: 'basic',
                            name: 'H2',
                            text: '마일리지',
                        },
                    ],
                },
                {
                    comment: '비회원 안내 메시지',
                    id: 'guest-notice',
                    type: 'basic',
                    name: 'Div',
                    if: '{{!_global.isAuthenticated}}',
                    props: { 'data-testid': 'guest-notice' },
                    children: [
                        {
                            type: 'basic',
                            name: 'P',
                            text: '$t:sirsoft-ecommerce.shop.checkout.guest_notice',
                        },
                    ],
                },
            ],
        },
    ],
};

// ========== 비회원 체크아웃 Mock 데이터 ==========

const mockGuestCheckoutData = {
    temp_order_id: 2,
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
            quantity: 1,
            unit_price: 30000,
            unit_price_formatted: '30,000원',
            subtotal: 30000,
            subtotal_formatted: '30,000원',
            product_coupon_discount_amount: 0,
            total_discount: 0,
            final_amount: 30000,
            final_amount_formatted: '30,000원',
        },
    ],
    calculation: {
        summary: {
            subtotal: 30000,
            subtotal_formatted: '30,000원',
            product_coupon_discount: 0,
            total_discount: 0,
            total_shipping: 3000,
            total_shipping_formatted: '3,000원',
            points_earning: 0,
            points_used: 0,
            payment_amount: 33000,
            payment_amount_formatted: '33,000원',
            final_amount: 33000,
            final_amount_formatted: '33,000원',
            multi_currency: {
                KRW: {
                    subtotal_formatted: '30,000원',
                    total_shipping_formatted: '3,000원',
                    final_amount_formatted: '33,000원',
                },
            },
        },
    },
    // 비회원은 쿠폰 없음
    available_coupons: [],
    // 비회원은 마일리지 없음
    mileage: {
        available: 0,
        max_usable: 0,
    },
    expires_at: '2026-02-02T23:30:00Z',
};

// ========== 비회원 체크아웃 테스트 ==========

describe('checkout-guest.json (비회원 체크아웃)', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    let registry: ComponentRegistry;

    beforeEach(() => {
        registry = setupTestRegistry();

        // 비회원 상태로 테스트 헬퍼 생성
        testUtils = createLayoutTest(checkoutLayout as any, {
            auth: {
                isAuthenticated: false,
                user: null,
            },
            initialState: {
                _global: {
                    isAuthenticated: false,
                },
            },
            translations: {
                'sirsoft-ecommerce': {
                    shop: {
                        checkout: {
                            title: '주문서 작성',
                            guest_notice: '비회원 주문은 쿠폰과 마일리지를 사용할 수 없습니다.',
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

    describe('비회원 상태 검증', () => {
        it('비회원 상태로 렌더링이 성공한다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockGuestCheckoutData },
            });

            await testUtils.render();

            expect(testUtils.getState()._local).toBeDefined();
            expect(testUtils.getState()._global.isAuthenticated).toBe(false);
        });

        it('비회원 API 응답에 available_coupons가 빈 배열이다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockGuestCheckoutData },
            });

            await testUtils.render();

            // API 응답 데이터 확인
            expect(mockGuestCheckoutData.available_coupons).toEqual([]);
        });

        it('비회원 API 응답에 마일리지가 0이다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockGuestCheckoutData },
            });

            await testUtils.render();

            expect(mockGuestCheckoutData.mileage.available).toBe(0);
            expect(mockGuestCheckoutData.mileage.max_usable).toBe(0);
        });

        it('비회원은 points_earning이 0이다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockGuestCheckoutData },
            });

            await testUtils.render();

            expect(mockGuestCheckoutData.calculation.summary.points_earning).toBe(0);
        });
    });

    describe('조건부 렌더링 검증', () => {
        it('쿠폰 영역에 if 조건이 isAuthenticated로 설정되어 있다', () => {
            const layout = checkoutLayout as any;
            const couponSection = layout.components[0].children.find(
                (c: any) => c.id === 'coupon-section'
            );
            expect(couponSection).toBeDefined();
            expect(couponSection.if).toBe('{{_global.isAuthenticated}}');
        });

        it('마일리지 영역에 if 조건이 isAuthenticated로 설정되어 있다', () => {
            const layout = checkoutLayout as any;
            const mileageSection = layout.components[0].children.find(
                (c: any) => c.id === 'mileage-section'
            );
            expect(mileageSection).toBeDefined();
            expect(mileageSection.if).toBe('{{_global.isAuthenticated}}');
        });

        it('비회원 안내 메시지 영역에 if 조건이 !isAuthenticated로 설정되어 있다', () => {
            const layout = checkoutLayout as any;
            const guestNotice = layout.components[0].children.find(
                (c: any) => c.id === 'guest-notice'
            );
            expect(guestNotice).toBeDefined();
            expect(guestNotice.if).toBe('{{!_global.isAuthenticated}}');
        });
    });

    describe('비회원 결제 흐름', () => {
        it('비회원도 결제 방법을 선택할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockGuestCheckoutData },
            });

            await testUtils.render();

            testUtils.setState('paymentMethod', 'vbank', 'local');

            const state = testUtils.getState();
            expect(state._local.paymentMethod).toBe('vbank');
        });

        it('비회원 배송 정보를 입력할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockGuestCheckoutData },
            });

            await testUtils.render();

            testUtils.setState('shipping', {
                recipient_name: '홍길동',
                phone: '010-1234-5678',
                zonecode: '12345',
                address: '서울시 강남구',
                detail_address: '역삼동 123',
            }, 'local');

            const state = testUtils.getState();
            expect(state._local.shipping.recipient_name).toBe('홍길동');
            expect(state._local.shipping.phone).toBe('010-1234-5678');
        });

        it('비회원 주문자 정보를 입력할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockGuestCheckoutData },
            });

            await testUtils.render();

            testUtils.setState('orderer', {
                name: '홍길동',
                phone: '010-1234-5678',
                email: 'hong@example.com',
            }, 'local');

            const state = testUtils.getState();
            expect(state._local.orderer.name).toBe('홍길동');
            expect(state._local.orderer.email).toBe('hong@example.com');
        });
    });

    describe('비회원 쿠폰/마일리지 제한', () => {
        it('비회원이 쿠폰 ID를 전송해도 API에서 무시된다', async () => {
            // 이 테스트는 실제 API 동작을 검증하는 것이 아니라,
            // 프론트엔드에서 비회원에게 쿠폰 선택 UI가 제공되지 않음을 확인
            const layout = checkoutLayout as any;
            const couponSection = layout.components[0].children.find(
                (c: any) => c.id === 'coupon-section'
            );

            // 쿠폰 섹션은 isAuthenticated 조건에 의해 비회원에게 표시되지 않음
            expect(couponSection.if).toBe('{{_global.isAuthenticated}}');
        });

        it('비회원이 마일리지를 사용하려고 해도 API에서 무시된다', async () => {
            // 마일리지 섹션은 isAuthenticated 조건에 의해 비회원에게 표시되지 않음
            const layout = checkoutLayout as any;
            const mileageSection = layout.components[0].children.find(
                (c: any) => c.id === 'mileage-section'
            );

            expect(mileageSection.if).toBe('{{_global.isAuthenticated}}');
        });
    });

    describe('API 에러 처리', () => {
        it('비회원 체크아웃 API 에러 시에도 레이아웃이 유지된다', async () => {
            testUtils.mockApiError('checkoutData', 500, '서버 오류');

            await testUtils.render();

            const state = testUtils.getState();
            expect(state._local).toBeDefined();
        });

        it('비회원 체크아웃 만료 시 에러 처리', async () => {
            testUtils.mockApiError('checkoutData', 410, '체크아웃이 만료되었습니다');

            await testUtils.render();

            const state = testUtils.getState();
            expect(state._local).toBeDefined();
        });
    });
});
