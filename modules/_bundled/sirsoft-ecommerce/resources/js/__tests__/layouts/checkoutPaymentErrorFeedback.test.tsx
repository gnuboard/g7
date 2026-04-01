/**
 * 체크아웃 결제 에러 피드백 테스트
 *
 * PG 결제 실패 후 리다이렉트 시 에러 배너 표시 기능을 검증합니다.
 * init_actions의 conditions 핸들러는 엔진 레벨에서 실행되므로,
 * 테스트에서는 _local.orderError 상태 기반 조건부 렌더링을 검증합니다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{
    className?: string;
    children?: React.ReactNode;
    'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
    <div className={className} data-testid={testId}>{children}</div>
);

const TestP: React.FC<{
    className?: string;
    children?: React.ReactNode;
    text?: string;
    'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
    <p className={className} data-testid={testId}>{children || text}</p>
);

const TestIcon: React.FC<{ name?: string; className?: string }> =
    ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} />;

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h1 className={className}>{children || text}</h1>;

const TestFragment: React.FC<{ children?: React.ReactNode }> =
    ({ children }) => <>{children}</>;

const TestContainer: React.FC<{ className?: string; children?: React.ReactNode }> =
    ({ className, children }) => <div className={className} data-testid="container">{children}</div>;

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
        Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
    };

    return registry;
}

// ========== 에러 배너 레이아웃 (checkout.json의 에러 표시 부분 간소화) ==========

function createErrorLayout(orderErrorValue: string | null) {
    return {
        version: '1.0.0',
        layout_name: 'shop/checkout-error-test',
        permissions: [],
        meta: { title: '체크아웃 에러 피드백 테스트' },
        initLocal: {
            orderError: orderErrorValue,
        },
        components: [
            {
                type: 'layout',
                name: 'Container',
                children: [
                    {
                        comment: '에러 배너 (checkout.json line 152-186 동일 구조)',
                        type: 'basic',
                        name: 'Div',
                        if: '{{_local.orderError}}',
                        props: {
                            className: 'mb-6 p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800',
                            'data-testid': 'payment-error-banner',
                        },
                        children: [
                            {
                                type: 'basic',
                                name: 'Div',
                                props: { className: 'flex items-start gap-3' },
                                children: [
                                    {
                                        type: 'basic',
                                        name: 'Icon',
                                        props: { name: 'exclamation-circle', className: 'w-5 h-5 text-red-500 flex-shrink-0 mt-0.5' },
                                    },
                                    {
                                        type: 'basic',
                                        name: 'P',
                                        props: {
                                            className: 'text-sm font-medium text-red-800 dark:text-red-200',
                                            'data-testid': 'payment-error-message',
                                        },
                                        text: '{{_local.orderError}}',
                                    },
                                ],
                            },
                        ],
                    },
                    {
                        type: 'basic',
                        name: 'H1',
                        text: '주문서 작성',
                    },
                ],
            },
        ],
    };
}

// ========== 테스트 ==========

describe('체크아웃 결제 에러 피드백', () => {
    let registry: ComponentRegistry;
    let testUtils: ReturnType<typeof createLayoutTest>;

    beforeEach(() => {
        registry = setupTestRegistry();
    });

    afterEach(() => {
        testUtils?.cleanup?.();
    });

    describe('에러 배너 조건부 렌더링', () => {
        it('orderError가 null이면 에러 배너가 표시되지 않는다', async () => {
            testUtils = createLayoutTest(createErrorLayout(null), {
                componentRegistry: registry,
            });

            await testUtils.render();

            expect(screen.queryByTestId('payment-error-banner')).toBeNull();
        });

        it('orderError에 confirm_failed 메시지가 있으면 에러 배너가 표시된다', async () => {
            testUtils = createLayoutTest(
                createErrorLayout('결제 승인에 실패했습니다. 다시 시도해 주세요.'),
                { componentRegistry: registry }
            );

            await testUtils.render();

            const errorBanner = screen.getByTestId('payment-error-banner');
            expect(errorBanner).toBeTruthy();

            const errorMessage = screen.getByTestId('payment-error-message');
            expect(errorMessage.textContent).toContain('결제 승인에 실패했습니다');
        });

        it('orderError에 amount_mismatch 메시지가 있으면 에러 배너가 표시된다', async () => {
            testUtils = createLayoutTest(
                createErrorLayout('결제 금액이 일치하지 않습니다. 다시 주문해 주세요.'),
                { componentRegistry: registry }
            );

            await testUtils.render();

            const errorMessage = screen.getByTestId('payment-error-message');
            expect(errorMessage.textContent).toContain('결제 금액이 일치하지 않습니다');
        });

        it('orderError에 order_not_found 메시지가 있으면 에러 배너가 표시된다', async () => {
            testUtils = createLayoutTest(
                createErrorLayout('주문을 찾을 수 없습니다.'),
                { componentRegistry: registry }
            );

            await testUtils.render();

            const errorMessage = screen.getByTestId('payment-error-message');
            expect(errorMessage.textContent).toContain('주문을 찾을 수 없습니다');
        });

        it('orderError에 일반 에러 메시지가 있으면 에러 배너가 표시된다', async () => {
            testUtils = createLayoutTest(
                createErrorLayout('결제 처리 중 오류가 발생했습니다. 다시 시도해 주세요.'),
                { componentRegistry: registry }
            );

            await testUtils.render();

            const errorMessage = screen.getByTestId('payment-error-message');
            expect(errorMessage.textContent).toContain('결제 처리 중 오류가 발생했습니다');
        });

        it('에러 배너에 경고 아이콘이 표시된다', async () => {
            testUtils = createLayoutTest(
                createErrorLayout('테스트 에러 메시지'),
                { componentRegistry: registry }
            );

            await testUtils.render();

            const icon = screen.getByTestId('payment-error-banner')
                .querySelector('[data-icon="exclamation-circle"]');
            expect(icon).toBeTruthy();
        });
    });
});
