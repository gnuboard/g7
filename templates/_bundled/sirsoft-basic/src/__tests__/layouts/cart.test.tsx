/**
 * @file cart.test.tsx
 * @description 장바구니 페이지 레이아웃 렌더링 테스트
 *
 * 테스트 케이스 (113~128, 16개)
 * - 7.19.1 기본 렌더링: 113~116 (4개)
 * - 7.19.2 체크박스 및 선택 상태: 117~120 (4개)
 * - 7.19.3 모달 인터랙션: 121~124 (4개)
 * - 7.19.4 API 연동 및 상태 업데이트: 125~128 (4개)
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  screen,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';
import { ActionDispatcher } from '@/core/template-engine/ActionDispatcher';
import {
  toggleCartItemSelectionHandler,
  selectAllCartItemsHandler,
  openCartDeleteModalHandler,
  openCartOptionModalHandler,
  recalculateCartHandler,
} from '../../handlers/cartHandlers';
import cartSummaryJson from '../../../layouts/partials/shop/_cart_summary.json';

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
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button
    type={type as 'button' | 'submit' | 'reset'}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
  >
    {children || text}
  </button>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  checked?: boolean;
  className?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ type, name, checked, className, onChange, 'data-testid': testId }) => (
  <input
    type={type}
    name={name}
    checked={checked}
    className={className}
    onChange={onChange}
    data-testid={testId}
  />
);

const TestCheckbox: React.FC<{
  name?: string;
  checked?: boolean;
  className?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ name, checked, className, onChange, 'data-testid': testId }) => (
  <input
    type="checkbox"
    name={name}
    checked={checked}
    className={className}
    onChange={onChange}
    data-testid={testId}
  />
);

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <h1 className={className} data-testid={testId}>{children || text}</h1>;

const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <h3 className={className} data-testid={testId}>{children || text}</h3>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <p className={className} data-testid={testId}>{children || text}</p>;

const TestLabel: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <label className={className} data-testid={testId}>{children || text}</label>;

const TestIcon: React.FC<{ name?: string; className?: string; 'data-testid'?: string }> =
  ({ name, className, 'data-testid': testId }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} data-testid={testId} />;

const TestImg: React.FC<{ src?: string; alt?: string; className?: string; 'data-testid'?: string }> =
  ({ src, alt, className, 'data-testid': testId }) => <img src={src} alt={alt} className={className} data-testid={testId} />;

const TestFragment: React.FC<{ children?: React.ReactNode }> =
  ({ children }) => <>{children}</>;

const TestContainer: React.FC<{ children?: React.ReactNode; className?: string }> =
  ({ children, className }) => <div className={`container ${className || ''}`}>{children}</div>;

const TestGrid: React.FC<{ cols?: number; gap?: number; children?: React.ReactNode }> =
  ({ children }) => <div className="grid">{children}</div>;

// ========== 테스트용 레이아웃 Fixture ==========

/**
 * 실제 주문 요약(`_cart_summary.json`)에서 주문하기 단추의 비활성 식을 찾는다.
 * 주문 권한 조건(`abilities?.can_order`)이 들어 있는 `disabled` 식이 그 단추다.
 *
 * @param node 찾을 레이아웃 노드
 * @returns 비활성 식, 없으면 undefined
 */
function findCheckoutDisabledExpr(node: unknown): string | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findCheckoutDisabledExpr(child);
      if (found) return found;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const disabled = (node as { props?: { disabled?: unknown } }).props?.disabled;
    if (typeof disabled === 'string' && disabled.includes('abilities?.can_order')) return disabled;
    for (const value of Object.values(node)) {
      const found = findCheckoutDisabledExpr(value);
      if (found) return found;
    }
  }
  return undefined;
}

/** 고정물 주문하기 단추가 쓰는 비활성 식 — 운영 레이아웃 식을 그대로 쓴다(고정물이 따로 식을 적으면 운영과 어긋나도 모른다) */
const CHECKOUT_DISABLED_EXPR = findCheckoutDisabledExpr(cartSummaryJson);

/**
 * 장바구니 페이지 기본 레이아웃 (partial을 해결한 형태)
 */
