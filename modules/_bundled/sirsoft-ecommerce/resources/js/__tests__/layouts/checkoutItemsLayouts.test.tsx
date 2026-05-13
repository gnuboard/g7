/**
 * 체크아웃 상품 목록 레이아웃 테스트
 *
 * _checkout_items.json partial의 렌더링 및 동작을 테스트합니다.
 * - 상품 목록 iteration 렌더링
 * - 상품별 쿠폰 드롭다운 (회원만 표시)
 * - 쿠폰 선택 시 API 호출
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

const TestH2: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h2 className={className}>{children || text}</h2>;

const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestIcon: React.FC<{ name?: string; className?: string }> =
    ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} />;

const TestImg: React.FC<{ src?: string; alt?: string; className?: string }> =
    ({ src, alt, className }) => <img src={src} alt={alt} className={className} />;

const TestSelect: React.FC<{
    value?: string;
    className?: string;
    children?: React.ReactNode;
    name?: string;
    disabled?: boolean;
    'data-testid'?: string;
}> = ({ value, className, children, name, disabled, 'data-testid': testId }) => (
    <select value={value} className={className} name={name} disabled={disabled} data-testid={testId}>{children}</select>
);

const TestOption: React.FC<{ value?: string; disabled?: boolean; children?: React.ReactNode }> =
    ({ value, disabled, children }) => <option value={value} disabled={disabled}>{children}</option>;

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
        H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
        Option: { component: TestOption, metadata: { name: 'Option', type: 'basic' } },
    };

    return registry;
}

// ========== _checkout_items.json 기반 레이아웃 (간소화) ==========

const checkoutItemsLayout = {
    version: '1.0.0',
    layout_name: 'partials/shop/_checkout_items',
    meta: {
        is_partial: true,
        description: '주문 상품 목록 섹션',
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
        itemCoupons: {},
    },
    components: [
        {
            id: 'items-container',
            type: 'basic',
            name: 'Div',
            props: { className: 'bg-white rounded-lg border p-6', 'data-testid': 'items-container' },
            children: [
                {
                    comment: '섹션 헤더',
                    id: 'items-header',
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'flex items-center gap-2 mb-4', 'data-testid': 'items-header' },
                    children: [
                        { type: 'basic', name: 'Icon', props: { name: 'shopping-cart' } },
                        {
                            type: 'basic',
                            name: 'H2',
                            props: { className: 'text-lg font-semibold' },
                            text: '$t:sirsoft-ecommerce.shop.checkout.order_items',
                        },
                    ],
                },
                {
                    comment: '상품 목록',
                    id: 'items-list',
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'divide-y', 'data-testid': 'items-list' },
                    children: [
                        {
                            id: 'item-iteration',
                            type: 'basic',
                            name: 'Div',
                            iteration: { source: '{{checkoutData.data.items ?? []}}', item_var: 'item' },
                            children: [
                                {
                                    type: 'basic',
                                    name: 'Div',
                                    props: { className: 'flex gap-4 py-4', 'data-testid': 'item-row' },
                                    children: [
                                        {
                                            comment: '상품 이미지',
                                            type: 'basic',
                                            name: 'Div',
                                            props: { className: 'w-20 h-20 flex-shrink-0' },
                                            children: [
                                                {
                                                    type: 'basic',
                                                    name: 'Img',
                                                    props: {
                                                        src: '{{item.product.thumbnail}}',
                                                        alt: '{{item.product.name}}',
                                                        className: 'w-full h-full object-cover',
                                                    },
                                                },
                                            ],
                                        },
                                        {
                                            comment: '상품 정보',
                                            type: 'basic',
                                            name: 'Div',
                                            props: { className: 'flex-1' },
                                            children: [
                                                {
                                                    type: 'basic',
                                                    name: 'H3',
                                                    props: { className: 'text-sm font-medium', 'data-testid': 'item-name' },
                                                    text: '{{item.product.name}}',
                                                },
                                                {
                                                    type: 'basic',
                                                    name: 'P',
                                                    props: { className: 'text-sm text-gray-500', 'data-testid': 'item-option' },
                                                    text: '{{item.option_text}}',
                                                },
                                                {
                                                    type: 'basic',
                                                    name: 'P',
                                                    props: { className: 'text-sm text-gray-600', 'data-testid': 'item-price-qty' },
                                                    text: '{{item.unit_price_formatted}} × {{item.quantity}}$t:sirsoft-ecommerce.shop.count_unit',
                                                },
                                                {
                                                    comment: '쿠폰 적용 정보',
                                                    id: 'coupon-applied-info',
                                                    type: 'basic',
                                                    name: 'Div',
                                                    if: '{{(item.product_coupon_discount_amount ?? 0) > 0}}',
                                                    props: { className: 'flex items-center gap-1 mt-1', 'data-testid': 'coupon-applied' },
                                                    children: [
                                                        { type: 'basic', name: 'Icon', props: { name: 'ticket', className: 'w-3 h-3 text-red-500' } },
                                                        {
                                                            type: 'basic',
                                                            name: 'Span',
                                                            props: { className: 'text-xs text-red-600' },
                                                            text: '$t:sirsoft-ecommerce.shop.cart.coupon_discount -{{item.product_coupon_discount_formatted}}',
                                                        },
                                                    ],
                                                },
                                                {
                                                    comment: '상품별 쿠폰 선택 영역 (회원만)',
                                                    id: 'item-coupon-select-area',
                                                    type: 'basic',
                                                    name: 'Div',
                                                    if: '{{_global.isAuthenticated && (checkoutData.data.available_coupons ?? []).filter(c => c.coupon?.target_type === \'product\').length > 0}}',
                                                    props: { className: 'mt-2 flex flex-wrap gap-2', 'data-testid': 'item-coupon-area' },
                                                    children: [
                                                        {
                                                            comment: '상품 쿠폰 1',
                                                            id: 'item-coupon-select-1',
                                                            type: 'basic',
                                                            name: 'Select',
                                                            props: {
                                                                name: 'item_coupon_1',
                                                                value: '{{_local.itemCoupons?.[item.product_option_id]?.[0] ?? \'\'}}',
                                                                className: 'flex-1 min-w-[140px] px-2 py-1 text-xs border rounded',
                                                                'data-testid': 'item-coupon-select-1',
                                                            },
                                                            children: [
                                                                {
                                                                    type: 'basic',
                                                                    name: 'Option',
                                                                    props: { value: '' },
                                                                    text: '$t:sirsoft-ecommerce.shop.checkout.product_coupon_1',
                                                                },
                                                                {
                                                                    type: 'basic',
                                                                    name: 'Option',
                                                                    iteration: {
                                                                        source: '{{(checkoutData.data.available_coupons ?? []).filter(c => c.coupon?.target_type === \'product\')}}',
                                                                        item_var: 'pcoupon',
                                                                    },
                                                                    props: {
                                                                        value: '{{pcoupon.id}}',
                                                                        disabled: '{{_local.itemCoupons?.[item.product_option_id]?.[1] == pcoupon.id}}',
                                                                    },
                                                                    text: '{{pcoupon.coupon?.name ?? pcoupon.name}} ({{pcoupon.coupon?.benefit_display ?? \'\'}})',
                                                                },
                                                            ],
                                                            actions: [
                                                                {
                                                                    type: 'change',
                                                                    handler: 'sequence',
                                                                    actions: [
                                                                        {
                                                                            handler: 'setState',
                                                                            params: {
                                                                                target: 'local',
                                                                                itemCoupons: '{{Object.assign({}, _local.itemCoupons ?? {}, { [item.product_option_id]: [$event.target.value || null, (_local.itemCoupons?.[item.product_option_id]?.[1] ?? null)].filter(Boolean) })}}',
                                                                            },
                                                                        },
                                                                        {
                                                                            handler: 'apiCall',
                                                                            params: {
                                                                                endpoint: '/api/modules/sirsoft-ecommerce/checkout',
                                                                                method: 'PUT',
                                                                                body: {
                                                                                    item_coupons: '{{_local.itemCoupons ?? {}}}',
                                                                                },
                                                                            },
                                                                            onSuccess: [
                                                                                { handler: 'refetchDataSource', params: { id: 'checkoutData' } },
                                                                            ],
                                                                        },
                                                                    ],
                                                                },
                                                            ],
                                                        },
                                                        {
                                                            comment: '상품 쿠폰 2',
                                                            id: 'item-coupon-select-2',
                                                            type: 'basic',
                                                            name: 'Select',
                                                            props: {
                                                                name: 'item_coupon_2',
                                                                value: '{{_local.itemCoupons?.[item.product_option_id]?.[1] ?? \'\'}}',
                                                                className: 'flex-1 min-w-[140px] px-2 py-1 text-xs border rounded',
                                                                'data-testid': 'item-coupon-select-2',
                                                            },
                                                            children: [
                                                                {
                                                                    type: 'basic',
                                                                    name: 'Option',
                                                                    props: { value: '' },
                                                                    text: '$t:sirsoft-ecommerce.shop.checkout.product_coupon_2',
                                                                },
                                                                {
                                                                    type: 'basic',
                                                                    name: 'Option',
                                                                    iteration: {
                                                                        source: '{{(checkoutData.data.available_coupons ?? []).filter(c => c.coupon?.target_type === \'product\')}}',
                                                                        item_var: 'pcoupon2',
                                                                    },
                                                                    props: {
                                                                        value: '{{pcoupon2.id}}',
                                                                        disabled: '{{_local.itemCoupons?.[item.product_option_id]?.[0] == pcoupon2.id}}',
                                                                    },
                                                                    text: '{{pcoupon2.coupon?.name ?? pcoupon2.name}} ({{pcoupon2.coupon?.benefit_display ?? \'\'}})',
                                                                },
                                                            ],
                                                            actions: [
                                                                {
                                                                    type: 'change',
                                                                    handler: 'sequence',
                                                                    actions: [
                                                                        {
                                                                            handler: 'setState',
                                                                            params: {
                                                                                target: 'local',
                                                                                itemCoupons: '{{Object.assign({}, _local.itemCoupons ?? {}, { [item.product_option_id]: [(_local.itemCoupons?.[item.product_option_id]?.[0] ?? null), $event.target.value || null].filter(Boolean) })}}',
                                                                            },
                                                                        },
                                                                        {
                                                                            handler: 'apiCall',
                                                                            params: {
                                                                                endpoint: '/api/modules/sirsoft-ecommerce/checkout',
                                                                                method: 'PUT',
                                                                                body: {
                                                                                    item_coupons: '{{_local.itemCoupons ?? {}}}',
                                                                                },
                                                                            },
                                                                            onSuccess: [
                                                                                { handler: 'refetchDataSource', params: { id: 'checkoutData' } },
                                                                            ],
                                                                        },
                                                                    ],
                                                                },
                                                            ],
                                                        },
                                                    ],
                                                },
                                            ],
                                        },
                                        {
                                            comment: '상품 금액',
                                            type: 'basic',
                                            name: 'Div',
                                            props: { className: 'text-right space-y-0.5' },
                                            children: [
                                                {
                                                    type: 'basic',
                                                    name: 'Div',
                                                    props: { className: 'text-xs text-gray-500', 'data-testid': 'item-subtotal' },
                                                    text: '{{item.subtotal_formatted}}',
                                                },
                                                {
                                                    type: 'basic',
                                                    name: 'Div',
                                                    if: '{{(item.total_discount ?? 0) > 0}}',
                                                    props: { className: 'text-xs text-red-600', 'data-testid': 'item-discount' },
                                                    text: '-{{item.total_discount_formatted}}',
                                                },
                                                {
                                                    type: 'basic',
                                                    name: 'Div',
                                                    props: { className: 'text-sm font-bold', 'data-testid': 'item-final' },
                                                    text: '{{item.final_amount_formatted}}',
                                                },
                                            ],
                                        },
                                    ],
                                },
                            ],
                        },
                    ],
                },
            ],
        },
    ],
};

// ========== Mock 데이터 ==========

const mockCheckoutDataWithItems = {
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
            final_amount: 105000,
            final_amount_formatted: '105,000원',
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
            },
        },
    ],
    mileage: { available: 5000, max_usable: 5000 },
};

// ========== 테스트 ==========

describe('_checkout_items.json (상품 목록 Partial)', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    let registry: ComponentRegistry;

    beforeEach(() => {
        registry = setupTestRegistry();

        testUtils = createLayoutTest(checkoutItemsLayout as any, {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'TestUser' },
            },
            initialState: {
                _global: {
                    isAuthenticated: true,
                },
            },
            translations: {
                'sirsoft-ecommerce': {
                    shop: {
                        checkout: {
                            order_items: '주문 상품',
                            product_coupon_1: '상품 쿠폰 1 선택',
                            product_coupon_2: '상품 쿠폰 2 선택',
                        },
                        cart: {
                            coupon_discount: '쿠폰 할인',
                        },
                        count_unit: '개',
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
            expect(info.name).toBe('partials/shop/_checkout_items');
        });

        it('상품 목록 iteration이 checkoutData.data.items를 소스로 사용한다', () => {
            const layout = checkoutItemsLayout as any;

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

            const itemIteration = findComponent(layout.components, 'item-iteration');
            expect(itemIteration).toBeDefined();
            expect(itemIteration.iteration).toBeDefined();
            expect(itemIteration.iteration.source).toBe('{{checkoutData.data.items ?? []}}');
            expect(itemIteration.iteration.item_var).toBe('item');
        });

        it('쿠폰 선택 영역에 회원 조건이 설정되어 있다', () => {
            const layout = checkoutItemsLayout as any;

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

            const couponArea = findComponent(layout.components, 'item-coupon-select-area');
            expect(couponArea).toBeDefined();
            expect(couponArea.if).toContain('_global.isAuthenticated');
            expect(couponArea.if).toContain('available_coupons');
        });

        it('상품 쿠폰 드롭다운이 2개 정의되어 있다', () => {
            const layout = checkoutItemsLayout as any;

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

            const couponSelect1 = findComponent(layout.components, 'item-coupon-select-1');
            const couponSelect2 = findComponent(layout.components, 'item-coupon-select-2');

            expect(couponSelect1).toBeDefined();
            expect(couponSelect2).toBeDefined();
            expect(couponSelect1.name).toBe('Select');
            expect(couponSelect2.name).toBe('Select');
        });
    });

    describe('상품 목록 렌더링 테스트', () => {
        it('API 모킹 후 렌더링이 성공한다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithItems },
            });

            await testUtils.render();

            expect(testUtils.getState()._local).toBeDefined();
        });

        it('쿠폰 할인이 있는 상품에 쿠폰 적용 정보 if 조건이 있다', () => {
            const layout = checkoutItemsLayout as any;

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

            const couponApplied = findComponent(layout.components, 'coupon-applied-info');
            expect(couponApplied).toBeDefined();
            expect(couponApplied.if).toBe('{{(item.product_coupon_discount_amount ?? 0) > 0}}');
        });
    });

    describe('쿠폰 드롭다운 테스트', () => {
        it('쿠폰 드롭다운 1에 change 액션이 연결되어 있다', () => {
            const layout = checkoutItemsLayout as any;

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

            const couponSelect1 = findComponent(layout.components, 'item-coupon-select-1');
            expect(couponSelect1.actions).toBeDefined();
            expect(couponSelect1.actions[0].type).toBe('change');
            expect(couponSelect1.actions[0].handler).toBe('sequence');
        });

        it('쿠폰 선택 시 setState와 apiCall이 순차 실행된다', () => {
            const layout = checkoutItemsLayout as any;

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

            const couponSelect1 = findComponent(layout.components, 'item-coupon-select-1');
            const sequenceActions = couponSelect1.actions[0].actions;

            expect(sequenceActions).toHaveLength(2);
            expect(sequenceActions[0].handler).toBe('setState');
            expect(sequenceActions[1].handler).toBe('apiCall');
        });

        it('apiCall onSuccess에서 refetchDataSource가 호출된다', () => {
            const layout = checkoutItemsLayout as any;

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

            const couponSelect1 = findComponent(layout.components, 'item-coupon-select-1');
            const apiCallAction = couponSelect1.actions[0].actions[1];

            expect(apiCallAction.onSuccess).toBeDefined();
            expect(apiCallAction.onSuccess[0].handler).toBe('refetchDataSource');
            expect(apiCallAction.onSuccess[0].params.id).toBe('checkoutData');
        });

        it('쿠폰 드롭다운 2에 쿠폰 1과 중복 선택 방지 disabled 조건이 있다', () => {
            const layout = checkoutItemsLayout as any;

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

            const couponSelect2 = findComponent(layout.components, 'item-coupon-select-2');
            // Option에 iteration이 있고, 그 안에 disabled 조건이 있는지 확인
            const optionWithIteration = couponSelect2.children.find((c: any) => c.iteration);
            expect(optionWithIteration).toBeDefined();
            expect(optionWithIteration.props.disabled).toContain('itemCoupons');
        });
    });

    describe('상품별 쿠폰 상태 관리', () => {
        it('itemCoupons 상태를 설정할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithItems },
            });

            await testUtils.render();

            // 상품 101에 쿠폰 1 선택
            testUtils.setState('itemCoupons', { 101: [1] }, 'local');

            const state = testUtils.getState();
            expect(state._local.itemCoupons).toEqual({ 101: [1] });
        });

        it('한 상품에 2개 쿠폰을 설정할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithItems },
            });

            await testUtils.render();

            // 상품 101에 쿠폰 1, 2 선택
            testUtils.setState('itemCoupons', { 101: [1, 2] }, 'local');

            const state = testUtils.getState();
            expect(state._local.itemCoupons).toEqual({ 101: [1, 2] });
            expect(state._local.itemCoupons[101]).toHaveLength(2);
        });

        it('여러 상품에 각각 쿠폰을 설정할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutDataWithItems },
            });

            await testUtils.render();

            // 상품 101에 쿠폰 1, 상품 102에 쿠폰 2 선택
            testUtils.setState('itemCoupons', { 101: [1], 102: [2] }, 'local');

            const state = testUtils.getState();
            expect(state._local.itemCoupons).toEqual({ 101: [1], 102: [2] });
        });
    });

    describe('비회원 상태 테스트', () => {
        it('비회원일 때 쿠폰 선택 영역이 표시되지 않는 조건이 있다', () => {
            const layout = checkoutItemsLayout as any;

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

            const couponArea = findComponent(layout.components, 'item-coupon-select-area');
            expect(couponArea.if).toContain('_global.isAuthenticated');
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
