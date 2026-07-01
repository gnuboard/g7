/**
 * @file order-cancelled-earned-points-hidden.test.tsx
 * @description U19③ — 취소 주문의 "적립 예정" 마일리지 숨김 회귀 렌더 테스트 (유저 화면 3종)
 *
 * 결함: total_earned_points_amount 는 취소 후에도 주문시점 스냅샷으로 양수가 보존된다
 * (실 적립은 스케줄러가 option_status 기준으로 차단). 레이아웃이 `> 0` 만 검사하면
 * 취소 주문에도 "적립 예정 +NNN P" 가 계속 노출된다.
 *
 * 정정: 노출 블록 if 에 취소/부분취소 제외 가드를 결합한다(값 보존, 표시만 숨김).
 *   order.data: `... > 0 && order.data.order_status !== 'cancelled' && !order.data.is_partially_cancelled`
 *   목록 iteration order.*: 동일 논리 (order.* 경로)
 *
 * 본 테스트는 각 실제 레이아웃에서 적립예정 블록의 if 식을 추출해 그대로 렌더한다.
 * 가드가 없으면 취소 데이터에서 RED, 가드가 있으면 GREEN.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  screen,
  waitFor,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';
import paymentJson from '../../../layouts/partials/mypage/orders/_payment.json';
import listJson from '../../../layouts/partials/mypage/orders/_list.json';
import orderCompleteJson from '../../../layouts/shop/order_complete.json';

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestSpan: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);
const TestP: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
  <p className={className} data-testid={testId}>{children || text}</p>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function findNode(node: any, predicate: (n: any) => boolean): any {
  if (node == null || typeof node !== 'object') return null;
  if (predicate(node)) return node;
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        const hit = findNode(item, predicate);
        if (hit) return hit;
      }
    } else if (value && typeof value === 'object') {
      const hit = findNode(value, predicate);
      if (hit) return hit;
    }
  }
  return null;
}

/** 트리에서 total_earned_points_amount 노출 블록의 if 식 추출 */
function getEarnedIf(tree: any): string {
  const node = findNode(
    tree,
    (n) => typeof n.if === 'string' && n.if.includes('total_earned_points_amount'),
  );
  if (!node) throw new Error('적립예정 블록 if 식을 찾지 못함');
  return node.if as string;
}

function registerComponents() {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
}

// order.data.* 컨텍스트 (주문상세 결제정보) 프로브
function buildDetailProbe(earnedIf: string) {
  return {
    version: '1.0.0',
    layout_name: 'test/earned-detail',
    data_sources: [
      { id: 'order', type: 'api', endpoint: '/api/test/order', method: 'GET', auto_fetch: true },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        if: earnedIf,
        props: { 'data-testid': 'earned-block' },
        children: [
          {
            type: 'basic',
            name: 'Span',
            props: { 'data-testid': 'earned-text' },
            text: '+{{order.data.total_earned_points_amount_formatted ?? ""}}',
          },
        ],
      },
    ],
  };
}

// 목록 iteration order.* 컨텍스트 프로브
function buildListProbe(earnedIf: string) {
  return {
    version: '1.0.0',
    layout_name: 'test/earned-list',
    data_sources: [
      { id: 'orders', type: 'api', endpoint: '/api/test/orders', method: 'GET', auto_fetch: true },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        iteration: { source: '{{orders.data ?? []}}', item_var: 'order' },
        children: [
          {
            type: 'basic',
            name: 'P',
            if: earnedIf,
            props: { 'data-testid': 'earned-text' },
            text: '+{{order.total_earned_points_amount_formatted ?? ""}}',
          },
        ],
      },
    ],
  };
}

const normalDetail = {
  data: {
    total_earned_points_amount: 840,
    total_earned_points_amount_formatted: '840원',
    order_status: 'confirmed',
    is_partially_cancelled: false,
  },
};
const cancelledDetail = {
  data: {
    total_earned_points_amount: 840,
    total_earned_points_amount_formatted: '840원',
    order_status: 'cancelled',
    is_partially_cancelled: false,
  },
};
const partialCancelledDetail = {
  data: {
    total_earned_points_amount: 840,
    total_earned_points_amount_formatted: '840원',
    order_status: 'confirmed',
    is_partially_cancelled: true,
  },
};

describe('U19③-a 유저 주문상세 결제정보 적립예정 가드 (_payment.json)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  beforeEach(registerComponents);
  afterEach(() => { if (testUtils) testUtils.cleanup(); vi.clearAllMocks(); });

  it('정상 주문은 적립예정이 노출된다', async () => {
    testUtils = createLayoutTest(buildDetailProbe(getEarnedIf(paymentJson)));
    testUtils.mockApi('order', { response: normalDetail });
    await testUtils.render();
    await waitFor(() => expect(screen.getByTestId('earned-text')).toHaveTextContent('840원'));
  });

  it('전체취소 주문은 적립예정이 미노출된다', async () => {
    testUtils = createLayoutTest(buildDetailProbe(getEarnedIf(paymentJson)));
    testUtils.mockApi('order', { response: cancelledDetail });
    await testUtils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('earned-block')).not.toBeInTheDocument();
  });

  it('부분취소 주문은 적립예정이 미노출된다', async () => {
    testUtils = createLayoutTest(buildDetailProbe(getEarnedIf(paymentJson)));
    testUtils.mockApi('order', { response: partialCancelledDetail });
    await testUtils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('earned-block')).not.toBeInTheDocument();
  });
});

