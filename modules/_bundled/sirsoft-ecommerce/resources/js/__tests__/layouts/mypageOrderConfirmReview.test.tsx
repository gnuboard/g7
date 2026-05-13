/**
 * 마이페이지 주문상세 구매확정/리뷰 레이아웃 렌더링 테스트
 *
 * _items.json partial의 구매확정/리뷰작성 버튼 조건부 렌더링을 검증합니다.
 * 실제 partial은 모듈 외부(templates/)에 있어 import 불가하므로
 * 핵심 조건부 렌더링 패턴만 인라인 레이아웃으로 테스트합니다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

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

const TestIcon: React.FC<{ name?: string; className?: string }> =
    ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} />;

const TestFragment: React.FC<{ children?: React.ReactNode }> =
    ({ children }) => <>{children}</>;

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };

    return registry;
}

// ========== 인라인 레이아웃 (실제 _items.json의 구매확정/리뷰 버튼 패턴 재현) ==========

/**
 * _items.json에서 사용하는 실제 조건부 렌더링 패턴:
 * - if: "{{item.can_confirm === true}}" → 구매확정 버튼
 * - if: "{{item.can_write_review === true}}" → 리뷰작성 버튼
 * - if: "{{item.has_review === true}}" → 리뷰작성완료 뱃지
 */
const createItemsLayout = () => ({
    version: '1.0.0',
    layout_name: 'test/confirm-review-buttons',
    initLocal: {
        confirmTarget: null,
        isConfirming: false,
        reviewTarget: null,
    },
    data_sources: [
        {
            id: 'order',
            type: 'api',
            endpoint: '/api/modules/sirsoft-ecommerce/user/orders/1',
            method: 'GET',
            auto_fetch: true,
        },
    ],
    slots: {
        content: [
            {
                type: 'basic',
                name: 'Div',
                props: { 'data-testid': 'items-container' },
                children: [
                    {
                        comment: '상품 행 반복',
                        type: 'basic',
                        name: 'Div',
                        iteration: {
                            source: 'order?.data?.options',
                            item_var: 'item',
                            index_var: 'itemIdx',
                        },
                        props: { 'data-testid': 'item-row' },
                        children: [
                            {
                                comment: '상품 정보',
                                type: 'basic',
                                name: 'Span',
                                text: '{{item.product_name ?? ""}}',
                            },
                            {
                                comment: '구매확정 버튼',
                                type: 'basic',
                                name: 'Button',
                                if: '{{item.can_confirm === true}}',
                                props: {
                                    'data-testid': 'confirm-button',
                                    className: 'bg-blue-600 text-white',
                                },
                                children: [
                                    {
                                        type: 'basic',
                                        name: 'Icon',
                                        props: { name: 'check' },
                                    },
                                    {
                                        type: 'basic',
                                        name: 'Span',
                                        text: 'mypage.order_detail.confirm_purchase',
                                    },
                                ],
                            },
                            {
                                comment: '리뷰작성 버튼',
                                type: 'basic',
                                name: 'Button',
                                if: '{{item.can_write_review === true}}',
                                props: {
                                    'data-testid': 'review-button',
                                    className: 'bg-blue-600 text-white',
                                },
                                children: [
                                    {
                                        type: 'basic',
                                        name: 'Icon',
                                        props: { name: 'pen-to-square' },
                                    },
                                    {
                                        type: 'basic',
                                        name: 'Span',
                                        text: 'mypage.order_detail.write_review',
                                    },
                                ],
                            },
                            {
                                comment: '리뷰작성완료 뱃지',
                                type: 'basic',
                                name: 'Div',
                                if: '{{item.has_review === true}}',
                                props: {
                                    'data-testid': 'review-written-badge',
                                    className: 'bg-green-100 text-green-600',
                                },
                                children: [
                                    {
                                        type: 'basic',
                                        name: 'Icon',
                                        props: { name: 'check' },
                                    },
                                    {
                                        type: 'basic',
                                        name: 'Span',
                                        text: 'mypage.order_detail.review_written',
                                    },
                                ],
                            },
                        ],
                    },
                ],
            },
        ],
    },
});

// ========== Mock 데이터 팩토리 ==========

const createMockOption = (overrides: Record<string, any> = {}) => ({
    id: 1,
    product_name: '테스트 상품',
    option_name: '빨강/L',
    option_status: 'shipping',
    option_status_label: '배송중',
    can_confirm: false,
    can_write_review: false,
    has_review: false,
    ...overrides,
});

// ========== 테스트 ==========

