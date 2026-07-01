/**
 * @file order-item-subtotal-display.test.tsx
 * @description U19② — 무할인 주문 항목의 KRW 소계 표시 회귀 렌더 테스트
 *
 * 결함: OrderOption.subtotal_discount_amount 는 모델 decimal:2 캐스트로 JSON 에
 * 문자열("0.00")로 직렬화된다. _items.json 의 무할인 분기 `if` 가 strict 비교
 * (`=== 0`)를 쓰면 "0.00" === 0 → false 가 되어, 무할인 항목 소계가 할인/무할인
 * 양 분기 모두에서 미렌더되어 화면에서 사라진다.
 *
 * 정정: OrderOptionResource 가 비교용 숫자 보조 필드 subtotal_discount_amount_value(float)
 * 를 노출하고, _items.json 의 비교가 이 숫자 필드를 사용하도록 정정한다.
 *
 * 본 테스트는 _items.json 실제 파일에서 무할인/할인 분기의 `if` 식을 추출해 그대로
 * 렌더(템플릿 엔진 ConditionEvaluator 실 평가)하므로, 비교 기준이 문자열 컬럼이면
 * RED, 숫자 보조 필드면 GREEN 이 된다.
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
import itemsJson from '../../../layouts/partials/mypage/orders/_items.json';

// ---- 테스트 컴포넌트 ----
const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestSpan: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/** 객체 트리에서 술어를 만족하는 첫 노드를 깊이우선 탐색 */
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

/** _items.json 에서 무할인 소계 분기의 실제 if 식을 추출 (line-through 없는 단일 Span) */
function getNoDiscountSubtotalIf(): string {
  const node = findNode(
    itemsJson,
    (n) =>
      typeof n.if === 'string' &&
      n.if.includes('subtotal_discount_amount') &&
      n.if.includes('=== 0') &&
      n.name === 'Span'
  );
  if (!node) throw new Error('무할인 소계 분기 if 식을 _items.json 에서 찾지 못함');
  return node.if as string;
}

/** _items.json 에서 할인 소계 분기(`> 0`)의 실제 if 식을 추출 */
function getDiscountSubtotalIf(): string {
  const node = findNode(
    itemsJson,
    (n) =>
      typeof n.if === 'string' &&
      n.if.includes('subtotal_discount_amount') &&
      n.if.includes('> 0') &&
      n.name === 'Div'
  );
  if (!node) throw new Error('할인 소계 분기 if 식을 _items.json 에서 찾지 못함');
  return node.if as string;
}

// 백엔드 직렬화 재현: decimal:2 컬럼은 문자열, _value 보조 필드는 숫자
const noDiscountItem = {
  id: 1,
  product_name: '무할인 상품',
  subtotal_discount_amount: '0.00', // decimal:2 → 문자열
  subtotal_discount_amount_value: 0, // (float) 보조 필드
  subtotal_price_formatted: '30,000원',
  mc_subtotal_price: { KRW: { formatted: '30,000원' } },
  mc_final_amount: { KRW: { formatted: '30,000원' } },
};

const discountItem = {
  id: 2,
  product_name: '할인 상품',
  subtotal_discount_amount: '3000.00',
  subtotal_discount_amount_value: 3000,
  subtotal_price_formatted: '30,000원',
  mc_subtotal_price: { KRW: { formatted: '30,000원' } },
  mc_final_amount: { KRW: { formatted: '27,000원' } },
};

function buildProbeLayout(item: any, noDiscountIf: string, discountIf: string) {
  return {
    version: '1.0.0',
    layout_name: 'test/order-item-subtotal',
    data_sources: [
      { id: 'order', type: 'api', endpoint: '/api/test/order', method: 'GET', auto_fetch: true },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        iteration: { source: '{{order.data.options ?? []}}', item_var: 'item' },
        children: [
          // 할인 분기 (line-through + final)
          {
            type: 'basic',
            name: 'Div',
            if: discountIf,
            props: { 'data-testid': 'discount-block' },
            children: [
              {
                type: 'basic',
                name: 'Span',
                props: { 'data-testid': 'discount-subtotal' },
                text: '{{item.mc_final_amount?.KRW?.formatted ?? ""}}',
              },
            ],
          },
          // 무할인 분기 (단일 소계 Span)
          {
            type: 'basic',
            name: 'Span',
            if: noDiscountIf,
            props: { 'data-testid': 'no-discount-subtotal' },
            text: '{{item.mc_subtotal_price?.KRW?.formatted ?? item.subtotal_price_formatted ?? ""}}',
          },
        ],
      },
    ],
  };
}

describe('U19② 무할인 주문 항목 소계 표시 (_items.json if 식 실 평가)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
      Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
      Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
    vi.clearAllMocks();
  });

  it('무할인 항목의 소계 Span 이 렌더된다 (decimal 문자열 strict 비교 회귀 차단)', async () => {
    const layout = buildProbeLayout(noDiscountItem, getNoDiscountSubtotalIf(), getDiscountSubtotalIf());
    testUtils = createLayoutTest(layout);
    testUtils.mockApi('order', { response: { data: { options: [noDiscountItem] } } });
    await testUtils.render();

    await waitFor(() => {
      expect(screen.getByTestId('no-discount-subtotal')).toBeInTheDocument();
      expect(screen.getByTestId('no-discount-subtotal')).toHaveTextContent('30,000원');
    });
    // 무할인이므로 할인 분기는 미렌더
    expect(screen.queryByTestId('discount-block')).not.toBeInTheDocument();
  });

  it('할인 항목은 할인 분기가 렌더되고 무할인 분기는 미렌더된다 (회귀 없음)', async () => {
    const layout = buildProbeLayout(discountItem, getNoDiscountSubtotalIf(), getDiscountSubtotalIf());
    testUtils = createLayoutTest(layout);
    testUtils.mockApi('order', { response: { data: { options: [discountItem] } } });
    await testUtils.render();

    await waitFor(() => {
      expect(screen.getByTestId('discount-subtotal')).toBeInTheDocument();
      expect(screen.getByTestId('discount-subtotal')).toHaveTextContent('27,000원');
    });
    expect(screen.queryByTestId('no-discount-subtotal')).not.toBeInTheDocument();
  });

  it('비회원 주문상세도 동일 partial 공유로 무할인 소계가 렌더된다 (GuestOrderResource shape)', async () => {
    // GuestOrderResource 는 OrderOptionResource::collection 을 그대로 노출하므로
    // subtotal_discount_amount_value 보조 필드를 회원과 동일하게 가진다.
    const guestNoDiscountItem = { ...noDiscountItem, id: 99 };
    const layout = buildProbeLayout(
      guestNoDiscountItem,
      getNoDiscountSubtotalIf(),
      getDiscountSubtotalIf(),
    );
    testUtils = createLayoutTest(layout);
    testUtils.mockApi('order', { response: { data: { options: [guestNoDiscountItem] } } });
    await testUtils.render();

    await waitFor(() => {
      expect(screen.getByTestId('no-discount-subtotal')).toHaveTextContent('30,000원');
    });
  });
});