describe('U19③-b 유저 주문목록 카드 적립예정 가드 (_list.json)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  beforeEach(registerComponents);
  afterEach(() => { if (testUtils) testUtils.cleanup(); vi.clearAllMocks(); });

  // 유저 주문목록은 UserOrderListResource 가 order_status 가 아닌 `status` 키로 노출한다.
  const listRow = (status: string, partial: boolean) => ({
    total_earned_points_amount: 840,
    total_earned_points_amount_formatted: '840원',
    status,
    is_partially_cancelled: partial,
  });

  // 회귀 가드(라이브 검수에서 발견): _list.json 의 취소 가드는 UserOrderListResource 가
  // 노출하는 실제 필드명 `order.status` 를 써야 한다. `order.order_status`(존재하지 않는 키)를
  // 쓰면 항상 undefined 라 전체취소가 숨겨지지 않는다.
  it('_list.json 적립예정 가드는 order.order_status 가 아닌 order.status 를 검사한다', () => {
    const earnedIf = getEarnedIf(listJson);
    expect(earnedIf).toContain("order.status !== 'cancelled'");
    expect(earnedIf).not.toContain('order.order_status');
  });

  it('정상 주문 카드는 적립예정이 노출된다', async () => {
    testUtils = createLayoutTest(buildListProbe(getEarnedIf(listJson)));
    testUtils.mockApi('orders', { response: { data: [listRow('confirmed', false)] } });
    await testUtils.render();
    await waitFor(() => expect(screen.getByTestId('earned-text')).toHaveTextContent('840원'));
  });

  it('전체취소 주문 카드는 적립예정이 미노출된다', async () => {
    testUtils = createLayoutTest(buildListProbe(getEarnedIf(listJson)));
    testUtils.mockApi('orders', { response: { data: [listRow('cancelled', false)] } });
    await testUtils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('earned-text')).not.toBeInTheDocument();
  });

  it('부분취소 주문 카드는 적립예정이 미노출된다', async () => {
    testUtils = createLayoutTest(buildListProbe(getEarnedIf(listJson)));
    testUtils.mockApi('orders', { response: { data: [listRow('confirmed', true)] } });
    await testUtils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('earned-text')).not.toBeInTheDocument();
  });
});

describe('U19③-e 주문완료 화면 적립예정 가드 (order_complete.json)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  beforeEach(registerComponents);
  afterEach(() => { if (testUtils) testUtils.cleanup(); vi.clearAllMocks(); });

  // order_complete 는 orderData?.data.* 컨텍스트 — 별도 프로브
  function buildOrderCompleteProbe(earnedIf: string) {
    return {
      version: '1.0.0',
      layout_name: 'test/earned-complete',
      data_sources: [
        { id: 'orderData', type: 'api', endpoint: '/api/test/order', method: 'GET', auto_fetch: true },
      ],
      components: [
        {
          type: 'basic',
          name: 'Div',
          if: earnedIf,
          props: { 'data-testid': 'earned-block' },
          children: [
            {
              type: 'basic',
              name: 'Span',
              props: { 'data-testid': 'earned-text' },
              text: '+{{orderData?.data?.total_earned_points_amount_formatted ?? ""}}',
            },
          ],
        },
      ],
    };
  }

  it('정상 결제완료는 적립예정이 노출된다 (회귀 없음)', async () => {
    testUtils = createLayoutTest(buildOrderCompleteProbe(getEarnedIf(orderCompleteJson)));
    testUtils.mockApi('orderData', {
      response: {
        data: {
          total_earned_points_amount: 840,
          total_earned_points_amount_formatted: '840원',
          order_status: 'paid',
          is_partially_cancelled: false,
        },
      },
    });
    await testUtils.render();
    await waitFor(() => expect(screen.getByTestId('earned-text')).toHaveTextContent('840원'));
  });

  it('전체취소 상태로 진입해도 적립예정이 미노출된다 (일관성)', async () => {
    testUtils = createLayoutTest(buildOrderCompleteProbe(getEarnedIf(orderCompleteJson)));
    testUtils.mockApi('orderData', {
      response: {
        data: {
          total_earned_points_amount: 840,
          total_earned_points_amount_formatted: '840원',
          order_status: 'cancelled',
          is_partially_cancelled: false,
        },
      },
    });
    await testUtils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('earned-block')).not.toBeInTheDocument();
  });
});
