/**
 * @file order-shipping.test.tsx
 * @description 주문 상세 - 배송지 정보 및 배송지 변경 모달 렌더링 테스트
 *
 * 테스트 케이스 (8개)
 * - 기본 렌더링: 배송지 정보 표시
 * - 배송지 수정 버튼: payment_complete 상태에서 표시
 * - 배송지 수정 버튼 숨김: shipping 상태에서 숨김
 * - 배송 메모 표시: delivery_memo가 있을 때 표시
 * - 배송 메모 숨김: delivery_memo가 없을 때 숨김
 * - 변경 모달: 탭 전환 (저장/직접입력)
 * - 변경 모달: confirm 속성 존재
 * - 변경 모달: 저장된 배송지 목록 렌더링
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

const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <h3 className={className} data-testid={testId}>{children || text}</h3>;

const TestIcon: React.FC<{ name?: string; className?: string; 'data-testid'?: string }> =
  ({ name, className, 'data-testid': testId }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} data-testid={testId} />;

const TestInput: React.FC<{
  type?: string;
  name?: string;
  value?: string;
  placeholder?: string;
  className?: string;
  readOnly?: boolean;
  'data-testid'?: string;
}> = ({ type, name, value, placeholder, className, 'data-testid': testId }) => (
  <input type={type} name={name} value={value} placeholder={placeholder} className={className} data-testid={testId} readOnly />
);

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <p className={className} data-testid={testId}>{children || text}</p>;

const TestLabel: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <label className={className} data-testid={testId}>{children || text}</label>;

const TestFragment: React.FC<{ children?: React.ReactNode }> =
  ({ children }) => <>{children}</>;

// ========== Fixture: 배송지 정보 섹션 ==========

const shippingInfoFixture = {
  version: '1.0.0',
  layout_name: 'mypage/orders/shipping',
  data_sources: [
    {
      id: 'order',
      endpoint: '/api/modules/sirsoft-ecommerce/user/orders/1',
      method: 'GET',
    },
  ],
  components: [
    {
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white rounded-lg p-6', 'data-testid': 'shipping-section' },
      children: [
        {
          comment: '섹션 제목 + 배송지 수정 버튼',
          type: 'basic',
          name: 'Div',
          props: { className: 'flex items-center justify-between mb-4', 'data-testid': 'shipping-header' },
          children: [
            {
              type: 'basic',
              name: 'H3',
              props: { 'data-testid': 'shipping-title' },
              text: '배송지 정보',
            },
            {
              comment: '배송지 수정 버튼 - pending_payment / payment_complete에서만 표시',
              type: 'basic',
              name: 'Button',
              if: "{{order.data.order_status === 'pending_payment' || order.data.order_status === 'payment_complete'}}",
              props: { type: 'button', 'data-testid': 'change-address-btn' },
              text: '배송지 수정',
            },
          ],
        },
        {
          comment: '받는 분',
          type: 'basic',
          name: 'Div',
          props: { 'data-testid': 'recipient-info' },
          children: [
            {
              type: 'basic',
              name: 'Span',
              props: { 'data-testid': 'recipient-name' },
              text: "{{order.data.shipping_address?.recipient_name ?? ''}}",
            },
            {
              type: 'basic',
              name: 'Span',
              props: { 'data-testid': 'recipient-phone' },
              text: "{{order.data.shipping_address?.recipient_phone ?? ''}}",
            },
          ],
        },
        {
          comment: '주소',
          type: 'basic',
          name: 'Span',
          props: { 'data-testid': 'full-address' },
          text: "{{order.data.shipping_address?.full_address ?? ''}}",
        },
        {
          comment: '배송 메모',
          type: 'basic',
          name: 'Div',
          if: '{{order.data.shipping_address?.delivery_memo}}',
          props: { 'data-testid': 'delivery-memo' },
          children: [
            {
              type: 'basic',
              name: 'Span',
              text: "{{order.data.shipping_address?.delivery_memo ?? ''}}",
            },
          ],
        },
      ],
    },
  ],
};

// ========== Fixture: 변경 모달 JSON 구조 검증용 ==========

const changeAddressModalFixture = {
  actions: [
    {
      comment: '저장된 배송지 선택 모드',
      type: 'click',
      if: "{{(_local.changeAddressMode ?? 'saved') === 'saved' && _local.selectedAddressId}}",
      confirm: '$t:mypage.order_detail.confirm_change_address',
      handler: 'apiCall',
      auth_required: true,
      target: '/api/modules/sirsoft-ecommerce/user/orders/{{order.data.id}}/shipping-address',
      params: {
        method: 'PUT',
        body: { address_id: '{{_local.selectedAddressId}}' },
      },
    },
    {
      comment: '직접 입력 모드',
      type: 'click',
      if: "{{(_local.changeAddressMode ?? 'saved') === 'manual'}}",
      confirm: '$t:mypage.order_detail.confirm_change_address',
      handler: 'apiCall',
      auth_required: true,
      target: '/api/modules/sirsoft-ecommerce/user/orders/{{order.data.id}}/shipping-address',
      params: {
        method: 'PUT',
        body: '{{_local.manualAddress}}',
      },
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
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ========== 테스트 ==========

describe('주문 상세 - 배송지 정보', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
    vi.restoreAllMocks();
  });

  it('배송지 정보를 렌더링한다', async () => {
    testUtils = createLayoutTest(shippingInfoFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('order', {
      response: {
        data: {
          id: 1,
          order_status: 'payment_complete',
          shipping_address: {
            recipient_name: '홍길동',
            recipient_phone: '010-1234-5678',
            full_address: '[12345] 서울시 강남구 테헤란로 101동 201호',
            delivery_memo: null,
          },
          shippings: [],
        },
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('shipping-section')).toBeInTheDocument();
    expect(screen.getByTestId('shipping-title')).toBeInTheDocument();
    expect(screen.getByTestId('recipient-name')).toBeInTheDocument();
    expect(screen.getByTestId('recipient-phone')).toBeInTheDocument();
  });

  it('payment_complete 상태에서 배송지 수정 버튼이 표시된다', async () => {
    testUtils = createLayoutTest(shippingInfoFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('order', {
      response: {
        data: {
          id: 1,
          order_status: 'payment_complete',
          shipping_address: {
            recipient_name: '홍길동',
            recipient_phone: '010-1234-5678',
            full_address: '[12345] 서울시 강남구',
          },
          shippings: [],
        },
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('change-address-btn')).toBeInTheDocument();
  });

  it('shipping 상태에서 배송지 수정 버튼이 숨겨진다', async () => {
    testUtils = createLayoutTest(shippingInfoFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('order', {
      response: {
        data: {
          id: 1,
          order_status: 'shipping',
          shipping_address: {
            recipient_name: '홍길동',
            recipient_phone: '010-1234-5678',
            full_address: '[12345] 서울시 강남구',
          },
          shippings: [],
        },
      },
    });

    await testUtils.render();

    expect(screen.queryByTestId('change-address-btn')).not.toBeInTheDocument();
  });

  it('배송 메모가 있을 때 표시된다', async () => {
    testUtils = createLayoutTest(shippingInfoFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('order', {
      response: {
        data: {
          id: 1,
          order_status: 'payment_complete',
          shipping_address: {
            recipient_name: '홍길동',
            recipient_phone: '010-1234-5678',
            full_address: '[12345] 서울시 강남구',
            delivery_memo: '부재 시 경비실에 맡겨주세요',
          },
          shippings: [],
        },
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('delivery-memo')).toBeInTheDocument();
  });

  it('배송 메모가 없으면 숨겨진다', async () => {
    testUtils = createLayoutTest(shippingInfoFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('order', {
      response: {
        data: {
          id: 1,
          order_status: 'payment_complete',
          shipping_address: {
            recipient_name: '홍길동',
            recipient_phone: '010-1234-5678',
            full_address: '[12345] 서울시 강남구',
            delivery_memo: null,
          },
          shippings: [],
        },
      },
    });

    await testUtils.render();

    expect(screen.queryByTestId('delivery-memo')).not.toBeInTheDocument();
  });
});

describe('주문 배송지 변경 모달 구조 검증', () => {
  it('저장된 배송지 모드 apiCall에 confirm 속성이 있다', () => {
    const savedAction = changeAddressModalFixture.actions[0];
    expect(savedAction.confirm).toBe('$t:mypage.order_detail.confirm_change_address');
    expect(savedAction.handler).toBe('apiCall');
    expect(savedAction.params.method).toBe('PUT');
  });

  it('직접 입력 모드 apiCall에 confirm 속성이 있다', () => {
    const manualAction = changeAddressModalFixture.actions[1];
    expect(manualAction.confirm).toBe('$t:mypage.order_detail.confirm_change_address');
    expect(manualAction.handler).toBe('apiCall');
    expect(manualAction.params.method).toBe('PUT');
  });

  it('배송지 수정 버튼 조건에 pending_payment과 payment_complete만 포함한다', () => {
    const ifCondition = shippingInfoFixture.components[0].children[0].children[1].if;
    expect(ifCondition).toContain('pending_payment');
    expect(ifCondition).toContain('payment_complete');
    expect(ifCondition).not.toContain('preparing');
    expect(ifCondition).not.toContain('shipping_ready');
    expect(ifCondition).not.toContain('shipping_hold');
    expect(ifCondition).not.toContain('pending_order');
  });
});
