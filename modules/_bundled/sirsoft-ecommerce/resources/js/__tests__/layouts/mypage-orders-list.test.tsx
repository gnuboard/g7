/**
 * @file mypage-orders-list.test.tsx
 * @description 마이페이지 주문내역 목록 레이아웃 테스트
 *
 * 주문 목록의 다통화 보조 표시 패턴을 검증합니다.
 * - 기본 통화(KRW) 금액 표시
 * - 보조 통화(USD, JPY 등) 회색 소형 텍스트 표시
 * - Object.entries().filter() iteration 패턴
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 기본 컴포넌트 정의
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);

const TestSpan: React.FC<{
  className?: string;
  text?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, text, children, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>
    {children || text}
  </span>
);

const TestP: React.FC<{
  className?: string;
  text?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, text, children, 'data-testid': testId }) => (
  <p className={className} data-testid={testId}>
    {children || text}
  </p>
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 컴포넌트 레지스트리 설정
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// 다통화 주문 목록 간소화 레이아웃
const orderListLayout = {
  version: '1.0.0',
  layout_name: 'mypage_orders_list_test',
  components: [
    {
      id: 'order-list',
      type: 'basic' as const,
      name: 'Div',
      props: { 'data-testid': 'order-list' },
      iteration: {
        source: 'orders.data.data',
        item_var: 'order',
        index_var: 'orderIdx',
      },
      children: [
        {
          id: 'order-card-{{orderIdx}}',
          type: 'basic' as const,
          name: 'Div',
          props: { 'data-testid': 'order-card' },
          children: [
            {
              id: 'order-total-{{orderIdx}}',
              type: 'basic' as const,
              name: 'P',
              props: {
                className: 'text-lg font-bold',
                'data-testid': 'order-total',
              },
              text: "{{order.mc_total_amount?.[_global.preferredCurrency ?? 'KRW']?.formatted ?? order.total_amount_formatted}}",
            },
            {
              id: 'order-total-mc-{{orderIdx}}',
              comment: '보조 통화들 (선택된 통화 제외)',
              type: 'basic' as const,
              name: 'Div',
              props: {
                className: 'flex flex-wrap gap-x-2',
                'data-testid': 'order-total-mc',
              },
              iteration: {
                source:
                  "{{Object.entries(order.mc_total_amount ?? {}).filter(([code]) => code !== (_global.preferredCurrency ?? 'KRW'))}}",
                item_var: 'currency',
              },
              children: [
                {
                  id: 'order-total-mc-item',
                  type: 'basic' as const,
                  name: 'Span',
                  props: { className: 'text-xs text-gray-500' },
                  text: '{{currency[1]?.formatted}}',
                },
              ],
            },
          ],
        },
      ],
    },
  ],
};

// 테스트용 API 응답 데이터
const mockOrdersResponse = {
  success: true,
  message: '주문 정보를 조회했습니다.',
  data: {
    data: [
      {
        id: 154,
        order_number: '20260209-TEST001',
        status: 'pending_payment',
        total_amount: '356000.00',
        total_amount_formatted: '356,000원',
        mc_total_amount: {
          CNY: { amount: 2064.8, formatted: '¥2,064.80' },
          EUR: { amount: 277.68, formatted: '€277.68' },
          JPY: { amount: 40940, formatted: '¥40,940' },
          KRW: { amount: 356000, formatted: '356,000원' },
          USD: { amount: 302.6, formatted: '$302.60' },
        },
      },
      {
        id: 155,
        order_number: '20260209-TEST002',
        status: 'payment_complete',
        total_amount: '100000.00',
        total_amount_formatted: '100,000원',
        mc_total_amount: {
          CNY: { amount: 580, formatted: '¥580.00' },
          EUR: { amount: 78, formatted: '€78.00' },
          JPY: { amount: 11500, formatted: '¥11,500' },
          KRW: { amount: 100000, formatted: '100,000원' },
          USD: { amount: 85, formatted: '$85.00' },
        },
      },
    ],
    statistics: {
      pending_payment: 1,
      payment_complete: 1,
      preparing: 0,
      shipping: 0,
      delivered: 0,
      confirmed: 0,
    },
    pagination: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 2,
    },
  },
};

describe('마이페이지 주문내역 - 다통화 표시', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  it('기본 통화(KRW)로 주문 총액이 표시된다', async () => {
    const testUtils = createLayoutTest(orderListLayout, {
      componentRegistry: registry,
      initialData: {
        orders: mockOrdersResponse,
      },
    });

    await testUtils.render();

    // KRW 금액이 표시되어야 함
    expect(screen.getByText('356,000원')).toBeInTheDocument();
    expect(screen.getByText('100,000원')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('보조 통화가 회색 소형 텍스트로 표시된다', async () => {
    const testUtils = createLayoutTest(orderListLayout, {
      componentRegistry: registry,
      initialData: {
        orders: mockOrdersResponse,
      },
    });

    await testUtils.render();

    // 보조 통화 (KRW 제외)가 표시되어야 함
    expect(screen.getByText('$302.60')).toBeInTheDocument();
    expect(screen.getByText('€277.68')).toBeInTheDocument();
    expect(screen.getByText('¥40,940')).toBeInTheDocument();
    expect(screen.getByText('¥2,064.80')).toBeInTheDocument();

    // 두 번째 주문의 보조 통화도 표시
    expect(screen.getByText('$85.00')).toBeInTheDocument();
    expect(screen.getByText('€78.00')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('preferredCurrency 변경 시 해당 통화가 주 표시된다', async () => {
    const testUtils = createLayoutTest(orderListLayout, {
      componentRegistry: registry,
      initialData: {
        orders: mockOrdersResponse,
      },
    });

    testUtils.mockApi('orders', { response: mockOrdersResponse });

    // preferredCurrency를 USD로 먼저 설정
    testUtils.setState('preferredCurrency', 'USD', 'global');

    await testUtils.render();

    // USD 금액이 주 표시로 나타나야 함
    expect(screen.getByText('$302.60')).toBeInTheDocument();
    expect(screen.getByText('$85.00')).toBeInTheDocument();

    // KRW는 보조 통화로 표시되어야 함
    expect(screen.getByText('356,000원')).toBeInTheDocument();
    expect(screen.getByText('100,000원')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('mc_total_amount가 비어있으면 fallback으로 total_amount_formatted가 표시된다', async () => {
    const emptyMcData = {
      ...mockOrdersResponse,
      data: {
        ...mockOrdersResponse.data,
        data: [
          {
            id: 999,
            order_number: '20260209-EMPTY',
            status: 'pending_payment',
            total_amount: '50000.00',
            total_amount_formatted: '50,000원',
            mc_total_amount: null,
          },
        ],
      },
    };

    const testUtils = createLayoutTest(orderListLayout, {
      componentRegistry: registry,
      initialData: {
        orders: emptyMcData,
      },
    });

    await testUtils.render();

    // fallback 금액이 표시되어야 함
    expect(screen.getByText('50,000원')).toBeInTheDocument();

    testUtils.cleanup();
  });
});
