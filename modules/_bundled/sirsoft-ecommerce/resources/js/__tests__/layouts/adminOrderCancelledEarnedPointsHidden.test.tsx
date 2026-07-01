/**
 * @file adminOrderCancelledEarnedPointsHidden.test.tsx
 * @description U19③-c/③-d — 관리자 주문상세/주문목록의 취소 주문 "적립 예정" 숨김 회귀 렌더 테스트
 *
 * 결함: total_earned_points_amount 는 취소 후에도 주문시점 스냅샷으로 양수가 보존된다.
 * 관리자 주문상세(_partial_payment_info.json)와 주문목록 DataGrid(_partial_order_datagrid.json)
 * 가 `> 0` 만 검사하면 취소 주문에도 적립예정이 노출된다.
 *
 * 정정: 노출 if 에 취소/부분취소 제외 가드를 결합한다(값 보존, 표시만 숨김).
 *   상세(order.data?.*): `... > 0 && order.data?.order_status !== 'cancelled' && !order.data?.is_partially_cancelled`
 *   목록(row.*):         `... > 0 && row.order_status !== 'cancelled' && !row.is_partially_cancelled`
 *
 * 각 실제 레이아웃에서 적립예정 if 식을 추출해 그대로 렌더한다 — 가드 없으면 RED, 있으면 GREEN.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import paymentInfoJson from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';
import datagridJson from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json';

/** 트리 전체를 깊이우선 순회하며 술어 만족 첫 노드 반환 */
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

function getEarnedIf(tree: any): string {
  const node = findNode(
    tree,
    (n) => typeof n.if === 'string' && n.if.includes('total_earned_points_amount'),
  );
  if (!node) throw new Error('적립예정 if 식을 찾지 못함');
  return node.if as string;
}

let registry: MockComponentRegistry;
beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
});
afterEach(() => {
  vi.clearAllMocks();
});

// order.data?.* 컨텍스트 (관리자 주문상세 결제정보) 프로브
function buildDetailProbe(earnedIf: string) {
  return {
    version: '1.0.0',
    layout_name: 'test/admin-earned-detail',
    data_sources: [
      { id: 'order', type: 'api', endpoint: '/api/test/order', method: 'GET', auto_fetch: true },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        if: earnedIf,
        props: { 'data-testid': 'admin-earned-block' },
        children: [
          {
            type: 'basic',
            name: 'Span',
            props: { 'data-testid': 'admin-earned-text' },
            text: '{{order.data?.total_earned_points_amount}}P',
          },
        ],
      },
    ],
  };
}

// row.* 컨텍스트 (관리자 주문목록 DataGrid 셀) 프로브 — iteration 으로 row 컨텍스트 재현
function buildListProbe(earnedIf: string) {
  return {
    version: '1.0.0',
    layout_name: 'test/admin-earned-list',
    data_sources: [
      { id: 'rows', type: 'api', endpoint: '/api/test/rows', method: 'GET', auto_fetch: true },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        iteration: { source: '{{rows.data ?? []}}', item_var: 'row' },
        children: [
          {
            type: 'basic',
            name: 'Span',
            if: earnedIf,
            props: { 'data-testid': 'admin-earned-text' },
            text: '{{row.total_earned_points_amount_formatted ?? ""}}',
          },
        ],
      },
    ],
  };
}

const detailData = (status: string, partial: boolean) => ({
  data: {
    total_earned_points_amount: 840,
    total_earned_points_amount_formatted: '840',
    order_status: status,
    is_partially_cancelled: partial,
  },
});

const rowData = (status: string, partial: boolean) => ({
  total_earned_points_amount: 840,
  total_earned_points_amount_formatted: '840',
  order_status: status,
  is_partially_cancelled: partial,
});

describe('U19③-c 관리자 주문상세 결제정보 적립예정 가드 (_partial_payment_info.json)', () => {
  it('정상 주문은 적립예정이 노출된다', async () => {
    const utils = createLayoutTest(buildDetailProbe(getEarnedIf(paymentInfoJson)), {
      componentRegistry: registry as any,
      locale: 'ko',
    });
    utils.mockApi('order', { response: detailData('confirmed', false) });
    await utils.render();
    expect(screen.getByTestId('admin-earned-text')).toBeInTheDocument();
    utils.cleanup();
  });

  it('전체취소 주문은 적립예정이 미노출된다', async () => {
    const utils = createLayoutTest(buildDetailProbe(getEarnedIf(paymentInfoJson)), {
      componentRegistry: registry as any,
      locale: 'ko',
    });
    utils.mockApi('order', { response: detailData('cancelled', false) });
    await utils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('admin-earned-block')).not.toBeInTheDocument();
    utils.cleanup();
  });

  it('부분취소 주문은 적립예정이 미노출된다', async () => {
    const utils = createLayoutTest(buildDetailProbe(getEarnedIf(paymentInfoJson)), {
      componentRegistry: registry as any,
      locale: 'ko',
    });
    utils.mockApi('order', { response: detailData('confirmed', true) });
    await utils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('admin-earned-block')).not.toBeInTheDocument();
    utils.cleanup();
  });
});

describe('U19③-d 관리자 주문목록 DataGrid 적립예정 가드 (_partial_order_datagrid.json)', () => {
  it('정상 주문 행은 적립예정이 노출된다', async () => {
    const utils = createLayoutTest(buildListProbe(getEarnedIf(datagridJson)), {
      componentRegistry: registry as any,
      locale: 'ko',
    });
    utils.mockApi('rows', { response: { data: [rowData('confirmed', false)] } });
    await utils.render();
    expect(screen.getByTestId('admin-earned-text')).toBeInTheDocument();
    utils.cleanup();
  });

  it('전체취소 주문 행은 적립예정이 미노출된다', async () => {
    const utils = createLayoutTest(buildListProbe(getEarnedIf(datagridJson)), {
      componentRegistry: registry as any,
      locale: 'ko',
    });
    utils.mockApi('rows', { response: { data: [rowData('cancelled', false)] } });
    await utils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('admin-earned-text')).not.toBeInTheDocument();
    utils.cleanup();
  });

  it('부분취소 주문 행은 적립예정이 미노출된다', async () => {
    const utils = createLayoutTest(buildListProbe(getEarnedIf(datagridJson)), {
      componentRegistry: registry as any,
      locale: 'ko',
    });
    utils.mockApi('rows', { response: { data: [rowData('confirmed', true)] } });
    await utils.render();
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('admin-earned-text')).not.toBeInTheDocument();
    utils.cleanup();
  });
});