describe('마이페이지 주문상세 구매확정/리뷰 버튼 조건부 렌더링', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    const registry = setupTestRegistry();

    afterEach(() => {
        if (testUtils) testUtils.cleanup();
    });

    it('can_confirm=true일 때 구매확정 버튼이 렌더링됨', async () => {
        testUtils = createLayoutTest(createItemsLayout(), { componentRegistry: registry });
        testUtils.mockApi('order', {
            response: {
                data: {
                    options: [createMockOption({ can_confirm: true })],
                },
            },
        });

        await testUtils.render();

        const html = document.body.innerHTML;
        expect(html).toContain('mypage.order_detail.confirm_purchase');
        expect(html).toContain('data-testid="confirm-button"');
    });

    it('can_confirm=false일 때 구매확정 버튼이 렌더링되지 않음', async () => {
        testUtils = createLayoutTest(createItemsLayout(), { componentRegistry: registry });
        testUtils.mockApi('order', {
            response: {
                data: {
                    options: [createMockOption({ can_confirm: false })],
                },
            },
        });

        await testUtils.render();

        const html = document.body.innerHTML;
        expect(html).not.toContain('mypage.order_detail.confirm_purchase');
        expect(html).not.toContain('data-testid="confirm-button"');
    });

    it('can_write_review=true일 때 리뷰작성 버튼이 렌더링됨', async () => {
        testUtils = createLayoutTest(createItemsLayout(), { componentRegistry: registry });
        testUtils.mockApi('order', {
            response: {
                data: {
                    options: [createMockOption({
                        can_write_review: true,
                        option_status: 'confirmed',
                    })],
                },
            },
        });

        await testUtils.render();

        const html = document.body.innerHTML;
        expect(html).toContain('mypage.order_detail.write_review');
        expect(html).toContain('data-testid="review-button"');
    });

    it('has_review=true일 때 리뷰작성완료 뱃지가 렌더링됨', async () => {
        testUtils = createLayoutTest(createItemsLayout(), { componentRegistry: registry });
        testUtils.mockApi('order', {
            response: {
                data: {
                    options: [createMockOption({
                        has_review: true,
                        can_write_review: false,
                        option_status: 'confirmed',
                    })],
                },
            },
        });

        await testUtils.render();

        const html = document.body.innerHTML;
        expect(html).toContain('mypage.order_detail.review_written');
        expect(html).toContain('data-testid="review-written-badge"');
    });

    it('일반 상태(결제완료)에서는 구매확정/리뷰 버튼 모두 미표시', async () => {
        testUtils = createLayoutTest(createItemsLayout(), { componentRegistry: registry });
        testUtils.mockApi('order', {
            response: {
                data: {
                    options: [createMockOption({
                        option_status: 'payment_complete',
                        can_confirm: false,
                        can_write_review: false,
                        has_review: false,
                    })],
                },
            },
        });

        await testUtils.render();

        const html = document.body.innerHTML;
        expect(html).not.toContain('mypage.order_detail.confirm_purchase');
        expect(html).not.toContain('mypage.order_detail.write_review');
        expect(html).not.toContain('mypage.order_detail.review_written');
    });

    it('구매확정과 리뷰작성은 동시에 표시되지 않음', async () => {
        testUtils = createLayoutTest(createItemsLayout(), { componentRegistry: registry });
        testUtils.mockApi('order', {
            response: {
                data: {
                    options: [createMockOption({
                        can_confirm: true,
                        can_write_review: false,
                        has_review: false,
                    })],
                },
            },
        });

        await testUtils.render();

        const html = document.body.innerHTML;
        expect(html).toContain('mypage.order_detail.confirm_purchase');
        expect(html).not.toContain('mypage.order_detail.write_review');
        expect(html).not.toContain('mypage.order_detail.review_written');
    });
});

// ========== 리뷰 모달 스피너 렌더링 테스트 ==========

/**
 * _modal_write_review.json의 제출 버튼 스피너 패턴을 인라인 레이아웃으로 테스트합니다.
 * - isSubmittingReview=false: 스피너 미표시, 제출 텍스트 표시
 * - isSubmittingReview=true: 스피너 표시, 처리중 텍스트 표시, 버튼 비활성화
 */
const createReviewModalButtonLayout = () => ({
    version: '1.0.0',
    layout_name: 'test/review-modal-spinner',
    initLocal: {
        isSubmittingReview: false,
    },
    slots: {
        content: [
            {
                type: 'basic',
                name: 'Button',
                props: {
                    type: 'button',
                    'data-testid': 'submit-review-btn',
                    disabled: '{{_local.isSubmittingReview === true}}',
                },
                children: [
                    {
                        type: 'basic',
                        name: 'Icon',
                        if: '{{_local.isSubmittingReview}}',
                        props: { name: 'spinner', className: 'animate-spin' },
                    },
                    {
                        type: 'basic',
                        name: 'Span',
                        text: "{{_local.isSubmittingReview ? 'processing' : 'submit_review'}}",
                    },
                ],
            },
        ],
    },
});

describe('리뷰 모달 제출 버튼 스피너 렌더링', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    const registry = setupTestRegistry();

    afterEach(() => {
        if (testUtils) testUtils.cleanup();
    });

    it('isSubmittingReview=false 시 스피너 미표시 + 제출 텍스트', async () => {
        testUtils = createLayoutTest(createReviewModalButtonLayout(), { componentRegistry: registry });
        await testUtils.render();

        const html = document.body.innerHTML;
        expect(html).toContain('submit_review');
        expect(html).not.toContain('icon-spinner');
        expect(html).not.toContain('animate-spin');
    });

    it('isSubmittingReview=true 시 스피너 표시 + 처리중 텍스트 + 버튼 비활성화', async () => {
        const layout = createReviewModalButtonLayout();
        layout.initLocal.isSubmittingReview = true;

        testUtils = createLayoutTest(layout, { componentRegistry: registry });
        await testUtils.render();

        const html = document.body.innerHTML;
        expect(html).toContain('processing');
        expect(html).not.toContain('submit_review');
        expect(html).toContain('icon-spinner');
        expect(html).toContain('animate-spin');

        const btn = document.querySelector('[data-testid="submit-review-btn"]') as HTMLButtonElement;
        expect(btn?.disabled).toBe(true);
    });
});
