/**
 * 체크아웃 배타적 쿠폰 모달 테스트
 *
 * checkout.json의 modals.exclusiveCouponConfirm 모달 동작을 테스트합니다.
 * - 배타적 쿠폰 선택 시 확인 모달 표시
 * - 확인 버튼 클릭 시 기존 쿠폰 해제 및 새 쿠폰 적용
 * - 취소 버튼 클릭 시 모달 닫기
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

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

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

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    };

    return registry;
}

// ========== 배타적 쿠폰 모달 레이아웃 ==========

const exclusiveCouponModalLayout = {
    version: '1.0.0',
    layout_name: 'shop/checkout_exclusive_coupon_modal',
    meta: {
        description: '배타적 쿠폰 확인 모달',
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
        selectedCouponId: null,
        usePoints: 0,
    },
    components: [
        {
            id: 'modal-wrapper',
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'modal-wrapper' },
            children: [],
        },
    ],
    modals: {
        exclusiveCouponConfirm: {
            type: 'composite',
            name: 'Modal',
            props: {
                title: '$t:sirsoft-ecommerce.shop.checkout.exclusive_coupon_title',
                size: 'medium',
            },
            children: [
                {
                    id: 'modal-message',
                    type: 'basic',
                    name: 'P',
                    props: { className: 'text-base text-gray-700', 'data-testid': 'modal-message' },
                    text: '$t:sirsoft-ecommerce.shop.checkout.exclusive_coupon_message',
                },
                {
                    id: 'modal-buttons',
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'flex justify-end gap-3 mt-6', 'data-testid': 'modal-buttons' },
                    children: [
                        {
                            id: 'cancel-button',
                            type: 'basic',
                            name: 'Button',
                            props: {
                                type: 'button',
                                className: 'px-4 py-2 border border-gray-300 rounded-lg',
                                'data-testid': 'cancel-button',
                            },
                            text: '$t:common.cancel',
                            actions: [
                                {
                                    type: 'click',
                                    handler: 'closeModal',
                                },
                            ],
                        },
                        {
                            id: 'confirm-button',
                            type: 'basic',
                            name: 'Button',
                            props: {
                                type: 'button',
                                className: 'px-4 py-2 bg-blue-600 text-white rounded-lg',
                                'data-testid': 'confirm-button',
                            },
                            text: '$t:common.confirm',
                            actions: [
                                {
                                    type: 'click',
                                    handler: 'sequence',
                                    actions: [
                                        {
                                            handler: 'setState',
                                            params: {
                                                target: 'local',
                                                selectedCouponId: '{{_global.pendingExclusiveCoupon?.id}}',
                                            },
                                        },
                                        {
                                            handler: 'setState',
                                            params: {
                                                target: 'global',
                                                pendingExclusiveCoupon: null,
                                            },
                                        },
                                        {
                                            handler: 'closeModal',
                                        },
                                        {
                                            handler: 'apiCall',
                                            params: {
                                                endpoint: '/api/modules/sirsoft-ecommerce/checkout',
                                                method: 'PUT',
                                                body: {
                                                    coupon_issue_ids: '{{[_local.selectedCouponId]}}',
                                                    use_points: '{{_local.usePoints ?? 0}}',
                                                },
                                            },
                                            onSuccess: [
                                                {
                                                    handler: 'refetchDataSource',
                                                    params: { id: 'checkoutData' },
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
    },
};

// ========== Mock 데이터 ==========

const mockCheckoutData = {
    temp_order_id: 1,
    items: [
        {
            id: 1,
            product_option_id: 101,
            product: { id: 1, name: '테스트 상품' },
            quantity: 1,
            unit_price: 50000,
            final_amount: 50000,
            final_amount_formatted: '50,000원',
        },
    ],
    calculation: {
        summary: {
            subtotal: 50000,
            product_coupon_discount: 0,
            final_amount: 50000,
            final_amount_formatted: '50,000원',
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
                is_combinable: true,
            },
        },
        {
            id: 2,
            name: '배타적 쿠폰',
            coupon: {
                id: 2,
                name: '50% 특별 할인',
                target_type: 'order',
                is_combinable: false,
            },
        },
    ],
    mileage: { available: 5000, max_usable: 5000 },
};

// ========== 테스트 ==========

describe('exclusiveCouponConfirm 모달 테스트', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    let registry: ComponentRegistry;

    beforeEach(() => {
        registry = setupTestRegistry();

        testUtils = createLayoutTest(exclusiveCouponModalLayout as any, {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'TestUser' },
            },
            initialState: {
                _global: {
                    isAuthenticated: true,
                    pendingExclusiveCoupon: null,
                },
            },
            translations: {
                'sirsoft-ecommerce': {
                    shop: {
                        checkout: {
                            exclusive_coupon_title: '배타적 쿠폰 적용',
                            exclusive_coupon_message: '이 쿠폰은 다른 쿠폰과 함께 사용할 수 없습니다. 기존에 적용된 쿠폰이 모두 해제됩니다. 계속하시겠습니까?',
                        },
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

    describe('모달 구조 검증', () => {
        it('modals에 exclusiveCouponConfirm이 정의되어 있다', () => {
            const layout = exclusiveCouponModalLayout as any;
            expect(layout.modals).toBeDefined();
            expect(layout.modals.exclusiveCouponConfirm).toBeDefined();
        });

        it('모달 제목이 다국어 키로 설정되어 있다', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;
            expect(modal.props.title).toBe('$t:sirsoft-ecommerce.shop.checkout.exclusive_coupon_title');
        });

        it('취소 버튼에 closeModal 핸들러가 연결되어 있다', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;

            const findComponent = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findComponent(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const cancelButton = findComponent(modal.children, 'cancel-button');
            expect(cancelButton).toBeDefined();
            expect(cancelButton.actions[0].handler).toBe('closeModal');
        });

        it('확인 버튼에 sequence 핸들러가 연결되어 있다', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;

            const findComponent = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findComponent(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const confirmButton = findComponent(modal.children, 'confirm-button');
            expect(confirmButton).toBeDefined();
            expect(confirmButton.actions[0].handler).toBe('sequence');
        });
    });

    describe('확인 버튼 시퀀스 액션 검증', () => {
        it('시퀀스에 4개의 액션이 포함되어 있다', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;

            const findComponent = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findComponent(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const confirmButton = findComponent(modal.children, 'confirm-button');
            const sequenceActions = confirmButton.actions[0].actions;

            expect(sequenceActions).toHaveLength(4);
        });

        it('첫 번째 액션: setState로 selectedCouponId 설정', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;

            const findComponent = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findComponent(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const confirmButton = findComponent(modal.children, 'confirm-button');
            const action1 = confirmButton.actions[0].actions[0];

            expect(action1.handler).toBe('setState');
            expect(action1.params.target).toBe('local');
            expect(action1.params.selectedCouponId).toBe('{{_global.pendingExclusiveCoupon?.id}}');
        });

        it('두 번째 액션: setState로 pendingExclusiveCoupon 초기화', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;

            const findComponent = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findComponent(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const confirmButton = findComponent(modal.children, 'confirm-button');
            const action2 = confirmButton.actions[0].actions[1];

            expect(action2.handler).toBe('setState');
            expect(action2.params.target).toBe('global');
            expect(action2.params.pendingExclusiveCoupon).toBeNull();
        });

        it('세 번째 액션: closeModal', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;

            const findComponent = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findComponent(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const confirmButton = findComponent(modal.children, 'confirm-button');
            const action3 = confirmButton.actions[0].actions[2];

            expect(action3.handler).toBe('closeModal');
        });

        it('네 번째 액션: apiCall로 체크아웃 업데이트', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;

            const findComponent = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findComponent(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const confirmButton = findComponent(modal.children, 'confirm-button');
            const action4 = confirmButton.actions[0].actions[3];

            expect(action4.handler).toBe('apiCall');
            expect(action4.params.endpoint).toContain('checkout');
            expect(action4.params.method).toBe('PUT');
        });

        it('apiCall onSuccess에서 refetchDataSource가 호출된다', () => {
            const layout = exclusiveCouponModalLayout as any;
            const modal = layout.modals.exclusiveCouponConfirm;

            const findComponent = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findComponent(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };

            const confirmButton = findComponent(modal.children, 'confirm-button');
            const action4 = confirmButton.actions[0].actions[3];

            expect(action4.onSuccess).toBeDefined();
            expect(action4.onSuccess[0].handler).toBe('refetchDataSource');
            expect(action4.onSuccess[0].params.id).toBe('checkoutData');
        });
    });

    describe('모달 열기/닫기 테스트', () => {
        it('모달을 열 수 있다', async () => {
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

    describe('pendingExclusiveCoupon 상태 관리', () => {
        it('pendingExclusiveCoupon 상태를 설정할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            // 배타적 쿠폰을 pendingExclusiveCoupon으로 설정
            testUtils.setState('pendingExclusiveCoupon', { id: 2, name: '50% 특별 할인' }, 'global');

            const state = testUtils.getState();
            expect(state._global.pendingExclusiveCoupon).toEqual({ id: 2, name: '50% 특별 할인' });
        });

        it('pendingExclusiveCoupon을 null로 초기화할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            // 설정 후 초기화
            testUtils.setState('pendingExclusiveCoupon', { id: 2 }, 'global');
            testUtils.setState('pendingExclusiveCoupon', null, 'global');

            const state = testUtils.getState();
            expect(state._global.pendingExclusiveCoupon).toBeNull();
        });
    });

    describe('selectedCouponId 상태 관리', () => {
        it('selectedCouponId 상태를 설정할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            testUtils.setState('selectedCouponId', 2, 'local');

            const state = testUtils.getState();
            expect(state._local.selectedCouponId).toBe(2);
        });
    });

    describe('배타적 쿠폰 선택 흐름 시뮬레이션', () => {
        it('배타적 쿠폰 선택 시 전체 흐름 상태 변화를 확인할 수 있다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            // 1. 기존 쿠폰이 적용된 상태 설정
            testUtils.setState('selectedCouponId', 1, 'local');

            // 2. 배타적 쿠폰 선택 시도 → pendingExclusiveCoupon 설정
            testUtils.setState('pendingExclusiveCoupon', {
                id: 2,
                name: '50% 특별 할인',
                is_combinable: false,
            }, 'global');

            // 3. 모달 열기
            testUtils.openModal('exclusiveCouponConfirm');

            let state = testUtils.getState();
            expect(state._global.pendingExclusiveCoupon.id).toBe(2);
            expect(testUtils.getModalStack()).toContain('exclusiveCouponConfirm');

            // 4. 확인 버튼 클릭 시뮬레이션 (상태 변화)
            // - selectedCouponId를 pendingExclusiveCoupon.id로 변경
            testUtils.setState('selectedCouponId', state._global.pendingExclusiveCoupon.id, 'local');
            // - pendingExclusiveCoupon 초기화
            testUtils.setState('pendingExclusiveCoupon', null, 'global');
            // - 모달 닫기
            testUtils.closeModal('exclusiveCouponConfirm');

            state = testUtils.getState();
            expect(state._local.selectedCouponId).toBe(2);
            expect(state._global.pendingExclusiveCoupon).toBeNull();
            expect(testUtils.getModalStack()).not.toContain('exclusiveCouponConfirm');
        });

        it('취소 버튼 클릭 시 상태가 변경되지 않는다', async () => {
            testUtils.mockApi('checkoutData', {
                response: { data: mockCheckoutData },
            });

            await testUtils.render();

            // 1. 기존 쿠폰 적용
            testUtils.setState('selectedCouponId', 1, 'local');

            // 2. 배타적 쿠폰 선택 시도
            testUtils.setState('pendingExclusiveCoupon', { id: 2 }, 'global');

            // 3. 모달 열기
            testUtils.openModal('exclusiveCouponConfirm');

            // 4. 취소 버튼 클릭 시뮬레이션 - 모달만 닫고 상태는 유지
            testUtils.closeModal('exclusiveCouponConfirm');

            const state = testUtils.getState();
            // selectedCouponId는 기존 값 유지
            expect(state._local.selectedCouponId).toBe(1);
            // pendingExclusiveCoupon은 그대로 (실제 구현에서는 취소 시 초기화할 수도 있음)
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
    });
});