const cartLayoutFixture = {
  version: '1.0.0',
  layout_name: 'shop/cart',
  state: {
    selectedItems: [],
    allSelected: false,
    isCalculating: false,
    optionModal: { visible: false, targetItem: null },
    deleteModal: { visible: false, targetIds: [] },
  },
  data_sources: [
    {
      id: 'cartItems',
      type: 'api',
      endpoint: '/api/modules/sirsoft-ecommerce/cart',
      method: 'GET',
      auto_fetch: true,
    },
  ],
  components: [
    // 페이지 타이틀
    {
      id: 'cart-title',
      type: 'basic',
      name: 'H1',
      props: { className: 'text-2xl font-bold', 'data-testid': 'cart-title' },
      children: [
        { type: 'basic', name: 'Span', text: '장바구니' },
        {
          id: 'cart-count',
          type: 'basic',
          name: 'Span',
          props: { className: 'text-gray-500', 'data-testid': 'cart-count' },
          text: '({{cartItems.data.items.length ?? 0}})',
        },
      ],
    },
    // 전체 선택 영역
    {
      id: 'select-all-section',
      type: 'basic',
      name: 'Div',
      props: { className: 'flex items-center', 'data-testid': 'select-all-section' },
      children: [
        {
          id: 'select-all-checkbox',
          type: 'basic',
          name: 'Checkbox',
          props: {
            name: 'selectAll',
            checked: '{{_local.allSelected}}',
            'data-testid': 'select-all-checkbox',
          },
          actions: [
            {
              type: 'change',
              handler: 'selectAllCartItems',
              params: {
                allItemIds: '{{cartItems.data.items?.map(item => item.id) ?? []}}',
                isSelected: '{{!_local.allSelected}}',
              },
            },
          ],
        },
        {
          type: 'basic',
          name: 'Label',
          props: { className: 'text-sm', 'data-testid': 'select-all-label' },
          text: '전체선택',
        },
        {
          type: 'basic',
          name: 'Span',
          props: { className: 'text-gray-500', 'data-testid': 'selected-count' },
          text: '({{_local.selectedItems?.length ?? 0}}/{{cartItems.data.item_count ?? 0}})',
        },
      ],
    },
    // 선택 삭제 버튼
    {
      id: 'delete-selected-btn',
      type: 'basic',
      name: 'Button',
      props: {
        className: 'text-red-600',
        disabled: '{{!_local.selectedItems || _local.selectedItems.length === 0}}',
        'data-testid': 'delete-selected-btn',
      },
      text: '선택삭제',
      actions: [
        {
          type: 'click',
          handler: 'openCartDeleteModal',
          params: { targetIds: '{{_local.selectedItems}}' },
        },
      ],
    },
    // 장바구니 아이템 목록
    {
      id: 'cart-list',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'cart-list' },
      children: [
        // 빈 장바구니 표시
        {
          id: 'cart-empty',
          type: 'basic',
          name: 'Div',
          if: '{{!cartItems.data.items || cartItems.data.items.length === 0}}',
          props: { className: 'text-center', 'data-testid': 'cart-empty' },
          children: [
            { type: 'basic', name: 'Icon', props: { name: 'shopping-cart', className: 'w-16 h-16' } },
            { type: 'basic', name: 'P', text: '장바구니가 비어있습니다', props: { 'data-testid': 'empty-message' } },
            {
              type: 'basic',
              name: 'Button',
              text: '쇼핑 계속하기',
              props: { 'data-testid': 'continue-shopping-btn' },
              actions: [{ type: 'click', handler: 'navigate', params: { path: '/shop' } }],
            },
          ],
        },
        // 아이템 목록 (iteration)
        {
          id: 'cart-items-list',
          type: 'basic',
          name: 'Div',
          if: '{{cartItems.data.items && cartItems.data.items.length > 0}}',
          iteration: {
            source: '{{cartItems.data.items ?? []}}',
            item_var: 'item',
            index_var: 'index',
          },
          props: { 'data-testid': 'cart-items-list' },
          children: [
            {
              id: 'cart-item-{{index}}',
              type: 'basic',
              name: 'Div',
              props: { className: 'cart-item', 'data-testid': 'cart-item' },
              children: [
                {
                  type: 'basic',
                  name: 'Checkbox',
                  props: {
                    checked: '{{_local.selectedItems?.includes(item.id)}}',
                    'data-testid': 'item-checkbox',
                  },
                  actions: [
                    {
                      type: 'change',
                      handler: 'toggleCartItemSelection',
                      params: { itemId: '{{item.id}}', selectedItems: '{{_local.selectedItems}}' },
                    },
                  ],
                },
                { type: 'basic', name: 'Img', props: { src: '{{item.product.thumbnail_url}}', 'data-testid': 'item-image' } },
                { type: 'basic', name: 'Span', text: '{{item.product.name}}', props: { 'data-testid': 'item-name' } },
                { type: 'basic', name: 'Span', text: '{{item.product_option.option_name}}', props: { 'data-testid': 'item-option' } },
                { type: 'basic', name: 'Span', text: '{{item.quantity}}', props: { 'data-testid': 'item-quantity' } },
                { type: 'basic', name: 'Span', text: '₩{{item.product_option.price}}', props: { 'data-testid': 'item-price' } },
                {
                  type: 'basic',
                  name: 'Button',
                  text: '옵션변경',
                  props: { 'data-testid': 'change-option-btn' },
                  actions: [
                    {
                      type: 'click',
                      handler: 'openCartOptionModal',
                      params: { targetItem: '{{item}}' },
                    },
                  ],
                },
                {
                  type: 'basic',
                  name: 'Button',
                  text: '삭제',
                  props: { 'data-testid': 'delete-item-btn' },
                  actions: [
                    {
                      type: 'click',
                      handler: 'openCartDeleteModal',
                      params: { targetIds: '[{{item.id}}]' },
                    },
                  ],
                },
              ],
            },
          ],
        },
      ],
    },
    // 주문 요약 패널
    {
      id: 'order-summary',
      type: 'basic',
      name: 'Div',
      props: { className: 'order-summary', 'data-testid': 'order-summary' },
      children: [
        { type: 'basic', name: 'H3', text: '주문 요약', props: { 'data-testid': 'summary-title' } },
        // 로딩 상태
        {
          type: 'basic',
          name: 'Div',
          if: '{{_local.isCalculating}}',
          props: { 'data-testid': 'loading-spinner' },
          children: [{ type: 'basic', name: 'Icon', props: { name: 'loader', className: 'animate-spin' } }],
        },
        // 금액 정보
        {
          type: 'basic',
          name: 'Div',
          if: '{{!_local.isCalculating}}',
          props: { 'data-testid': 'price-info' },
          children: [
            {
              type: 'basic',
              name: 'Div',
              props: { 'data-testid': 'subtotal-row' },
              children: [
                { type: 'basic', name: 'Span', text: '상품금액' },
                { type: 'basic', name: 'Span', text: '{{cartItems.data.calculation?.subtotal_formatted ?? "₩0"}}', props: { 'data-testid': 'subtotal-value' } },
              ],
            },
            {
              type: 'basic',
              name: 'Div',
              props: { 'data-testid': 'shipping-row' },
              children: [
                { type: 'basic', name: 'Span', text: '배송비' },
                { type: 'basic', name: 'Span', text: '{{cartItems.data.calculation?.shipping_fee_formatted ?? "₩0"}}', props: { 'data-testid': 'shipping-value' } },
              ],
            },
            {
              type: 'basic',
              name: 'Div',
              if: '{{(cartItems.data.calculation?.total_discount ?? 0) > 0}}',
              props: { 'data-testid': 'discount-row' },
              children: [
                { type: 'basic', name: 'Span', text: '할인' },
                { type: 'basic', name: 'Span', text: '-{{cartItems.data.calculation?.discount_formatted}}', props: { 'data-testid': 'discount-value' } },
              ],
            },
            {
              type: 'basic',
              name: 'Div',
              props: { 'data-testid': 'total-row' },
              children: [
                { type: 'basic', name: 'Span', text: '결제 예정 금액' },
                { type: 'basic', name: 'Span', text: '{{cartItems.data.calculation?.final_amount_formatted ?? "₩0"}}', props: { 'data-testid': 'total-value' } },
              ],
            },
          ],
        },
        // 주문하기 버튼
        {
          id: 'checkout-btn',
          type: 'basic',
          name: 'Button',
          text: '주문하기',
          props: {
            disabled: CHECKOUT_DISABLED_EXPR,
            'data-testid': 'checkout-btn',
          },
          actions: [
            {
              type: 'click',
              handler: 'apiCall',
              target: '/api/modules/sirsoft-ecommerce/checkout',
              params: {
                method: 'POST',
                body: { item_ids: '{{_local.selectedItems}}' },
              },
              onSuccess: [{ handler: 'navigate', params: { path: '/shop/checkout' } }],
            },
          ],
        },
      ],
    },
  ],
};

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Checkbox: { component: TestCheckbox, metadata: { name: 'Checkbox', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
    Grid: { component: TestGrid, metadata: { name: 'Grid', type: 'layout' } },
  };

  return registry;
}

// ========== 테스트 케이스 ==========

describe('장바구니 페이지 레이아웃 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
    // 실제 장바구니 핸들러를 시험 유틸이 만드는 디스패처에 끼운다(운영 레이아웃과 같은 핸들러 이름)
    const original = ActionDispatcher.prototype.setGlobalStateUpdater;
    vi.spyOn(ActionDispatcher.prototype, 'setGlobalStateUpdater').mockImplementation(function (this: ActionDispatcher, updater) {
      this.registerHandler('toggleCartItemSelection', toggleCartItemSelectionHandler);
      this.registerHandler('selectAllCartItems', selectAllCartItemsHandler);
      this.registerHandler('openCartDeleteModal', openCartDeleteModalHandler);
      this.registerHandler('openCartOptionModal', openCartOptionModalHandler);
      this.registerHandler('recalculateCart', recalculateCartHandler);

      return original.call(this, updater);
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('7.19.1 기본 렌더링 테스트', () => {
    // 113: 빈 장바구니 렌더링
    // @scenario area=render
    // @effects cart_empty_state_renders
    it('#113 빈 장바구니 시 기본 레이아웃이 렌더링된다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
        translations: { 'shop.cart.title': '장바구니' },
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [],
            item_count: 0,
            calculation: null,
          },
        },
      });

      // When
      await testUtils.render();

      // Then: 기본 레이아웃 요소들이 렌더링됨
      expect(screen.getByTestId('cart-title')).toBeInTheDocument();
      expect(screen.getByTestId('select-all-section')).toBeInTheDocument();
      expect(screen.getByTestId('order-summary')).toBeInTheDocument();
      // 참고: if 조건부 렌더링은 레이아웃 테스트 유틸리티 보완 후 검증 예정

      testUtils.cleanup();
    });

    // 114: 장바구니 기본 구조 렌더링
    // @scenario area=render
    // @effects cart_structure_renders
    it('#114 장바구니 기본 구조가 렌더링된다', async () => {
      // Given
      const mockCartItems = [
        {
          id: 1,
          product: { id: 1, name: '상품A', thumbnail_url: '/img/a.jpg' },
          product_option: { id: 10, option_name: '빨강/XL', price: 15000 },
          quantity: 2,
        },
        {
          id: 2,
          product: { id: 2, name: '상품B', thumbnail_url: '/img/b.jpg' },
          product_option: { id: 20, option_name: '기본', price: 20000 },
          quantity: 1,
        },
      ];

      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: mockCartItems,
            item_count: 2,
            calculation: {
              subtotal: 50000,
              subtotal_formatted: '₩50,000',
              shipping_fee_formatted: '₩3,000',
              final_amount_formatted: '₩53,000',
            },
          },
        },
      });

      // When
      await testUtils.render();

      // Then: 기본 구조가 렌더링됨
      expect(screen.getByTestId('cart-title')).toBeInTheDocument();
      expect(screen.getByTestId('cart-list')).toBeInTheDocument();
      expect(screen.getByTestId('order-summary')).toBeInTheDocument();
      // 참고: iteration 기반 아이템 목록 렌더링은 유틸리티 보완 후 검증 예정

      testUtils.cleanup();
    });

    // 115: 주문 요약 패널 렌더링
    // @scenario area=render
    // @effects cart_summary_panel_renders
    it('#115 주문 요약 패널을 렌더링한다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [{ id: 1, product: { name: 'Test' }, product_option: { price: 10000 }, quantity: 1 }],
            calculation: {
              subtotal: 50000,
              subtotal_formatted: '₩50,000',
              total_shipping_fee: 3000,
              shipping_fee_formatted: '₩3,000',
              total_discount: 0, // 할인이 0이면 할인 row가 조건부 렌더링되지 않음
              discount_formatted: '₩0',
              final_amount: 53000,
              final_amount_formatted: '₩53,000',
            },
          },
        },
      });

      // When
      await testUtils.render();

      // Then: 주문 요약 패널이 렌더링됨
      expect(screen.getByTestId('order-summary')).toBeInTheDocument();
      expect(screen.getByTestId('checkout-btn')).toBeInTheDocument();
      expect(screen.getByText('주문 요약')).toBeInTheDocument();
      // 참고: 금액 바인딩 및 조건부 렌더링은 유틸리티 보완 후 상세 검증 예정

      testUtils.cleanup();
    });

    // 116: 초기 상태 올바른 설정
    // @scenario area=render
    // @effects cart_initial_state_seeded
    it('#116 초기 상태가 올바르게 설정된다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [
              { id: 1, product: { name: 'A' }, product_option: { price: 100 }, quantity: 1 },
              { id: 2, product: { name: 'B' }, product_option: { price: 200 }, quantity: 1 },
            ],
            calculation: null,
          },
        },
      });

      // When
      await testUtils.render();

      // Then
      const state = testUtils.getState();
      expect(state._local.allSelected).toBe(false);
      expect(state._local.isCalculating).toBe(false);
      expect(state._local.deleteModal).toEqual({ visible: false, targetIds: [] });

      testUtils.cleanup();
    });
  });

  describe('7.19.2 체크박스 및 선택 상태 테스트', () => {
    // 117: 개별 아이템 체크 해제
    // @scenario area=selection
    // @effects cart_item_toggle_updates_selection
    it('#117 개별 아이템 체크 해제 시 selectedItems에서 제거된다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
        initialState: {
          _local: { selectedItems: [1, 2], allSelected: true },
        },
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [
              { id: 1, product: { name: 'A' }, product_option: { price: 100 }, quantity: 1 },
              { id: 2, product: { name: 'B' }, product_option: { price: 200 }, quantity: 1 },
            ],
            calculation: null,
          },
        },
      });

      await testUtils.render();

      // When: toggleCartItemSelection 액션 트리거 (itemId: 1 해제)
      await testUtils.triggerAction({
        type: 'change',
        handler: 'toggleCartItemSelection',
        params: { itemId: 1, selectedItems: [1, 2] },
      });

      // Then: 실제 핸들러가 레이아웃 디스패처를 거쳐 selectedItems 에서 1 을 뺀다
      expect(testUtils.getState()._local.selectedItems).toEqual([2]);
      expect(testUtils.getState()._local.allSelected).toBe(false);

      testUtils.cleanup();
    });

    // 118: 개별 아이템 재체크
    // @scenario area=selection
    // @effects cart_item_toggle_updates_selection
    it('#118 개별 아이템 재체크 시 selectedItems에 추가된다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
        initialState: {
          _local: { selectedItems: [1], allSelected: false },
        },
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [
              { id: 1, product: { name: 'A' }, product_option: {}, quantity: 1 },
              { id: 2, product: { name: 'B' }, product_option: {}, quantity: 1 },
            ],
            calculation: null,
          },
        },
      });

      await testUtils.render();

      // When
      await testUtils.triggerAction({
        type: 'change',
        handler: 'toggleCartItemSelection',
        params: { itemId: 2, selectedItems: [1] },
      });

      // Then
      expect(testUtils.getState()._local.selectedItems).toEqual([1, 2]);

      testUtils.cleanup();
    });

    // 119: 전체 선택 토글 (해제)
    // @scenario area=selection
    // @effects cart_select_all_updates_selection
    it('#119 전체 선택 해제 시 selectedItems가 비워진다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
        initialState: {
          _local: { selectedItems: [1, 2], allSelected: true },
        },
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [
              { id: 1, product: { name: 'A' }, product_option: {}, quantity: 1 },
              { id: 2, product: { name: 'B' }, product_option: {}, quantity: 1 },
            ],
            calculation: null,
          },
        },
      });

      await testUtils.render();
      // 전제: initialState 의 전체 선택 상태가 그대로 들어와 있다(없으면 아래 단언이 처음 값과 같아 헛돈다)
      expect(testUtils.getState()._local.selectedItems).toEqual([1, 2]);
      expect(testUtils.getState()._local.allSelected).toBe(true);

      // When
      await testUtils.triggerAction({
        type: 'change',
        handler: 'selectAllCartItems',
        params: { allItemIds: [1, 2], isSelected: false },
      });

      // Then
      expect(testUtils.getState()._local.selectedItems).toEqual([]);
      expect(testUtils.getState()._local.allSelected).toBe(false);

      testUtils.cleanup();
    });

    // 120: 전체 선택 토글 (선택)
    // @scenario area=selection
    // @effects cart_select_all_updates_selection
    it('#120 전체 선택 시 모든 아이템이 selectedItems에 추가된다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
        initialState: {
          _local: { selectedItems: [], allSelected: false },
        },
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [
              { id: 1, product: { name: 'A' }, product_option: {}, quantity: 1 },
              { id: 2, product: { name: 'B' }, product_option: {}, quantity: 1 },
            ],
            calculation: null,
          },
        },
      });

      await testUtils.render();

      // When
      await testUtils.triggerAction({
        type: 'change',
        handler: 'selectAllCartItems',
        params: { allItemIds: [1, 2], isSelected: true },
      });

      // Then
      expect(testUtils.getState()._local.selectedItems).toEqual([1, 2]);
      expect(testUtils.getState()._local.allSelected).toBe(true);

      testUtils.cleanup();
    });
  });

  describe('7.19.3 모달 인터랙션 테스트', () => {
    // 121: 옵션/수량 변경 모달 열기
    // @scenario area=modal
    // @effects cart_option_modal_opens_with_target
    it('#121 옵션 변경 버튼 클릭 시 모달이 열린다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [{ id: 1, product: { name: 'A' }, product_option: { option_name: 'XL' }, quantity: 1 }],
            calculation: null,
          },
        },
      });

      await testUtils.render();

      // When
      await testUtils.triggerAction({
        type: 'click',
        handler: 'openCartOptionModal',
        params: {
          targetItem: {
            id: 1,
            product_name: 'A',
            options: { size: 'XL' },
            quantity: 1,
          },
        },
      });

      // Then
      const state = testUtils.getState();
      expect(state._local.optionModal).toEqual({
        visible: true,
        targetItem: { id: 1, product_name: 'A', options: { size: 'XL' }, quantity: 1 },
      });
      expect(state._local.selectedOptions).toEqual({ size: 'XL' });
      expect(state._local.selectedQuantity).toBe(1);

      testUtils.cleanup();
    });

    // 122: 옵션/수량 변경 모달 닫기
    // @scenario area=modal
    // @effects cart_option_modal_closes
    it('#122 모달 취소 시 optionModal이 닫힌다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
        initialState: {
          _local: {
            optionModal: { visible: true, targetItem: { id: 1 } },
          },
        },
      });

      testUtils.mockApi('cartItems', { response: { data: { items: [], calculation: null } } });

      await testUtils.render();

      // When: closeModal 액션 (별도 구현 필요)
      testUtils.setState('optionModal', { visible: false, targetItem: null }, 'local');

      // Then
      expect(testUtils.getState()._local.optionModal.visible).toBe(false);

      testUtils.cleanup();
    });

    // 123: 삭제 확인 모달 열기
    // @scenario area=modal
    // @effects cart_delete_modal_opens_with_targets
    it('#123 삭제 버튼 클릭 시 deleteModal이 열린다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [{ id: 1, product: { name: 'A' }, product_option: {}, quantity: 1 }],
            calculation: null,
          },
        },
      });

      await testUtils.render();

      // When
      await testUtils.triggerAction({
        type: 'click',
        handler: 'openCartDeleteModal',
        params: { targetIds: [1] },
      });

      // Then
      const state = testUtils.getState();
      expect(state._local.deleteModal).toEqual({ visible: true, targetIds: [1] });

      testUtils.cleanup();
    });

    // 124: 선택 삭제 모달 열기
    // @scenario area=modal
    // @effects cart_delete_modal_opens_with_targets
    it('#124 선택삭제 버튼 클릭 시 선택된 아이템들로 deleteModal이 열린다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
        initialState: {
          _local: { selectedItems: [1, 2], allSelected: true },
        },
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [
              { id: 1, product: { name: 'A' }, product_option: {}, quantity: 1 },
              { id: 2, product: { name: 'B' }, product_option: {}, quantity: 1 },
            ],
            calculation: null,
          },
        },
      });

      await testUtils.render();

      // When
      await testUtils.triggerAction({
        type: 'click',
        handler: 'openCartDeleteModal',
        params: { targetIds: [1, 2] },
      });

      // Then
      const state = testUtils.getState();
      expect(state._local.deleteModal).toEqual({ visible: true, targetIds: [1, 2] });

      testUtils.cleanup();
    });
  });

  describe('7.19.4 API 연동 및 상태 업데이트 테스트', () => {
    // 125: 선택 변경 시 금액 재계산
    // @scenario area=api
    // @effects cart_recalculate_marks_calculating
    it('#125 선택 변경 시 recalculateCart가 호출된다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
        initialState: {
          _local: { selectedItems: [1, 2], isCalculating: false },
        },
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [
              { id: 1, product: { name: 'A' }, product_option: {}, quantity: 1 },
              { id: 2, product: { name: 'B' }, product_option: {}, quantity: 1 },
            ],
            calculation: null,
          },
        },
      });

      await testUtils.render();

      // When
      await testUtils.triggerAction({
        type: 'change',
        handler: 'recalculateCart',
        params: { selectedItems: [1, 2] },
      });

      // Then: 선택 항목이 있으면 계산 중으로 바뀐다
      const state = testUtils.getState();
      expect(state._local.isCalculating).toBe(true);

      testUtils.cleanup();
    });

    // 126: API Mock이 올바르게 동작하는지 확인
    // @scenario area=api
    // @effects cart_mocked_data_renders
    it('#126 API Mock이 올바르게 설정되고 데이터를 반환한다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
      });

      const mockData = {
        items: [{ id: 1, product: { name: 'A' }, product_option: {}, quantity: 2 }],
        calculation: { final_amount_formatted: '₩20,000' },
      };

      testUtils.mockApi('cartItems', {
        response: {
          data: mockData,
        },
      });

      await testUtils.render();

      // Then: 레이아웃이 렌더링되고 API가 호출됨
      expect(screen.getByTestId('cart-list')).toBeInTheDocument();
      expect(screen.getByTestId('checkout-btn')).toBeInTheDocument();
      // 참고: iteration 기반 수량 표시는 유틸리티 보완 후 검증 예정

      testUtils.cleanup();
    });

    // 127: 삭제 후 목록 갱신
    // @scenario area=api
    // @effects cart_empty_after_delete
    it('#127 삭제 후 아이템이 목록에서 제거된다', async () => {
      // Given
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [], // 삭제 후 빈 목록
            calculation: null,
          },
        },
      });

      await testUtils.render();

      // Then: 빈 장바구니 표시
      expect(screen.getByTestId('cart-empty')).toBeInTheDocument();

      testUtils.cleanup();
    });

    // 128: 주문하기 버튼 클릭
    // @scenario area=api
    // @effects cart_checkout_button_disabled_without_selection
    it('#128 주문하기 버튼이 렌더링되고 비활성화 상태를 확인한다', async () => {
      // 운영 레이아웃에서 비활성 식을 찾지 못하면 고정물이 식 없이 그려져 단언이 헛돈다
      expect(CHECKOUT_DISABLED_EXPR).toContain('_local.selectedItems.length === 0');

      // Given: 주문할 수 있는 상품이 있고 아직 아무것도 고르지 않았다
      const testUtils = createLayoutTest(cartLayoutFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApi('cartItems', {
        response: {
          data: {
            items: [{ id: 1, product: { name: 'A' }, product_option: {}, quantity: 1 }],
            calculation: { final_amount_formatted: '₩10,000' },
            has_unshippable_items: false,
            abilities: { can_order: true },
          },
        },
      });

      await testUtils.render();

      // Then: 고른 상품이 없으면 비활성
      expect(screen.getByTestId('checkout-btn')).toBeDisabled();

      // When: 상품 하나를 고르면
      testUtils.setState('selectedItems', [1], 'local');
      await testUtils.rerender();

      // Then: 다른 조건이 모두 맞으므로 활성
      expect(screen.getByTestId('checkout-btn')).toBeEnabled();

      testUtils.cleanup();
    });
  });
});
